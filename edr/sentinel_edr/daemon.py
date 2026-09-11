# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The loop.

Every completed write on the filesystem holding the Nextcloud data directory
arrives here with the process that made it. Almost all of them are thrown away
in the first few lines — that is the point, and it is why this can watch a whole
filesystem without costing anything noticeable.

What survives the filter is counted, sometimes sampled, and occasionally judged.
Acting requires several independent things to agree; see verdict.py for why rate
is worth one point out of the five it takes.
"""

from __future__ import annotations

import logging
import os
import signal
import sys
import time

from . import attribution, kinds
from .clam import Clam
from .config import Config
from .fanotify import NotAvailable, Watch
from .respond import KILL, REPORT, SUSPEND, end_sessions, stop
from .spool import Spool
from .verdict import Account, Write, judge

VERSION = "1.0.0"
log = logging.getLogger("sentinel-edr")


class Daemon:
    def __init__(self, config: Config) -> None:
        self.config = config
        self.spool = Spool(config.spool)
        self.clam = Clam(config.clam_socket, max_bytes=config.clam_max_bytes) if config.clam_enabled else None
        self.accounts: dict[str, Account] = {}
        self.processes: dict[int, attribution.Process] = {}
        self.seen = 0
        self.sampled = 0
        self.running = True
        self.method = ""
        self._last_beat = 0.0
        self._scanned_writers: set[str] = set()

    # -- the filter ---------------------------------------------------------

    def account_for(self, path: str) -> str | None:
        """Whose files are these?

        A Nextcloud data directory is <data>/<account>/files/…, and only that
        shape is of interest. Everything else on the filesystem — and on a
        server there is a great deal of it — leaves here immediately.
        """
        root = self.config.data_dir
        if not path.startswith(root + "/"):
            return None
        rest = path[len(root) + 1:]
        parts = rest.split("/", 2)
        if len(parts) < 3 or parts[1] != "files":
            return None
        for ignore in self.config.ignore_paths:
            if ignore in path:
                return None
        return parts[0] or None

    def process(self, pid: int) -> attribution.Process:
        known = self.processes.get(pid)
        if known is not None:
            return known
        found = attribution.describe(pid)
        # Bounded, and cleared wholesale rather than aged: pids are recycled,
        # and a stale answer here would be attributed to the wrong program.
        if len(self.processes) > 4096:
            self.processes.clear()
        self.processes[pid] = found
        return found

    # -- the loop -----------------------------------------------------------

    def run(self) -> int:
        try:
            watch = Watch()
            self.method = watch.watch(self.config.data_dir)
        except NotAvailable as error:
            log.error("cannot watch: %s", error)
            self.beat(error=str(error))
            return 1

        log.info(
            "watching %s by %s mark; response=%s; clamd=%s",
            self.config.data_dir, self.method, self.config.response,
            self.clam.version() if self.clam else "off",
        )
        self.beat()

        while self.running:
            for event in watch.read():
                try:
                    self.handle(event)
                finally:
                    # Every descriptor the kernel hands over is ours to close.
                    # Leaking them would exhaust this process within minutes on
                    # a busy server, which would be a far more reliable way to
                    # break the machine than anything being watched for.
                    try:
                        os.close(event.fd)
                    except OSError:
                        pass

            self.beat()
            time.sleep(0.2)

        watch.close()
        return 0

    def handle(self, event) -> None:
        path = event.path()
        if path is None:
            return
        uid = self.account_for(path)
        if uid is None:
            return

        self.seen += 1
        name = os.path.basename(path)
        writer = self.process(event.pid)
        expected = attribution.is_expected(writer, self.config.expected)

        account = self.accounts.get(uid)
        if account is None:
            account = Account(uid=uid, window=float(self.config.window))
            self.accounts[uid] = account

        mismatch: bool | None = None
        halfway = account.count * 2 >= self.config.rate_floor
        if kinds.known(name) and (not expected or halfway):
            # Sampled once the rate is halfway to interesting, or immediately
            # when the writer is a program that should not be here at all.
            if self.sampled % self.config.sample_every == 0:
                verdict = kinds.matches(name, event.head(64))
                if verdict is not None:
                    mismatch = not verdict
            self.sampled += 1

        note = kinds.looks_like_a_ransom_note(name)

        account.record(Write(
            at=time.time(), mismatch=mismatch, note=note,
            pid=event.pid, expected=expected,
        ))

        # A scanner is only worth waking for things that already look wrong.
        if self.clam is not None and (mismatch or note):
            hit = self.clam.scan_fd(event.fd)
            if hit.infected:
                account.scanner_hits.append(f"{name}: {hit.name}")
        if self.clam is not None and not expected and self.config.clam_scan_writers and writer.exe:
            if writer.exe not in self._scanned_writers:
                self._scanned_writers.add(writer.exe)
                hit = self.clam.scan_path(writer.exe)
                if hit.infected:
                    account.scanner_hits.append(f"{writer.exe}: {hit.name}")

        self.consider(account)

    # -- the decision -------------------------------------------------------

    def consider(self, account: Account) -> None:
        if account.count < max(20, self.config.rate_floor // 10):
            return
        # Judged on a cadence rather than on every file: the answer cannot
        # change meaningfully between one file and the next, and this runs in
        # the path of every write on the machine.
        if account.count % 25 != 0:
            return

        found = judge(
            account,
            rate_floor=self.config.rate_floor,
            mismatch_floor=self.config.mismatch_floor,
            mismatch_ratio=self.config.mismatch_ratio,
            stranger_floor=self.config.stranger_floor,
        )
        if not found.report:
            return
        now = time.time()
        if not account.worth_saying_again(found.score, now):
            return
        account.last_report = now
        account.reported_score = found.score

        strangers = account.strangers()
        offenders = sorted(strangers, key=lambda pid: strangers[pid], reverse=True)
        described = [self._describe(pid, strangers[pid]) for pid in offenders[:5]]

        action = {"what": REPORT, "pids": [], "detail": "reported only"}
        sessions = ""

        if found.act:
            if offenders:
                # Something that has no business here. Freeze it where it
                # stands; a person decides what happens next.
                taken = stop(offenders, self.config.response, self.config.protected_pids)
                action = {"what": taken.what, "pids": taken.pids, "detail": taken.detail}
            else:
                # It came through the web stack, which means php-fpm, which
                # must not be touched: stopping it stops Nextcloud for
                # everybody. The session behind it is Nextcloud's to end.
                action = {"what": REPORT, "pids": [], "detail": "writer is the web server; left alone on purpose"}
            if self.config.end_sessions:
                sessions = end_sessions(
                    self.config.occ, self.config.occ_user, account.uid, self.config.disable_account,
                )

        payload = {
            "uid": account.uid,
            "verdict": found.verdict,
            "score": found.score,
            "signals": found.signals,
            "files": account.count,
            "sampled": account.checked,
            "mismatched": account.mismatched,
            "window": int(account.window),
            "process": described[0] if described else None,
            "processes": described,
            "action": action,
            # What the daemon wanted done on Nextcloud's side, and how it went.
            # Both are recorded, because the second can fail — a misconfigured
            # occ path, a sandbox that will not let it run — and a response that
            # silently did not happen is worse than one that never existed. When
            # this says it failed, Nextcloud carries it out itself on ingest.
            "request": {
                "endSessions": bool(found.act and self.config.end_sessions),
                "disable": bool(found.act and self.config.end_sessions and self.config.disable_account),
            },
            "sessions": sessions,
            "version": VERSION,
        }
        self.spool.report(payload)
        log.warning(
            "%s: %s (score %d) %s -> %s",
            account.uid, found.verdict, found.score, found.signals, action,
        )

    def _describe(self, pid: int, files: int) -> dict:
        found = self.process(pid)
        return {
            "pid": pid,
            "files": files,
            "exe": found.exe,
            "name": found.name,
            "cmdline": found.cmdline[:200],
            "uid": found.uid,
            "ppid": found.ppid,
        }

    # -- saying we are alive ------------------------------------------------

    def beat(self, error: str = "") -> None:
        now = time.time()
        if now - self._last_beat < self.config.heartbeat:
            return
        self._last_beat = now
        try:
            self.spool.heartbeat({
                "version": VERSION,
                "watching": [self.config.data_dir],
                "method": self.method,
                "response": self.config.response,
                "seen": self.seen,
                "accounts": len(self.accounts),
                "clam": (self.clam.version() if self.clam else None),
                "error": error,
            })
            self.spool.sweep()
        except OSError as problem:
            log.warning("could not write the heartbeat: %s", problem)

    def stop(self, *_args) -> None:
        self.running = False


def main(argv: list[str] | None = None) -> int:
    from . import config as configuration

    argv = list(sys.argv[1:] if argv is None else argv)
    path = configuration.DEFAULT_PATH
    if "--config" in argv:
        path = argv[argv.index("--config") + 1]

    logging.basicConfig(
        level=logging.DEBUG if "--debug" in argv else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
    )

    settings = configuration.load(path)

    if os.geteuid() != 0:
        log.error("fanotify needs root; nothing else here does, but that one does")
        return 1
    if not os.path.isdir(settings.data_dir):
        log.error("data_dir %s is not a directory", settings.data_dir)
        return 1

    daemon = Daemon(settings)
    signal.signal(signal.SIGTERM, daemon.stop)
    signal.signal(signal.SIGINT, daemon.stop)
    return daemon.run()
