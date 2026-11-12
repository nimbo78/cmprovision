#!/bin/sh
# Snapshot of an installed cmprovision4 for rolling back a package upgrade.
#   sudo cmprovision-backup [--with-uploads] [--keep N|all] [destination-dir]    (installed by the package; tools/backup.sh in a checkout)
#   sudo cmprovision-backup --prune [--keep N|all] [destination-dir]             only removes the old snapshots, makes none
# Default destination: /var/backups/cmprovision/<timestamp>-<installed version>/ containing
#   app.tar.gz           /var/lib/cmprovision (.env, firmware, storage, dnsmasq.conf; uploads only with --with-uploads)
#   database.sqlite      consistent copy of the database (SQLite online backup)
#   system-config.tar.gz files outside the app dir that the package or postinst touches
#   cmprovision4_*.deb   reinstallable copy of the installed package (needs dpkg-repack)
#   packages.txt         versions of the package and its runtime dependencies
# Restore with cmprovision-restore <that directory>.
# Only the newest completed snapshot stays, including in an explicit destination.
# Legacy --keep/CMPROVISION_BACKUP_KEEP values are accepted but capped at one.
# Unfinished snapshots, links and unrelated files/directories are never removed.
set -e
TIME_BACKUP=/usr/lib/cmprovision/time-backup.sh
[ -r "$TIME_BACKUP" ] || TIME_BACKUP=$(dirname "$0")/time-backup.sh
if [ -r "$TIME_BACKUP" ]; then . "$TIME_BACKUP"; fi
APP=/var/lib/cmprovision
DEFAULT_DEST=/var/backups/cmprovision
WITH_UPLOADS=0
PRUNE=0
KEEP=${CMPROVISION_BACKUP_KEEP:-1}
while [ $# -gt 0 ]; do
    case "$1" in
        --with-uploads) WITH_UPLOADS=1 ;;
        --prune) PRUNE=1 ;;
        --keep)
            [ $# -ge 2 ] || { echo "--keep needs a number or 'all'" >&2; exit 2; }
            KEEP=$2
            shift ;;
        --keep=*) KEEP=${1#--keep=} ;;
        --) shift; break ;;
        -*) echo "unknown option $1" >&2; exit 2 ;;
        *) break ;;
    esac
    shift
done
case "$KEEP" in
    all) ;;
    ''|*[!0-9]*|0|0[0-9]*) echo "the number of snapshots to keep is 1 or more, or 'all'" >&2; exit 2 ;;
esac
# Existing callers may still pass a larger value or "all"; neither can raise the cap.
KEEP=1
DEST=${1:-$DEFAULT_DEST}

# A completed snapshot has a regular database copy; other operator paths are not ours.
completed_snapshot() {
    [ -d "$1" ] && [ ! -L "$1" ] && [ ! -e "$1/.incomplete" ] && [ ! -L "$1/.incomplete" ] &&
        [ -f "$1/database.sqlite" ] && [ ! -L "$1/database.sqlite" ]
}

prune() {
    removed=0
    # Full names exclude whitespace and shell patterns before iterating shell words.
    names=$(ls -1 "$DEST" 2>/dev/null | grep -E '^[0-9]{8}-[0-9]{6}-[A-Za-z0-9][A-Za-z0-9.+:~_-]*$' | sort -r)
    keeper=''
    for name in $names; do
        path="$DEST/$name"
        completed_snapshot "$path" || continue
        [ -n "$keeper" ] || keeper=$path
        # Prefer the just-completed replacement even when the clock moved backwards.
        if [ "$path" = "${OUT:-}" ]; then keeper=$path; break; fi
    done
    for name in $names; do
        path="$DEST/$name"
        completed_snapshot "$path" || continue
        [ "$path" != "$keeper" ] || continue
        # Restore holds a shared lock on its source across nested package hooks.
        if (exec 7< "$path"; flock -xn 7 || exit 75; rm -rf "$path"); then
            removed=$((removed + 1))
        else
            case $? in 75) ;; *) echo "could not remove $path" >&2 ;; esac
        fi
    done
    if [ "$removed" != 0 ]; then
        echo "removed $removed older snapshot(s) from $DEST, kept the newest $KEEP"
    fi
}

# Serialize creation and pruning for this destination without creating a lock file.
# An absent destination needs no work for --prune; explicit symlink destinations are refused.
if [ "$PRUNE" = 1 ] && [ ! -e "$DEST" ] && [ ! -L "$DEST" ]; then exit 0; fi
if [ "$PRUNE" = 0 ]; then
    [ "$(id -u)" = 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
fi
umask 077
[ ! -L "$DEST" ] || { echo "snapshot destination must be a directory, not a link" >&2; exit 1; }
mkdir -p -m 700 "$DEST"
exec 8< "$DEST"
flock -x 8

if [ "$PRUNE" = 1 ]; then
    prune
    exit 0
fi

VER=$(dpkg-query -W -f='${Version}' cmprovision4 2>/dev/null || echo unknown)
OUT="$DEST/$(date +%Y%m%d-%H%M%S)-$VER"
# A snapshot of an unconfirmed network move could combine the old and new interfaces.
if [ -e /run/cmprovision-network/pending ] || [ -e /var/lib/cmprovision-network/transactions/pending.json ]; then
    echo "Confirm or recover the pending network change before making a backup." >&2; exit 1
fi
# The snapshot holds .env (APP_KEY), password hashes, printer and SNMP credentials: root-only.
mkdir -m 700 "$OUT"
: > "$OUT/.incomplete"

# The web user makes the copy, in a directory of its own: the database is in WAL, and a root sqlite3 would open the
# -wal and -shm files in the web user's directory, where a planted symlink would have root write to or chown any file.
# Root only writes its snapshot from what the web user's cat reads. .timeout: wait for a writer instead of failing
TMPDB=$(runuser -u www-data -- mktemp -d /var/tmp/cmprovision-backup.XXXXXX)
runuser -u www-data -- sqlite3 -cmd '.timeout 10000' "$APP/database/database.sqlite" ".backup '$TMPDB/database.sqlite'"
runuser -u www-data -- cat "$TMPDB/database.sqlite" > "$OUT/database.sqlite"
runuser -u www-data -- rm -rf "$TMPDB"

EXCLUDE="--exclude=$APP/public/uploads"
[ "$WITH_UPLOADS" = 1 ] && EXCLUDE=""
# The database comes from the consistent copy above. A log or cache file that changes while tar
# reads it is still archived; tar only warns about it and exits 1.
# shellcheck disable=SC2086
tar czf "$OUT/app.tar.gz" --warning=no-file-changed --exclude="$APP/database/database.sqlite*" \
    --exclude="$APP/storage/framework/configuration-transfer" $EXCLUDE "$APP" || [ $? = 1 ]

# Serialize the network part with a new network change. This lock is held only for the small
# system configuration archive, not while copying large uploaded images or the database.
NETWORK_LOCK=/run/cmprovision-network/provisioning.lock
NETWORK_LOCKED=0
if [ -x /usr/lib/cmprovision/network-transaction ] || [ -e "$NETWORK_LOCK" ]; then
    [ -f "$NETWORK_LOCK" ] && [ ! -L "$NETWORK_LOCK" ] || { echo "The network guard is unavailable." >&2; exit 1; }
    exec 9< "$NETWORK_LOCK"
    flock -w 10 9 || { echo "The network is changing; try the backup again." >&2; exit 1; }
    NETWORK_LOCKED=1
fi
if [ -e /run/cmprovision-network/pending ] || [ -e /var/lib/cmprovision-network/transactions/pending.json ]; then
    echo "Confirm or recover the pending network change before making a backup." >&2; exit 1
fi
if command -v cmprovision_time_snapshot >/dev/null 2>&1; then cmprovision_time_snapshot "$OUT"; fi
tar czf "$OUT/system-config.tar.gz" --ignore-failed-read \
    /etc/nginx/sites-available/cmprovision /etc/php/*/fpm/php.ini \
    /etc/sudoers.d/010_cmprovision /etc/dhcpcd.conf /etc/NetworkManager/system-connections \
    /var/lib/cmprovision-network/vlans \
    /etc/systemd/system/cmprovision-dnsmasq.service /etc/systemd/system/cmprovision-rpiboot.service \
    /etc/systemd/system/multi-user.target.wants/cmprovision-dnsmasq.service \
    /etc/systemd/system/multi-user.target.wants/cmprovision-rpiboot.service \
    /lib/systemd/system/cmprovision-*.service /lib/systemd/system/cmprovision-*.timer \
    /etc/default/cmprovision-uplink /etc/default/cmprovision-wallboard.json /etc/default/cmprovision-fpms.json \
    /etc/localtime /etc/timezone /etc/systemd/timesyncd.conf.d/90-cmprovision.conf \
    /etc/default/cmprovision-stand-interface 2>/dev/null || true
# The absence of a mask or WantedBy link is part of the saved role too.
printf '%s\n' 1 > "$OUT/boot-services.saved"
if [ "$NETWORK_LOCKED" = 1 ]; then flock -u 9; exec 9<&-; fi

if command -v dpkg-repack >/dev/null 2>&1; then
    (cd "$OUT" && dpkg-repack cmprovision4 >/dev/null 2>&1) || echo "dpkg-repack failed; restore will keep the installed package" >&2
else
    echo "dpkg-repack not installed: no reinstallable copy of the package in this backup" >&2
fi
dpkg -l cmprovision4 nginx 'php*-fpm' dnsmasq-base rpiboot 2>/dev/null | grep '^ii' > "$OUT/packages.txt" || true

rm -f "$OUT/.incomplete"
du -sh "$OUT" | awk '{print "backup written to '"$OUT"' (" $1 ")"}'
prune
