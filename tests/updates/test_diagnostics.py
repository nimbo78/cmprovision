import contextlib
import importlib
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from support import CONTEXT
from update_manifest import UpdateError
from update_store import UpdateStore


class DiagnosticsTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.store = UpdateStore(self.root/'store', self.root/'runtime', owner=os.getuid())
        self.module = importlib.import_module('update_diagnostics')
        self.number = 0

    def operation(self):
        self.number += 1
        value, _ = self.store.begin({'action': 'check', 'nonce': f'{self.number:064x}', 'components': ['application']})
        return value['id']

    def caught(self):
        try:
            raise OSError(104, 'secret=do-not-export https://login:password@host/path?token=private')
        except OSError as cause:
            try:
                raise RuntimeError('Authorization: Bearer do-not-export') from cause
            except RuntimeError as error:
                return error

    def test_private_report_has_frames_and_safe_cause_but_no_freeform_text(self):
        identity = self.operation()
        output = io.StringIO()
        with contextlib.redirect_stderr(output):
            summary = self.module.record(self.store, identity, self.caught(), 'checking')
        report = self.module.read(self.store, identity)
        raw = json.dumps(report)+output.getvalue()+json.dumps(summary)
        self.assertEqual(identity, summary['reference'])
        self.assertEqual('checking', summary['phase'])
        failure = report['failures'][0]
        self.assertEqual('RuntimeError', failure['error']['type'])
        self.assertEqual(104, failure['error']['cause']['errno'])
        self.assertTrue(failure['error']['frames'])
        self.assertNotIn('do-not-export', raw)
        self.assertNotIn('https://', raw)
        self.assertNotIn('Bearer', raw)
        self.assertNotIn('line_source', raw)
        path = self.store.private/'diagnostics'/(identity+'.json')
        self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_hostile_exception_string_and_failed_diagnostic_write_are_inert(self):
        class Hostile(Exception):
            def __str__(self): raise AssertionError('must not stringify unexpected exceptions')
        identity = self.operation()
        with contextlib.redirect_stderr(io.StringIO()):
            summary = self.module.record(self.store, identity, Hostile(), 'checking')
            with patch.object(self.store, '_atomic', side_effect=OSError('disk unavailable')):
                another = self.module.record(self.store, identity, self.caught(), 'recovery')
        self.assertEqual('Hostile', summary['type'])
        self.assertEqual(identity, another['reference'])

    def test_original_and_recovery_failures_are_separate_and_report_is_bounded(self):
        identity = self.operation()
        with contextlib.redirect_stderr(io.StringIO()):
            self.module.record(self.store, identity, self.caught(), 'installing')
            self.module.record(self.store, identity, RuntimeError('private recovery text'), 'recovery')
        report = self.module.read(self.store, identity)
        self.assertEqual(['installing', 'recovery'], [item['phase'] for item in report['failures']])
        self.assertLess(len(json.dumps(report).encode()), 65536)

    def test_invalid_unknown_and_symlink_report_ids_are_rejected(self):
        for identity in ('../private', 'a'*31, 'a'*32):
            with self.subTest(identity=identity), self.assertRaises(UpdateError):
                self.module.read(self.store, identity)
        identity = self.operation()
        folder = self.store.private/'diagnostics'
        self.store._directory(folder, 0o700)
        (folder/(identity+'.json')).symlink_to(self.store.state_file)
        with self.assertRaises(UpdateError): self.module.read(self.store, identity)

    def test_retention_preserves_recovery_required_and_unknown_files(self):
        first = self.operation()
        with contextlib.redirect_stderr(io.StringIO()):
            self.module.record(self.store, first, self.caught(), 'checking')
            foreign = self.store.private/'diagnostics'/'operator-notes.txt'
            foreign.write_text('not owned by diagnostic retention')
            self.store.update(first, state='recovery-required')
            # Complete the fixture state; recoverable operation itself must remain protected.
            with self.store.lock('control'):
                state = self.store._load()
                state['current'] = None
                self.store._persist(state)
            for _ in range(23):
                identity = self.operation()
                self.module.record(self.store, identity, self.caught(), 'checking')
                self.store.update(identity, state='failed')
        folder = self.store.private/'diagnostics'
        self.assertTrue((folder/(first+'.json')).exists())
        self.assertTrue(foreign.exists())
        self.assertLessEqual(len(list(folder.glob('*.json'))), 21)


if __name__ == '__main__': unittest.main()
