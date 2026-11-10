#!/usr/bin/env python3
"""Real account/SSH/NM/SQLite/package recovery proof, only inside an isolated Debian station."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

sys.path.insert(0, '/usr/lib/cmprovision')
from backup_archive import inspect_archive
from backup_restore import Restorer
from backup_restore_system import LinuxRestoreSystem
from backup_store import BackupStore
from backup_worker import Worker


def command(*args, **options): return subprocess.check_output(args, text=True, **options).strip()


def run():
    if (os.geteuid() != 0 or Path('/etc/hostname').read_text().strip() != 'cmprovision-backups-bench'
            or not Path('/fixture/backup-acceptance-only').is_file() or not Path('/run/systemd/container').is_file()):
        raise RuntimeError('Refusing to run outside the dedicated disposable backup container.')
    command('systemctl', 'start', 'nginx', 'php8.4-fpm', 'cmprovision-network-init', 'NetworkManager')
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert subprocess.run(['systemctl', 'is-enabled', name], capture_output=True, text=True).stdout.strip() == 'masked'
    fixture = Path(tempfile.mkdtemp(prefix='backup-run-', dir='/fixture'))
    command('ip', 'link', 'add', 'bench0', 'type', 'dummy')
    command('ip', 'link', 'set', 'bench0', 'address', '02:00:00:00:01:01')
    command('groupadd', '--gid', '1901', 'backupoperator')
    command('useradd', '--uid', '1901', '--gid', '1901', '--groups', 'sudo', '--create-home', '--shell', '/bin/bash', 'backupoperator')
    password_hash = command('openssl', 'passwd', '-6', '-stdin', input='disposable fixture password\n')
    subprocess.run(['chpasswd', '-e'], input='backupoperator:'+password_hash+'\n', text=True, check=True)
    key = fixture/'key'; command('ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key))
    ssh = Path('/home/backupoperator/.ssh'); ssh.mkdir(mode=0o700); os.chown(ssh, 1901, 1901)
    authorized = ssh/'authorized_keys'; authorized.write_bytes(key.with_suffix('.pub').read_bytes()); authorized.chmod(0o600); os.chown(authorized, 1901, 1901)
    profile = Path('/etc/NetworkManager/system-connections/backup-fixture.nmconnection')
    profile.write_text('[connection]\nid=backup-fixture\nuuid=7162dccc-d186-486c-a354-9b96768e3a30\ntype=ethernet\ninterface-name=bench0\nautoconnect=false\n[ethernet]\nmac-address=02:00:00:00:01:01\n[ipv4]\nmethod=disabled\n[ipv6]\nmethod=disabled\n'); profile.chmod(0o600)
    command('nmcli', 'connection', 'reload')
    root_hash = hashlib.sha256(next(line for line in Path('/etc/shadow').read_text().splitlines() if line.startswith('root:')).encode()).hexdigest()
    broker = subprocess.run(['runuser', '-u', 'www-data', '--', 'sudo', '-n', '/usr/lib/cmprovision/station-backups'], input='{"action":"status"}', text=True, capture_output=True, check=True)
    assert json.loads(broker.stdout)['result']['schema'] == 1
    assert subprocess.run(['runuser', '-u', 'www-data', '--', 'sudo', '-n', '/usr/lib/cmprovision/station-backups', '--recover'], capture_output=True).returncode != 0
    store = BackupStore(); system = LinuxRestoreSystem(store)
    store.configure_password('disposable full archive master password')
    original_id = store.configuration()['station_id']
    job, _ = store.begin('a'*64, 'download', {'images': False, 'packages': False})
    Worker(store, system).run(job['id'])
    result = store.operation(job['id'])
    assert result['state'] == 'ready', result['message']
    archive = store.download_file(job['id'])
    with inspect_archive(archive, 'disposable full archive master password', store.private) as verified:
        assert 'system/accounts.json' in {item['name'] for item in verified.manifest['members']}
        assert all(item['provenance'] in ('local-snapshot', 'signed-release') for item in verified.manifest['software'])
    stale = Path('/var/lib/cmprovision/storage/app/old-after-backup'); stale.write_text('must disappear'); os.chown(stale, 33, 33)
    command('ip', 'link', 'set', 'bench0', 'address', '02:00:00:00:01:02')
    command('usermod', '--lock', 'backupoperator'); authorized.write_text('')
    plan = Restorer(store, system).run(archive, 'disposable full archive master password', apply=True,
        groups=['network', 'display', 'web'], selected_accounts=['backupoperator'], interfaces={'bench0': 'bench0'})
    assert plan['preserved'] == ['ssh', 'host', 'role']
    assert not stale.exists()
    assert authorized.read_bytes() == key.with_suffix('.pub').read_bytes()
    assert next(line.split(':')[1] for line in Path('/etc/shadow').read_text().splitlines() if line.startswith('backupoperator:')) == password_hash
    assert hashlib.sha256(next(line for line in Path('/etc/shadow').read_text().splitlines() if line.startswith('root:')).encode()).hexdigest() == root_hash
    assert command('nmcli', '-g', '802-3-ethernet.mac-address', 'connection', 'show', 'backup-fixture').lower() == '02:00:00:00:01:02'
    assert store.configuration()['station_id'] != original_id and store.configuration()['master_password'] is None
    assert store.configuration()['schedule']['enabled'] is False and store.configuration()['pending'] is None
    assert command('runuser', '-u', 'www-data', '--', 'sqlite3', '/var/lib/cmprovision/database/database.sqlite', 'PRAGMA quick_check;') == 'ok'
    assert command('sshd', '-t') == ''
    for name in ('cmprovision-dnsmasq', 'cmprovision-rpiboot'):
        assert subprocess.run(['systemctl', 'is-enabled', name], capture_output=True, text=True).stdout.strip() == 'masked'
    print('PASS: real full backup/restore, package capture/reuse, account hash/key, emergency root, NM MAC mapping, SQLite/www-data, disabled schedule/new UUID and masked station role.')


if __name__ == '__main__': run()
