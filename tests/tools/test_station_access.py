"""Authorization and filesystem contracts; no real Linux accounts are changed."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest

TOOLS = Path(__file__).resolve().parents[2] / 'tools'
sys.path.insert(0, str(TOOLS))


class Backend:
    def __init__(self):
        self.users = [{'user': 'operator', 'uid': 1000, 'gid': 1000, 'home': '/home/operator', 'shell': '/bin/bash'}]
        self.identity = self.users[0] | {'credential_revision': 'old'}
        self.applied = []

    def status(self):
        return {'users': copy.deepcopy(self.users), 'ssh_active': True, 'password_login': True, 'key_login': True}

    def inspect(self, user):
        if user != 'operator':
            raise ValueError('Choose an existing ordinary Linux administrator.')
        return copy.deepcopy(self.identity)

    def validate_keys(self, keys):
        if keys and not keys.startswith('ssh-ed25519 '):
            raise ValueError('Invalid SSH public key.')
        return keys.strip()

    def hash_password(self, password):
        return '$6$test$' + password[::-1]

    def apply(self, identity, changes):
        self.applied.append((identity, changes))
        return ['password'] if changes.get('password_hash') else ['keys']


class StationAccessTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'state'
        self.now = 1000
        self.backend = Backend()
        self.module = __import__('station_access')
        self.control = self.module.Controller(self.root, self.backend, clock=lambda: self.now)

    def prepare(self, **changes):
        return self.control.request({'action': 'prepare', 'user': 'operator', 'password': 'new-password-123',
                                     'keys': '', 'enable_ssh': False, **changes})

    def approved(self):
        plan = self.prepare()
        code = self.control.approve(plan['id'])['code']
        return plan, code

    def test_preparation_keeps_only_a_hash_and_safe_summary(self):
        plan = self.prepare()
        state = self.root.joinpath('pending.json').read_text()
        self.assertNotIn('new-password-123', state)
        self.assertNotIn('password_hash', json.dumps(plan))
        self.assertNotIn('code', json.dumps(plan))
        self.assertEqual([], self.backend.applied)
        self.assertEqual('operator', plan['user'])
        self.assertEqual(['Change Linux password'], plan['changes'])
        self.assertEqual(0o700, stat.S_IMODE(self.root.stat().st_mode))
        self.assertEqual(0o600, stat.S_IMODE((self.root / 'pending.json').stat().st_mode))

    def test_web_router_cannot_issue_or_read_a_code(self):
        plan, code = self.approved()
        for action in ['approve', 'issue', 'read-code']:
            with self.assertRaises(ValueError):
                self.control.request({'action': action, 'id': plan['id']})
        self.assertNotIn(code, json.dumps(self.control.request({'action': 'status'})))

    def test_valid_code_applies_exact_original_plan_once(self):
        plan, code = self.approved()
        result = self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual('applied', result['state'])
        self.assertEqual(1, len(self.backend.applied))
        self.assertEqual('$6$test$321-drowssap-wen', self.backend.applied[0][1]['password_hash'])
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertNotIn('password_hash', (self.root / 'receipt.json').read_text())

    def test_payload_cannot_be_substituted_at_apply(self):
        plan, code = self.approved()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code, 'user': 'root'})
        self.assertEqual([], self.backend.applied)

    def test_approval_does_not_accept_a_changed_summary_from_web(self):
        plan = self.prepare()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'prepare', 'user': 'operator', 'password': 'other-password-123',
                                  'keys': '', 'enable_ssh': False})
        self.assertEqual(plan['id'], self.control.request({'action': 'status'})['pending']['id'])

    def test_five_wrong_attempts_revoke_the_operation(self):
        plan, code = self.approved()
        for _ in range(5):
            with self.assertRaises(ValueError):
                self.control.request({'action': 'apply', 'id': plan['id'], 'code': 'WRONG-CODE'})
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_code_and_unapproved_plan_expire(self):
        plan, code = self.approved()
        self.now += 120
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)
        self.assertIsNone(self.control.request({'action': 'status'})['pending'])
        plan = self.prepare()
        self.now += 300
        with self.assertRaises(ValueError):
            self.control.approve(plan['id'])

    def test_concurrent_identity_or_password_change_invalidates_approval(self):
        plan, code = self.approved()
        self.backend.identity['credential_revision'] = 'changed elsewhere'
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual([], self.backend.applied)

    def test_cancel_removes_only_the_named_operation(self):
        plan = self.prepare()
        with self.assertRaises(ValueError):
            self.control.request({'action': 'cancel', 'id': '0' * 32})
        self.control.request({'action': 'cancel', 'id': plan['id']})
        self.assertIsNone(self.control.request({'action': 'status'})['pending'])

    def test_invalid_users_empty_changes_secret_delimiters_and_large_keys_rejected(self):
        cases = [{'user': 'root'}, {'user': '../operator'}, {'password': 'short'},
                 {'password': 'bad\npassword12'}, {'password': 'bad\x00password12'},
                 {'password': '', 'keys': '', 'enable_ssh': False}, {'keys': 'x' * 16385},
                 {'keys': 'command="evil" ssh-ed25519 key'}, {'enable_ssh': 'yes'}]
        for case in cases:
            with self.subTest(case=repr(case)[:70]), self.assertRaises(ValueError):
                self.prepare(**case)

    def test_pending_state_symlink_is_not_followed(self):
        self.root.mkdir(mode=0o700)
        target = Path(self.tmp.name) / 'target'
        target.write_text('sentinel')
        (self.root / 'pending.json').symlink_to(target)
        with self.assertRaises((ValueError, OSError)):
            self.prepare()
        self.assertEqual('sentinel', target.read_text())

    def test_backend_failure_consumes_code_and_preserves_a_safe_receipt(self):
        plan, code = self.approved()
        def fail(*args):
            raise RuntimeError('backend failure')
        self.backend.apply = fail
        with self.assertRaises(RuntimeError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        with self.assertRaises(ValueError):
            self.control.request({'action': 'apply', 'id': plan['id'], 'code': code})
        self.assertEqual('failed', json.loads((self.root / 'receipt.json').read_text())['state'])


class UserKeysTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.home = Path(self.tmp.name)
        self.linux = __import__('station_access_linux').Linux

    def test_preserves_existing_restricted_keys_and_adds_once(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        path = folder / 'authorized_keys'
        original = 'restrict,command="/bin/true" ssh-ed25519 ORIGINAL old access'
        path.write_text(original)
        path.chmod(0o600)
        self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.assertTrue(path.read_text().startswith(original + '\n'))
        self.assertEqual(1, path.read_text().count('ssh-ed25519 NEW'))

    def test_key_file_and_directory_symlinks_are_rejected_without_touching_target(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        target = self.home / 'sentinel'
        target.write_text('keep')
        path = folder / 'authorized_keys'
        path.symlink_to(target)
        with self.assertRaises((ValueError, OSError)):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        self.assertEqual('keep', target.read_text())
        path.unlink()
        folder.rmdir()
        folder.symlink_to(self.home, target_is_directory=True)
        with self.assertRaises((ValueError, OSError)):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')

    def test_hardlinks_world_writable_files_and_fifo_are_rejected(self):
        folder = self.home / '.ssh'
        folder.mkdir(mode=0o700)
        path = folder / 'authorized_keys'
        path.write_text('keep')
        path.chmod(0o600)
        os.link(path, self.home / 'hardlink')
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        (self.home / 'hardlink').unlink()
        path.chmod(0o666)
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')
        path.unlink()
        os.mkfifo(path)
        with self.assertRaises(ValueError):
            self.linux.write_user_keys(self.home, 'ssh-ed25519 NEW')


if __name__ == '__main__':
    unittest.main()
