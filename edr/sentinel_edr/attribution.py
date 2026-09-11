# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Who is this, and should it be writing here?

The pid on a fanotify event is only a number. What matters is the answer to one
question: is this one of the handful of programs that are supposed to be writing
into the data directory, or is it something else? On a Nextcloud server the
legitimate writers are few and well known, and anything outside that set writing
into somebody's files is already remarkable before a single byte is examined.
"""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Process:
    pid: int
    exe: str
    name: str
    cmdline: str
    uid: int
    ppid: int

    @property
    def gone(self) -> bool:
        return self.exe == "" and self.name == ""


def describe(pid: int) -> Process:
    """Everything /proc will say about a process, tolerating it being gone.

    A process that wrote a file and exited before we looked is ordinary — most
    command-line tools do exactly that — so absence is a normal answer here, not
    an error.
    """
    exe = ""
    try:
        exe = os.readlink(f"/proc/{pid}/exe")
    except OSError:
        pass

    cmdline = ""
    try:
        with open(f"/proc/{pid}/cmdline", "rb") as handle:
            cmdline = handle.read(4096).replace(b"\0", b" ").decode("utf-8", "replace").strip()
    except OSError:
        pass

    name = ""
    uid = -1
    ppid = 0
    try:
        with open(f"/proc/{pid}/status", "r") as handle:
            for line in handle:
                if line.startswith("Name:"):
                    name = line.split(":", 1)[1].strip()
                elif line.startswith("Uid:"):
                    uid = int(line.split()[1])
                elif line.startswith("PPid:"):
                    ppid = int(line.split(":", 1)[1].strip())
                if name and uid >= 0 and ppid:
                    break
    except OSError:
        pass

    if not name and exe:
        name = os.path.basename(exe)

    return Process(pid=pid, exe=exe, name=name, cmdline=cmdline, uid=uid, ppid=ppid)


def is_expected(process: Process, expected: set[str]) -> bool:
    """Is this one of the programs that belong here?

    Matched on the basename of the executable and on the process name, because
    php-fpm renames itself to something like "php-fpm: pool www" and the
    executable is the honest half of that.
    """
    if process.gone:
        # Nothing to judge. Treated as expected on purpose: a process that has
        # already exited is not one this daemon can stop, and guessing badly
        # about it would only produce noise.
        return True

    candidates = {os.path.basename(process.exe), process.name}
    for candidate in candidates:
        if not candidate:
            continue
        if candidate in expected:
            return True
        # "php-fpm8.4" should match an expectation of "php-fpm", and
        # "php8.4" an expectation of "php".
        for allowed in expected:
            if candidate.startswith(allowed):
                return True
    return False
