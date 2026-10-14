#!/bin/sh
# Roll an installation back to a snapshot made by cmprovision-backup:
#   sudo cmprovision-restore /var/backups/cmprovision/<snapshot>      (installed by the package; tools/restore.sh in a checkout)
# Reinstalls the packaged version from the snapshot (if it holds a .deb), then puts back the
# application directory, the database and the system config, and restarts the services.
# A snapshot without a package (taken without dpkg-repack) is refused while another version is
# installed: its files would land on top of that version. --force restores it anyway.
#
# Moving to a new system (another Debian release, PHP version or distribution):
#   sudo cmprovision-restore --data-only <snapshot>
# Keeps the installed package and the system config (nginx site, php.ini, network) and puts back
# only the data: the database, .env (APP_KEY), the firmware store, the uploaded images (when the
# snapshot was made --with-uploads) and the dnsmasq config. Install cmprovision4 first.
set -e
DATA_ONLY=0
FORCE=0
while :; do
    case "${1:-}" in
        --data-only) DATA_ONLY=1; shift ;;
        --force) FORCE=1; shift ;;
        *) break ;;
    esac
done
SRC=${1:?usage: restore.sh [--data-only] [--force] <snapshot directory>}
APP=/var/lib/cmprovision
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
[ -f "$SRC/app.tar.gz" ] || { echo "$SRC: not a backup directory" >&2; exit 1; }
# the version is the end of the directory name cmprovision-backup gives: <date>-<time>-<version>
SNAPVER=$(basename "$SRC" | sed -n 's/^[0-9]\{8\}-[0-9]\{6\}-//p')
INSTALLED=$(dpkg-query -W -f='${Version}' cmprovision4 2>/dev/null || true)
if [ "$DATA_ONLY" = 0 ] && [ "$FORCE" = 0 ] && [ -n "$SNAPVER" ] && [ "$SNAPVER" != "$INSTALLED" ] \
        && ! ls "$SRC"/cmprovision4_*.deb > /dev/null 2>&1; then
    echo "$SRC holds no package of $SNAPVER, and ${INSTALLED:-no cmprovision4} is installed: its files would land on top of it." >&2
    echo "Install cmprovision4 $SNAPVER first (apt install --allow-downgrades ./cmprovision4_${SNAPVER}_all.deb), then run this again." >&2
    echo "--data-only puts back only the data; --force restores the snapshot anyway." >&2
    exit 1
fi
if [ "$DATA_ONLY" = 1 ] && ! dpkg-query -W -f='${Status}' cmprovision4 2>/dev/null | grep -q "ok installed"; then
    echo "--data-only: install cmprovision4 first" >&2; exit 1
fi

systemctl stop cmprovision-queue cmprovision-dnsmasq 2>/dev/null || true

if [ "$DATA_ONLY" = 1 ]; then
    echo "restoring the data into $APP"
    # only files: tar creates their directories, and the ownership is set below
    LIST=$(mktemp)
    tar tzf "$SRC/app.tar.gz" \
        | grep -E "^${APP#/}/(\.env|etc/dnsmasq\.conf|storage/app/.+[^/]|public/uploads/.+[^/])$" > "$LIST" || true
    grep -q "/\.env$" "$LIST" || { echo "$SRC/app.tar.gz holds no .env: not a cmprovision snapshot" >&2; rm -f "$LIST"; exit 1; }
    tar xzf "$SRC/app.tar.gz" -C / -T "$LIST"
    echo "  $(wc -l < "$LIST") files"
    rm -f "$LIST"
else
    DEB=$(ls "$SRC"/cmprovision4_*.deb 2>/dev/null | head -1)
    if [ -n "$DEB" ]; then
        echo "reinstalling $DEB"
        apt-get install -y --allow-downgrades "$DEB" || dpkg -i "$DEB"
    fi

    echo "restoring $APP"
    tar xzf "$SRC/app.tar.gz" -C /
fi
if [ -f "$SRC/database.sqlite" ]; then
    # a journal left from the replaced database would be rolled into the restored one
    rm -f "$APP/database/database.sqlite-journal" "$APP/database/database.sqlite-wal" "$APP/database/database.sqlite-shm"
    install -o www-data -g www-data -m 600 "$SRC/database.sqlite" "$APP/database/database.sqlite"
fi
# Ownership as debian/postinst sets it, before artisan writes as the web user: code root's, the web server writes only to its own places
find "$APP" -xdev \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/public/uploads" \
    -o -path "$APP/etc" -o -path "$APP/.env" -o -path "$APP/database/database.sqlite*" \) -prune \
    -o -user www-data -exec chown -h root:root {} +
# -h: never follow a symlink the web user may have planted in its own places
chown -R -h www-data:www-data "$APP/storage" "$APP/bootstrap/cache" "$APP/public/uploads" "$APP/etc"
chown -h www-data:www-data "$APP/database"
for f in "$APP/.env" "$APP"/database/database.sqlite*; do
    if [ -f "$f" ] && [ ! -L "$f" ]; then chown -h www-data:www-data "$f"; fi
done
# artisan runs as the web user: the database is in WAL, and -wal and -shm files root created would lock it out
if [ "$DATA_ONLY" = 1 ]; then
    # the snapshot may come from an older version
    runuser -u www-data -- "$APP/artisan" migrate --force
else
    tar xzf "$SRC/system-config.tar.gz" -C / 2>/dev/null || true
fi

runuser -u www-data -- "$APP/artisan" config:clear >/dev/null
runuser -u www-data -- "$APP/artisan" view:clear >/dev/null
systemctl restart 'php*-fpm' nginx cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot \
    || echo "not every service restarted, see their states:"
systemctl is-active nginx cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot | tr '\n' ' '; echo
echo "restored from $SRC; installed package: $(dpkg-query -W -f='${Version}' cmprovision4)"
