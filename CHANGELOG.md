# Changelog

All notable changes to Sentinel are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] — 2026-09-12

Speed stops being an accusation, and something outside Nextcloud finally answers
the question Nextcloud cannot: *which program is doing this?*

### Changed

* **The fast-files watch no longer acts on rate alone.** It was too eager, and
  it was right to be complained about: a phone finishing its first backup and a
  folder being encrypted are identical if all you count is files per minute.
  Crossing the rate now only opens the question. What answers it is the files
  themselves — a run of files that are no longer the kind of file their own name
  claims, a ransom note, a wave of renames onto one new extension. Rate with no
  evidence is a note in the journal, once a day, and nothing else happens.
* Files are only opened once an account is halfway to the threshold, and then
  only one in every few, so an ordinary day costs nothing.
* Nextcloud's own server-side encryption is recognised and never counts as
  evidence against anybody.

### Added

* **Sentinel EDR** (`edr/`) — a small Python daemon, one systemd unit, that uses
  fanotify to attach a process to every completed write in the data directory.
  That is the difference between a sync client uploading what ransomware did on
  somebody's laptop and something on this server writing into the files itself.
  It scores several independent signals and acts only at five points, where rate
  is worth one; see `edr/README.md` for the table and the reasoning.
  * It **suspends** rather than kills — frozen where it stood, nothing lost, a
    person decides — and it **never touches php-fpm**, because stopping that
    stops Nextcloud for everybody.
  * When the writing came through the web stack there is no process worth
    stopping, so it asks Nextcloud to end that account's sessions, through `occ`,
    having dropped to the account that owns the installation. Deliberately not
    `sudo`.
  * If that call fails, the request travels in the report and Nextcloud carries
    it out itself. A response that silently did not happen is worse than one
    that was never designed.
  * It talks to Nextcloud through a directory of JSON files. No socket, no port,
    no credential stored anywhere.
* **ClamAV**, asked only about files that already look wrong for another reason,
  and about the binary of any program writing where it should not be. Through
  clamd's socket, not clamscan.
* Two new posture checks: **which program is writing** (installed? alive? — a
  watcher that has stopped looks exactly like a quiet day) and **a scanner to
  ask** (reachable? and are its signatures fresh — a scanner on last year's
  signatures answers "clean" with the same confidence either way).
* `occ sentinel:respond --uid <account> [--disable]`.

[1.3.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases/tag/latest

## [1.2.1] — 2026-09-11

### Fixed

* **The app page could not be opened at all.** `/apps/sentinel/` refused every
  visit with "CSRF check failed", because the page route did not declare
  `NoCSRFRequired` and a browser following a link carries no request token.
  The admin page was unaffected, which is how it went unnoticed: the page was
  only ever tested without a session (a 401, which hid the real answer) and
  through the API with an application password, where the CSRF check does not
  apply. Every route is still administrator-only, and a non-administrator is
  still refused.
* **Two-factor authentication being switched off was reported when it was not.**
  Nextcloud's two-factor events do not mean what their names suggest:
  `TwoFactorProviderForUserDisabled` fires when somebody types a wrong code, and
  `TwoFactorProviderForUserUnregistered` fires during an ordinary sign-in
  whenever the registry tidies up a provider that is no longer installed. Both
  were being reported as an alarm. They are no longer listened to; a second
  factor actually going away is now found by comparing state in the background
  job, which is duller, correct, and also catches it being removed with occ or
  straight out of the database.

### Added

* **Repeated wrong second factors are now an alarm.** Eight in a quarter of an
  hour means the password has already been accepted eight times and only the
  second factor is in the way — which is worth knowing long before whoever has
  it finds a way past.

[1.2.1]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases

## [1.2.0] — 2026-09-11

The admin page stops being a settings form and becomes the whole app, and
findings stop waiting for somebody to come and look at them.

### Added

* **An overview**, and the admin page now carries all of it. Administration →
  Sentinel has the overview, the findings, the file baseline, the inventory, the
  journal and the settings — the same console as the full-page app. The two
  questions an administrator has arrive together (is anything wrong, and what is
  watching for it) and answering them on two screens is how one of them stops
  being asked.
* The overview says, on one screen: the state of the server, six figures worth
  knowing, **what is being watched and what is not** — plainly, because the most
  expensive mistake with an app like this is assuming it watches something it was
  never told to watch — what has happened lately, and whether any of it would
  actually reach a person.
* **Email.** Anything serious goes to every administrator who has an address and
  to any address added by hand, which is usually the one that reaches a phone
  rather than a mailbox on this same server — which may be the thing that is
  down. An administrator who is not signed in does not have a bell.
* **A daily summary** at an hour you choose, sent even when there is nothing to
  say: the morning it stops arriving is the morning to go and look at why.
* **A test message button**, so that "mail is configured" can be replaced by
  "mail arrived".

### Changed

* The bell settings moved into a **Being told** group at the top of the settings,
  next to the mail settings, since they answer the same question.

[1.2.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases

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

[1.1.0]: https://github.com/CristianCasapu/Sentinel-for-NextCloud/releases

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
