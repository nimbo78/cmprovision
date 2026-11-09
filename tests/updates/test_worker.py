import base64
import copy
from contextlib import contextmanager
import hashlib
import os
from pathlib import Path
import shutil
import tempfile
import unittest
from unittest.mock import patch

from support import CONTEXT, REPOSITORY, manifest, signed
from update_manifest import UpdateError
from update_store import UpdateStore
from update_worker import Worker


class Source:
    def __init__(self, envelope, key, payloads):
        self.envelope, self.key, self.payloads = envelope, key, payloads
        self.repository = REPOSITORY
        self.calls = []

    def check(self, component, context):
        self.calls.append(('check', component))
        return copy.deepcopy(self.envelope) if component == 'application' else None

    def release(self, tag, component, context):
        self.calls.append(('release', tag))
        return copy.deepcopy(self.envelope)

    def fetch(self, tag, asset, destination, progress):
        self.calls.append(('fetch', asset['name']))
        if progress(0, asset['size']) is False: raise UpdateError('canceled')
        destination.write_bytes(self.payloads[asset['name']])
        progress(asset['size'], asset['size'])


class System:
    def __init__(self, root):
        self.root, self.calls = root, []
        self.current = dict(CONTEXT, fpms_version='2.27.0~dev1', utility_os_version='2026.10.2',
                            utility_selector='bundled-modern', source_commit='abcdef0')
        self.original = copy.deepcopy(self.current)
        self.active, self.reserved, self.conflict = [], 0, False
        self.fail_backup = self.fail_install = self.fail_health = self.fail_recovery = False
        self.extra_dependency = False
        self.services = {'cmprovision-dnsmasq': {'enabled': 'masked', 'active': False},
                         'cmprovision-rpiboot': {'enabled': 'masked', 'active': False},
                         'cmprovision-queue': {'enabled': 'enabled', 'active': True}}

    def context(self): return copy.deepcopy(self.current)
    def admission(self): return {'active': self.active, 'reserved_jobs': self.reserved}
    def conflicts(self):
        if self.conflict: raise UpdateError('A network or power operation is pending.')
    @contextmanager
    def admission_lock(self): yield
    def capture_services(self): return copy.deepcopy(self.services)
    def pause(self, services): self.calls.append(('pause', copy.deepcopy(services)))
    def resume(self, services, boot=True):
        self.calls.append(('resume', copy.deepcopy(services), boot))
    def plan_install(self, paths, value):
        self.calls.append(('plan', len(paths)))
        result = [{'package': 'cmprovision4', 'version': value['version']}]
        if self.current['fpms_installed']: result.append({'package': 'fpms-lite', 'version': value['version']})
        if self.extra_dependency: result.append({'package': 'unexpected', 'version': '1.0'})
        return result
    def prefetch(self, paths, plan): self.calls.append(('prefetch', plan))
    def backup(self, identity):
        self.calls.append(('backup', identity))
        if self.fail_backup: raise UpdateError('Snapshot unavailable.')
        return {'directory': str(self.root/'snapshot'), 'identity': identity}
    def install(self, paths, plan, value):
        self.calls.append(('install', value['version']))
        self.current['cmprovision'] = value['version']
        self.current['fpms_version'] = value['version']
        self.current['source_commit'] = value['source_sha'][:7]
        if self.fail_install: raise UpdateError('Package installation failed.')
    def health(self, expected, services):
        self.calls.append(('health', expected.get('version', expected.get('cmprovision'))))
        if self.fail_health and self.current['cmprovision'] != self.original['cmprovision']:
            raise UpdateError('Target health check failed.')
    def recover(self, backup):
        self.calls.append(('recover', backup['identity']))
        if self.fail_recovery: raise UpdateError('Snapshot recovery failed.')
        self.current = copy.deepcopy(self.original)


class WorkerTest(unittest.TestCase):
    def setUp(self):
        if not shutil.which('minisign'): self.skipTest('minisign required')
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.store = UpdateStore(self.root/'store', self.root/'runtime', owner=os.getuid())
        value = manifest()
        payloads = {}
        for asset in value['assets']:
            data = ('contents of '+asset['name']).encode()
            payloads[asset['name']] = data
            asset.update(size=len(data), sha256=hashlib.sha256(data).hexdigest())
        raw, signature, key = signed(self.root/'signing', value)
        self.envelope = {'id': hashlib.sha256(raw).hexdigest(), 'manifest': value,
                         'raw': base64.b64encode(raw).decode(), 'signature': base64.b64encode(signature).decode(),
                         'incompatible': None}
        self.source, self.system = Source(self.envelope, key, payloads), System(self.root)
        self.worker = Worker(self.store, self.source, self.system)
        self.number = 0

    def run_operation(self, action, **arguments):
        self.number += 1
        operation, _ = self.store.begin({'action': action, 'nonce': f'{self.number:064x}', **arguments})
        self.worker.run(operation['id'])
        return self.store.operation(operation['id'])

    def download(self):
        operation = self.run_operation('download', component='application', tag='v2.28.0')
        self.assertEqual('ready', operation['state'], operation.get('message'))
        return self.store.snapshot()['ready'][0]

    def apply(self, ready): return self.run_operation('apply', candidate=ready['id'], receipt=ready['receipt'])

    def test_download_can_run_while_flashing_but_apply_cannot_interrupt_it(self):
        self.system.active = [{'serial': '10000000feedface', 'phase': 'write'}]
        ready = self.download()
        self.assertFalse(any(item[0] in ('pause', 'backup', 'install') for item in self.system.calls))
        result = self.apply(ready)
        self.assertEqual('failed', result['state'])
        self.assertIsNone(self.store.snapshot()['pending'])
        self.assertFalse(any(item[0] == 'install' for item in self.system.calls))

    def test_successful_install_is_durable_and_replay_never_installs_twice(self):
        ready = self.download()
        result = self.apply(ready)
        self.assertEqual('succeeded', result['state'], result.get('message'))
        self.assertEqual('2.28.0', self.system.current['cmprovision'])
        self.assertIsNone(self.store.snapshot()['pending'])
        self.assertEqual(self.system.services, [item for item in self.system.calls if item[0] == 'resume'][-1][1])
        self.worker.run(result['id'])
        self.assertEqual(1, sum(item[0] == 'install' for item in self.system.calls))

    def test_projection_context_failure_after_commit_never_demotes_or_restores_install(self):
        ready = self.download()
        with patch.object(self.store, 'metadata', side_effect=UpdateError('context unavailable')):
            result = self.apply(ready)
        self.assertEqual('succeeded', result['state'])
        self.assertFalse(any(item[0] == 'recover' for item in self.system.calls))

    def test_interrupted_install_recovers_once_instead_of_reinstalling(self):
        ready = self.download()
        operation, _ = self.store.begin({'action': 'apply', 'candidate': ready['id'],
            'receipt': ready['receipt'], 'nonce': 'e'*64})
        self.store.update(operation['id'], component='application', services=self.system.services,
            previous=self.system.original, backup=self.system.backup(operation['id']), state='applying')
        self.store.mark_pending(operation['id'])
        self.system.current['cmprovision'] = '2.28.0'
        self.worker.recover_pending()
        self.worker.recover_pending()
        self.assertEqual('rolled-back', self.store.operation(operation['id'])['state'])
        self.assertEqual(1, sum(item[0] == 'recover' for item in self.system.calls))
        self.assertFalse(any(item[0] == 'install' for item in self.system.calls))

    def test_committed_cleanup_failure_never_reinstalls_or_rolls_back(self):
        ready = self.download()
        with patch.object(self.store, 'clear_pending', side_effect=UpdateError('cleanup failed')):
            result = self.apply(ready)
        self.assertEqual('recovery-required', result['state'])
        self.assertTrue(self.store.snapshot()['pending'])
        self.assertFalse(any(item[0] == 'recover' for item in self.system.calls))
        self.worker.recover_pending()
        self.assertEqual('succeeded', self.store.operation(result['id'])['state'])
        self.assertIsNone(self.store.snapshot()['pending'])
        self.assertEqual(1, sum(item[0] == 'install' for item in self.system.calls))

    def test_changed_receipt_or_dependency_plan_requires_new_confirmation(self):
        ready = self.download()
        receipt = self.store.receipt(ready['id'])
        receipt['plan'] = [{'package': 'new-dependency', 'version': '1.0'}]
        self.store.save_receipt(ready['id'], receipt)
        self.assertEqual('failed', self.apply(ready)['state'])
        ready = self.store.snapshot()['ready'][0]
        self.system.extra_dependency = True
        self.assertEqual('failed', self.apply(ready)['state'])
        self.assertFalse(any(item[0] == 'install' for item in self.system.calls))

    def test_cached_package_or_catalogue_tampering_is_rejected_before_maintenance(self):
        ready = self.download()
        path = self.store.payload_dir(ready['id'])/self.envelope['manifest']['assets'][0]['name']
        path.write_bytes(b'tampered')
        self.assertEqual('failed', self.apply(ready)['state'])
        self.assertFalse(any(item[0] in ('pause', 'install') for item in self.system.calls))

    def test_install_and_health_failures_automatically_restore_previous_working_state(self):
        for failure in ('fail_install', 'fail_health'):
            with self.subTest(failure=failure):
                self.system.calls.clear()
                setattr(self.system, failure, True)
                ready = self.download()
                result = self.apply(ready)
                self.assertEqual('rolled-back', result['state'], result.get('message'))
                self.assertEqual(self.system.original, self.system.current)
                self.assertIsNone(self.store.snapshot()['pending'])
                self.assertTrue(any(item[0] == 'recover' for item in self.system.calls))
                setattr(self.system, failure, False)

    def test_failed_recovery_keeps_maintenance_and_blocks_further_updates(self):
        self.system.fail_install = self.system.fail_recovery = True
        ready = self.download()
        result = self.apply(ready)
        self.assertEqual('recovery-required', result['state'])
        self.assertTrue(self.store.snapshot()['pending'])
        with self.assertRaises(UpdateError): self.store.begin({'action': 'check', 'components': ['application'], 'nonce': 'f'*64})

    def test_backup_failure_does_not_modify_packages_and_restores_services(self):
        self.system.fail_backup = True
        ready = self.download()
        result = self.apply(ready)
        self.assertEqual('failed', result['state'])
        self.assertFalse(any(item[0] == 'install' for item in self.system.calls))
        self.assertIsNone(self.store.snapshot()['pending'])
        self.assertEqual(self.system.services, [item for item in self.system.calls if item[0] == 'resume'][-1][1])

    def test_reserved_jobs_and_power_network_operations_block_apply(self):
        ready = self.download()
        self.system.reserved = 1
        self.assertEqual('failed', self.apply(ready)['state'])
        self.system.reserved, self.system.conflict = 0, True
        self.assertEqual('failed', self.apply(ready)['state'])
        self.assertFalse(any(item[0] in ('backup', 'install') for item in self.system.calls))

    def test_canceled_queued_work_and_duplicate_worker_do_not_start_actions(self):
        operation, _ = self.store.begin({'action': 'download', 'component': 'application', 'tag': 'v2.28.0', 'nonce': 'f'*64})
        self.store.cancel(operation['id'])
        self.worker.run(operation['id'])
        self.assertEqual('canceled', self.store.operation(operation['id'])['state'])
        self.assertEqual([], self.source.calls)
        with self.store.lock('worker', blocking=False): self.worker.run(operation['id'])
        self.assertEqual('canceled', self.store.operation(operation['id'])['state'])


if __name__ == '__main__': unittest.main()
