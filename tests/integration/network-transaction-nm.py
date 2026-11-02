#!/usr/bin/env python3
"""Run the transaction coordinator against real NM, confined to hybrid-vlan.sh's namespace.

The backend uses real durable state and an explicit recovery call: it is a laboratory backend,
not a production implementation of a systemd watchdog or full boot recovery.
"""
import importlib.machinery
import glob
import json
import os
import re
import subprocess
import sys
import time

profile = importlib.machinery.SourceFileLoader('network_profile', sys.argv[1]).load_module()
transaction = importlib.machinery.SourceFileLoader('network_transaction', sys.argv[2]).load_module()
store_module = importlib.machinery.SourceFileLoader('network_store', sys.argv[3]).load_module()
os.environ['PATH'] = '/run/lab-bin:/usr/sbin:/usr/bin:/sbin:/bin'


def command(*args):
    return subprocess.check_output(args, text=True, stderr=subprocess.PIPE).strip()


class LabBackend:
    def __init__(self):
        self.files = store_module.Store('/var/lib/lab-transaction', '/run/lab-transaction')
        self.files.initialize()
        self.timer = False
        self.fail_commit = False

    def guard(self): return self.files.guard()
    def load(self): return self.files.load()
    def pending(self): return self.files.pending()
    def active_runs(self): return False
    def validate(self, request): return profile.properties(request)
    def snapshot(self, change):
        return [[name, command('nmcli', '--escape', 'no', '-g', name, 'connection', 'show', 'cmp-transaction')]
                for name in change[::2]]
    def checkpoint(self, _):
        device = command('nmcli', '--escape', 'no', '-g', 'GENERAL.DBUS-PATH', 'device', 'show', 'cmp-trans124')
        result = command('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], 'call',
                         'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                         'org.freedesktop.NetworkManager', 'CheckpointCreate', 'aouu', '1', device, '90', '0')
        return re.search(r'"(/org/freedesktop/NetworkManager/Checkpoint/\d+)"', result).group(1)
    def store(self, state): self.files.store(state)
    def schedule_recovery(self, _): self.timer = True
    def set_pending(self): self.files.set_pending()
    def clear_state(self): self.files.clear_state()
    def clear_pending(self): self.files.clear_pending()
    def cancel_recovery(self, _): self.timer = False
    def rollback_checkpoint(self, checkpoint):
        return command('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], 'call',
                       'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                       'org.freedesktop.NetworkManager', 'CheckpointRollback', 'o', checkpoint)
    def stage_temporary(self, change):
        command('nmcli', 'connection', 'modify', '--temporary', 'cmp-transaction', *change)

    def apply_temporary(self, change):
        command('nmcli', 'device', 'reapply', 'cmp-trans124')
    def persist(self, change):
        command('nmcli', 'connection', 'modify', 'cmp-transaction', *change)
    def verify_before_confirm(self, original, change, applied):
        if self.snapshot(change) != applied:
            raise RuntimeError('conflicting external change')
    def commit_checkpoint(self, checkpoint):
        if self.fail_commit:
            raise RuntimeError('simulated failure after profile was persisted')
        command('busctl', '--address=' + os.environ['DBUS_SYSTEM_BUS_ADDRESS'], 'call',
                'org.freedesktop.NetworkManager', '/org/freedesktop/NetworkManager',
                'org.freedesktop.NetworkManager', 'CheckpointDestroy', 'o', checkpoint)
    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        current = self.snapshot(change)
        if applied is not None and current != applied and current != original:
            raise RuntimeError('conflicting external change')
        try:
            self.rollback_checkpoint(checkpoint)
        except subprocess.CalledProcessError:
            pass  # a real watchdog must also handle a checkpoint lost on NM restart
        values = [part for pair in original for part in pair]
        command('nmcli', 'connection', 'modify', 'cmp-transaction', *values)
        command('nmcli', 'device', 'reapply', 'cmp-trans124')


def has_address(address):
    end = time.monotonic() + 8
    while time.monotonic() < end:
        devices = json.loads(command('ip', '-j', '-4', 'address', 'show', 'dev', 'cmp-trans124'))
        if devices and any(a.get('local') == address for a in devices[0]['addr_info']):
            return True
        time.sleep(.1)
    return False


command('nmcli', 'connection', 'add', 'type', 'vlan', 'ifname', 'cmp-trans124', 'dev', 'cmp-test0',
        'id', '124', 'con-name', 'cmp-transaction', 'connection.autoconnect', 'no',
        'ipv4.method', 'manual', 'ipv4.addresses', '198.19.1.10/24', 'ipv4.never-default', 'yes',
        'ipv6.method', 'disabled')
try:
    command('nmcli', '--wait', '10', 'connection', 'up', 'cmp-transaction')
    profile_uuid = command('nmcli', '-g', 'connection.uuid', 'connection', 'show', 'cmp-transaction')
    profiles = command('nmcli', '--escape', 'no', '-t', '-f', 'UUID,FILENAME', 'connection', 'show').splitlines()
    filename = next(line.partition(':')[2] for line in profiles if line.partition(':')[0] == profile_uuid)
    assert filename.startswith('/etc/NetworkManager/system-connections/')
    device_path = command('nmcli', '--escape', 'no', '-g', 'GENERAL.DBUS-PATH', 'device', 'show', 'cmp-trans124')
    assert re.fullmatch(r'/org/freedesktop/NetworkManager/Devices/\d+', device_path)
    b = LabBackend()
    # PHP and the privileged Python process use the same kernel lock, not separate lock mechanisms.
    lock_check = '$f=fopen("/run/lab-transaction/provisioning.lock","r"); exit(flock($f, LOCK_EX|LOCK_NB) ? 1 : 0);'
    with b.files.guard():
        # /etc is a private tmpfs here, so /usr/bin/php's alternatives symlink may not resolve.
        php = next(iter(sorted(glob.glob('/usr/bin/php[0-9]*'))), 'php')
        subprocess.check_call([php, '-r', lock_check])
    tx = transaction.NetworkTransaction(b)
    change = {'ipv4': {'method': 'manual', 'addresses': ['198.19.2.10/24'], 'never_default': True}}
    tx.begin(change)
    assert has_address('198.19.2.10') and b.pending()
    # Discard the process-local coordinator, then reconstruct it from the root-only state file.
    b = LabBackend()
    tx = transaction.NetworkTransaction(b)
    tx.recover()
    assert has_address('198.19.1.10') and not b.pending()
    token = tx.begin(change)
    b.fail_commit = True
    try:
        tx.confirm(token)
        raise AssertionError('a simulated failed commit did not fail')
    except RuntimeError:
        pass
    assert has_address('198.19.1.10') and not b.pending()
    assert command('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'cmp-transaction') == '198.19.1.10/24'
    b.fail_commit = False
    token = tx.begin(change)
    command('nmcli', 'connection', 'modify', '--temporary', 'cmp-transaction', 'ipv4.dns', '9.9.9.9')
    try:
        tx.confirm(token)
        raise AssertionError('an outside profile change was overwritten')
    except RuntimeError:
        pass
    assert b.pending() and command('nmcli', '-g', 'ipv4.dns', 'connection', 'show', 'cmp-transaction') == '9.9.9.9'
    # In this laboratory, undo the simulated external edit and allow recovery to proceed.
    command('nmcli', 'connection', 'modify', '--temporary', 'cmp-transaction', 'ipv4.dns', '')
    tx.recover()
    assert has_address('198.19.1.10') and not b.pending()
    token = tx.begin(change)
    tx.confirm(token)
    assert has_address('198.19.2.10') and not b.pending()
    assert command('nmcli', '-g', 'ipv4.addresses', 'connection', 'show', 'cmp-transaction') == '198.19.2.10/24'
    others = json.loads(command('ip', '-j', '-4', 'address', 'show', 'dev', 'cmp-test0.99'))
    assert any(a.get('local') == '198.51.100.10' for a in others[0]['addr_info'])
finally:
    subprocess.call(['nmcli', 'connection', 'delete', 'cmp-transaction'], stdout=subprocess.DEVNULL)
print('PASS: NM transaction applies, recovers after failed commit and persists confirmed changes')
