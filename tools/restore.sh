#!/bin/sh
# Roll an installation back to a snapshot made by cmprovision-backup:
#   sudo cmprovision-restore /var/backups/cmprovision/<snapshot>      (installed by the package; tools/restore.sh in a checkout)
# Reinstalls the packaged version from the snapshot (if it holds a .deb), then puts back the
# application directory, the database and the system config, and restarts the services.
set -e
SRC=${1:?usage: restore.sh <snapshot directory>}
APP=/var/lib/cmprovision
[ "$(id -u)" = 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
[ -f "$SRC/app.tar.gz" ] || { echo "$SRC: not a backup directory" >&2; exit 1; }

systemctl stop cmprovision-queue cmprovision-dnsmasq 2>/dev/null || true

DEB=$(ls "$SRC"/cmprovision4_*.deb 2>/dev/null | head -1)
if [ -n "$DEB" ]; then
    echo "reinstalling $DEB"
    apt-get install -y --allow-downgrades "$DEB" || dpkg -i "$DEB"
fi

echo "restoring $APP"
tar xzf "$SRC/app.tar.gz" -C /
if [ -f "$SRC/database.sqlite" ]; then
    install -o www-data -g www-data -m 600 "$SRC/database.sqlite" "$APP/database/database.sqlite"
fi
tar xzf "$SRC/system-config.tar.gz" -C / 2>/dev/null || true

"$APP/artisan" config:clear >/dev/null
"$APP/artisan" view:clear >/dev/null
# Ownership as debian/postinst sets it (after artisan, which runs as root): code root's, the web server writes only to its own places
find "$APP" -xdev \( -path "$APP/storage" -o -path "$APP/bootstrap/cache" -o -path "$APP/public/uploads" \
    -o -path "$APP/etc" -o -path "$APP/.env" -o -path "$APP/database/database.sqlite*" \) -prune \
    -o -user www-data -exec chown root:root {} +
chown -R www-data:www-data "$APP/storage" "$APP/bootstrap/cache" "$APP/public/uploads" "$APP/etc"
chown www-data:www-data "$APP/database"
for f in "$APP/.env" "$APP"/database/database.sqlite*; do
    if [ -e "$f" ]; then chown www-data:www-data "$f"; fi
done
systemctl restart 'php*-fpm' nginx cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot
systemctl is-active nginx cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot | tr '\n' ' '; echo
echo "restored from $SRC; installed package: $(dpkg-query -W -f='${Version}' cmprovision4)"
