#!/bin/sh
# Credentials arrive only inside SSH. Everything in this script may be logged by sh -v.
set -eu
[ "$(uname -m)" = aarch64 ] || { echo 'Station setup requires the 64-bit utility OS'; exit 1; }
ROOT=$(mktemp -d /tmp/cmprovision-station.XXXXXX)
mounted=0
sshd=
cleanup() {
    if [ -n "$sshd" ]; then kill "$sshd" 2>/dev/null || true; wait "$sshd" 2>/dev/null || true; fi
    for part in dev proc sys run; do
        if mountpoint -q "$ROOT/$part"; then umount "$ROOT/$part" || return 1; fi
    done
    if [ "$mounted" = 1 ]; then umount "$ROOT" || return 1; fi
    rmdir "$ROOT"
}
trap cleanup EXIT
mount -t ext4 {!! escapeshellarg($stationStorage.'p2') !!} "$ROOT"
mounted=1
@if (($stationProfile ?? 'station') === 'raspios')
[ -f "$ROOT/etc/os-release" ] && [ -x "$ROOT/usr/bin/python3" ] &&
    [ -x "$ROOT/usr/sbin/sshd" ] && [ -x "$ROOT/usr/bin/ssh-keygen" ] || {
    echo 'This image needs Python 3 and OpenSSH for installed OS access setup'; exit 1;
}
chroot "$ROOT" /usr/bin/python3 -c 'import platform,struct; assert platform.machine() == "aarch64" and struct.calcsize("P") == 8' || {
    echo 'Installed OS access setup requires an arm64 image'; exit 1;
}
@else
[ -f "$ROOT/usr/lib/cmprovision/image-setup-schema" ] &&
    [ "$(cat "$ROOT/usr/lib/cmprovision/image-setup-schema")" = 1 ] &&
    [ -x "$ROOT/usr/lib/cmprovision/image-setup" ] &&
    [ ! -e "$ROOT/var/lib/cmprovision-image/firstboot-ready" ] || {
    echo 'This image does not support initial station setup'; exit 1;
}
@endif
for part in dev proc sys run; do
    [ -d "$ROOT/$part" ] && [ ! -L "$ROOT/$part" ] || exit 1
done
mount -t tmpfs -o mode=0755 tmpfs "$ROOT/run"
mount --bind /dev "$ROOT/dev"
mount -t proc proc "$ROOT/proc"
mount -t sysfs -o ro sysfs "$ROOT/sys"
mkdir -p "$ROOT/run/sshd" "$ROOT/run/cmprovision-station-setup"
chmod 0700 "$ROOT/run/cmprovision-station-setup"
SETUP=$ROOT/run/cmprovision-station-setup
@if (($stationProfile ?? 'station') === 'raspios')
curl -fsS --max-time 30 --retry 2 --retry-delay 2 \
    {!! escapeshellarg($stationHelperUrl) !!} -o "$SETUP/image-access"
printf '%s  %s\n' {!! escapeshellarg($stationHelperSha256) !!} "$SETUP/image-access" | sha256sum -c - >/dev/null
chmod 0700 "$SETUP/image-access"
@endif
cat >"$SETUP/identity.json" <<'CMP_STATION_ID'
{!! json_encode(['schema' => 1, 'serial' => $stationSerial, 'cid' => $stationCid, 'run' => $stationRun]) !!}
CMP_STATION_ID
printf '%s\n' {!! escapeshellarg($stationStorage) !!} >"$SETUP/storage"
printf '%s\n' {!! escapeshellarg('restrict,command="'.(($stationProfile ?? 'station') === 'raspios' ? '/run/cmprovision-station-setup/image-access' : '/usr/lib/cmprovision/image-setup').'" '.$stationPublic) !!} >"$SETUP/authorized_keys"
chmod 0600 "$SETUP/identity.json" "$SETUP/storage" "$SETUP/authorized_keys"
chroot "$ROOT" /usr/bin/ssh-keygen -q -t ed25519 -N '' -f /run/cmprovision-station-setup/host
cat >"$SETUP/sshd_config" <<'CMP_STATION_SSH'
Port 2224
HostKey /run/cmprovision-station-setup/host
PidFile /run/cmprovision-station-setup/pid
AuthorizedKeysFile /run/cmprovision-station-setup/authorized_keys
UsePAM yes
PermitRootLogin forced-commands-only
PasswordAuthentication no
KbdInteractiveAuthentication no
AllowUsers root
DisableForwarding yes
PermitTTY no
PrintMotd no
LogLevel ERROR
CMP_STATION_SSH
chroot "$ROOT" /usr/sbin/sshd -D -e -f /run/cmprovision-station-setup/sshd_config >"$SETUP/sshd.log" 2>&1 &
sshd=$!
sleep 1
kill -0 "$sshd" || { echo 'Station setup SSH service failed to start'; exit 1; }
awk '{print $1" "$2}' "$SETUP/host.pub" >"$SETUP/host-public"
@if (($stationProfile ?? 'station') === 'raspios')
attempt=0
while :; do
    status=$(curl -sS --connect-timeout 5 --max-time 80 -o "$SETUP/result" -w '%{http_code}' \
        --data-urlencode {!! escapeshellarg('serial='.$stationSerial) !!} \
        --data-urlencode {!! escapeshellarg('run='.$stationRun) !!} \
        --data-urlencode "host_key@$SETUP/host-public" \
        {!! escapeshellarg($stationUrl) !!}) || status=000
    result=$(cat "$SETUP/result" 2>/dev/null || true)
    if [ "$status" = 200 ] && [ "$result" = applied ]; then break; fi
    case "$result" in
@foreach (\App\Support\ImageAccessFailure::MESSAGES as $code => $message)
        {!! escapeshellarg('image-access:error:'.$code) !!}) printf '%s\n' {!! escapeshellarg($message) !!}; exit 1 ;;
@endforeach
    esac
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 3 ]; then echo 'Installed OS access setup was not confirmed; check the board connection and image compatibility'; exit 1; fi
    sleep 2
done
@else
curl -fsS --max-time 80 --retry 2 --retry-delay 2 \
    --data-urlencode {!! escapeshellarg('serial='.$stationSerial) !!} \
    --data-urlencode {!! escapeshellarg('run='.$stationRun) !!} \
    --data-urlencode "host_key@$SETUP/host-public" \
    {!! escapeshellarg($stationUrl) !!} >"$SETUP/result"
[ "$(cat "$SETUP/result")" = applied ] || { echo 'Station setup was not confirmed'; exit 1; }
@endif
sync
cleanup
trap - EXIT
echo '{{ ($stationProfile ?? 'station') === 'raspios' ? 'Installed OS access settings applied' : 'Station access and management settings applied' }}'
