# Changelog

All notable changes to Sentinel are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-09-11

An open link is a decision, not an oversight. This release stops treating it as
one, and spends the attention on things that are actually worth an alarm.

### Changed

* **Public links are no longer a finding.** A link without a password or an
  expiry is the feature working as intended — a folder handed to somebody who
  has no account and is not going to make one — and a page that complains about
  it every week is a page nobody reads. The check now reports how the links are
  being used instead of grading them, and the notice when one is created is off.
  Both readings are still available for installations that want them, under
  **Also treat a link with no password as a finding**.
* The inventory shows each link's opens, downloads, refusals and how many
  networks it was opened from, in place of the red "exposed" marking.

### Added

* **Link usage watching.** Opens, downloads and refused password attempts are
  counted per link per day, along with how many distinct networks opened it.
  Nothing is said until a link is opened from a real crowd *and* that crowd is
  several times the link's own record — a link that has always been busy is
  allowed to be busy. Repeated refused attempts against a link that does have a
  password are reported separately.
* **A watch for files changing very fast.** A sync client on a machine that has
  caught ransomware uploads every encrypted file over the original; nothing was
  breached and every password-and-permission check says the server is fine. The
  rate is the only visible thing. Rewrites, deletes and renames onto a single new
  extension are counted per account in the cache, and the response can be an
  alarm or — if you ask for it — disabling the account and ending its sessions,
  because a sync client holds a token and does not care that its owner has been
  marked disabled.
* **A self-probe.** Sentinel asks its own web server, as an anonymous visitor,
  for the files that must never be served: the configuration with the database
  password in it, the log, `.git` directories left by apps installed from source,
  backup copies of config.php. Every other check reads the code; this is the only
  one that finds out what the web server in front of it actually does.
* **App enable, disable and update are reported.** Enabling an app is arbitrary
  code running as the server with access to everybody's files, and nothing
  anywhere says so out loud. Switching off one of the installation's defences —
  Sentinel included — is an alarm.
* **The settings that decide who the server trusts are watched.** Trusted
  domains, trusted proxies, forwarded-for headers, the overwrite settings, the
  data directory, the app store, session lifetimes and a dozen more. Only a
  fingerprint of each value is stored, never the value.
* **A certificate check**, because renewal is automatic until the day it is not,
  and the certificate simply runs out on a Saturday.
* `occ sentinel:check --probe`.

[1.1.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases/tag/latest

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

[1.0.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases
