#!/bin/sh
# From Git Bash on Windows: builds the package of a git ref in a WSL distro and leaves it, with its SHA256, in a folder here:
#   tools/build-deb-wsl.sh <git ref> [out-dir]        (out-dir: ./build; the distro: $CMP_WSL_DISTRO, cmprov-trixie by default)
# The distro needs dpkg-dev, debhelper, fakeroot, composer and the patched PHP (tools/build-deb.sh does the building).
# Commit first: a git archive takes the commit, not the files you are editing. About a minute and a half for 2.18.
set -e
REF=${1:-}
if [ -z "$REF" ]; then
    echo "usage: $0 <git ref> [out-dir]" >&2
    exit 2
fi
OUT=${2:-build}
DISTRO=${CMP_WSL_DISTRO:-cmprov-trixie}
ROOT=$(git rev-parse --show-toplevel)
V=$(git -C "$ROOT" show "$REF:debian/changelog" | head -1 | sed 's/.*(\(.*\)).*/\1/')
mkdir -p "$OUT"
export MSYS_NO_PATHCONV=1
# the source goes in through stdin, the script that builds it too; the package comes back through stdout
git -C "$ROOT" archive --format=tar "$REF" | wsl.exe -d "$DISTRO" -u root -- sh -c 'cat > /tmp/cmp-src.tar'
wsl.exe -d "$DISTRO" -u root -- sh -s /tmp/cmp-src.tar /tmp/cmp-out < "$ROOT/tools/build-deb.sh"
wsl.exe -d "$DISTRO" -u root -- sh -c "cat /tmp/cmp-out/cmprovision4_${V}_all.deb; rm -rf /tmp/cmp-out /tmp/cmp-src.tar" > "$OUT/cmprovision4_${V}_all.deb"
(cd "$OUT" && sha256sum "cmprovision4_${V}_all.deb" > SHA256SUMS && cat SHA256SUMS)
