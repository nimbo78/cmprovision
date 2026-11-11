#!/usr/bin/env python3
"""Prepare only the dedicated disposable Debian backup station, never the host."""
import argparse
import importlib.util
from pathlib import Path
import shutil
import subprocess

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
    (ROOT/'etc/hostname').write_text('cmprovision-backups-bench\n')
    shutil.copyfile(Path(__file__).with_name('run_acceptance.py'), ROOT/'fixture/backups-acceptance.py')
    shutil.copyfile(Path(__file__).with_name('run_failure_acceptance.py'), ROOT/'fixture/backups-failure-acceptance.py')
    (ROOT/'fixture/backup-acceptance-only').write_text('Disposable backup acceptance; no hardware attached.\n')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__); parser.add_argument('package', type=Path)
    prepare(parser.parse_args().package)
