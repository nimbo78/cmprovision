#!/bin/sh
# Snapshot of an installed cmprovision4 for rolling back a package upgrade.
#   sudo tools/backup.sh [--with-uploads] [destination-dir]
# Default destination: /var/backups/cmprovision/<timestamp>-<installed version>/ containing
#   app.tar.gz           /var/lib/cmprovision (database, .env, firmware, storage, dnsmasq.conf; uploads only with --with-uploads)
#   database.sqlite      consistent copy of the database (SQLite online backup)
#   system-config.tar.gz files outside the app dir that the package or postinst touches
#   cmprovision4_*.deb   reinstallable copy of the installed package (needs dpkg-repack)
#   packages.txt         versions of the package and its runtime dependencies
# Restore with tools/restore.sh <that directory>.
set -e
APP=/var/lib/cmprovision
WITH_UPLOADS=0
[ "$1" = "--with-uploads" ] && { WITH_UPLOADS=1; shift; }
DEST=${1:-/var/backups/cmprovision}
VER=$(dpkg-query -W -f='${Version}' cmprovision4 2>/dev/null || echo unknown)
OUT="$DEST/$(date +%Y%m%d-%H%M%S)-$VER"
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
# The snapshot holds .env (APP_KEY), password hashes, printer and SNMP credentials: root-only.
umask 077
mkdir -p -m 700 "$DEST"
mkdir -m 700 "$OUT"

sqlite3 "$APP/database/database.sqlite" ".backup '$OUT/database.sqlite'"

EXCLUDE="--exclude=$APP/public/uploads"
[ "$WITH_UPLOADS" = 1 ] && EXCLUDE=""
# shellcheck disable=SC2086
tar czf "$OUT/app.tar.gz" $EXCLUDE "$APP"

tar czf "$OUT/system-config.tar.gz" --ignore-failed-read \
    /etc/nginx/sites-available/cmprovision /etc/php/*/fpm/php.ini \
    /etc/sudoers.d/010_cmprovision /etc/dhcpcd.conf /etc/NetworkManager/system-connections \
    /lib/systemd/system/cmprovision-*.service 2>/dev/null || true

if command -v dpkg-repack >/dev/null 2>&1; then
    (cd "$OUT" && dpkg-repack cmprovision4 >/dev/null 2>&1) || echo "dpkg-repack failed; restore will keep the installed package" >&2
else
    echo "dpkg-repack not installed: no reinstallable copy of the package in this backup" >&2
fi
dpkg -l cmprovision4 nginx 'php*-fpm' dnsmasq-base rpiboot 2>/dev/null | grep '^ii' > "$OUT/packages.txt" || true

du -sh "$OUT" | awk '{print "backup written to '"$OUT"' (" $1 ")"}'
