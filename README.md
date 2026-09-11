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
Who can be signed in as with a password alone. How many links are open to
anyone holding the address, how old they are, and what stands between them and
the files. Which application passwords have not been used in months and would
still work. Whether anything at all is keeping a record of what happens.

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

## What it stores

Three tables, and nothing else:

* `sentinel_baseline` — one row per file: the path, a hash, the size, who
  approved it and why
* `sentinel_events` — what happened, when, and at what severity; pruned to the
  retention you set
* `sentinel_places` — for each account, the networks it has signed in from, when
  each was first and last seen, and how often

Uninstalling removes all three.

## Licence

AGPL-3.0-or-later.
