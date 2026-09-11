# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Stopping it, carefully.

Two rules, and the second one is the important one.

First: a process that has no business writing into the data directory gets
suspended, not killed. Suspending is reversible — the process is frozen exactly
where it stood, nothing it holds is lost, and a person can look at it and then
either kill it or let it go. Killing an encryptor mid-file and killing a backup
script mid-file look the same from here, and only one of those is a mistake you
can undo.

Second: php-fpm is never touched. On a Nextcloud server php-fpm is what writes
the files, all day, for everybody. Stopping it stops Nextcloud for every account
on the machine — which is a far more reliable way to take a server down than
most of what this daemon is watching for. When the writer is php-fpm, the answer
is not on this side of the wall at all: it is to end the *session* doing it, and
that is Nextcloud's to do.
"""

from __future__ import annotations

import os
import signal
import subprocess
from dataclasses import dataclass

SUSPEND = "suspend"
KILL = "kill"
REPORT = "report"


@dataclass(frozen=True)
class Action:
    what: str
    pids: list[int]
    detail: str = ""


def stop(pids: list[int], how: str, protected: set[int]) -> Action:
    """Suspend or kill the processes that are not allowed to be doing this."""
    if how == REPORT or not pids:
        return Action(what=REPORT, pids=[], detail="reported only")

    touched: list[int] = []
    for pid in pids:
        if pid in protected or pid <= 1 or pid == os.getpid():
            continue
        try:
            os.kill(pid, signal.SIGSTOP if how == SUSPEND else signal.SIGKILL)
            touched.append(pid)
        except (ProcessLookupError, PermissionError):
            continue

    if not touched:
        return Action(what=REPORT, pids=[], detail="nothing left to stop")
    return Action(what=how, pids=touched)


def end_sessions(occ: list[str], as_user: str, uid: str, lock: bool, timeout: int = 60) -> str:
    """Ask Nextcloud to end that account's sessions, and optionally disable it.

    Through occ rather than the API, so that no credential has to be stored
    anywhere on this machine and every invocation is visible in the process
    list.

    occ refuses to run as root — it would leave files in the data directory that
    the web server cannot then touch — so the daemon drops to the account that
    owns the installation before running it. Dropping privilege, never taking
    it: this is deliberately the opposite of asking sudo, which cannot work
    under NoNewPrivileges and would need a sudoers rule that is itself a way in.
    """
    if not occ:
        return "not configured"

    command = [*occ, "sentinel:respond", "--uid", uid]
    if lock:
        command.append("--disable")

    extra: dict = {}
    if as_user:
        try:
            import pwd

            account = pwd.getpwnam(as_user)
        except KeyError:
            return f"failed: no account called {as_user} on this machine"
        extra = {
            "user": account.pw_uid,
            "group": account.pw_gid,
            "env": {
                "HOME": account.pw_dir,
                "USER": as_user,
                "PATH": "/usr/local/bin:/usr/bin:/bin",
            },
        }

    try:
        finished = subprocess.run(
            command, capture_output=True, text=True, timeout=timeout, check=False, **extra,
        )
    except (OSError, subprocess.TimeoutExpired) as error:
        return f"failed: {error}"
    if finished.returncode != 0:
        return f"failed: {(finished.stderr or finished.stdout).strip()[:200]}"
    return (finished.stdout or "done").strip()[:200]
