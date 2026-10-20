# cmprovision utility OS (docs/utility-os.md): the pins (UOS_*) for the packages of this tree, then the packages
include $(BR2_EXTERNAL_CMPROVISION_PATH)/versions.env
include $(sort $(wildcard $(BR2_EXTERNAL_CMPROVISION_PATH)/package/*/*.mk))

# Exercise real EEPROM writes and no-ops without hardware in the QEMU acceptance test.
FLASHROM_PROGRAMMERS += dummy

# legal-info checks the kernel's license files against buildroot's linux.hash, which has the GPL-2.0 text of Linux
# before 6.17; the same text under another name is checked against nothing (BR2_LINUX_KERNEL_LICENSE_FILES names it)
define CMPROVISION_LINUX_GPL_TEXT
	cp $(@D)/LICENSES/preferred/GPL-2.0 $(@D)/LICENSES/preferred/GPL-2.0.cmprovision
endef
LINUX_POST_EXTRACT_HOOKS += CMPROVISION_LINUX_GPL_TEXT
