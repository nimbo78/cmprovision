import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from support import TOOLS
from update_manifest import UpdateError
from update_store import UpdateStore
from update_recovery import retain_runner, dispatch, install_dispatcher, NAMES


class RecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.store = UpdateStore(self.root/'updates', self.root/'run', owner=os.getuid())

    def test_installed_protocol1_dispatcher_is_upgraded_and_dispatches_both_inventory_generations(self):
        # d918911 differs only by the missing diagnostics module in this exact inventory.
        source = (TOOLS/'update_recovery.py').read_text()
        old = (TOOLS.parent/'tests/fixtures/update-recovery-protocol1.py').read_text()
        protected_source = self.root/'dispatcher.py'
        protected_source.write_text(source); protected_source.chmod(0o600)
        target = self.store.root/'recover.py'
        target.write_text(old); target.chmod(0o600)
        installed = self.root/'package'; installed.mkdir()
        for name in NAMES: (installed/name).write_text('# trusted package\n')
        for names in (tuple(name for name in NAMES if name != 'update_diagnostics.py'), NAMES):
            operation, _ = self.store.begin({'action': 'check', 'nonce': ('a' if names == NAMES else 'b')*64, 'components': ['application']})
            runner = retain_runner(self.store, operation['id'], installed, names=names, trust=installed)
            self.store.update(operation['id'], runner=runner, state='applying')
            self.store.mark_pending(operation['id'])
            from unittest.mock import patch
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
