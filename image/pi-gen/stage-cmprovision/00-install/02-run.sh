#!/bin/bash -e

: "${CMP_IMAGE_SOURCE:?Set CMP_IMAGE_SOURCE to the cmprovision source directory}"
if [ "${CMP_IMAGE_DISPLAY:-default}" != default ]; then
    python3 "${CMP_IMAGE_SOURCE}/image/fpms/prepare-display.py" "${ROOTFS_DIR}" "$CMP_IMAGE_DISPLAY"
fi
python3 "${CMP_IMAGE_SOURCE}/image/audit-rootfs.py" "${ROOTFS_DIR}"
