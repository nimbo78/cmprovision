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
from update_recovery import retain_runner, dispatch, NAMES


class RecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.store = UpdateStore(self.root/'updates', self.root/'run', owner=os.getuid())

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
