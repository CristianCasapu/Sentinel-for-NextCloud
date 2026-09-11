# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Does this file still look like what its name claims?

The same table Sentinel keeps on the PHP side, for the same reason: rate cannot
tell a photo library being moved from a folder being encrypted, and this can.
No legitimate client rewrites a .jpg so that it stops being a JPEG.
"""

from __future__ import annotations

MAGIC: dict[str, list[tuple[int, bytes]]] = {
    "jpg": [(0, b"\xff\xd8\xff")], "jpeg": [(0, b"\xff\xd8\xff")],
    "png": [(0, b"\x89PNG\r\n\x1a\n")],
    "gif": [(0, b"GIF87a"), (0, b"GIF89a")],
    "bmp": [(0, b"BM")],
    "webp": [(0, b"RIFF"), (8, b"WEBP")],
    "tif": [(0, b"II\x2a\x00"), (0, b"MM\x00\x2a")],
    "tiff": [(0, b"II\x2a\x00"), (0, b"MM\x00\x2a")],
    "psd": [(0, b"8BPS")],
    "heic": [(4, b"ftyp")], "heif": [(4, b"ftyp")], "avif": [(4, b"ftyp")],
    "cr2": [(0, b"II\x2a\x00")], "arw": [(0, b"II\x2a\x00")],
    "nef": [(0, b"MM\x00\x2a"), (0, b"II\x2a\x00")],
    "dng": [(0, b"II\x2a\x00"), (0, b"MM\x00\x2a")],

    "mp4": [(4, b"ftyp")], "m4v": [(4, b"ftyp")], "m4a": [(4, b"ftyp")],
    "mov": [(4, b"ftyp"), (4, b"moov"), (4, b"mdat")],
    "3gp": [(4, b"ftyp")],
    "mkv": [(0, b"\x1a\x45\xdf\xa3")], "webm": [(0, b"\x1a\x45\xdf\xa3")],
    "avi": [(0, b"RIFF"), (8, b"AVI ")],
    "wav": [(0, b"RIFF"), (8, b"WAVE")],
    "flv": [(0, b"FLV")],
    "wmv": [(0, b"\x30\x26\xb2\x75")], "asf": [(0, b"\x30\x26\xb2\x75")],
    "mpg": [(0, b"\x00\x00\x01")], "mpeg": [(0, b"\x00\x00\x01")],

    "mp3": [(0, b"ID3"), (0, b"\xff\xfb"), (0, b"\xff\xf3"), (0, b"\xff\xf2")],
    "flac": [(0, b"fLaC")],
    "ogg": [(0, b"OggS")], "opus": [(0, b"OggS")],

    "pdf": [(0, b"%PDF")],
    "zip": [(0, b"PK\x03\x04"), (0, b"PK\x05\x06")],
    "docx": [(0, b"PK\x03\x04")], "xlsx": [(0, b"PK\x03\x04")], "pptx": [(0, b"PK\x03\x04")],
    "odt": [(0, b"PK\x03\x04")], "ods": [(0, b"PK\x03\x04")], "odp": [(0, b"PK\x03\x04")],
    "epub": [(0, b"PK\x03\x04")],
    "doc": [(0, b"\xd0\xcf\x11\xe0")], "xls": [(0, b"\xd0\xcf\x11\xe0")], "ppt": [(0, b"\xd0\xcf\x11\xe0")],
    "rtf": [(0, b"{\\rtf")],
    "7z": [(0, b"7z\xbc\xaf\x27\x1c")],
    "rar": [(0, b"Rar!")],
    "gz": [(0, b"\x1f\x8b")], "tgz": [(0, b"\x1f\x8b")],
    "bz2": [(0, b"BZh")],
    "xz": [(0, b"\xfd7zXZ")],
    "sqlite": [(0, b"SQLite format 3")],
    "exe": [(0, b"MZ")], "dll": [(0, b"MZ")],
}

RANSOM_NOTES = (
    "how_to_decrypt", "how-to-decrypt", "howtodecrypt",
    "how_to_restore", "how-to-restore", "howtorestore",
    "recover_your_files", "recover-your-files", "recoveryourfiles",
    "restore_my_files", "restore-my-files", "restoremyfiles",
    "your_files_are_encrypted", "files_encrypted", "decrypt_instruction",
    "readme_for_decrypt", "read_me_decrypt", "decrypt_files",
    "_openme", "unlock_your_files",
)

NOTE_SUFFIXES = (".txt", ".html", ".htm", ".hta")

# Nextcloud's own server-side encryption writes every file with this header. On
# an installation that uses it, every single file on disk would otherwise look
# like it had been encrypted by somebody hostile — which is true, and useless.
NEXTCLOUD_ENCRYPTION = b"HBEGIN:oc_encryption_module"


def extension(name: str) -> str:
    dot = name.rfind(".")
    return name[dot + 1:].lower() if dot > 0 else ""


def known(name: str) -> bool:
    return extension(name) in MAGIC


def matches(name: str, head: bytes) -> bool | None:
    """True if the content fits the name, False if it does not, None if unknown.

    None matters as much as the other two: a file too short to judge, or of a
    kind with no signature worth trusting, must not count as evidence in either
    direction.
    """
    signatures = MAGIC.get(extension(name))
    if not signatures or not head:
        return None
    if head.startswith(NEXTCLOUD_ENCRYPTION[:len(head)]) or head.startswith(b"HBEGIN:"):
        # Encrypted by this server, on purpose. Says nothing either way.
        return None

    judged = False
    for offset, signature in signatures:
        if offset + len(signature) > len(head):
            continue
        judged = True
        if head[offset:offset + len(signature)] == signature:
            return True
    return False if judged else None


def looks_like_a_ransom_note(name: str) -> bool:
    lower = name.lower()
    if not lower.endswith(NOTE_SUFFIXES):
        return False
    return any(needle in lower for needle in RANSOM_NOTES)
