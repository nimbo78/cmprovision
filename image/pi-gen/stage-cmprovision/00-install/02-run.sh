#!/bin/bash -e

: "${CMP_IMAGE_SOURCE:?Set CMP_IMAGE_SOURCE to the cmprovision source directory}"
python3 "${CMP_IMAGE_SOURCE}/image/audit-rootfs.py" "${ROOTFS_DIR}"
