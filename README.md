# Sentinel for Nextcloud

Nextcloud defends itself well. This watches the things it does not watch.

Password guessing is already throttled. Passwords are already hashed properly.
The headers are already right, two-factor authentication is already offered, and
the installation already checks its own files against the signatures it shipped
with. Sentinel repeats none of that.

What it looks at instead is the state that accumulates quietly while a server is
used — the account that never turned two-factor on, the link shared for an
afternoon three years ago that is still open, the application password last used
in February that would still work today. None of those is a vulnerability. Each
is a door that was opened for a reason and never closed, and together they are
how most installations are actually lost.

## What it does

**Answers the questions nobody asks until it is too late.** One page, worst
first, with the reason beside each finding and what to do about it underneath.
Who can be signed in as with a password alone. Which application passwords have
not been used in months and would still work. How long the certificate has left.
Whether anything at all is keeping a record of what happens.

**Solves the permanently-failing integrity check.** Nextcloud verifies its own
files against the signatures it shipped with, which is the right thing to do —
until the installation is patched once, for any reason at all, and then it fails
for ever. A check that always fails is a check nobody reads, and the day
something is genuinely wrong it arrives as one more line in a warning that has
been ignored for a year. Sentinel records what the files look like at a moment
you choose, once you have decided that moment is correct, and from then on
reports only what has moved since. A legitimate patch is approved once, with a
note saying why, and stops being an alarm. An update is approved once. What
remains is a short list of files that changed when nobody changed them, which is
the only list worth looking at.

**Says something when it happens, not at the next audit.** An account becoming
an administrator. Two-factor authentication being switched off. A new account
appearing. One address working its way through several accounts — the thing
throttling alone does not make visible, because each account only sees a couple
of tries. An administrator signing in from a network the account has never used
before, with nothing but a password protecting it. Each of those is perfectly
ordinary when it was you who did it, and the last step of an intrusion when it
was not; the only way to tell them apart is to be told at the time.

**Asks the web server what it actually hands out.** Every other check on this
page is a statement about what the code intends. This one is an ordinary
anonymous visitor requesting the files that must never be served — the
configuration with the database password in it, the log, the `.git` directory
that any app installed from source leaves in the web root — and reporting
anything that comes back. None of those leaks are Nextcloud's doing. They come
from a rewrite rule changed during a debugging session, a virtual host copied
from another site, an `AllowOverride None` that quietly stopped the shipped
`.htaccess` from being read at all. The code is identical in every one of those
cases, and so is every check that only reads the code.

**Watches for the one thing that can destroy everything without a single
password being wrong.** A laptop with a sync client catches ransomware; the
ransomware encrypts the synced folder; the client does exactly what it is built
to do and uploads every encrypted file over the original. Nothing was breached,
no permission was exceeded, and every check that asks about passwords says the
server is fine. The only visible thing is the rate — hundreds of files rewritten
in minutes, which no person does by hand. Sentinel counts that, and can be told
to disable the account and end its sessions by itself. That last part is off
until you ask for it.

**Watches links rather than lecturing about them.** A public link without a
password is not a mistake. It is the most useful thing Nextcloud does — a folder
handed to somebody who has no account and is not going to make one — and a
security page that complains about it every week is a page nobody reads. So
Sentinel counts instead: how many times each link was opened, from how many
different networks, how many attempts at a protected one were refused. A link
sent to two people and opened from forty networks in an afternoon is the same
link doing something entirely different, and each link is measured against what
that link normally does, so a busy link is allowed to be busy.

**Notices code arriving.** Enabling an app is the most consequential thing
anybody can do to a Nextcloud installation: arbitrary code, running as the
server, with access to everybody's files. It is also completely silent. So is
switching one off, which is the first move against a server that is being
watched.

**Notices the settings that decide who this server trusts.** A changed trusted
domain sends password resets somewhere else. A changed trusted proxy makes the
server believe whatever an attacker puts in a header, including which address a
request came from — which is exactly what the brute-force protection counts.
Both are one line in a file, and neither announces itself. Only a fingerprint of
each value is stored, never the value: keeping a second copy of the settings
that matter most would be an odd way to protect them.

**Puts every way in on one page, with the means to close it.** Nextcloud hands
out access in four places and shows them together nowhere: shares in Files,
application passwords and sessions in each person's own settings, group
membership in administration. Reviewing them means four pages per account, which
is why nobody ever does. Here they are in one list, oldest and most open first,
each with an expiry, a revoke or a remove beside it.

**Says nothing twice.** A page that repeats the same standing situation every
fifteen minutes is a page people learn to ignore, so a finding is recorded once
and then left alone until it has been quiet for a while, or until it gets worse.
The journal is pruned on a schedule; a record nobody will ever read is only a
liability.

## What it does not do

It changes nothing on its own. It reports, and it acts only when asked — the
expiry you set, the token you revoke, the baseline you approve. There is no
automatic remediation, no blocking, no quarantine, and nothing that can lock you
out of your own server because a heuristic had an opinion at three in the
morning.

It has no opinion about how you share. It will not tell you that a link should
have a password, that a link should expire, or that a folder should not be
public — those are decisions, they are usually right, and a security page that
second-guesses them is a security page that gets ignored. (There is a switch for
anyone who does want that reading. It is off.)

It does not collect anything about visitors. Sign-ins are remembered as the
*network* an account uses, not the address — a home connection changes address
regularly and a phone changes it constantly, so exact addresses would make every
ordinary Tuesday look like an intrusion. Nothing is sent anywhere. There is no
telemetry, no cloud service, and no account to create.

It is not an antivirus and does not read anyone's files. The baseline covers the
code of the installation — `lib`, `core`, `apps`, `3rdparty`, `config`,
`themes` — and never the users' data.

## Installing

Copy the directory into `apps/` and enable it:

```
occ app:enable sentinel
```

That is the whole installation. It has no dependencies beyond Nextcloud itself,
runs entirely on what is already there, and needs nothing configured before it
works.

Then, once — after you have satisfied yourself that the installation is in the
state it ought to be in:

```
occ sentinel:baseline take
```

From that moment on, only a change since then is reported.

## Requirements

* Nextcloud 34
* PHP 8.4 or newer

Nothing older, on purpose. The code is written for the language as it is now,
not for the language as it was, and there is no compatibility layer to go wrong
quietly. An installation that cannot run PHP 8.4 has a more pressing security
problem than anything this app would tell it about.

## Using it from the command line

The same report the page shows, for people who live in a terminal and for cron
entries that email the result:

```
occ sentinel:check                     # the report
occ sentinel:check --inventory         # and every open link, session and token
occ sentinel:check --json              # for something else to read
occ sentinel:check --quiet-when-clean  # prints nothing unless it matters
occ sentinel:check --probe             # ask the web server first what it serves
```

The exit code is nonzero when something needs attention, so a weekly cron entry
that mails only on failure is one line.

The baseline lives here too, which is where it belongs: an update script can
approve its own changes as the last thing it does, so that the next unexplained
change is the only one that raises an alarm.

```
occ sentinel:baseline                  # what is recorded and whether it still matches
occ sentinel:baseline compare          # walk the installation now
occ sentinel:baseline accept --all --note "Nextcloud 34.0.4"
occ sentinel:baseline take             # start again from what is on disk now
occ sentinel:baseline forget           # throw it away entirely
```

Walking a full installation — around twenty thousand files — takes about six
seconds cold and under a second warm. The background job does it at most once an
hour regardless of how often it runs.

## Configuring it

Everything is on the admin page, under **Administration → Sentinel**, and
nothing is hardcoded anywhere else. What counts as a stale application password
on a machine used by one person is not what counts on one used by forty; how
precise a "network" should be depends on the connections people actually have;
whether a finding is worth interrupting somebody for depends on who is being
interrupted. Those are judgements about a particular server, and the person
running it is better placed to make them.

| | |
|---|---|
| **Watching** | Whether to watch at all, how often, who is told, from which severity, and how long the journal is kept |
| **What counts as too old** | Stale application passwords, idle sessions, dormant administrators, links with no expiry |
| **The files** | Which parts of the installation the baseline covers, which file kinds, what to skip, the size limit, and the hash |
| **Where people sign in from** | Whether to remember networks at all, whether to say anything about new ones, how precise a network is, and which accounts to ignore |
| **Public links** | Whether to watch how links are used, how big a crowd is worth mentioning, how far above a link's own record it has to be, and how many refused password attempts count as guessing |
| **Files changing very fast** | Whether to watch, over what window, how many rewrites, deletes or renames-to-one-extension, and whether to raise an alarm or disable the account outright |
| **Asking the server what it serves** | Whether to probe, any extra paths particular to this installation, and how many days' warning the certificate gets |

## What it stores

Three tables, and nothing else:

* `sentinel_baseline` — one row per file: the path, a hash, the size, who
  approved it and why
* `sentinel_events` — what happened, when, and at what severity; pruned to the
  retention you set
* `sentinel_places` — for each account, the networks it has signed in from, when
  each was first and last seen, and how often
* `sentinel_link_use` — one row per public link per day: how many times it was
  opened, downloaded and refused, and from how many networks

Uninstalling removes all four.

## Licence

AGPL-3.0-or-later.
