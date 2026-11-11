from contextlib import contextmanager
import copy
import importlib
import json
import os
from pathlib import Path
import tempfile
import unittest

from support import ROOT
from backup_archive import Entry
from backup_store import BackupStore
from update_manifest import UpdateError


class System:
    def __init__(self):
        self.active, self.reserved, self.conflict = [], 0, False
        self.fail_collect = self.fail_resume = False
        self.calls = []
        self.services = {'cmprovision-dnsmasq.service': {'active': False, 'enabled': 'masked'},
                         'cmprovision-rpiboot.service': {'active': False, 'enabled': 'masked'},
                         'cmprovision-queue.service': {'active': True, 'enabled': 'enabled'}}
    @contextmanager
    def admission_lock(self):
        if getattr(self, 'busy', False):
            from backup_store import BackupBusy
            raise BackupBusy('Busy')
        yield
    def admission(self): return {'active': self.active, 'reserved_jobs': self.reserved}
    def conflicts(self):
        if self.conflict: raise UpdateError('Another station change is pending.')
    def capture_services(self): return copy.deepcopy(self.services)
    def pause(self, services): self.calls.append(('pause', services))
    def resume(self, services):
        self.calls.append(('resume', services))
        if self.fail_resume: raise UpdateError('Service state could not be restored.')
    def collect(self, job, directory):
        self.calls.append(('collect', job['id']))
        if self.fail_collect: raise RuntimeError('secret=private-failure-output')
        return [Entry('application/.env', data=b'APP_KEY=fixture')], {
            'station_id': job['snapshot']['station_id'], 'version': '2.28.0', 'source_commit': 'd918911',
            'os': {'id': 'raspbian', 'version': '13', 'architecture': 'arm64'}, 'options': job['options'], 'software': [], 'absent': []}
    def cleanup(self, directory, keep_download=False): self.calls.append(('cleanup', keep_download))


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_worker')
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.store = BackupStore(self.root/'store', self.root/'runtime', owner=os.getuid())
        self.store.configure_password('a sufficiently long master password')
        self.system = System()
        self.worker = self.module.Worker(self.store, self.system)
        self.number = 0

    def job(self):
        self.number += 1
        return self.store.begin(f'{self.number:064x}', 'download', {'images': False, 'packages': False})[0]

    def test_busy_station_defers_without_stopping_services_and_cancel_is_safe(self):
        job = self.job()
        self.system.active = [{'serial': 'fixture', 'phase': 'write'}]
        self.worker.run(job['id'])
        self.assertEqual('waiting', self.store.operation(job['id'])['state'])
        self.assertEqual([], self.system.calls)
        self.assertIsNone(self.store.status()['pending'])
        self.store.cancel(job['id']); self.worker.run(job['id'])
        self.assertEqual('canceled', self.store.operation(job['id'])['state'])

    def test_busy_admission_defers_and_does_not_pause_services(self):
        self.system.busy = True
        job = self.job(); self.worker.run(job['id'])
        self.assertEqual('waiting', self.store.operation(job['id'])['state'])
        self.assertEqual([], self.system.calls)

    def test_success_restores_exact_masked_role_and_replay_never_repeats_snapshot(self):
        job = self.job(); self.worker.run(job['id'])
        result = self.store.operation(job['id'])
        self.assertEqual('ready', result['state'], result.get('message'))
        self.assertIsNone(self.store.status()['pending'])
        self.assertEqual(self.system.services, next(item[1] for item in self.system.calls if item[0] == 'resume'))
        self.assertTrue(self.store.download_file(job['id']).exists())
        self.assertNotIn('master_password', json.dumps(self.store.status()))
        self.worker.run(job['id'])
        self.assertEqual(1, sum(item[0] == 'collect' for item in self.system.calls))

    def test_failed_collection_unfreezes_and_unexpected_text_is_not_exposed(self):
        self.system.fail_collect = True
        job = self.job(); self.worker.run(job['id'])
        self.assertEqual('failed', self.store.operation(job['id'])['state'])
        self.assertIsNone(self.store.status()['pending'])
        self.assertNotIn('private-failure-output', json.dumps(self.store.status()))
        self.assertTrue(any(item[0] == 'resume' for item in self.system.calls))

    def test_failed_role_recovery_keeps_gate_and_recovers_without_another_copy(self):
        self.system.fail_resume = True
        job = self.job(); self.worker.run(job['id'])
        self.assertEqual('recovery-required', self.store.operation(job['id'])['state'])
        self.assertIsNotNone(self.store.status()['pending'])
        self.system.fail_resume = False
        self.worker.recover_pending()
        self.assertEqual('ready', self.store.operation(job['id'])['state'])
        self.assertIsNone(self.store.status()['pending'])
        self.assertEqual(1, sum(item[0] == 'collect' for item in self.system.calls))

    def test_reserved_work_and_conflicts_never_allow_a_snapshot(self):
        for active, reserved, conflict in (([], 1, False), ([], 0, True)):
            self.system.active, self.system.reserved, self.system.conflict = active, reserved, conflict
            job = self.job(); self.worker.run(job['id'])
            self.assertFalse(any(item[0] == 'collect' for item in self.system.calls))
            self.assertIsNone(self.store.status()['pending'])
            self.store.cancel(job['id']); self.worker.run(job['id'])

    def test_cancel_during_actual_readback_does_not_commit_the_download(self):
        from unittest.mock import patch
        from backup_archive import verify_stream
        job = self.job()
        from backup_system import LinuxBackupSystem
        cleanup = self.system.cleanup
        def remove(directory, keep_download=False):
            cleanup(directory, keep_download)
            LinuxBackupSystem(self.store, root=self.root).cleanup(directory, keep_download)
        self.system.cleanup = remove
        def verify(*args, **kwargs):
            # The real reader must poll this callback, not just cancellation before it starts.
            polls = [0]
            def canceled():
                polls[0] += 1
                if polls[0] < 3: return False
                self.store.cancel(job['id'])
                return True
            kwargs['canceled'] = canceled
            return verify_stream(*args, **kwargs)
        with patch.object(self.module, 'verify_stream', side_effect=verify): self.worker.run(job['id'])
        result = self.store.operation(job['id'])
        self.assertEqual('canceled', result['state'], result['message'])
        self.assertFalse(result.get('committed', False))
        self.assertIsNone(self.store.status()['pending'])
        self.assertIn(('cleanup', False), self.system.calls)
        self.assertFalse((self.store.job_dir(job['id'])/'backup.cmpbackup').exists())

    def test_cancel_during_final_ciphertext_hash_does_not_commit(self):
        from unittest.mock import patch
        job = self.job()
        original = self.worker.progress
        def progress(identity, phase, done, total):
            if phase == 'verifying' and total and done: self.store.cancel(identity)
            return original(identity, phase, done, total)
        with patch.object(self.worker, 'progress', side_effect=progress): self.worker.run(job['id'])
        self.assertEqual('canceled', self.store.operation(job['id'])['state'])
        self.assertIsNone(self.store.status()['pending'])

    def test_deferred_multiday_schedule_coalesces_slots_at_actual_capture(self):
        from backup_broker import tick
        from backup_schedule import due
        from unittest.mock import Mock
        now = [1794211200] # Monday 2026-11-09 08:00 UTC.
        self.store.clock = lambda: now[0]
        self.store.save_profile({'type': 'ftp', 'name': 'Fixture', 'host': '127.0.0.1', 'user': 'fixture', 'password': 'fixture', 'path': '/backups'})
        target = self.store.status()['profiles'][0]['id']
        config = {'enabled': True, 'frequency': 'daily', 'time': '03:00', 'weekday': 0,
            'profile': target, 'keep': 7, 'images': False, 'packages': False}
        self.store.save_schedule(config, timezone='UTC')
        self.store.mutate(lambda value: value['schedule'].update(not_before=0))
        slot = due(self.store.configuration()['schedule'], [], now[0], 'UTC')
        job, _ = self.store.begin('a'*64, target, {'images': False, 'packages': False}, scheduled=True, slot=slot)
        self.system.active = [{'phase': 'write'}]; self.worker.run(job['id'])
        self.assertEqual('waiting', self.store.operation(job['id'])['state'])
        now[0] += 2*86400
        self.system.active = []
        class Destination:
            def __init__(inner, *args): pass
            def __enter__(inner): return inner
            def __exit__(inner, *args): pass
            def upload(inner, *args): return {'sha256': 'a'*64, 'size': 1024, 'name': 'fixture', 'station_id': self.store.configuration()['station_id']}
            def namespace(inner): return 'fixture'
            def prune(inner, *args): return []
        self.module.Worker(self.store, self.system, remote=Destination).run(job['id'])
        self.assertEqual('succeeded', self.store.operation(job['id'])['state'])
        launcher = Mock()
        self.system.timezone = lambda: 'UTC'; self.system.sweep = lambda: None
        import subprocess
        self.system.command = lambda *args, **kw: subprocess.CompletedProcess([], 3, stdout='inactive', stderr='')
        tick(self.store, self.system, launcher)
        launcher.assert_not_called()
        now[0] += 86400
        tick(self.store, self.system, launcher)
        launcher.assert_called_once()


if __name__ == '__main__': unittest.main()
