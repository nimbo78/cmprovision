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
# Only the newest 5 snapshots stay (--keep N, or CMPROVISION_BACKUP_KEEP; "all" keeps everything): after a snapshot in the
# default destination the older <date>-<time>-<version> directories are removed. A destination given by hand is trimmed only
# by --prune; other files and directories in it are never touched.
set -e
APP=/var/lib/cmprovision
DEFAULT_DEST=/var/backups/cmprovision
WITH_UPLOADS=0
PRUNE=0
KEEP=${CMPROVISION_BACKUP_KEEP:-5}
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
DEST=${1:-$DEFAULT_DEST}

# The snapshots of this script are the directories named <date>-<time>-<version>; the names sort by time.
prune() {
    if [ "$KEEP" = all ]; then
        return 0
    fi
    removed=0
    seen=0
    for name in $(ls -1 "$DEST" 2>/dev/null | grep -E '^[0-9]{8}-[0-9]{6}-' | sort -r); do
        path="$DEST/$name"
        # a file or a link with such a name is not a snapshot and does not take a place among the newest
        if [ ! -d "$path" ] || [ -L "$path" ]; then
            continue
        fi
        seen=$((seen + 1))
        if [ "$seen" -le "$KEEP" ] || [ "$path" = "${OUT:-}" ]; then
            continue
        fi
        if rm -rf "$path"; then
            removed=$((removed + 1))
        else
            echo "could not remove $path" >&2
        fi
    done
    if [ "$removed" != 0 ]; then
        echo "removed $removed older snapshot(s) from $DEST, kept the newest $KEEP"
    fi
}

if [ "$PRUNE" = 1 ]; then
    prune
    exit 0
fi

VER=$(dpkg-query -W -f='${Version}' cmprovision4 2>/dev/null || echo unknown)
OUT="$DEST/$(date +%Y%m%d-%H%M%S)-$VER"
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
# The snapshot holds .env (APP_KEY), password hashes, printer and SNMP credentials: root-only.
umask 077
mkdir -p -m 700 "$DEST"
mkdir -m 700 "$OUT"

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
tar czf "$OUT/app.tar.gz" --warning=no-file-changed --exclude="$APP/database/database.sqlite*" $EXCLUDE "$APP" || [ $? = 1 ]

tar czf "$OUT/system-config.tar.gz" --ignore-failed-read \
    /etc/nginx/sites-available/cmprovision /etc/php/*/fpm/php.ini \
    /etc/sudoers.d/010_cmprovision /etc/dhcpcd.conf /etc/NetworkManager/system-connections \
    /lib/systemd/system/cmprovision-*.service /lib/systemd/system/cmprovision-*.timer \
    /etc/default/cmprovision-uplink /etc/default/cmprovision-wallboard.json 2>/dev/null || true

if command -v dpkg-repack >/dev/null 2>&1; then
    (cd "$OUT" && dpkg-repack cmprovision4 >/dev/null 2>&1) || echo "dpkg-repack failed; restore will keep the installed package" >&2
else
    echo "dpkg-repack not installed: no reinstallable copy of the package in this backup" >&2
fi
dpkg -l cmprovision4 nginx 'php*-fpm' dnsmasq-base rpiboot 2>/dev/null | grep '^ii' > "$OUT/packages.txt" || true

if [ "$DEST" = "$DEFAULT_DEST" ]; then
    prune
fi

du -sh "$OUT" | awk '{print "backup written to '"$OUT"' (" $1 ")"}'
