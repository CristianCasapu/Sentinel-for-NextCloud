# Changelog

All notable changes to Sentinel are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-09-11

The first release.

### Added

* **Posture report** — eight checks that Nextcloud's own defences do not make:
  two-factor coverage across every account, administrators and whether any of
  them has stopped using the account, public links without a password or an
  expiry and how long they have been open, application passwords nobody has used
  in months, whether anything is keeping a record, what counts as a password, and
  what a new link does by default. Each finding carries the reason it matters and
  what to do about it, because a warning nobody understands is a warning nobody
  acts on.
* **A file baseline you can acknowledge** — records what the installation's files
  look like at a moment you choose and reports only what has moved since, so a
  deliberate patch is approved once and stops raising an alarm. This is the
  answer to the integrity check that fails for ever on any installation that has
  ever been patched, and is therefore read by nobody.
* **Watchers** — an account becoming an administrator, two-factor being switched
  off, an account created or deleted, a link made with no password and no expiry,
  one address working its way through several accounts, and an account signing in
  from a network it has never used before. Recorded at the time, and put in front
  of the administrators when it is worth interrupting them for.
* **An inventory** of every way into the server on one page — links, sessions,
  application passwords and accounts — oldest and most open first, each with an
  expiry, a revoke or a remove beside it.
* **`occ sentinel:check`** — the same report in a terminal, with `--json`,
  `--inventory` and `--quiet-when-clean`, and an exit code a cron entry can act
  on.
* **`occ sentinel:baseline`** — take, compare, accept and forget, so an update
  script can approve its own changes as the last thing it does.
* **A setup check** on the administration overview that says plainly whether
  Sentinel is switched on and whether the files still match, because an installed
  security app that was never set up is worse than none at all.
* **An admin page** where every number the app uses can be changed. Nothing is
  hardcoded anywhere else.
* **Romanian** throughout, including the three plural forms.
* **An uninstall step** that drops all three tables, removes every setting and
  clears pending notifications. Nextcloud does not do this for an app on its own,
  and leaving a list of every file on the server in the database of a server that
  removed the app two years ago would be a poor joke.

[1.0.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases/tag/v1.0.0
