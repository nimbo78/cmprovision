#!/bin/bash
# Run the actual backup/restore scripts in private mounts with service/database command stubs.
set -Eeuo pipefail
SOURCE=$(cd "$(dirname "$0")/../.." && pwd)
if [ "${1:-}" != --inner ]; then
    exec unshare --net --mount --pid --fork --mount-proc bash "$0" --inner
fi
mount --make-rprivate /
mount -t tmpfs tmpfs /run
mount -t tmpfs tmpfs /var/lib
mount -t tmpfs tmpfs /var/tmp
mount -t tmpfs tmpfs /tmp
trap 'echo "Failed at line $LINENO" >&2; tail -n 15 /tmp/backup.log /tmp/restore.log 2>/dev/null || true' ERR
cp /etc/passwd /etc/group /tmp/
cp -L /usr/bin/awk /tmp/awk
mount -t tmpfs tmpfs /etc
cp /tmp/passwd /tmp/group /etc/
mount -t tmpfs tmpfs /lib/systemd/system
mkdir -p /tmp/bin /etc/systemd/system/multi-user.target.wants /run/cmprovision-network \
    /var/lib/cmprovision/{database,storage,bootstrap/cache,public/uploads,etc}
: >/run/cmprovision-network/provisioning.lock
mv /tmp/awk /tmp/bin/awk
printf '#!/bin/sh\nexit 0\n' >/var/lib/cmprovision/artisan
chmod +x /var/lib/cmprovision/artisan
printf 'APP_KEY=test\n' >/var/lib/cmprovision/.env
printf 'dhcp-authoritative\n' >/var/lib/cmprovision/etc/dnsmasq.conf
cat >/tmp/bin/runuser <<'SH'
#!/bin/sh
shift 3
exec "$@"
SH
cat >/tmp/bin/sqlite3 <<'SH'
#!/bin/sh
case "$*" in
    *".backup "*) printf x > "$(printf '%s' "$4" | cut -d "'" -f 2)" ;;
    *pragma_table_info*) echo 0 ;;
    *) exit 1 ;;
esac
SH
cat >/tmp/bin/dpkg-query <<'SH'
#!/bin/sh
case "$*" in *Status*) echo 'install ok installed';; *) echo 2.24.0;; esac
SH
cat >/tmp/bin/dpkg-repack <<'SH'
#!/bin/sh
exit 1
SH
cat >/tmp/bin/systemctl <<'SH'
#!/bin/sh
printf '%s\n' "$*" >>/tmp/systemctl.log
# Reject an accidental start of a masked unit, as the real manager would.
if [ "$1" = restart ]; then
    shift
    for unit in "$@"; do
        if [ "$(readlink "/etc/systemd/system/$unit.service")" = /dev/null ]; then exit 1; fi
    done
fi
SH
chmod +x /tmp/bin/*
export PATH=/tmp/bin:$PATH
export LC_ALL=C

role()
{
    rm -f /etc/systemd/system/cmprovision-{dnsmasq,rpiboot}.service \
        /etc/systemd/system/multi-user.target.wants/cmprovision-{dnsmasq,rpiboot}.service
    for unit in cmprovision-dnsmasq cmprovision-rpiboot; do
        if [ "$1" = secondary ]; then
            ln -s /dev/null "/etc/systemd/system/$unit.service"
        else
            ln -s "/lib/systemd/system/$unit.service" "/etc/systemd/system/multi-user.target.wants/$unit.service"
        fi
    done
}

for saved in secondary primary; do
    role "$saved"
    bash "$SOURCE/tools/backup.sh" "/tmp/$saved" >/tmp/backup.log 2>&1
    snapshot=$(find "/tmp/$saved" -mindepth 1 -maxdepth 1 -type d)
    test -f "$snapshot/boot-services.saved"
    if [ "$saved" = secondary ]; then role primary; else role secondary; fi
    bash "$SOURCE/tools/restore.sh" "$snapshot" >/tmp/restore.log 2>&1
    for unit in cmprovision-dnsmasq cmprovision-rpiboot; do
        if [ "$saved" = secondary ]; then
            test "$(readlink "/etc/systemd/system/$unit.service")" = /dev/null
            test ! -e "/etc/systemd/system/multi-user.target.wants/$unit.service"
        else
            test ! -L "/etc/systemd/system/$unit.service"
            test -L "/etc/systemd/system/multi-user.target.wants/$unit.service"
        fi
    done
done
# Data-only and legacy snapshots preserve the current system role.
role secondary
bash "$SOURCE/tools/restore.sh" --data-only "$snapshot" >/tmp/restore.log 2>&1
test "$(readlink /etc/systemd/system/cmprovision-dnsmasq.service)" = /dev/null
rm "$snapshot/boot-services.saved"
bash "$SOURCE/tools/restore.sh" "$snapshot" >/tmp/restore.log 2>&1
test "$(readlink /etc/systemd/system/cmprovision-dnsmasq.service)" = /dev/null
echo 'PASS: full snapshots restore both station roles; data-only and legacy keep the current role'

# A failed writer may leave a readable tar prefix. Fill a private tmpfs and
# use a nonempty directory at the destination to force a real extraction error.
cat >/tmp/bin/dpkg-repack <<'SH'
#!/bin/sh
touch cmprovision4_2.24.0_all.deb
SH
cat >/tmp/bin/dpkg <<'SH'
#!/bin/sh
[ "$1" = -l ] && echo 'ii  cmprovision4  2.24.0'
SH
chmod +x /tmp/bin/dpkg /tmp/bin/dpkg-repack
export CMPROVISION_UPDATE_SNAPSHOT=1
db=/var/lib/cmprovision/database/database.sqlite
rm /tmp/bin/sqlite3 "$db"
/usr/bin/sqlite3 "$db" 'CREATE TABLE projects(id INTEGER PRIMARY KEY);'
if sh "$SOURCE/tools/backup.sh" /tmp/required-missing >/tmp/backup.log 2>&1; then
    echo 'Updater backup accepted a missing nginx site' >&2; exit 1
fi
mkdir -p /etc/nginx/sites-available
printf 'server { }\n' >/etc/nginx/sites-available/cmprovision
mv "$db" /tmp/healthy.sqlite
if sh "$SOURCE/tools/backup.sh" /tmp/missing-db >/tmp/backup.log 2>&1; then
    echo 'Updater backup accepted a missing station database' >&2; exit 1
fi
test ! -e "$db"
printf 'damaged database\n' > "$db"
if sh "$SOURCE/tools/backup.sh" /tmp/damaged-db >/tmp/backup.log 2>&1; then
    echo 'Updater backup accepted a damaged station database' >&2; exit 1
fi
test -z "$(find /var/tmp -mindepth 1 -name 'cmprovision-backup.*' -print -quit)"
mv /tmp/healthy.sqlite "$db"
mv /var/lib/cmprovision/.env /tmp/saved.env
if sh "$SOURCE/tools/backup.sh" /tmp/missing-env >/tmp/backup.log 2>&1; then
    echo 'Updater backup accepted a missing .env' >&2; exit 1
fi
mv /tmp/saved.env /var/lib/cmprovision/.env
mkdir -m 700 /tmp/write-failure
mount -t tmpfs -o size=1M tmpfs /tmp/write-failure
dd if=/dev/urandom of=/etc/dhcpcd.conf bs=1M count=2 status=none
if sh "$SOURCE/tools/backup.sh" /tmp/write-failure >/tmp/backup.log 2>&1; then
    echo 'Updater backup accepted a failed archive write' >&2; exit 1
fi
grep -Fiq 'No space left on device' /tmp/backup.log
test -f /tmp/write-failure/*/.incomplete
rm /etc/dhcpcd.conf
sh "$SOURCE/tools/backup.sh" --with-uploads /tmp/valid >/tmp/backup.log 2>&1
snapshot=$(find /tmp/valid -mindepth 1 -maxdepth 1 -type d)
tar tzf "$snapshot/system-config.tar.gz" | grep -Fxq 'etc/nginx/sites-available/cmprovision'
tar tzf "$snapshot/system-config.tar.gz" | grep -Fxq 'etc/systemd/system/cmprovision-dnsmasq.service'
test ! -e /etc/dhcpcd.conf
# The updater stages data alone and installs the checked package separately.
mkdir /tmp/restore-data
ln "$snapshot/"{app.tar.gz,database.sqlite,system-config.tar.gz} /tmp/restore-data/
rm /etc/nginx/sites-available/cmprovision
mkdir /etc/nginx/sites-available/cmprovision
touch /etc/nginx/sites-available/cmprovision/occupied
if sh "$SOURCE/tools/restore.sh" --force /tmp/restore-data >/tmp/restore.log 2>&1; then
    echo 'Updater restore accepted a failed required extraction' >&2; exit 1
fi
grep -Fq 'etc/nginx/sites-available/cmprovision' /tmp/restore.log
echo 'PASS: updater rejects missing required config and real archive write/extraction failures; optional absent configs are valid'
