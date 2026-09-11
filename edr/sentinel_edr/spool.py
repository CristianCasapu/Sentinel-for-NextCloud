# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The channel between a daemon running as root and a web app that is not.

Deliberately the dullest one available: a file written into a directory. No
socket, no port, no credential stored on disk, nothing that has to be kept
secret, and nothing that breaks when either side restarts. The web app can read
the directory and cannot write to it; the daemon can write and never reads
anything the web app produced. Neither can make the other do anything.
"""

from __future__ import annotations

import json
import os
import tempfile
import time


class Spool:
    def __init__(self, directory: str) -> None:
        self.directory = directory
        os.makedirs(directory, mode=0o755, exist_ok=True)

    def _write(self, name: str, payload: dict) -> str:
        # Written beside and renamed into place, so the reader never sees half
        # a report: rename within one directory is atomic.
        fd, temporary = tempfile.mkstemp(dir=self.directory, prefix=".tmp-")
        try:
            with os.fdopen(fd, "w") as handle:
                json.dump(payload, handle, ensure_ascii=False)
            os.chmod(temporary, 0o644)
            final = os.path.join(self.directory, name)
            os.replace(temporary, final)
            return final
        except Exception:
            try:
                os.unlink(temporary)
            except OSError:
                pass
            raise

    def report(self, payload: dict) -> str:
        payload = {"at": int(time.time()), **payload}
        name = f"report-{payload['at']}-{os.getpid()}-{int(time.monotonic() * 1000) % 100000}.json"
        return self._write(name, payload)

    def heartbeat(self, payload: dict) -> str:
        return self._write("heartbeat.json", {"at": int(time.time()), **payload})

    def sweep(self, keep_seconds: int = 7 * 86400) -> int:
        """Reports nobody collected. Nextcloud removes what it reads; anything
        older than this means nobody is reading, and it should not pile up."""
        removed = 0
        edge = time.time() - keep_seconds
        try:
            for entry in os.scandir(self.directory):
                if not entry.name.startswith("report-"):
                    continue
                try:
                    if entry.stat().st_mtime < edge:
                        os.unlink(entry.path)
                        removed += 1
                except OSError:
                    continue
        except OSError:
            pass
        return removed
