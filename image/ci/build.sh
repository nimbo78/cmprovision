#!/bin/bash
# Run only in the disposable privileged Debian builder, without release credentials.
set -euo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin LC_ALL=C.UTF-8 PYTHONDONTWRITEBYTECODE=1
: "${RELEASE_VERSION:?}" "${SOURCE_SHA:?}" "${PACKAGE_SHA:?}" "${FPMS_SHA:?}"
: "${BUILD_CONTROL_SHA:?}" "${BUILD_RUN_URL:?}"
[[ "$RELEASE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]
[[ "$SOURCE_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$BUILD_CONTROL_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$PACKAGE_SHA" =~ ^[0-9a-f]{64}$ && "$FPMS_SHA" =~ ^[0-9a-f]{64}$ ]]
test "$(id -u)" = 0
test "$(dpkg --print-architecture)" = arm64
test -z "${GH_TOKEN:-}${GITHUB_TOKEN:-}"
OUT=/output/assets
AUDIT=/output/audit
CMP=$OUT/cmprovision4_${RELEASE_VERSION}_all.deb
FPMS=$OUT/fpms-lite_${RELEASE_VERSION}_all.deb
printf '%s  %s\n%s  %s\n' "$PACKAGE_SHA" "$CMP" "$FPMS_SHA" "$FPMS" | sha256sum -c -
test "$(dpkg-deb -f "$CMP" Version)" = "$RELEASE_VERSION"
test "$(dpkg-deb -f "$FPMS" Version)" = "$RELEASE_VERSION"
mkdir -p "$AUDIT" /work/package
dpkg-deb -x "$CMP" /work/package
CMP_IMAGE_PACKAGE="$CMP" CMP_IMAGE_LARAVEL_SOURCE=/work/package/var/lib/cmprovision \
    python3 -m unittest discover -s /source/tests/image -p 'test_*.py' -v \
    > "$AUDIT/package-firstboot-tests.log" 2>&1
cat "$AUDIT/package-firstboot-tests.log"
# The two opt-in systemd cases run separately on the disposable hosted runner.
bash /source/image/build.sh /work/pi-image "$CMP" "$PACKAGE_SHA" "$FPMS" "$FPMS_SHA" \
    2>&1 | tee "$AUDIT/pi-gen.log"
PI=/work/pi-image/pi-gen
set -- "$PI/deploy"/*.img.xz
test "$#" = 1 && test -f "$1"
IMAGE=$OUT/cmprovision-lite-${RELEASE_VERSION}-arm64.img.xz
cp --reflink=auto "$1" "$IMAGE"
cp "$PI/deploy/cmprovision-build-manifest.txt" "$PI/deploy/cmprovision-image-source.sha256" "$OUT/"
xz -t "$IMAGE"
xz -dc "$IMAGE" > /work/audit.img
RAW_SHA=$(sha256sum /work/audit.img | cut -d ' ' -f 1)
printf '%s  -\n' "$RAW_SHA" > "$OUT/image-uncompressed.sha256"
LOOP=$(losetup --find --show --read-only --partscan /work/audit.img)
mkdir -p /work/audit-root
cleanup() {
    umount /work/audit-root/boot/firmware 2>/dev/null || true
    umount /work/audit-root 2>/dev/null || true
    losetup -d "$LOOP"
}
trap cleanup EXIT
for attempt in {1..40}; do
    if [ -b "${LOOP}p1" ] && [ -b "${LOOP}p2" ]; then break; fi
    sleep 0.25
done
test -b "${LOOP}p1" && test -b "${LOOP}p2"
fsck.vfat -n "${LOOP}p1" > "$AUDIT/fat-check.log" 2>&1
e2fsck -fn "${LOOP}p2" > "$AUDIT/ext4-check.log" 2>&1
mount -o ro,noload "${LOOP}p2" /work/audit-root
mount -o ro "${LOOP}p1" /work/audit-root/boot/firmware
cmp /work/audit-root/usr/bin/sync "$PI/work/cmprovision-lite/stage-cmprovision/rootfs/usr/bin/sync"
cmp /work/audit-root/usr/bin/rename-user "$PI/work/cmprovision-lite/stage-cmprovision/rootfs/usr/bin/rename-user"
python3 /source/image/audit-rootfs.py /work/audit-root > "$AUDIT/rootfs-audit.log"
dpkg-query --admindir=/work/audit-root/var/lib/dpkg -W \
    -f='${binary:Package}\t${Version}\t${Architecture}\n' | LC_ALL=C sort \
    > "$OUT/cmprovision-lite-${RELEASE_VERSION}-packages.txt"
test "$(dpkg-query --admindir=/work/audit-root/var/lib/dpkg -W -f='${Version}' cmprovision4)" = "$RELEASE_VERSION"
test "$(dpkg-query --admindir=/work/audit-root/var/lib/dpkg -W -f='${Version}' fpms-lite)" = "$RELEASE_VERSION"
printf '\ncmprovision source %s (v%s)\nNative ARM64 GitHub Actions build.\nFactory-rootfs, compressed/raw hashes, FAT and ext4 audit passed.\n' \
    "$SOURCE_SHA" "$RELEASE_VERSION" >> "$OUT/cmprovision-build-manifest.txt"
printf 'Build control %s\nBuild run %s\n' "$BUILD_CONTROL_SHA" "$BUILD_RUN_URL" >> "$OUT/cmprovision-build-manifest.txt"
cd "$OUT"
sha256sum ./*.deb ./*.img.xz cmprovision-build-manifest.txt \
    "cmprovision-lite-${RELEASE_VERSION}-packages.txt" cmprovision-image-source.sha256 \
    fpms-upstream.txt fpms-patch.sha256 image-uncompressed.sha256 > SHA256SUMS
sha256sum -c SHA256SUMS
cat "$AUDIT/fat-check.log" "$AUDIT/ext4-check.log" "$AUDIT/rootfs-audit.log"
printf 'Native image build and read-only audit complete.\n'
