# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Asking clamd about one file, at the moment it becomes interesting.

Not a scanner for everything that arrives — that is a different job with a
different cost, and Nextcloud has an app for it. This is the narrow use: once
something already looks wrong for other reasons, put the handful of files that
caused the suspicion, and the program that wrote them, in front of a scanner.

Spoken to over its socket rather than through clamscan, which loads the whole
signature database for every single file it is asked about.
"""

from __future__ import annotations

import socket
import struct
from dataclasses import dataclass

CHUNK = 65536


@dataclass(frozen=True)
class Verdict:
    scanned: bool
    infected: bool
    name: str = ""

    @property
    def useful(self) -> bool:
        return self.scanned


UNKNOWN = Verdict(scanned=False, infected=False)


class Clam:
    def __init__(self, address: str, timeout: float = 30.0, max_bytes: int = 32 * 1024 * 1024) -> None:
        self.address = address
        self.timeout = timeout
        self.max_bytes = max_bytes

    def _connect(self) -> socket.socket | None:
        try:
            if self.address.startswith("/"):
                sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                sock.settimeout(self.timeout)
                sock.connect(self.address)
            else:
                host, _, port = self.address.rpartition(":")
                sock = socket.create_connection((host or "127.0.0.1", int(port or 3310)), self.timeout)
                sock.settimeout(self.timeout)
            return sock
        except OSError:
            return None

    def version(self) -> str | None:
        sock = self._connect()
        if sock is None:
            return None
        try:
            sock.sendall(b"zVERSION\0")
            answer = sock.recv(512).decode("utf-8", "replace").strip().rstrip("\0")
            return answer or None
        except OSError:
            return None
        finally:
            sock.close()

    def scan_fd(self, fd: int) -> Verdict:
        """Scan through an open descriptor.

        The descriptor, not the path: between noticing a file and reading it,
        the name may already belong to something else, and it is precisely the
        interesting cases where that is likely.
        """
        import os

        sock = self._connect()
        if sock is None:
            return UNKNOWN
        try:
            sock.sendall(b"zINSTREAM\0")
            sent = 0
            while sent < self.max_bytes:
                chunk = os.pread(fd, min(CHUNK, self.max_bytes - sent), sent)
                if not chunk:
                    break
                sent += len(chunk)
                sock.sendall(struct.pack("!I", len(chunk)) + chunk)
            sock.sendall(struct.pack("!I", 0))
            return self._read(sock)
        except OSError:
            return UNKNOWN
        finally:
            sock.close()

    def scan_path(self, path: str) -> Verdict:
        import os

        try:
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
        except OSError:
            return UNKNOWN
        try:
            return self.scan_fd(fd)
        finally:
            os.close(fd)

    def _read(self, sock: socket.socket) -> Verdict:
        try:
            answer = sock.recv(4096).decode("utf-8", "replace").strip().rstrip("\0")
        except OSError:
            return UNKNOWN
        if not answer or answer.endswith("ERROR"):
            return UNKNOWN
        if answer.endswith("FOUND"):
            name = answer.split(":", 1)[-1].strip()
            name = name[: -len("FOUND")].strip()
            return Verdict(scanned=True, infected=True, name=name)
        return Verdict(scanned=True, infected=False)
