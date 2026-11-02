#!/bin/bash
# Experimental Raspberry Pi OS Lite image build. Use a disposable Debian build machine.
set -euo pipefail

if [ "$#" -ne 3 ] && [ "$#" -ne 5 ]; then
    echo 'usage: sudo image/build.sh EMPTY_WORK_DIR CMPROVISION_DEB EXPECTED_DEB_SHA256 [FPMS_DEB FPMS_SHA256]' >&2
    exit 2
fi
if [ "$(id -u)" -ne 0 ]; then
    echo 'The image builder must run as root on a disposable build machine.' >&2
    exit 1
fi
SOURCE=$(cd "$(dirname "$0")/.." && pwd -P)
WORK=$(realpath -m "$1")
DEB=$(realpath "$2")
EXPECTED=$3
FPMS=''
FPMS_SHA=''
if [ "$#" -eq 5 ]; then
    FPMS=$(realpath "$4")
    FPMS_SHA=$5
fi
PIN=c851f00178c64b87a86c06c2be216bd89d62e55b

if [[ ! "$EXPECTED" =~ ^[0-9a-f]{64}$ ]] || [ "$(sha256sum "$DEB" | cut -d ' ' -f 1)" != "$EXPECTED" ]; then
    echo 'The package checksum does not match.' >&2
    exit 1
fi
if [ -n "$FPMS" ] && { [[ ! "$FPMS_SHA" =~ ^[0-9a-f]{64}$ ]] || [ "$(sha256sum "$FPMS" | cut -d ' ' -f 1)" != "$FPMS_SHA" ]; }; then
    echo 'The fpms-lite package checksum does not match.' >&2
    exit 1
fi
if [[ "$WORK" == *' '* ]] || [ "$WORK" = / ] || [ "$WORK" = "$SOURCE" ] || [[ "$SOURCE" == "$WORK/"* ]]; then
    echo 'Choose an empty build directory outside the source, without spaces.' >&2
    exit 1
fi
mkdir -p "$WORK"
if [ -n "$(find "$WORK" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
    echo 'The image build directory must be empty.' >&2
    exit 1
fi
git clone --filter=blob:none --branch arm64 https://github.com/RPi-Distro/pi-gen.git "$WORK/pi-gen"
git -C "$WORK/pi-gen" checkout --detach "$PIN"
cp -a "$SOURCE/image/pi-gen/stage-cmprovision" "$WORK/pi-gen/stage-cmprovision"
chmod +x "$WORK/pi-gen/stage-cmprovision/prerun.sh" "$WORK/pi-gen/stage-cmprovision/00-install/00-run.sh" \
    "$WORK/pi-gen/stage-cmprovision/00-install/02-run.sh"
touch "$WORK/pi-gen/stage2/SKIP_IMAGES" "$WORK/pi-gen/stage2/04-cloud-init/SKIP"
cat > "$WORK/pi-gen/config-cmprovision" <<'CONFIG'
IMG_NAME='cmprovision-lite'
RELEASE='trixie'
STAGE_LIST='stage0 stage1 stage2 stage-cmprovision'
ENABLE_CLOUD_INIT=0
ENABLE_SSH=0
FIRST_USER_NAME='pi'
FIRST_USER_PASS=''
DISABLE_FIRST_BOOT_USER_RENAME=0
PASSWORDLESS_SUDO=0
PUBKEY_SSH_FIRST_USER=''
PUBKEY_ONLY_SSH=0
TARGET_HOSTNAME='cmprovision'
DEPLOY_COMPRESSION='xz'
WORK_DIR="${BASE_DIR}/work/cmprovision-lite"
DEPLOY_DIR="${BASE_DIR}/deploy"
CONFIG
cd "$WORK/pi-gen"
env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LC_ALL=C.UTF-8 \
    CMP_IMAGE_SOURCE="$SOURCE" CMP_IMAGE_DEB="$DEB" CMP_IMAGE_DEB_SHA256="$EXPECTED" \
    CMP_IMAGE_FPMS="$FPMS" CMP_IMAGE_FPMS_SHA256="$FPMS_SHA" \
    ./build.sh -c config-cmprovision
(
    cd deploy
    sha256sum ./*.img.xz > SHA256SUMS
    printf 'pi-gen %s\ncmprovision package sha256 %s\n' "$PIN" "$EXPECTED" > cmprovision-build-manifest.txt
    if [ -n "$FPMS" ]; then printf 'fpms-lite package sha256 %s\n' "$FPMS_SHA" >> cmprovision-build-manifest.txt; fi
)
(cd "$SOURCE" && find image -type f ! -path '*/__pycache__/*' ! -name '*.pyc' -print0 | \
    LC_ALL=C sort -z | xargs -0 sha256sum) > "$WORK/pi-gen/deploy/cmprovision-image-source.sha256"
echo "Experimental image and checksums: $WORK/pi-gen/deploy"
