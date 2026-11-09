#!/usr/bin/env python3
"""Root-only preparation of a dedicated Debian tree. Never installs the app on the host."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess

ROOT = Path('/var/tmp/cmprovision-updates-bench')


def prepare(package):
    if os.geteuid() != 0: raise RuntimeError('This disposable-container check requires root.')
    root = ROOT
    if root.is_symlink() or root.resolve() != root: raise RuntimeError('Unsafe acceptance tree.')
    if not (root/'etc/debian_version').exists():
        subprocess.run(['debootstrap', '--variant=minbase', '--include=systemd-sysv,dbus,ca-certificates',
                        'trixie', str(root), 'http://deb.debian.org/debian'], check=True)
    if (root/'var/lib/cmprovision').exists(): raise RuntimeError('Acceptance tree already has an application; preserve it for inspection.')
    (root/'usr/sbin/policy-rc.d').write_text('#!/bin/sh\nexit 101\n')
    (root/'usr/sbin/policy-rc.d').chmod(0o755)
    (root/'etc/apt/sources.list').write_text('deb http://deb.debian.org/debian trixie main\ndeb http://deb.debian.org/debian trixie-updates main\ndeb http://security.debian.org/debian-security trixie-security main\n')
    shutil.copyfile('/etc/resolv.conf', root/'etc/resolv.conf')
    fixture = root/'fixture'
    fixture.mkdir(exist_ok=True)
    shutil.copyfile(package, fixture/package.name)
    shutil.copyfile(Path(__file__).with_name('run_acceptance.py'), fixture/'run_acceptance.py')
    shim = fixture/'usbboot-shim'
    (shim/'DEBIAN').mkdir(parents=True, exist_ok=True)
    (shim/'DEBIAN/control').write_text('Package: cmprovision-acceptance-usbboot\nVersion: 1\nArchitecture: all\nProvides: rpiboot (= 20261002)\nMaintainer: Test <test@example.test>\nDescription: No hardware USB boot in the isolated updater acceptance container\n')
    (shim/'usr/bin').mkdir(parents=True, exist_ok=True)
    (shim/'usr/bin/rpiboot').write_text('#!/bin/sh\nexit 0\n')
    (shim/'usr/bin/rpiboot').chmod(0o755)
    subprocess.run(['dpkg-deb', '--build', str(shim), str(fixture/'usbboot-shim.deb')], check=True)
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        path = root/f'etc/systemd/system/{name}.service'
        if not path.is_symlink(): path.symlink_to('/dev/null')
    environment = dict(os.environ, DEBIAN_FRONTEND='noninteractive', LC_ALL='C')
    def command(*args): subprocess.run(['chroot', str(root), *args], env=environment, check=True)
    command('/usr/bin/apt-get', 'update')
    command('/usr/bin/apt-get', '-y', '--no-install-recommends', 'install', 'network-manager',
        '/fixture/usbboot-shim.deb', '/fixture/'+package.name)
    (root/'etc/hostname').write_text('cmprovision-update-bench\n')
    (root/'usr/sbin/policy-rc.d').unlink()
    print('Prepared isolated package acceptance; boot this tree with systemd-nspawn --private-network.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('package', type=Path)
    prepare(parser.parse_args().package)
