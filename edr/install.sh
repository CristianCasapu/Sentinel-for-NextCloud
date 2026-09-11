#!/bin/sh
# SPDX-FileCopyrightText: 2026 Cristian Casapu
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Installs the Sentinel EDR daemon. Run as root, from this directory.
#
# It installs three things and nothing else: the Python package under
# /opt/sentinel-edr, a configuration file under /etc/sentinel-edr, and a systemd
# unit. Removing it is the reverse and is printed at the end.
set -eu

PREFIX=/opt/sentinel-edr
CONFIG=/etc/sentinel-edr
STATE=/var/lib/sentinel-edr

if [ "$(id -u)" != 0 ]; then
	echo "This has to run as root: fanotify needs it, and so does installing a unit." >&2
	exit 1
fi

if ! python3 -c 'import ctypes' 2>/dev/null; then
	echo "python3 with ctypes is required." >&2
	exit 1
fi

echo "Installing the package into $PREFIX"
mkdir -p "$PREFIX"
cp -r sentinel_edr "$PREFIX/"
find "$PREFIX/sentinel_edr" -name '__pycache__' -type d -exec rm -rf {} + 2>/dev/null || true

mkdir -p "$CONFIG" "$STATE/spool"
chmod 755 "$STATE" "$STATE/spool"

if [ -f "$CONFIG/config.ini" ]; then
	echo "Keeping the configuration already at $CONFIG/config.ini"
	echo "(the new example is at $CONFIG/config.example.ini)"
	cp config.example.ini "$CONFIG/config.example.ini"
else
	cp config.example.ini "$CONFIG/config.ini"
	echo "Wrote $CONFIG/config.ini — set data_dir before starting."
fi
chmod 640 "$CONFIG/config.ini"

cp sentinel-edr.service /etc/systemd/system/sentinel-edr.service
systemctl daemon-reload

echo
echo "Installed. Now:"
echo "  1. edit $CONFIG/config.ini — at minimum data_dir"
echo "  2. systemctl enable --now sentinel-edr"
echo "  3. journalctl -u sentinel-edr -f"
echo
echo "To remove it entirely:"
echo "  systemctl disable --now sentinel-edr"
echo "  rm -rf $PREFIX $CONFIG $STATE /etc/systemd/system/sentinel-edr.service"
echo "  systemctl daemon-reload"

# --- what this particular installation needs to be able to write -----------
#
# The unit keeps /usr read-only and /home read-only, which is right for the
# daemon and wrong for the one thing it shells out to: occ writes Nextcloud's
# log, and a Nextcloud installation can be anywhere. Only config.ini knows
# where, so the drop-in is written from it — and rewritten every time this
# script runs, which is why changing data_dir means running it again.
DROPIN=/etc/systemd/system/sentinel-edr.service.d
DATA=$(sed -n 's/^data_dir *= *//p' "$CONFIG/config.ini" | tail -1)
OCC=$(sed -n 's/^occ *= *//p' "$CONFIG/config.ini" | tail -1)

PATHS="$STATE"
[ -n "$DATA" ] && PATHS="$PATHS $DATA"
if [ -n "$OCC" ]; then
	# "php /var/www/nextcloud/occ" -> /var/www/nextcloud
	OCCDIR=$(dirname "$(echo "$OCC" | awk '{print $NF}')")
	[ -d "$OCCDIR" ] && PATHS="$PATHS $OCCDIR"
fi

mkdir -p "$DROPIN"
{
	echo "# Written by install.sh from $CONFIG/config.ini. Re-run install.sh after changing"
	echo "# data_dir or occ in that file."
	echo "[Service]"
	echo "ReadWritePaths=$PATHS"
} > "$DROPIN/paths.conf"
systemctl daemon-reload

echo
echo "Writable for this installation: $PATHS"
