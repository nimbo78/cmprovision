#!/bin/sh
# The buildroot hash files of the utility OS's own sources, from utility-os/versions.env (2.7.0): <dir>/<pkg>/<pkg>.hash.
# build.sh points BR2_GLOBAL_PATCH_DIR at <dir>. With BR2_DOWNLOAD_FORCE_CHECK_HASHES a source without its line stops
# the download, but a package with no hash file at all would be taken unchecked: all four are always written.
#   tools/utility-os/hashes.sh <versions.env> <dir>
set -eu
[ $# -eq 2 ] && [ -f "$1" ] || { echo "usage: hashes.sh <versions.env> <dir>" >&2; exit 2; }
. "$1"
DIR=$2
line() {
    [ -n "$1" ] || { echo "hashes.sh: $3 has no SHA256 in versions.env" >&2; exit 1; }
    printf 'sha256  %s  %s\n' "$1" "$2"
}
mkdir -p "$DIR/linux" "$DIR/cmp-rpi-boot-files" "$DIR/cmp-rpi-utils" "$DIR/cmp-usbboot-bootfiles"
line "${UOS_LINUX_SHA256:-}" "linux-$UOS_LINUX_COMMIT.tar.gz" UOS_LINUX_SHA256 > "$DIR/linux/linux.hash"
{
    line "${UOS_START4_SHA256:-}" start4.elf UOS_START4_SHA256
    line "${UOS_FIXUP4_SHA256:-}" fixup4.dat UOS_FIXUP4_SHA256
    line "${UOS_FIRMWARE_LICENCE_SHA256:-}" LICENCE.broadcom UOS_FIRMWARE_LICENCE_SHA256
} > "$DIR/cmp-rpi-boot-files/cmp-rpi-boot-files.hash"
line "${UOS_RPI_UTILS_SHA256:-}" "cmp-rpi-utils-$UOS_RPI_UTILS_COMMIT.tar.gz" UOS_RPI_UTILS_SHA256 > "$DIR/cmp-rpi-utils/cmp-rpi-utils.hash"
{
    line "${UOS_BOOTFILES_SHA256:-}" bootfiles.bin UOS_BOOTFILES_SHA256
    line "${UOS_USBBOOT_LICENSE_SHA256:-}" LICENSE UOS_USBBOOT_LICENSE_SHA256
} > "$DIR/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.hash"
