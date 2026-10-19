#!/bin/sh
# Build the cmprovision utility OS with buildroot (2.7.0, docs/utility-os.md). utility-os/ is the BR2_EXTERNAL tree,
# utility-os/versions.env every version and hash. The TFTP directory lands in scriptexecute64/ of this repository, the
# sources and licences of everything in it in $UOS_WORK/output/legal-info.
#   UOS_WORK (default $HOME/uos-work): buildroot and its output; UOS_CACHE (default $HOME/uos-cache): downloads, ccache
# Debian or Ubuntu x86_64 (the Bootlin toolchain is built for it), never in OneDrive. Then tools/utility-os/test.sh.
set -eu
REPO=$(cd "$(dirname "$0")/../.." && pwd)
EXT=$REPO/utility-os
. "$EXT/versions.env"
WORK=${UOS_WORK:-$HOME/uos-work}
CACHE=${UOS_CACHE:-$HOME/uos-cache}

[ "$(uname -m)" = x86_64 ] || { echo "build.sh: needs an x86_64 host" >&2; exit 1; }
case "$PATH" in
    *' '*|*'	'*) echo "build.sh: PATH has spaces, buildroot refuses it (WSL: export PATH=/usr/sbin:/usr/bin:/sbin:/bin)" >&2; exit 1 ;;
esac
missing=
for c in which sed make gcc g++ patch perl python3 rsync cpio unzip bc wget git xz bzip2 gzip tar find xargs cmp sha256sum; do
    command -v "$c" >/dev/null 2>&1 || missing="$missing $c"
done
[ -x /usr/bin/file ] || missing="$missing file"
if [ -n "$missing" ]; then
    echo "build.sh: missing on this host:$missing" >&2
    echo "  apt-get install build-essential bc cpio rsync unzip file wget git python3 perl patch xz-utils bzip2" >&2
    exit 1
fi

mkdir -p "$WORK" "$CACHE/dl" "$CACHE/ccache"
BR=$WORK/buildroot-$UOS_BUILDROOT_VERSION
if [ ! -d "$BR" ]; then
    tarball=$CACHE/dl/buildroot-$UOS_BUILDROOT_VERSION.tar.gz
    if [ ! -f "$tarball" ]; then
        wget -q -O "$tarball.part" "https://buildroot.org/downloads/buildroot-$UOS_BUILDROOT_VERSION.tar.gz"
        mv "$tarball.part" "$tarball"
    fi
    if ! echo "$UOS_BUILDROOT_SHA256  $tarball" | sha256sum -c - >/dev/null 2>&1; then
        echo "build.sh: $tarball does not match UOS_BUILDROOT_SHA256" >&2
        rm -f "$tarball"
        exit 1
    fi
    tar xzf "$tarball" -C "$WORK"
fi

# the hashes of our sources, where buildroot looks for them (BR2_GLOBAL_PATCH_DIR)
sh "$REPO/tools/utility-os/hashes.sh" "$EXT/versions.env" "$WORK/hashes"

OUT=$WORK/output
# Buildroot's linux-custom name stays the same when its tarball changes. Reconfigure does not
# re-extract it, and a new Buildroot/toolchain also requires a fresh output. Keep download/ccache.
if [ -d "$OUT" ] && { ! cmp -s "$EXT/versions.env" "$OUT/.cmprovision-versions" \
    || ! cmp -s "$EXT/configs/cmprovision_defconfig" "$OUT/.cmprovision-defconfig"; }; then
    echo "build.sh: pins or package selection changed/unknown: rebuilding output (download and compiler caches stay)"
    rm -rf "$OUT"
fi
export BR2_DL_DIR="$CACHE/dl" BR2_CCACHE_DIR="$CACHE/ccache"
make -C "$BR" O="$OUT" BR2_EXTERNAL="$EXT" cmprovision_defconfig > /dev/null
# This Buildroot helper uses ':' for sed replacements. Append an undefined URL instead, then verify it.
KERNEL_URL="https://github.com/raspberrypi/linux/archive/$UOS_LINUX_COMMIT/linux-$UOS_LINUX_COMMIT.tar.gz"
"$BR/utils/config" --file "$OUT/.config" \
    --undefine BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION \
    --set-str BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION "$KERNEL_URL" \
    --set-str BR2_GLOBAL_PATCH_DIR "$WORK/hashes" \
    --set-str BR2_WGET "wget -nd -t 3 --connect-timeout=10 --read-timeout=60"
make -C "$OUT" olddefconfig > /dev/null
grep -Fxq "BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION=\"$KERNEL_URL\"" "$OUT/.config" || {
    echo "build.sh: kernel URL was not applied to the configuration" >&2; exit 1;
}
grep -Fxq "BR2_GLOBAL_PATCH_DIR=\"$WORK/hashes\"" "$OUT/.config" || {
    echo "build.sh: source hashes were not applied to the configuration" >&2; exit 1;
}
cp "$EXT/versions.env" "$OUT/.cmprovision-versions"
cp "$EXT/configs/cmprovision_defconfig" "$OUT/.cmprovision-defconfig"
# a changed linux.fragment or busybox.fragment takes effect only with a new configuration of its package
for pkg in linux-custom busybox; do
    if ls "$OUT"/build/$pkg*/.stamp_configured > /dev/null 2>&1; then
        make -C "$OUT" "${pkg%-custom}-reconfigure"
    fi
done
make -C "$OUT"
python3 "$REPO/tools/utility-os/legal_info.py" prepare "$REPO" "$WORK" "$CACHE"
make -C "$OUT" legal-info > /dev/null
python3 "$REPO/tools/utility-os/legal_info.py" collect "$REPO" "$WORK" "$CACHE"

rsync -a --checksum --delete "$OUT/images/scriptexecute64/" "$REPO/scriptexecute64/"
echo "build.sh: done: $REPO/scriptexecute64 ($(du -sh "$REPO/scriptexecute64" | cut -f1)), legal-info in $OUT/legal-info"
