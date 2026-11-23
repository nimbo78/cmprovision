#!/bin/bash -e

export DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8
# pi-gen mounts this temporary tmpfs with default 1777 permissions. Match the
# production /run before package scripts initialize privileged controllers.
chmod 0755 /run
apt-get -o Acquire::Retries=3 update
apt-get -o Acquire::Retries=3 install --no-install-recommends -y network-manager wpasupplicant iw nftables python3-kms++
if [ -f /opt/cmprovision-image-fpms.deb ]; then
    apt-get -o Acquire::Retries=3 install --no-install-recommends -y firmware-iwlwifi lldpd /opt/cmprovision-image.deb /opt/cmprovision-image-fpms.deb
    systemctl disable fpms-lite.service
    systemctl enable cmprovision-image-fpms.path cmprovision-image-fpms.timer
else
    apt-get -o Acquire::Retries=3 install --no-install-recommends -y firmware-iwlwifi lldpd /opt/cmprovision-image.deb
fi
# postinst can only warn about initialization while dpkg is still configuring dependencies.
# Retry after apt completes, and refuse to export an image with a missing boot recovery program.
python3 -B -I /usr/lib/cmprovision/station-updates.py --initialize >/dev/null
# Source packages from earlier stable tags can be used in custom image builds.
if [ -x /usr/sbin/cmprovision-stand-ntp ]; then
    apt-get -o Acquire::Retries=3 install --no-install-recommends -y chrony
    /usr/sbin/cmprovision-stand-ntp prepare-image
    systemctl enable chrony.service
fi
systemctl enable lldpd.service
rm -f /opt/cmprovision-image.deb
rm -f /opt/cmprovision-image-fpms.deb

# pi-gen's base stage assigns a build-time root password. The image must never ship it.
passwd -l root

# postinst creates a key during image assembly. Remove it before export; the first-boot
# unit creates a new one before nginx or a queue worker can start on an individual station.
runuser -u www-data -- python3 - <<'PY'
import os
from pathlib import Path
path = Path('/var/lib/cmprovision/.env')
lines = path.read_text().splitlines()
if sum(line.startswith('APP_KEY=') for line in lines) != 1:
    raise SystemExit('The image application key could not be sanitized safely.')
lines = ['APP_KEY=' if line.startswith('APP_KEY=') else line for line in lines]
with path.open('w') as out:
    out.write('\n'.join(lines) + '\n')
    out.flush()
    os.fsync(out.fileno())
PY
rm -f /var/lib/cmprovision/bootstrap/cache/config.php
# This build root may be resumed. Never export an earlier run's one-use code.
rm -rf -- /var/lib/cmprovision-bootstrap /run/cmprovision-bootstrap
rm -f -- /var/lib/cmprovision-image/web-setup.json
if [ -e /var/lib/cmprovision-image/firstboot-ready ]; then
    echo 'The image first-boot marker must not be present in an exported image.' >&2
    exit 1
fi
rm -f /etc/ssh/ssh_host_*_key /etc/ssh/ssh_host_*_key.pub
systemctl enable cmprovision-image-firstboot.service
