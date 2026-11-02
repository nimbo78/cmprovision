#!/bin/bash -e

: "${CMP_IMAGE_SOURCE:?Set CMP_IMAGE_SOURCE to the cmprovision source directory}"
: "${CMP_IMAGE_DEB:?Set CMP_IMAGE_DEB to the built cmprovision4 package}"
: "${CMP_IMAGE_DEB_SHA256:?Set CMP_IMAGE_DEB_SHA256 to its expected SHA-256}"

actual=$(sha256sum "${CMP_IMAGE_DEB}" | cut -d ' ' -f 1)
if [ "$actual" != "$CMP_IMAGE_DEB_SHA256" ]; then
    echo 'The cmprovision package does not match its expected checksum.' >&2
    exit 1
fi
if [ "$(dpkg-deb -f "${CMP_IMAGE_DEB}" Package)" != cmprovision4 ]; then
    echo 'The image input is not a cmprovision4 package.' >&2
    exit 1
fi
case "$(dpkg-deb -f "${CMP_IMAGE_DEB}" Architecture)" in
    all|arm64) ;;
    *) echo 'The cmprovision package is not compatible with arm64.' >&2; exit 1 ;;
esac
# pi-gen mounts a fresh tmpfs on /tmp inside the chroot; keep the package outside it.
install -D -m 0644 "${CMP_IMAGE_DEB}" "${ROOTFS_DIR}/opt/cmprovision-image.deb"
install -D -m 0755 "${CMP_IMAGE_SOURCE}/image/firstboot/cmprovision-image-firstboot.py" "${ROOTFS_DIR}/usr/lib/cmprovision/image-firstboot"
install -D -m 0644 "${CMP_IMAGE_SOURCE}/image/firstboot/cmprovision-image-firstboot.service" "${ROOTFS_DIR}/etc/systemd/system/cmprovision-image-firstboot.service"
for unit in ssh nginx cmprovision-queue cmprovision-queue-images cmprovision-wallboard-data cmprovision-fpms-data; do
    install -D -m 0644 "${CMP_IMAGE_SOURCE}/image/firstboot/image-firstboot.conf" "${ROOTFS_DIR}/etc/systemd/system/${unit}.service.d/image-firstboot.conf"
done
# Neither service may start on an unconfigured network, including while the package is installed.
ln -sfn /dev/null "${ROOTFS_DIR}/etc/systemd/system/cmprovision-dnsmasq.service"
ln -sfn /dev/null "${ROOTFS_DIR}/etc/systemd/system/cmprovision-rpiboot.service"
