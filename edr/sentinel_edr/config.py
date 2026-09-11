# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Everything this daemon decides with, in one file it reads at start."""

from __future__ import annotations

import configparser
import os
from dataclasses import dataclass, field

DEFAULT_PATH = "/etc/sentinel-edr/config.ini"

# The programs that are supposed to write into a Nextcloud data directory.
# Anything else doing it is remarkable before a single byte is examined.
DEFAULT_EXPECTED = "php-fpm,php,apache2,httpd,nginx,occ,rsync,cp,mv,tar,updatenotification"


@dataclass
class Config:
    data_dir: str = "/var/www/nextcloud/data"
    spool: str = "/var/lib/sentinel-edr/spool"
    occ: list[str] = field(default_factory=list)
    occ_user: str = ""

    window: int = 300
    rate_floor: int = 400
    mismatch_floor: int = 5
    mismatch_ratio: int = 50
    stranger_floor: int = 20
    sample_every: int = 3

    response: str = "suspend"       # suspend | kill | report
    end_sessions: bool = True       # ask Nextcloud to sign the account out
    disable_account: bool = False   # and to disable it entirely

    clam_socket: str = "/run/clamav/clamd.ctl"
    clam_enabled: bool = True
    clam_max_bytes: int = 32 * 1024 * 1024
    clam_scan_writers: bool = True

    expected: set[str] = field(default_factory=lambda: set(DEFAULT_EXPECTED.split(",")))
    ignore_paths: list[str] = field(default_factory=lambda: [
        "/files_trashbin/", "/files_versions/", "/appdata_", "/.ocdata",
        "/uploads/", "/cache/", "/nextcloud.log", "/audit.log",
    ])
    heartbeat: int = 60

    @property
    def protected_pids(self) -> set[int]:
        return {1, os.getpid()}


def load(path: str = DEFAULT_PATH) -> Config:
    config = Config()
    parser = configparser.ConfigParser()
    if not parser.read(path):
        return config

    section = parser["sentinel-edr"] if parser.has_section("sentinel-edr") else parser["DEFAULT"]

    config.data_dir = section.get("data_dir", config.data_dir).rstrip("/")
    config.spool = section.get("spool", config.spool).rstrip("/")
    occ = section.get("occ", "").strip()
    config.occ = occ.split() if occ else []
    config.occ_user = section.get("occ_user", config.occ_user).strip()

    config.window = section.getint("window", config.window)
    config.rate_floor = section.getint("rate_floor", config.rate_floor)
    config.mismatch_floor = section.getint("mismatch_floor", config.mismatch_floor)
    config.mismatch_ratio = section.getint("mismatch_ratio", config.mismatch_ratio)
    config.stranger_floor = section.getint("stranger_floor", config.stranger_floor)
    config.sample_every = max(1, section.getint("sample_every", config.sample_every))

    config.response = section.get("response", config.response).strip().lower()
    config.end_sessions = section.getboolean("end_sessions", config.end_sessions)
    config.disable_account = section.getboolean("disable_account", config.disable_account)

    config.clam_socket = section.get("clam_socket", config.clam_socket)
    config.clam_enabled = section.getboolean("clam_enabled", config.clam_enabled)
    config.clam_max_bytes = section.getint("clam_max_bytes", config.clam_max_bytes)
    config.clam_scan_writers = section.getboolean("clam_scan_writers", config.clam_scan_writers)

    expected = section.get("expected", DEFAULT_EXPECTED)
    config.expected = {name.strip() for name in expected.split(",") if name.strip()}

    ignore = section.get("ignore_paths", "")
    if ignore.strip():
        config.ignore_paths = [part.strip() for part in ignore.split(",") if part.strip()]

    config.heartbeat = section.getint("heartbeat", config.heartbeat)
    return config
