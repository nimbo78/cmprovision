#!/bin/sh
# buildroot post-image of the cmprovision utility OS ($1: the images directory): the TFTP directory in $1/scriptexecute64,
# with its MANIFEST; tools/utility-os/build.sh copies it into the repository
set -eu
IMAGES=$1
EXT=$BR2_EXTERNAL_CMPROVISION_PATH
. "$EXT/versions.env"
OUT=$IMAGES/scriptexecute64
rm -rf "$OUT"
mkdir -p "$OUT/overlays"
cp "$IMAGES/Image.gz" "$OUT/kernel8.img"
cp "$IMAGES/rootfs.cpio.xz" "$OUT/scriptexecute.img"
for dtb in bcm2711-rpi-cm4 bcm2711-rpi-4-b bcm2711-rpi-400; do
    cp "$IMAGES/$dtb.dtb" "$OUT/"
done
for overlay in dwc2 spi-gpio40-45; do
    if [ -f "$IMAGES/$overlay.dtbo" ]; then
        cp "$IMAGES/$overlay.dtbo" "$OUT/overlays/"
    else
        # the kernel's own "make all" builds every overlay of the tree
        cp "$BUILD_DIR/linux-custom/arch/arm64/boot/dts/overlays/$overlay.dtbo" "$OUT/overlays/"
    fi
done
cp "$IMAGES/rpi-boot/start4.elf" "$IMAGES/rpi-boot/fixup4.dat" "$IMAGES/bootfiles.bin" "$OUT/"
cp "$EXT/board/cmprovision/config.txt" "$OUT/config.txt"
sed "s/@UOS_VERSION@/$UOS_VERSION/" "$EXT/board/cmprovision/cmdline.txt" > "$OUT/cmdline.txt"
python3 "$EXT/../tools/utility-os/usb_image.py" "$OUT"
sh "$EXT/../tools/utility-os/manifest.sh" "$OUT" "version=$UOS_VERSION" bits=64 \
    "kernel=Linux $UOS_LINUX_VERSION (raspberrypi/linux $UOS_LINUX_TAG)" "built=$(date -u +%Y-%m-%d)" \
    "buildroot=$UOS_BUILDROOT_VERSION" "firmware=$UOS_FIRMWARE_TAG" "rpi_utils=$UOS_RPI_UTILS_COMMIT" "usbboot=$UOS_USBBOOT_TAG"
echo "post-image: $OUT"
