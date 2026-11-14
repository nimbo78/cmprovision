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
if [ "$#" -eq 1 ] && [ "$1" = inspect ]; then
    [ "$(lsblk -dn -o RO "$STORAGE" | tr -d ' ')" = 0 ] || fail 'read-only disk'
    MODEL=$(tr -d '\000' </proc/device-tree/model 2>/dev/null) || MODEL=unknown
    REVISION=$(awk '/^Revision[[:space:]]*:/ {print tolower($3)}' /proc/cpuinfo)
    [ -n "$REVISION" ] || REVISION=unknown
    MAC=$(tr 'A-F' 'a-f' </sys/class/net/eth0/address 2>/dev/null) || fail 'cannot read Ethernet MAC'
    BOOT_ORDER=$(vcgencmd bootloader_config 2>/dev/null | sed -n 's/^BOOT_ORDER=//p' | tr 'A-F' 'a-f')
    case "$BOOT_ORDER" in 0x[0-9a-f]*) ;; *) BOOT_ORDER=unknown;; esac
    PROVISIONER=0
    [ ! -f /var/lib/cmprovision/artisan ] || PROVISIONER=1
    printf 'agent=2\nserial=%s\nhost=%s\nstorage=%s\ncid=%s\nsectors=%s\nmodel=%s\nrevision=%s\nmac=%s\nboot_order=%s\nprovisioner=%s\n' \
        "$SERIAL" "$(hostname)" "$STORAGE" "$CID" "$SECTORS" "$MODEL" "$REVISION" "$MAC" "$BOOT_ORDER" "$PROVISIONER"
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
