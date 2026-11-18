import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from support import TOOLS
from update_manifest import UpdateError
from update_retention import prune
from update_store import MAX_STATE, UpdateStore
import update_space


MIB = 1024**2
RECOVERY = '/var/lib/cmprovision-updates/private/recovery'
JOURNAL = '/var/lib/cmprovision-updates/private'


class SpaceTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.app = self.root/'var/lib/cmprovision'
        self.db = self.app/'database/database.sqlite'
        self.upload = self.app/'public/uploads/images'
        self.status = self.root/'var/lib/dpkg/status'
        for directory in (self.db.parent, self.upload, self.root/'tmp', self.root/'var/tmp',
                          self.status.parent/'info', self.root/'usr/share/fixtures'):
            directory.mkdir(parents=True)
        self.db.write_bytes(b'x'*4096)
        self.status.touch()
        self.package('cmprovision4', 16*1024)
        self.store = UpdateStore(self.root/'updates', self.root/'run', owner=os.getuid())
        self.enterContext(patch.object(update_space, 'ROOT', self.root))

    def package(self, name, size):
        # No Installed-Size, like the portable fpms-lite .deb. A registered directory can
        # contain arbitrary uploads. dpkg-repack copies only the registered leaf files.
        with self.status.open('a') as stream:
            stream.write(f'Package: {name}\nStatus: install ok installed\nArchitecture: all\n'
                         'Version: 1.0\nMaintainer: Test <test@example.org>\nDescription: fixture\n\n')
        relative = f'/usr/share/fixtures/{name}'
        (self.status.parent/'info'/(name+'.list')).write_text('/.\n/var/lib/cmprovision/public/uploads\n'+relative+'\n')
        with (self.root/relative.lstrip('/')).open('wb') as stream:
            stream.truncate(size)

    def rows(self, selected=None):
        report = update_space.measure(update_space.estimate(self.store, selected=selected))
        self.assertEqual(report['sufficient'], all(row['shortfall'] == 0 for row in report['volumes']))
        return {row['location']: row for row in report['volumes']}

    def point(self):
        job, _ = self.store.begin({'action': 'check', 'components': ['application'], 'nonce': 'a'*64})
        snapshot = self.store.private/'recovery'/job['id']/'20261118-100000-1.0'
        self.store._directory(snapshot, 0o700)
        self.store.update(job['id'], state='succeeded', component='application',
                          previous={'cmprovision': '1.0'}, backup={'identity': job['id'], 'directory': str(snapshot)})
        return job['id'], snapshot

    def different_disk(self):
        if not Path('/dev/shm').is_dir() or os.stat('/dev/shm').st_dev == self.root.stat().st_dev:
            self.skipTest('needs a second filesystem on /dev/shm')
        temp = tempfile.TemporaryDirectory(dir='/dev/shm')
        self.addCleanup(temp.cleanup)
        return Path(temp.name)

    def private_mount_namespace(self):
        if not shutil.which('unshare') or not shutil.which('mount'):
            self.skipTest('needs a private mount namespace')
        if os.environ.get('CMPROVISION_TEST_PARENT_MOUNT_NS') not in (None, os.readlink('/proc/self/ns/mnt')):
            return True
        result = subprocess.run(['unshare', '--mount', '--propagation', 'private', 'true'], capture_output=True)
        if result.returncode:
            self.skipTest('private mount namespaces are unavailable')
        subprocess.run(['unshare', '--mount', '--propagation', 'private', sys.executable, '-B', '-m', 'unittest',
                        self.id()], cwd=Path(__file__).parent, check=True,
                       env=dict(os.environ, CMPROVISION_TEST_PARENT_MOUNT_NS=os.readlink('/proc/self/ns/mnt')))
        return False

    def test_archived_sparse_uploads_count_apparent_bytes_only_once_on_a_shared_disk(self):
        before = self.rows()
        self.assertEqual([RECOVERY], list(before))
        with (self.upload/'image.img').open('wb') as stream:
            stream.truncate(8*MIB)
        with (self.root/'outside').open('wb') as stream:
            stream.truncate(40*MIB)
        (self.upload/'external-link').symlink_to(self.root/'outside')
        transfer = self.app/'storage/framework/configuration-transfer'
        transfer.mkdir(parents=True)
        (transfer/'staged').hardlink_to(self.root/'outside')
        extra = self.rows()[RECOVERY]['required'] - before[RECOVERY]['required']
        self.assertGreaterEqual(extra, 8*MIB)
        self.assertLess(extra, 9*MIB)
        self.assertFalse((self.store.private/'recovery').exists(), 'the first preview must not create a destination')

    def test_db_wal_and_redaction_use_var_tmp_even_when_another_volume_has_room(self):
        scratch = self.root/'var/tmp'
        scratch.rmdir()
        scratch.symlink_to(self.different_disk(), target_is_directory=True)
        before = self.rows()
        with self.db.open('r+b') as stream:
            stream.truncate(5*MIB)
        with self.db.with_suffix('.sqlite-wal').open('wb') as stream:
            stream.truncate(3*MIB)
        after = self.rows()
        self.assertEqual({RECOVERY, '/var/tmp'}, set(after))
        self.assertGreater(after['/var/tmp']['required'] - before['/var/tmp']['required'], 15*MIB)
        final_copy = after[RECOVERY]['required'] - before[RECOVERY]['required']
        self.assertGreater(final_copy, 7*MIB)
        self.assertLess(final_copy, 9*MIB)

    def test_dpkg_deb_scratch_and_repack_tree_are_on_their_actual_filesystems(self):
        scratch = self.root/'tmp'
        scratch.rmdir()
        scratch.symlink_to(self.different_disk(), target_is_directory=True)
        before = self.rows()
        self.package('fpms-lite', 4*MIB)
        after = self.rows()
        self.assertEqual({RECOVERY, '/tmp'}, set(after))
        self.assertGreater(after['/tmp']['required'] - before['/tmp']['required'], 3*MIB)
        both = after[RECOVERY]['required'] - before[RECOVERY]['required']
        self.assertGreaterEqual(both, 8*MIB-128*1024)
        self.assertLess(both, 9*MIB)

    def test_disjoint_database_and_repack_peaks_share_only_one_reserve(self):
        self.package('fpms-lite', 8*MIB)
        before = self.rows()[RECOVERY]['required']
        self.assertGreater(before, 128*MIB+23*MIB)
        with self.db.open('r+b') as stream:
            stream.truncate(6*MIB)
        after = self.rows()[RECOVERY]['required']
        # The temporary DB has already gone when the repack tree, scratch member
        # and final .deb coexist. Only the permanent database copy increases the peak.
        self.assertGreater(after-before, 5*MIB)
        self.assertLess(after-before, 7*MIB)

    def test_held_fpms_is_sized_and_half_configured_fpms_blocks_the_estimate(self):
        self.package('fpms-lite', 6*MIB)
        status = self.status.read_text()
        expected = self.rows()[RECOVERY]['required']
        for status_value in ('hold ok installed', 'install ok half-configured', 'install reinstreq installed'):
            with self.subTest(status=status_value):
                self.status.write_text(status.replace('fpms-lite\nStatus: install ok installed',
                                                      'fpms-lite\nStatus: '+status_value))
                if status_value == 'hold ok installed':
                    self.assertEqual(expected, self.rows()[RECOVERY]['required'])
                else:
                    with self.assertRaises(UpdateError):
                        self.rows()

    def test_replacement_credits_only_owned_unshared_blocks_and_keeps_rollback_source(self):
        identity, snapshot = self.point()
        owned = snapshot/'app.tar.gz'
        owned.write_bytes(b'r'*MIB)
        unrelated = self.store.private/'recovery/operator-backup'
        unrelated.mkdir()
        (unrelated/'images').write_bytes(b'x'*MIB)
        (snapshot/'system-config.tar.gz').symlink_to(unrelated/'images')
        (snapshot/'database.sqlite').hardlink_to(unrelated/'images')
        marks = self.store.state_file.stat()
        preview = self.rows()[RECOVERY]
        self.assertEqual(owned.stat().st_blocks*512, preview['reclaimable'])
        rollback = self.rows(selected=identity)[RECOVERY]
        self.assertEqual(preview['required'], rollback['required'])
        self.assertEqual(0, rollback['reclaimable'])
        self.assertEqual(marks.st_mtime_ns, self.store.state_file.stat().st_mtime_ns)
        self.assertTrue(owned.is_file())
        self.assertTrue((unrelated/'images').is_file())
        self.assertNotIn(str(self.root), json.dumps(preview))

    def test_reclaimable_blocks_can_cover_only_a_deficit_on_the_same_volume(self):
        _, snapshot = self.point()
        (snapshot/'app.tar.gz').write_bytes(b'r'*MIB)
        scratch = self.root/'var/tmp'
        scratch.rmdir()
        scratch.symlink_to(self.different_disk(), target_is_directory=True)
        scratch_dev = scratch.stat().st_dev
        plan = update_space.estimate(self.store)
        need = {row['location']: row['required'] for row in update_space.measure(plan)['volumes']}
        def free(fd):
            label = '/var/tmp' if os.fstat(fd).st_dev == scratch_dev else RECOVERY
            return SimpleNamespace(f_flag=0, f_frsize=1, f_bavail=need[label]-512*1024)
        with patch.object(os, 'fstatvfs', side_effect=free):
            report = update_space.measure(plan)
            actual = update_space.measure(plan, reclaimable=False)
        self.assertFalse(report['sufficient'])
        self.assertEqual([0, 512*1024], [row['shortfall'] for row in report['volumes']])
        self.assertEqual([512*1024, 512*1024], [row['shortfall'] for row in actual['volumes']])

    def test_final_probe_rejects_replaced_or_read_only_destination(self):
        scratch = self.root/'tmp'
        second = self.different_disk()
        plan = update_space.estimate(self.store)
        scratch.rmdir()
        scratch.symlink_to(second, target_is_directory=True)
        with self.assertRaisesRegex(UpdateError, 'filesystem.*changed'):
            update_space.measure(plan, reclaimable=False)
        scratch.unlink()
        scratch.mkdir()
        with patch.object(os, 'fstatvfs', return_value=SimpleNamespace(f_flag=os.ST_RDONLY)):
            with self.assertRaisesRegex(UpdateError, 'read-only'):
                update_space.measure(plan, reclaimable=False)

    def test_final_probe_uses_actual_free_blocks_while_an_unlinked_file_is_open(self):
        _, snapshot = self.point()
        self.store.begin({'action': 'check', 'components': ['application'], 'nonce': 'b'*64})
        with (snapshot/'app.tar.gz').open('wb') as held:
            os.posix_fallocate(held.fileno(), 0, 8*MIB)
            required = self.rows()[RECOVERY]['required']
            free = self.rows()[RECOVERY]['free']
            # Sparse file puts the requirement just over measured capacity.
            with (self.upload/'large.img').open('wb') as stream:
                stream.truncate(max(free-required+16*MIB, 16*MIB))
            plan = update_space.estimate(self.store)
            prune(self.store, managed=None, before_snapshot=True)
            self.assertFalse((snapshot/'app.tar.gz').exists())
            with patch.object(os, 'fwalk', side_effect=AssertionError('walk under admission lock')), \
                    patch.object(subprocess, 'run', side_effect=AssertionError('package query under admission lock')), \
                    patch.object(self.store, '_load', side_effect=AssertionError('read journal under admission lock')):
                row = update_space.measure(plan, reclaimable=False)['volumes'][0]
            self.assertEqual(0, row['reclaimable'])
            self.assertGreater(row['shortfall'], 0)
            self.assertEqual(row['required']-row['free'], row['shortfall'])
        released = update_space.measure(plan, reclaimable=False)['volumes'][0]
        self.assertGreater(released['free']-row['free'], 7*MIB)

    def test_linked_database_is_rejected_without_exposing_a_private_name(self):
        self.db.unlink()
        self.db.symlink_to(self.root/'private-board-secret')
        with self.assertRaises(UpdateError) as caught:
            self.rows()
        self.assertNotIn(str(self.root), str(caught.exception))
        self.assertNotIn('private-board-secret', str(caught.exception))

    def test_real_nested_upload_mount_is_included_in_archive_requirement(self):
        if not self.private_mount_namespace(): return
        source = self.different_disk()
        with (source/'nested.img').open('wb') as stream:
            stream.truncate(8*MIB)
        before = self.rows()[RECOVERY]['required']
        subprocess.run(['mount', '--bind', source, self.upload], check=True)
        self.addCleanup(subprocess.run, ['umount', self.upload], check=True)
        self.assertNotEqual(self.app.stat().st_dev, self.upload.stat().st_dev)
        after = self.rows()
        self.assertEqual([RECOVERY], list(after))
        self.assertGreaterEqual(after[RECOVERY]['required']-before, 8*MIB)

    def test_separate_full_journal_volume_blocks_admission_with_empty_recovery_storage(self):
        if not self.private_mount_namespace(): return
        source = self.different_disk()
        destination = self.store.private/'recovery'
        destination.mkdir(mode=0o700)
        subprocess.run(['mount', '--bind', source, destination], check=True)
        self.addCleanup(subprocess.run, ['umount', destination], check=True)
        for scratch in (self.root/'tmp', self.root/'var/tmp'):
            scratch.rmdir()
            scratch.symlink_to(source, target_is_directory=True)
        plan = update_space.estimate(self.store)
        self.assertEqual({RECOVERY, JOURNAL}, {row['location'] for row in update_space.measure(plan)['volumes']})
        journal_dev = self.store.private.stat().st_dev
        def free(fd):
            available = 0 if os.fstat(fd).st_dev == journal_dev else 1024*MIB
            return SimpleNamespace(f_flag=0, f_frsize=1, f_bavail=available)
        with patch.object(os, 'fstatvfs', side_effect=free):
            report = update_space.measure(plan, reclaimable=False)
        self.assertFalse(report['sufficient'])
        rows = {row['location']: row for row in report['volumes']}
        self.assertEqual(0, rows[RECOVERY]['shortfall'])
        self.assertGreater(rows[JOURNAL]['shortfall'], 2*MAX_STATE)
        self.assertLess(rows[JOURNAL]['required'], 128*MIB)
