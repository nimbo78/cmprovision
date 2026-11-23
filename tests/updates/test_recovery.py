import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from support import TOOLS
from update_manifest import UpdateError
from update_store import UpdateStore
from update_recovery import retain_runner, dispatch, install_dispatcher, NAMES


class RecoveryTest(unittest.TestCase):
    def test_retained_runner_contains_the_time_provider_preflight(self):
        self.assertIn('stand_time.py', NAMES)
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.store = UpdateStore(self.root/'updates', self.root/'run', owner=os.getuid())

    def test_boot_services_follow_the_durable_decision_after_a_cold_start(self):
        from update_recovery import boot_permitted
        service = 'cmprovision-dnsmasq.service'
        secondary = 'cmprovision-rpiboot.service'
        operation, _ = self.store.begin({'action': 'activate', 'nonce': 'f'*64, 'selector': 'bundled-modern'})
        identity = operation['id']
        self.store.update(identity, boot_guard=True, services={
            service: {'enabled': 'enabled', 'active': True},
            secondary: {'enabled': 'masked', 'active': False}})
        self.assertTrue(boot_permitted(self.store.root, service, os.getuid()))
        self.store.mark_pending(identity)
        self.store.pending_file.unlink()  # /run is fresh and even a broken dispatcher cannot open the stand.
        for state in ('applying', 'recovery-required'):
            self.store.update(identity, state=state)
            self.assertFalse(boot_permitted(self.store.root, service, os.getuid()))
        self.store.update(identity, state='rolled-back', committed=True, outcome='rolled-back')
        self.assertTrue(boot_permitted(self.store.root, service, os.getuid()))
        self.assertFalse(boot_permitted(self.store.root, secondary, os.getuid()))
        self.store.clear_pending(identity)
        self.assertTrue(boot_permitted(self.store.root, service, os.getuid()))

    def test_legacy_runner_can_restart_services_during_its_final_cleanup(self):
        from update_recovery import boot_permitted
        operation, _ = self.store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
        self.store.update(operation['id'], state='reconnecting')
        self.store.mark_pending(operation['id'])
        self.assertTrue(boot_permitted(self.store.root, 'cmprovision-rpiboot.service', os.getuid()))

    def test_installed_dispatcher_accepts_both_protocol1_inventories(self):
        # d918911 differs only by the missing diagnostics module in this exact inventory.
        source = (TOOLS/'update_recovery.py').read_text()
        old = (TOOLS.parent/'tests/fixtures/update-recovery-protocol1.py').read_text()
        protected_source = self.root/'dispatcher.py'
        protected_source.write_text(source); protected_source.chmod(0o600)
        target = self.store.root/'recover.py'
        target.write_text(old); target.chmod(0o600)
        installed = self.root/'package'; installed.mkdir()
        for name in NAMES: (installed/name).write_text('# trusted package\n')
        # Preflight is only used before an update. Retaining the original inventory
        # also lets older dispatchers recognize a pending runner during downgrade.
        self.assertNotIn('update_space.py', NAMES)
        old = tuple(name for name in NAMES if name != 'stand_time.py')
        for number, names in enumerate((tuple(name for name in old if name != 'update_diagnostics.py'), old, NAMES)):
            operation, _ = self.store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
            runner = retain_runner(self.store, operation['id'], installed, names=names, trust=installed)
            self.store.update(operation['id'], runner=runner, state='applying')
            self.store.mark_pending(operation['id'])
            with patch('update_recovery.__file__', str(protected_source)): install_dispatcher(self.store)
            spec = importlib.util.spec_from_file_location('installed_dispatcher', target)
            module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
            calls = []
            module.dispatch(self.store.root, self.store.runtime,
                run=lambda argv, **kw: calls.append(argv) or subprocess.CompletedProcess(argv, 0), owner=os.getuid())
            self.assertEqual(1, len(calls))
            self.store.update(operation['id'], state='succeeded')
            self.store.clear_pending(operation['id'])
        self.assertEqual(source, target.read_text())

    def test_runner_is_not_published_when_its_directory_entry_cannot_be_flushed(self):
        installed = self.root/'package'
        installed.mkdir()
        for name in NAMES: (installed/name).write_text('# protected package file\n')
        operation, _ = self.store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
        parent = self.store.private/'runners'
        synced = os.fsync
        def fail_parent(fd):
            if Path(f'/proc/self/fd/{fd}').resolve() == parent: raise OSError('Injected directory write failure')
            synced(fd)
        with patch('update_recovery.os.fsync', side_effect=fail_parent), self.assertRaisesRegex(OSError, 'directory write failure'):
            retain_runner(self.store, operation['id'], installed, trust=installed)
        self.assertNotIn('runner', self.store.operation(operation['id']))

    def test_boot_uses_hashed_retained_runner_even_if_package_helper_is_replaced(self):
        installed = self.root/'package'
        installed.mkdir()
        for name in NAMES:
            (installed/name).write_text('# previous trusted package\n')
        operation, _ = self.store.begin({'action': 'check', 'nonce': 'a'*64, 'components': ['application']})
        runner = retain_runner(self.store, operation['id'], installed, trust=installed)
        self.store.update(operation['id'], runner=runner, state='applying')
        self.store.mark_pending(operation['id'])
        self.store.pending_file.unlink()
        (installed/'station-updates.py').write_text('broken replacement')
        calls = []
        dispatch(self.store.root, self.store.runtime, run=lambda argv, **kw: calls.append(argv) or subprocess.CompletedProcess(argv, 0), owner=os.getuid())
        self.assertTrue(self.store.pending_file.exists())
        self.assertIn(str(Path(runner['directory'])/'station-updates.py'), calls[0])
        self.assertNotIn(str(installed/'station-updates.py'), calls[0])
        (Path(runner['directory'])/'update_worker.py').write_text('tampered')
        with self.assertRaises(RuntimeError):
            dispatch(self.store.root, self.store.runtime, run=lambda *a, **kw: self.fail('Launched tampered worker'), owner=os.getuid())
        self.assertTrue(self.store.pending_file.exists())


if __name__ == '__main__': unittest.main()
