#!/bin/bash -e

export DEBIAN_FRONTEND=noninteractive LANG=C.UTF-8 LC_ALL=C.UTF-8
apt-get -o Acquire::Retries=3 update
if [ -f /opt/cmprovision-image-fpms.deb ]; then
    apt-get -o Acquire::Retries=3 install --no-install-recommends -y /opt/cmprovision-image.deb /opt/cmprovision-image-fpms.deb
    systemctl disable fpms-lite.service
    systemctl enable cmprovision-image-fpms.path cmprovision-image-fpms.timer
else
    apt-get -o Acquire::Retries=3 install --no-install-recommends -y /opt/cmprovision-image.deb
fi
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
if [ -e /var/lib/cmprovision-image/firstboot-ready ]; then
    echo 'The image first-boot marker must not be present in an exported image.' >&2
    exit 1
fi
rm -f /etc/ssh/ssh_host_*_key /etc/ssh/ssh_host_*_key.pub
systemctl enable cmprovision-image-firstboot.service
