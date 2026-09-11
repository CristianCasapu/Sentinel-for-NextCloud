# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""The kernel interface that makes this worth writing at all.

inotify can tell you a file changed. It cannot tell you *who* changed it, and
who changed it is the entire question: a sync client faithfully uploading what
ransomware did on somebody's laptop and a process on this machine encrypting the
data directory look identical from inside Nextcloud, and could not be more
different.

fanotify answers it. Every event carries the process identifier and an open file
descriptor for the file itself — the descriptor matters as much as the pid,
because it lets the contents be read without a second lookup by path, which
would be a race the encryptor could win.

There is no fanotify in the standard library, so this is the syscall by hand.
"""

from __future__ import annotations

import ctypes
import ctypes.util
import os
import struct
from dataclasses import dataclass
from typing import Iterator

# fanotify_init flags
FAN_CLOEXEC = 0x00000001
FAN_NONBLOCK = 0x00000002
FAN_CLASS_NOTIF = 0x00000000

# fanotify_mark flags
FAN_MARK_ADD = 0x00000001
FAN_MARK_MOUNT = 0x00000010
FAN_MARK_FILESYSTEM = 0x00000100

# events
FAN_ACCESS = 0x00000001
FAN_MODIFY = 0x00000002
FAN_CLOSE_WRITE = 0x00000008
FAN_OPEN = 0x00000020

AT_FDCWD = -100

# struct fanotify_event_metadata: event_len, vers, reserved, metadata_len,
# mask (8-byte aligned, which it already is at offset 8), fd, pid.
_METADATA = struct.Struct("=IBBHQii")
FANOTIFY_METADATA_VERSION = 3


@dataclass(frozen=True)
class Event:
    """One completed write, and the hand that made it."""

    fd: int
    pid: int
    mask: int

    def path(self) -> str | None:
        """Where the descriptor points, right now.

        Read from /proc rather than remembered, because a file that has been
        renamed since — which is exactly what an encryptor does — should be
        reported where it actually is.
        """
        try:
            return os.readlink(f"/proc/self/fd/{self.fd}")
        except OSError:
            return None

    def head(self, size: int = 64) -> bytes:
        """The first bytes, read through the descriptor the kernel handed us.

        Not reopened by name: between the event and the read, the name may
        already belong to a different file.
        """
        try:
            return os.pread(self.fd, size, 0)
        except OSError:
            return b""

    def size(self) -> int:
        try:
            return os.fstat(self.fd).st_size
        except OSError:
            return 0


class NotAvailable(RuntimeError):
    """fanotify is not usable here — too old a kernel, or not running as root."""


class Watch:
    """A fanotify group, watching whole filesystems for finished writes."""

    def __init__(self) -> None:
        self._libc = ctypes.CDLL(ctypes.util.find_library("c") or "libc.so.6", use_errno=True)
        if not hasattr(self._libc, "fanotify_init"):
            raise NotAvailable("this libc has no fanotify_init")

        self._libc.fanotify_init.argtypes = [ctypes.c_uint, ctypes.c_uint]
        self._libc.fanotify_init.restype = ctypes.c_int
        self._libc.fanotify_mark.argtypes = [
            ctypes.c_int, ctypes.c_uint, ctypes.c_uint64, ctypes.c_int, ctypes.c_char_p,
        ]
        self._libc.fanotify_mark.restype = ctypes.c_int

        # Notification only. This group is never asked for permission decisions:
        # a watcher that can block file access is a watcher that can hang the
        # server if it stalls, and nothing here is worth that risk.
        fd = self._libc.fanotify_init(
            FAN_CLOEXEC | FAN_CLASS_NOTIF | FAN_NONBLOCK,
            os.O_RDONLY | os.O_LARGEFILE,
        )
        if fd < 0:
            err = ctypes.get_errno()
            raise NotAvailable(f"fanotify_init failed: {os.strerror(err)}")
        self.fd = fd
        self._marks: list[str] = []

    def watch(self, path: str) -> str:
        """Watch everything on the filesystem holding this path.

        fanotify cannot watch a directory tree: a mark on a directory covers its
        immediate children and nothing deeper. Whole-filesystem marks are the
        only way to see a tree, so the filtering by path happens afterwards, in
        userspace, on the resolved path of each event.
        """
        mode = FAN_MARK_ADD | FAN_MARK_FILESYSTEM
        how = "filesystem"
        result = self._libc.fanotify_mark(
            self.fd, mode, ctypes.c_uint64(FAN_CLOSE_WRITE), AT_FDCWD, path.encode(),
        )
        if result < 0:
            # FAN_MARK_FILESYSTEM wants Linux 4.20. A mount mark is older and
            # covers the same ground for anything not spread over bind mounts.
            mode = FAN_MARK_ADD | FAN_MARK_MOUNT
            how = "mount"
            result = self._libc.fanotify_mark(
                self.fd, mode, ctypes.c_uint64(FAN_CLOSE_WRITE), AT_FDCWD, path.encode(),
            )
        if result < 0:
            err = ctypes.get_errno()
            raise NotAvailable(f"fanotify_mark({path}) failed: {os.strerror(err)}")

        self._marks.append(path)
        return how

    def read(self, blocking_fd_timeout: float = 1.0) -> Iterator[Event]:
        """Drain whatever is waiting.

        The caller owns every descriptor yielded and must close it. Leaking them
        would exhaust the process in minutes on a busy server, which is a far
        more likely way for this to take a machine down than anything it is
        watching for.
        """
        try:
            buffer = os.read(self.fd, 64 * _METADATA.size * 16)
        except BlockingIOError:
            return
        except OSError:
            return

        offset = 0
        while offset + _METADATA.size <= len(buffer):
            event_len, version, _reserved, _metadata_len, mask, fd, pid = _METADATA.unpack_from(buffer, offset)
            if version != FANOTIFY_METADATA_VERSION:
                # A kernel speaking a dialect this does not know. Stop rather
                # than guess: misreading the struct would mean closing file
                # descriptors that belong to somebody else.
                if fd >= 0:
                    os.close(fd)
                return
            if event_len < _METADATA.size:
                return
            if fd >= 0:
                yield Event(fd=fd, pid=pid, mask=mask)
            offset += event_len

    def close(self) -> None:
        try:
            os.close(self.fd)
        except OSError:
            pass
