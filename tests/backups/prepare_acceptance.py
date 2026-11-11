#!/usr/bin/env python3
"""Prepare only the dedicated disposable Debian backup station, never the host."""
import argparse
import importlib.util
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path('/var/tmp/cmprovision-backups-bench')


def prepare(package):
    spec = importlib.util.spec_from_file_location('update_acceptance_preparer', Path(__file__).parents[1]/'updates/prepare_acceptance.py')
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    module.ROOT = ROOT
    module.prepare(package)
    (ROOT/'usr/sbin/policy-rc.d').write_text('#!/bin/sh\nexit 101\n'); (ROOT/'usr/sbin/policy-rc.d').chmod(0o755)
    try:
        subprocess.run(['chroot', str(ROOT), '/usr/bin/apt-get', '-y', '--no-install-recommends', 'install', 'openssh-server'], check=True)
    finally: (ROOT/'usr/sbin/policy-rc.d').unlink()
    # Model the real portable FPMS package's optional Installed-Size omission, without
    # GPIO dependencies or starting a physical display on the disposable amd64 station.
    version = subprocess.check_output(['dpkg-deb', '-f', str(package), 'Version'], text=True).strip()
    with tempfile.TemporaryDirectory(prefix='backup-fpms-fixture-') as directory:
        staging = Path(directory)
        (staging/'DEBIAN').mkdir()
        (staging/'DEBIAN/control').write_text('Package: fpms-lite\nVersion: '+version+'\nArchitecture: all\n'
            'Maintainer: Disposable acceptance <fixture@example.test>\nDescription: Headerless-size backup fixture\n')
        path = staging/'usr/share/cmprovision-backup-acceptance/fpms-fixture'
        path.parent.mkdir(parents=True); path.write_text('No GPIO or display implementation; package capture test only.\n')
        output = ROOT/'fixture/fpms-fixture.deb'
        subprocess.run(['dpkg-deb', '--root-owner-group', '--build', str(staging), str(output)], check=True)
        subprocess.run(['chroot', str(ROOT), '/usr/bin/dpkg', '-i', '/fixture/fpms-fixture.deb'], check=True)
    (ROOT/'etc/hostname').write_text('cmprovision-backups-bench\n')
    shutil.copyfile(Path(__file__).with_name('run_acceptance.py'), ROOT/'fixture/backups-acceptance.py')
    shutil.copyfile(Path(__file__).with_name('run_failure_acceptance.py'), ROOT/'fixture/backups-failure-acceptance.py')
    (ROOT/'fixture/backup-acceptance-only').write_text('Disposable backup acceptance; no hardware attached.\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument('package', type=Path)
    prepare(parser.parse_args().package)
