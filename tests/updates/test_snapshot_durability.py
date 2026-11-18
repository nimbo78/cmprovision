import errno
import os
import shutil
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from support import TOOLS
from test_system import write_snapshot
from update_manifest import UpdateError
from update_store import UpdateStore
from update_system import LinuxSystem


class SnapshotDurabilityTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        root = Path(temp.name)
        self.store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
        op, _ = self.store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
        self.identity = op['id']
        self.store.update(self.identity, previous={'cmprovision': '2.30.0', 'fpms_installed': False})
        self.operation = self.store.private/'recovery'/self.identity
        self.snapshot = self.operation/'20261118-120000-2.30.0'
        fixture = root/'archive'
        write_snapshot(fixture)
        for name in ('boot-services.saved', 'time-settings.saved', 'packages.txt'):
            (fixture/name).write_text('fixture\n')
        self.files = [self.snapshot/path.name for path in fixture.iterdir()]
        self.restore = self.operation/'restore.sh'
        self.dirs = [self.snapshot, self.operation, self.operation.parent,
                     self.store.private, self.store.root, self.store.root.parent]

        self.system = LinuxSystem(self.store)
        self.system.package = lambda name: None
        self.enterContext(patch('update_system.RESTORE_HELPER', TOOLS/'restore.sh'))
        read_only = self.system.command
        def command(argv, **options):
            if argv[0] == '/usr/sbin/cmprovision-backup':
                shutil.copytree(fixture, self.snapshot)
                return ''
            if argv[0] in ('/usr/bin/dpkg-deb', '/usr/bin/tar'):
                return read_only(argv, **options)
            self.fail(f'Unexpected system command: {argv[0]}')
        self.system.command = command

    def test_invalid_identity_cannot_create_a_recovery_directory(self):
        with self.assertRaises(UpdateError):
            self.system.backup('other')
        self.assertFalse((self.operation.parent/'other').exists())

    def test_the_updater_opts_in_only_its_two_snapshot_scripts_and_retains_the_old_argv(self):
        run = LinuxSystem(self.store).command
        create = self.system.command
        env = ['/usr/bin/printenv', 'CMPROVISION_UPDATE_SNAPSHOT']
        self.assertNotEqual(0, run(env, check=False).returncode)
        def intercept(argv, **options):
            if argv[0] == '/usr/sbin/cmprovision-backup':
                self.assertEqual('1', run(env, **options))
            elif argv[0] == '/usr/bin/dpkg':
                return ''
            elif argv[0] == '/bin/sh':
                self.assertEqual(['/bin/sh', self.restore, '--force', self.operation/'restore-data'], argv)
                self.assertEqual('1', run(env, **options))
                raise UpdateError('Reached retained restore helper')
            return create(argv, **options)
        self.system.command = intercept
        receipt = self.system.backup(self.identity)
        with self.assertRaisesRegex(UpdateError, 'Reached retained restore helper'):
            self.system.recover(receipt)

    def test_every_receipted_file_and_linking_directory_is_flushed_before_return(self):
        flushed = []
        fsync = os.fsync
        def trace(fd):
            fsync(fd)
            flushed.append(Path(os.readlink(f'/proc/self/fd/{fd}')))
        with patch('os.fsync', side_effect=trace), patch('os.sync', side_effect=AssertionError('global sync')):
            receipt = self.system.backup(self.identity)
        self.system.validate_backup(receipt)
        self.assertEqual({path.name for path in self.files}, set(receipt['hashes']))
        for path in [*self.files, self.restore, *self.dirs]:
            self.assertIn(path, flushed)
        for path in [*self.files, self.restore, *self.dirs[:-1]]:
            self.assertLess(flushed.index(path), flushed.index(path.parent), path)

    def test_each_failed_file_or_directory_flush_rejects_and_discards_the_attempt(self):
        fsync = os.fsync
        for index, target in enumerate([*self.files, self.restore, *self.dirs]):
            number = errno.EIO if index % 2 == 0 else errno.ENOSPC
            def fail_target(fd):
                if Path(os.readlink(f'/proc/self/fd/{fd}')) == target:
                    raise OSError(number, os.strerror(number))
                fsync(fd)
            with self.subTest(path=target), patch('os.fsync', side_effect=fail_target):
                try:
                    with self.assertRaises((UpdateError, OSError)) as caught:
                        self.system.backup(self.identity)
                    error = caught.exception
                    while error.__cause__ is not None:
                        error = error.__cause__
                    self.assertEqual(number, getattr(error, 'errno', None))
                    self.assertFalse(self.operation.exists(), 'an unpublished copy still occupies space')
                finally:
                    if self.operation.exists():
                        shutil.rmtree(self.operation)

    def test_earlier_contents_are_never_removed_or_overwritten_by_a_failed_retry(self):
        self.store._directory(self.operation, 0o700)
        self.restore.write_bytes(b'previously retained helper')
        with self.assertRaises(UpdateError):
            self.system.backup(self.identity)
        self.assertEqual(b'previously retained helper', self.restore.read_bytes())
        self.assertFalse(self.snapshot.exists())
