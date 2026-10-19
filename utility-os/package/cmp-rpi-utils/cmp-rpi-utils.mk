################################################################################
#
# cmp-rpi-utils: the tools of raspberrypi/utils the utility OS ships
#
################################################################################

CMP_RPI_UTILS_VERSION = $(UOS_RPI_UTILS_COMMIT)
CMP_RPI_UTILS_SITE = $(call github,raspberrypi,utils,$(CMP_RPI_UTILS_VERSION))
CMP_RPI_UTILS_LICENSE = BSD-3-Clause
CMP_RPI_UTILS_LICENSE_FILES = LICENCE
CMP_RPI_UTILS_DEPENDENCIES = dtc
CMP_RPI_UTILS_SUBDIR = cmprovision
CMP_RPI_UTILS_CONF_OPTS = -DBUILD_SHARED_LIBS=OFF

# the project's own top level builds every tool, and rpi-gpu-usage needs ncurses: ours builds the four the OS ships
define CMP_RPI_UTILS_TOP_LEVEL
	$(INSTALL) -D -m 0644 $(CMP_RPI_UTILS_PKGDIR)/CMakeLists.txt $(@D)/cmprovision/CMakeLists.txt
endef
CMP_RPI_UTILS_POST_EXTRACT_HOOKS += CMP_RPI_UTILS_TOP_LEVEL

$(eval $(cmake-package))
