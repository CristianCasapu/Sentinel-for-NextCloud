# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Deciding, from several things that each mean little on their own.

The rule this file exists to enforce: **rate never decides anything**. Five
hundred files in five minutes is a phone finishing its first backup as often as
it is an encryptor working through a folder, and a daemon allowed to stop
processes must not be able to act on that alone. Rate is worth one point. Acting
needs five.

Everything else here is a signal that has a reason to be trusted:

* files that are no longer the kind of file their own name says they are — no
  legitimate client does this, ever
* a ransom note appearing in somebody's folder
* a program writing into the data directory that has no business being there
* a scanner recognising what was written, or the thing that wrote it
"""

from __future__ import annotations

import time
from collections import deque
from dataclasses import dataclass, field

# What each signal is worth. Rate is deliberately the cheapest thing here.
POINTS_RATE = 1
POINTS_MISMATCH = 4
# When the sample is large and almost all of it is wrong, this stops being one
# signal among several and becomes the answer. Twenty files sampled and four in
# five of them no longer being the kind of file their own name claims is not
# something a working system does on an ordinary day.
POINTS_MISMATCH_OVERWHELMING = 2
OVERWHELMING_COUNT = 20
OVERWHELMING_RATIO = 80

# Before the most recent handful is allowed to speak louder than the whole
# window, there has to be enough of it to mean anything.
RECENT_MINIMUM = 20
RECENT_MISMATCH_MINIMUM = 10
POINTS_RANSOM_NOTE = 4
POINTS_UNEXPECTED_WRITER = 3
POINTS_SCANNER = 5

ACT_AT = 5
REPORT_AT = 4


@dataclass
class Write:
    at: float
    mismatch: bool | None
    note: bool
    pid: int
    expected: bool


@dataclass
class Account:
    """What has happened to one Nextcloud account's files, lately."""

    uid: str
    window: float
    writes: deque[Write] = field(default_factory=deque)
    scanner_hits: list[str] = field(default_factory=list)
    # What was already said about this account, so that it is not said again.
    last_report: float = 0.0
    reported_score: int = 0

    def worth_saying_again(self, score: int, now: float) -> bool:
        """Has anything actually changed since the last time?

        Evidence stays in the window for its whole length, so without this an
        incident that lasted eight seconds would be announced once a minute for
        five minutes — and the fifth announcement would arrive while somebody
        was already dealing with the first. Only a worse picture, or a genuinely
        new episode after everything has aged out, is worth another word.
        """
        if self.last_report == 0.0:
            return True
        if score > self.reported_score:
            return True
        return now - self.last_report > self.window

    def record(self, write: Write) -> None:
        self.writes.append(write)
        self.prune(write.at)

    def prune(self, now: float) -> None:
        edge = now - self.window
        while self.writes and self.writes[0].at < edge:
            self.writes.popleft()

    @property
    def count(self) -> int:
        return len(self.writes)

    @property
    def checked(self) -> int:
        return sum(1 for w in self.writes if w.mismatch is not None)

    @property
    def mismatched(self) -> int:
        return sum(1 for w in self.writes if w.mismatch is True)

    @property
    def notes(self) -> int:
        return sum(1 for w in self.writes if w.note)

    def recent(self, howmany: int = 40) -> tuple[int, int]:
        """The last few files that could be judged, and how many were wrong.

        The ratio over the whole window is the obvious measure and it has a
        hole in it: somebody who uploads eight hundred holiday photos and is
        attacked twenty minutes later has, for the next five minutes, a window
        in which the genuine files outnumber the encrypted ones and the ratio
        stays low. The attack is invisible precisely because the account was
        busy. Looking at the most recent handful closes that: ransomware
        produces a run of wrong files, one after another, and a run is what this
        sees.
        """
        judged = [w for w in self.writes if w.mismatch is not None]
        tail = judged[-howmany:]
        return len(tail), sum(1 for w in tail if w.mismatch)

    def writers(self) -> dict[int, int]:
        out: dict[int, int] = {}
        for w in self.writes:
            out[w.pid] = out.get(w.pid, 0) + 1
        return out

    def strangers(self) -> dict[int, int]:
        out: dict[int, int] = {}
        for w in self.writes:
            if not w.expected:
                out[w.pid] = out.get(w.pid, 0) + 1
        return out


@dataclass(frozen=True)
class Judgement:
    score: int
    signals: dict[str, object]
    act: bool
    report: bool

    @property
    def verdict(self) -> str:
        if self.act:
            return "ransomware"
        if self.report:
            return "suspicious"
        return "quiet"


def judge(
    account: Account,
    rate_floor: int,
    mismatch_floor: int,
    mismatch_ratio: int,
    stranger_floor: int,
) -> Judgement:
    now = time.time()
    account.prune(now)

    signals: dict[str, object] = {}
    score = 0

    if account.count >= rate_floor:
        score += POINTS_RATE
        signals["rate"] = f"{account.count} files in {int(account.window)}s"

    checked = account.checked
    mismatched = account.mismatched
    window_ratio = round(mismatched * 100 / checked) if checked else 0

    # And the same question asked of the most recent handful only, which is what
    # survives a busy account being attacked.
    recent_checked, recent_mismatched = account.recent()
    recent_ratio = round(recent_mismatched * 100 / recent_checked) if recent_checked else 0

    # The recent view is allowed to override the window one, but it has to earn
    # it: a handful of genuinely mislabelled files landing at the end of an
    # upload must not look like an attack just because they arrived last.
    recent_is_telling = (
        recent_checked >= RECENT_MINIMUM
        and recent_mismatched >= RECENT_MISMATCH_MINIMUM
        and recent_ratio > window_ratio
    )
    if recent_is_telling:
        counted, ratio, scope = recent_mismatched, recent_ratio, f"of the last {recent_checked}"
    else:
        counted, ratio, scope = mismatched, window_ratio, f"of {checked} sampled"

    if counted >= mismatch_floor and ratio >= mismatch_ratio:
        score += POINTS_MISMATCH
        signals["contentsDoNotMatchNames"] = f"{counted} {scope} ({ratio}%)"
        if counted >= OVERWHELMING_COUNT and ratio >= OVERWHELMING_RATIO:
            score += POINTS_MISMATCH_OVERWHELMING
            signals["overwhelming"] = True

    if account.notes:
        score += POINTS_RANSOM_NOTE
        signals["ransomNote"] = account.notes

    strangers = account.strangers()
    if strangers and sum(strangers.values()) >= stranger_floor:
        score += POINTS_UNEXPECTED_WRITER
        signals["unexpectedWriter"] = strangers

    if account.scanner_hits:
        score += POINTS_SCANNER
        signals["scanner"] = sorted(set(account.scanner_hits))[:5]

    return Judgement(
        score=score,
        signals=signals,
        act=score >= ACT_AT,
        report=score >= REPORT_AT,
    )
