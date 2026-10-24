#!/bin/sh
# Build on a Debian 13 build machine. Never build on an active provisioning station.
set -eu
PROFILE=${1:?lite or wlanpi required}
OUT=$(realpath -m "${2:?output directory required}")
case "$PROFILE" in lite|wlanpi) ;; *) exit 2;; esac
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
sh "$HERE/prepare.sh" "$WORK/source"
mkdir -p "$OUT"
if [ "$PROFILE" = lite ]; then
    sh "$WORK/source/tools/build-lite-deb.sh" "$OUT"
else
    # dh-virtualenv builds native dependencies: use an arm64 Debian 13 builder for the Pi package.
    [ "$(dpkg --print-architecture)" = arm64 ] || { echo 'WLAN Pi package requires an arm64 builder' >&2; exit 2; }
    cd "$WORK/source"
    VERSION=$(dpkg-parsechangelog -S Version)
    DEBFULLNAME='cmprovision contributors' DEBEMAIL='cmprovision@localhost' \
        dch --newversion "${VERSION}+cmprovision1" 'Add optional cmprovision front panel integration.'
    dpkg-buildpackage -b -us -uc
    cp "$WORK"/wlanpi-fpms_*.deb "$OUT/"
fi
cp "$WORK/source/.cmprovision-upstream" "$OUT/fpms-upstream.txt"
sha256sum "$HERE/portable-cmprovision.patch" > "$OUT/fpms-patch.sha256"
