#!/bin/sh
# Raise the pins of the utility OS (utility-os/versions.env, docs/utility-os.md) to the newest versions:
#   buildroot  the newest point release of the LTS branch in versions.env; moving to the next LTS is a step by hand
#   kernel     the newest stable_* tag of raspberrypi/linux: its commit, its Linux version, GitHub's archive of it
#   firmware   the newest release tag of raspberrypi/firmware (1.YYYYMMDD): start4.elf, fixup4.dat, LICENCE.broadcom
#   utils      the head of raspberrypi/utils (it has no tags)
#   usbboot    the newest YYYYMMDD-hhmmss tag of raspberrypi/usbboot: firmware/bootfiles.bin, LICENSE
#   update.sh            download what moved, hash it, rewrite versions.env, raise UOS_VERSION
#   update.sh --check    only tell: exit 0 nothing new, 10 something new, 1 could not find out
# versions.env is rewritten in one mv, only when every download and hash succeeded.
# UOS_VERSIONS_ENV (the file) and UOS_TODAY (YYYY-MM-DD) are for tests/Unit/UtilityOsUpdateTest.php.
set -eu
REPO=$(cd "$(dirname "$0")/../.." && pwd)
VERSIONS_FILE=${UOS_VERSIONS_ENV:-$REPO/utility-os/versions.env}
TODAY=${UOS_TODAY:-$(date -u +%Y-%m-%d)}
MODE=${1:-update}
case "$#:$MODE" in
    0:update|1:--check) ;;
    *) echo "usage: update.sh [--check]" >&2; exit 1 ;;
esac
export GIT_TERMINAL_PROMPT=0
. "$VERSIONS_FILE"

fail() { echo "update.sh: $*" >&2; exit 1; }
BUILDROOT=https://gitlab.com/buildroot.org/buildroot.git
LINUX=https://github.com/raspberrypi/linux.git
FIRMWARE=https://github.com/raspberrypi/firmware.git
UTILS=https://github.com/raspberrypi/utils.git
USBBOOT=https://github.com/raspberrypi/usbboot.git

# the tag names of a repository (peeled lines left out)
tags() {
    refs=$(git ls-remote --tags --refs "$1" "$2") || return 1
    printf '%s\n' "$refs" | sed 's#.*refs/tags/##'
}
# the commit a tag names (an annotated tag's ^{} line wins)
commit_of() {
    refs=$(git ls-remote --tags "$1") || return 1
    printf '%s\n' "$refs" | awk -v t="refs/tags/$2" '$2 == t { c = $1 } $2 == t "^{}" { p = $1 } END { print p ? p : c }'
}
# the next version of the OS: YYYY.MM, or YYYY.MM.N for a second one in a month
next_version() {
    month=$(printf '%s' "$TODAY" | cut -c1-7 | tr - .)
    case "$1" in
        "$month") echo "$month.1" ;;
        "$month".*) echo "$month.$(( ${1##*.} + 1 ))" ;;
        *) echo "$month" ;;
    esac
}

branch=$(printf '%s' "$UOS_BUILDROOT_VERSION" | cut -d. -f1-2)
branch_re=$(printf '%s' "$branch" | sed 's/\./\\./g')
br_tags=$(tags "$BUILDROOT" "refs/tags/$branch*") || fail "could not find out the newest buildroot $branch.x"
br=$(printf '%s\n' "$br_tags" | grep -E "^$branch_re(\.[0-9]+)?\$" | sort -V | tail -n 1) || true
[ -n "$br" ] || fail "could not find out the newest buildroot $branch.x"
lts_tags=$(tags "$BUILDROOT" 'refs/tags/20*') || fail "could not find out buildroot LTS releases"
# Since 2025, only February releases of odd-numbered years are LTS (buildroot.org/lts.html).
lts=$(printf '%s\n' "$lts_tags" | grep -E '^[0-9]{4}\.02$' | awk -F. '$1 >= 2025 && $1 % 2 == 1' | sort -V | tail -n 1) || true
kernel_tags=$(tags "$LINUX" 'refs/tags/stable_*') || fail "could not find out the newest kernel (stable_* of raspberrypi/linux)"
kernel=$(printf '%s\n' "$kernel_tags" | grep -E '^stable_[0-9]{8}$' | sort | tail -n 1) || true
[ -n "$kernel" ] || fail "could not find out the newest kernel (stable_* of raspberrypi/linux)"
firmware_tags=$(tags "$FIRMWARE" 'refs/tags/1.*') || fail "could not find out the newest firmware (raspberrypi/firmware)"
firmware=$(printf '%s\n' "$firmware_tags" | grep -E '^1\.[0-9]{8}$' | sort | tail -n 1) || true
[ -n "$firmware" ] || fail "could not find out the newest firmware (raspberrypi/firmware)"
utils_refs=$(git ls-remote "$UTILS" HEAD) || fail "could not find out the head of raspberrypi/utils"
utils=$(printf '%s\n' "$utils_refs" | awk '{ print $1; exit }')
printf '%s\n' "$utils" | grep -Eq '^[0-9a-f]{40}$' || fail "could not find out the head of raspberrypi/utils"
usbboot_tags=$(tags "$USBBOOT" 'refs/tags/2*') || fail "could not find out the newest usbboot"
usbboot=$(printf '%s\n' "$usbboot_tags" | grep -E '^[0-9]{8}-[0-9]{6}$' | sort | tail -n 1) || true
[ -n "$usbboot" ] || fail "could not find out the newest usbboot"

moved=0
report() {
    if [ "$2" = "$3" ]; then echo "$1: $2 (newest)"; else echo "$1: $2 -> $3"; moved=1; fi
}
report buildroot "$UOS_BUILDROOT_VERSION" "$br"
report kernel "$UOS_LINUX_TAG" "$kernel"
report firmware "$UOS_FIRMWARE_TAG" "$firmware"
report utils "$UOS_RPI_UTILS_COMMIT" "$utils"
report usbboot "$UOS_USBBOOT_TAG" "$usbboot"
if [ -n "$lts" ] && [ "$lts" != "$branch" ] && [ "$(printf '%s\n%s\n' "$branch" "$lts" | sort -V | tail -n 1)" = "$lts" ]; then
    echo "note: buildroot $lts is a newer LTS; moving to it is a step by hand (docs/utility-os.md)"
fi

if [ "$MODE" = --check ]; then
    [ "$moved" = 1 ] && exit 10
    exit 0
fi
[ "$moved" = 1 ] || { echo "update.sh: nothing new"; exit 0; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
# the SHA256 of what a URL serves
fetch() {
    curl -fsSL --retry 3 -o "$TMP/file" "$1" || fail "cannot download $1"
    sha256sum "$TMP/file" | cut -d' ' -f1
}

V_BR=$UOS_BUILDROOT_VERSION; S_BR=$UOS_BUILDROOT_SHA256
if [ "$br" != "$UOS_BUILDROOT_VERSION" ]; then
    V_BR=$br
    S_BR=$(fetch "https://buildroot.org/downloads/buildroot-$br.tar.gz")
    sign=$(curl -fsSL --retry 3 "https://buildroot.org/downloads/buildroot-$br.tar.gz.sign") || fail "cannot download buildroot-$br.tar.gz.sign"
    printf '%s\n' "$sign" | grep -q "^SHA256: $S_BR  buildroot-$br.tar.gz\$" || fail "buildroot-$br.tar.gz does not match its .sign"
fi
T_LX=$UOS_LINUX_TAG; C_LX=$UOS_LINUX_COMMIT; V_LX=$UOS_LINUX_VERSION; S_LX=$UOS_LINUX_SHA256
if [ "$kernel" != "$UOS_LINUX_TAG" ]; then
    T_LX=$kernel
    C_LX=$(commit_of "$LINUX" "$kernel") || fail "no commit for $kernel"
    printf '%s\n' "$C_LX" | grep -Eq '^[0-9a-f]{40}$' || fail "no commit for $kernel"
    makefile=$(curl -fsSL --retry 3 "https://raw.githubusercontent.com/raspberrypi/linux/$C_LX/Makefile") || fail "cannot download the Makefile of $C_LX"
    V_LX=$(printf '%s\n' "$makefile" | awk '/^VERSION =/ { v = $3 } /^PATCHLEVEL =/ { p = $3 } /^SUBLEVEL =/ { s = $3 } END { print v "." p "." s }')
    printf '%s\n' "$V_LX" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || fail "no Linux version in the Makefile of $C_LX"
    S_LX=$(fetch "https://github.com/raspberrypi/linux/archive/$C_LX/linux-$C_LX.tar.gz")
fi
T_FW=$UOS_FIRMWARE_TAG; S_START4=$UOS_START4_SHA256; S_FIXUP4=$UOS_FIXUP4_SHA256; S_FWL=$UOS_FIRMWARE_LICENCE_SHA256
if [ "$firmware" != "$UOS_FIRMWARE_TAG" ]; then
    T_FW=$firmware
    S_START4=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/start4.elf")
    S_FIXUP4=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/fixup4.dat")
    S_FWL=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/LICENCE.broadcom")
fi
C_UT=$UOS_RPI_UTILS_COMMIT; S_UT=$UOS_RPI_UTILS_SHA256
if [ "$utils" != "$UOS_RPI_UTILS_COMMIT" ]; then
    C_UT=$utils
    S_UT=$(fetch "https://github.com/raspberrypi/utils/archive/$utils/cmp-rpi-utils-$utils.tar.gz")
fi
T_UB=$UOS_USBBOOT_TAG; S_BF=$UOS_BOOTFILES_SHA256; S_UBL=$UOS_USBBOOT_LICENSE_SHA256
if [ "$usbboot" != "$UOS_USBBOOT_TAG" ]; then
    T_UB=$usbboot
    S_BF=$(fetch "https://raw.githubusercontent.com/raspberrypi/usbboot/$usbboot/firmware/bootfiles.bin")
    S_UBL=$(fetch "https://raw.githubusercontent.com/raspberrypi/usbboot/$usbboot/LICENSE")
fi
V_OS=$(next_version "$UOS_VERSION")

{
    sed -n '/^#/p' "$VERSIONS_FILE"
    cat <<EOF
UOS_VERSION=$V_OS
UOS_BUILDROOT_VERSION=$V_BR
UOS_BUILDROOT_SHA256=$S_BR
UOS_LINUX_TAG=$T_LX
UOS_LINUX_COMMIT=$C_LX
UOS_LINUX_VERSION=$V_LX
UOS_LINUX_SHA256=$S_LX
UOS_FIRMWARE_TAG=$T_FW
UOS_START4_SHA256=$S_START4
UOS_FIXUP4_SHA256=$S_FIXUP4
UOS_FIRMWARE_LICENCE_SHA256=$S_FWL
UOS_RPI_UTILS_COMMIT=$C_UT
UOS_RPI_UTILS_SHA256=$S_UT
UOS_USBBOOT_TAG=$T_UB
UOS_BOOTFILES_SHA256=$S_BF
UOS_USBBOOT_LICENSE_SHA256=$S_UBL
EOF
} > "$VERSIONS_FILE.new"
mv "$VERSIONS_FILE.new" "$VERSIONS_FILE"
echo "update.sh: versions.env rewritten, the OS is now $V_OS: build.sh, then test.sh"
