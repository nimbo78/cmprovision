#!/bin/sh
# The OS check (2.6.0): puts it into the written system. Mounts its root file system ($PART2), adds the service with what
# it runs (the module's script wrote them to /tmp/oscheck) and enables it for the next boot. Tests point it elsewhere
# with OSCHECK_SRC, OSCHECK_ROOT and OSCHECK_LEDPATTERN.
set -e
SRC=${OSCHECK_SRC:-/tmp/oscheck}
ROOT=${OSCHECK_ROOT:-/mnt/cmprovision-root}
LEDPATTERN_FILE=${OSCHECK_LEDPATTERN:-/tmp/ledpattern}
mkdir -p "$ROOT"
mount -t ext4 "$PART2" "$ROOT"
trap 'umount "$ROOT"' EXIT
LIB=$ROOT/usr/local/lib/cmprovision-firstboot
mkdir -p "$LIB/scripts" "$ROOT/etc/systemd/system/multi-user.target.wants"
cp "$SRC/run" "$LIB/run"
for f in "$SRC"/scripts/*.sh; do
    if [ -e "$f" ]; then cp "$f" "$LIB/scripts/"; fi
done
if [ -x "$LEDPATTERN_FILE" ]; then cp "$LEDPATTERN_FILE" "$LIB/ledpattern"; fi
cp "$SRC/cmprovision-firstboot.service" "$ROOT/etc/systemd/system/cmprovision-firstboot.service"
ln -sf /etc/systemd/system/cmprovision-firstboot.service "$ROOT/etc/systemd/system/multi-user.target.wants/cmprovision-firstboot.service"
sync
echo "The OS check is in $PART2: it runs on the first boot"
