#!/bin/sh
# Builds the cmprovision4 package from a git archive of the source:  tools/build-deb.sh <source.tar> <out-dir>
# The package is Architecture: all and its vendor/ comes from composer.lock, so any Debian 13 machine with dpkg-dev, debhelper,
# fakeroot and composer builds the same package; the machine only has to pass the version check of composer.json (the patched
# PHP: 8.4.27 or later for 8.4), which composer makes before it installs. Building on the provisioner itself slows the modules
# that are being written there, so build somewhere else (tools/build-deb-wsl.sh does it in a WSL distro of a Windows PC).
set -e
SRC=$1
OUT=$2
if [ ! -f "$SRC" ] || [ -z "$OUT" ]; then
    echo "usage: $0 <source.tar> <out-dir>" >&2
    exit 2
fi
# in WSL composer adds .bat launchers to vendor/bin, which a build on Linux does not have
export LC_ALL=C.UTF-8 COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_BIN_COMPAT=symlink
V=$(tar -xOf "$SRC" debian/changelog | head -1 | sed 's/.*(\(.*\)).*/\1/')
WORK=$(mktemp -d /tmp/cmprovision-build.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/cmprovision4-$V" "$OUT"
tar -xf "$SRC" -C "$WORK/cmprovision4-$V"
cd "$WORK/cmprovision4-$V"
# -b: the binary package only, -us -uc: not signed
if ! dpkg-buildpackage -b -us -uc > "$WORK/build.log" 2>&1; then
    tail -30 "$WORK/build.log" >&2
    exit 1
fi
cp "$WORK/cmprovision4_${V}_all.deb" "$OUT/"
echo "built $OUT/cmprovision4_${V}_all.deb"
