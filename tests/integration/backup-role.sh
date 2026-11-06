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
trap 'echo "Failed at line $LINENO" >&2; tail -15 /tmp/backup.log /tmp/restore.log 2>/dev/null || true' ERR
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
cat >/tmp/bin/runuser <<'SH'
#!/bin/sh
shift 3
exec "$@"
SH
cat >/tmp/bin/sqlite3 <<'SH'
#!/bin/sh
printf x > "$(printf '%s' "$4" | cut -d "'" -f 2)"
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
