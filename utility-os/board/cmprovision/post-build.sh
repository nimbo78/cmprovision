#!/bin/sh
# buildroot post-build of the cmprovision utility OS ($1: the target directory)
set -eu
TARGET=$1
. "$BR2_EXTERNAL_CMPROVISION_PATH/versions.env"
# no kernel modules: the OS needs only what linux.fragment builds in (depmod ran already and skips a missing directory)
rm -rf "$TARGET/lib/modules"
# the legacy OS started no cron daemon: the applet stays, the script buildroot adds for it goes
rm -f "$TARGET/etc/init.d/S50crond"
# which OS this is, for the console and the checks
printf '%s\n' "$UOS_VERSION" > "$TARGET/etc/cmprovision-uos"
printf 'cmprovision utility OS %s\n\n' "$UOS_VERSION" > "$TARGET/etc/issue"
