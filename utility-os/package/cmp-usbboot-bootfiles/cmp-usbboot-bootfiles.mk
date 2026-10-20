################################################################################
#
# cmp-usbboot-bootfiles: bootfiles.bin of raspberrypi/usbboot, for rpiboot -d scriptexecute64/
#
################################################################################

CMP_USBBOOT_BOOTFILES_VERSION = $(UOS_USBBOOT_TAG)
CMP_USBBOOT_BOOTFILES_SITE = https://raw.githubusercontent.com/raspberrypi/usbboot/$(CMP_USBBOOT_BOOTFILES_VERSION)/firmware
CMP_USBBOOT_BOOTFILES_SOURCE = bootfiles.bin
CMP_USBBOOT_BOOTFILES_EXTRA_DOWNLOADS = https://raw.githubusercontent.com/raspberrypi/usbboot/$(CMP_USBBOOT_BOOTFILES_VERSION)/LICENSE
CMP_USBBOOT_BOOTFILES_LICENSE = Apache-2.0 (usbboot); Raspberry Pi bootloader (redistributable binary)
CMP_USBBOOT_BOOTFILES_LICENSE_FILES = LICENSE
CMP_USBBOOT_BOOTFILES_INSTALL_TARGET = NO
CMP_USBBOOT_BOOTFILES_INSTALL_IMAGES = YES

define CMP_USBBOOT_BOOTFILES_EXTRACT_CMDS
	cp $(CMP_USBBOOT_BOOTFILES_DL_DIR)/bootfiles.bin $(CMP_USBBOOT_BOOTFILES_DL_DIR)/LICENSE $(@D)/
endef

define CMP_USBBOOT_BOOTFILES_INSTALL_IMAGES_CMDS
	$(INSTALL) -D -m 0644 $(@D)/bootfiles.bin $(BINARIES_DIR)/bootfiles.bin
endef

$(eval $(generic-package))
