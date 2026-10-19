################################################################################
#
# cmp-rpi-boot-files: the Pi 4 boot files of a raspberrypi/firmware release, for scriptexecute64/
#
################################################################################

CMP_RPI_BOOT_FILES_VERSION = $(UOS_FIRMWARE_TAG)
CMP_RPI_BOOT_FILES_SITE = https://raw.githubusercontent.com/raspberrypi/firmware/$(CMP_RPI_BOOT_FILES_VERSION)/boot
CMP_RPI_BOOT_FILES_SOURCE = start4.elf
CMP_RPI_BOOT_FILES_EXTRA_DOWNLOADS = fixup4.dat LICENCE.broadcom
CMP_RPI_BOOT_FILES_LICENSE = Raspberry Pi firmware (redistributable binary)
CMP_RPI_BOOT_FILES_LICENSE_FILES = LICENCE.broadcom
CMP_RPI_BOOT_FILES_INSTALL_TARGET = NO
CMP_RPI_BOOT_FILES_INSTALL_IMAGES = YES

define CMP_RPI_BOOT_FILES_EXTRACT_CMDS
	cp $(CMP_RPI_BOOT_FILES_DL_DIR)/start4.elf $(CMP_RPI_BOOT_FILES_DL_DIR)/fixup4.dat \
		$(CMP_RPI_BOOT_FILES_DL_DIR)/LICENCE.broadcom $(@D)/
endef

define CMP_RPI_BOOT_FILES_INSTALL_IMAGES_CMDS
	$(INSTALL) -D -m 0644 $(@D)/start4.elf $(BINARIES_DIR)/rpi-boot/start4.elf
	$(INSTALL) -D -m 0644 $(@D)/fixup4.dat $(BINARIES_DIR)/rpi-boot/fixup4.dat
endef

$(eval $(generic-package))
