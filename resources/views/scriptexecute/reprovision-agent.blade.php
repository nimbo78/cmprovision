#!/bin/sh
# Only the running system's eMMC/SD root disk (partition 2, ext4) is supported.
set -f
export LC_ALL=C
STORAGE={!! escapeshellarg($target ?? '') !!}
fail() { printf 'refused: %s\n' "$1"; exit 1; }
SERIAL=$(awk '/^Serial[[:space:]]*:/ {print tolower($3)}' /proc/cpuinfo)
case "$SERIAL" in ''|*[!a-f0-9]*|0000000000000000) fail 'invalid serial';; esac
[ "${#SERIAL}" -eq 16 ] || fail 'invalid serial'
[ "$(findmnt -n -o FSTYPE /)" = ext4 ] || fail 'root is not ext4'
ROOT_PART=$(findmnt -n -o SOURCE /)
ROOT_PART=$(readlink -f "$ROOT_PART") || fail 'cannot resolve root device'
case "$ROOT_PART" in /dev/mmcblk[0-9]*p2) ROOT_DISK=${ROOT_PART%p2};; *) fail 'unsupported root device';; esac
[ -n "$STORAGE" ] || STORAGE=$ROOT_DISK
[ "$STORAGE" = "$ROOT_DISK" ] || fail 'configured disk is not the running root disk'
[ -b "$STORAGE" ] || fail 'not a block device'
[ "$(lsblk -dn -o PTTYPE "$STORAGE")" = dos ] || fail 'only an MBR partition table is supported'
DISK=${STORAGE##*/}
CID=$(tr 'A-F' 'a-f' </sys/class/block/"$DISK"/device/cid) || fail 'cannot read CID'
SECTORS=$(cat /sys/class/block/"$DISK"/size) || fail 'cannot read disk size'
case "$CID" in ''|*[!a-f0-9]*) fail 'invalid CID';; esac
[ "${#CID}" -eq 32 ] || fail 'invalid CID'
case "$SECTORS" in ''|*[!0-9]*) fail 'invalid disk size';; esac
[ "$SECTORS" -ge 2048 ] || fail 'disk too small'
REQUEST=${SSH_ORIGINAL_COMMAND:-}
set -- $REQUEST
if [ "$#" -eq 1 ] && [ "$1" = identify ]; then
    printf 'agent=1\nserial=%s\nhost=%s\nstorage=%s\ncid=%s\nsectors=%s\n' "$SERIAL" "$(hostname)" "$STORAGE" "$CID" "$SECTORS"
    exit 0
fi
[ "$#" -eq 4 ] && [ "$1" = wipe ] || fail 'unknown command'
[ "$2" = "$SERIAL" ] || fail "serial $SERIAL"
[ "$3" = "$CID" ] && [ "$4" = "$SECTORS" ] || fail 'the disk changed'
[ "$(id -u)" = 0 ] || fail 'root access required'
dd if=/dev/zero of="$STORAGE" bs=1M count=1 conv=fsync,notrunc >/dev/null 2>&1 || fail 'disk write failed'
sync || fail 'disk sync failed'
printf 'CMPROVISION_WIPED=%s\n' "$SERIAL"
( sleep 2; reboot -f ) </dev/null >/dev/null 2>&1 &
exit 0
