# Sentinel EDR

The half of the watching Nextcloud cannot do from inside itself.

Nextcloud sees files changing and knows whose account they belong to. It cannot
see which *process* changed them, and that is the difference between two things
that look identical from in there:

* a sync client faithfully uploading what ransomware did on somebody's laptop —
  which arrives as ordinary authenticated requests, no password wrong, no
  permission exceeded; and
* something on this server writing straight into the data directory, which is a
  compromise of the machine itself.

This daemon answers that, using fanotify, which is the only interface on Linux
that hands you the process identifier along with the write.

## The rule it is built around

**Rate never decides anything.**

Five hundred files in five minutes is a phone finishing its first backup at
least as often as it is an encryptor working through a folder. A daemon allowed
to freeze processes must not be able to act on that alone, so files-per-minute
is worth **one point** out of the **five** it takes to act.

The points that actually decide:

| Signal | Points | Why it can be trusted |
|---|---|---|
| Contents no longer match the name | 4, or 6 when overwhelming | No legitimate client rewrites a `.jpg` so that it stops being a JPEG. Ransomware does it to every file it touches, because it encrypts the contents and keeps the name. |
| A ransom note appears | 4 | Nothing else writes `HOW_TO_DECRYPT_FILES.txt` into somebody's photos. |
| A program writing that has no business here | 3 | On a Nextcloud server the legitimate writers are few and known. |
| A scanner recognises it | 5 | ClamAV on the written file, or on the binary that wrote it. |
| Rate | 1 | Enough to open the question. Never enough to answer it. |

The "contents versus name" check is also measured over the **most recent
handful** of files, not only the window average — otherwise somebody who uploads
eight hundred holiday photos and is attacked a minute later is invisible
precisely because the account was busy.

Nextcloud's own server-side encryption is recognised and never counts as
evidence.

## What it does when it decides

**It suspends, it does not kill.** A frozen process stands exactly where it was,
holding everything it held, and a person decides what happens next. Killing an
encryptor mid-file and killing a backup script mid-file look the same from here,
and only one of those is a mistake you can undo.

```
kill -CONT <pid>   # let it go
kill -KILL <pid>   # end it
```

**It never touches php-fpm.** On a Nextcloud server php-fpm is what writes
everybody's files all day. Stopping it stops Nextcloud for every account on the
machine, which is a more reliable way to lose a day than most of what this is
watching for. When the writing is coming through the web stack there is no
process worth stopping — the thing to stop is the *session*, and that is
Nextcloud's to do. The daemon asks, through `occ`, having first dropped to the
account that owns the installation. Dropping privilege, never taking it: this is
deliberately not `sudo`, which cannot work under `NoNewPrivileges` and would need
a sudoers rule that is itself a way in.

If that call fails for any reason, the request travels in the report and
Nextcloud carries it out itself when it next reads the spool. A response that
silently did not happen is worse than one that was never designed.

## Installing

```
sudo sh install.sh
sudoedit /etc/sentinel-edr/config.ini    # at minimum data_dir
sudo sh install.sh                       # again, to grant the paths it needs
sudo systemctl enable --now sentinel-edr
journalctl -u sentinel-edr -f
```

Running it twice is not a mistake: the second run reads `data_dir` and `occ` out
of the configuration and writes the systemd drop-in that lets `occ` write where
it has to. Re-run it after changing either.

Requirements: Linux 4.20 or newer for a filesystem mark (it falls back to a
mount mark), Python 3.9 or newer, and root — fanotify needs it and nothing else
here does.

## How it talks to Nextcloud

Through a directory. The daemon writes a JSON file into
`/var/lib/sentinel-edr/spool`; Nextcloud reads it and remembers how far it got.
No socket, no port, no credential stored anywhere, nothing that has to be kept
secret, and nothing that breaks when either side is restarted. The web server
can read that directory and cannot write to it; the daemon writes and never
reads anything the web server produced. Neither can make the other do anything.

A heartbeat lands in the same place every minute, which is how Sentinel's
posture page knows the difference between "nothing is happening" and "nothing is
watching" — the two states that look identical from every other angle.

## What it costs

One `FAN_CLOSE_WRITE` event per finished file on the filesystem, almost all of
which are discarded in the first few lines: anything not under
`<data>/<account>/files/` leaves immediately. Files are only *opened* once an
account's rate is halfway to the threshold, and then only one in every few. On
an ordinary day it reads nothing at all.

## Removing it

```
sudo systemctl disable --now sentinel-edr
sudo rm -rf /opt/sentinel-edr /etc/sentinel-edr /var/lib/sentinel-edr \
            /etc/systemd/system/sentinel-edr.service \
            /etc/systemd/system/sentinel-edr.service.d
sudo systemctl daemon-reload
```

## Licence

AGPL-3.0-or-later, the same as Sentinel.
