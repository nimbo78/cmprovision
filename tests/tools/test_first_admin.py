import concurrent.futures
import hashlib
import os
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from first_admin import Bootstrap, save


class FirstAdminTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.base = Path(temp.name)
        self.people = {}
        self.clock = 100
        self.broker = Bootstrap(self.base / 'private', self.base / 'display', self.database,
                                os.getuid(), os.getgid(), lambda: self.clock, 'boot-a')
        self.user = dict(name='Operator', email='op@example.org', password_hash='$2y$12$'+'A'*53)

    def database(self, payload):
        if not payload:
            return {'result': 'occupied' if self.people else 'empty'}
        claim = payload['claim']
        if self.people and claim not in self.people:
            return {'result': 'occupied'}
        self.people[claim] = payload
        return {'result': 'committed', 'claim': claim, 'id': 1}

    def arm(self):
        self.broker.local('fresh')
        return self.broker.local('show')['code']

    def submit(self, code):
        return self.broker.web(dict(action='claim', code=code, **self.user))

    def test_fresh_code_survives_boot_then_closes_even_if_users_removed(self):
        code = self.arm()
        self.assertRegex(code, r'^[A-Z2-7]{16}$')
        self.broker.local('resume')
        self.assertEqual(code, self.broker.local('show')['code'])
        self.assertEqual(self.broker.web({'action': 'status'}), {'state': 'armed'})
        projection = self.base / 'display/display.json'
        self.assertEqual(projection.stat().st_mode & 0o777, 0o640)
        self.assertIn(code, projection.read_text())
        typed = '-'.join(code[i:i+4].lower() for i in range(0, 16, 4))
        self.assertEqual(self.submit(typed), {'state': 'claimed', 'id': 1})
        self.assertFalse(projection.exists())
        self.people.clear()
        for action in ('resume', 'fresh', 'upgrade'):
            self.assertEqual(self.broker.local(action)['state'], 'closed')
        self.assertEqual(self.submit(code), {'state': 'closed'})

    def test_fresh_clones_rotation_and_global_rate_limit(self):
        original = self.arm()
        self.broker.local('rotate')
        code = self.broker.local('show')['code']
        self.assertNotEqual(code, original)
        for _ in range(5):
            self.assertEqual(self.submit(original)['state'], 'invalid')
        self.assertEqual(self.submit(code)['state'], 'limited')
        self.clock += 61
        self.assertEqual(self.submit(code)['state'], 'claimed')

    def test_interrupted_claim_resumes_without_authenticating_retry(self):
        code = self.arm()
        def interrupted(payload):
            result = self.database(payload)
            if payload:
                raise OSError('simulated lost reply')
            return result
        self.broker.database = interrupted
        with self.assertRaises(OSError):
            self.submit(code)
        self.broker.database = self.database
        self.assertEqual(self.submit(code), {'state': 'closed'})
        self.assertEqual(len(self.people), 1)
        self.assertFalse((self.base / 'display/display.json').exists())

    def test_concurrent_claims_have_one_winner(self):
        code = self.arm()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            result = list(pool.map(self.submit, [code, code]))
        self.assertCountEqual([r['state'] for r in result], ['claimed', 'closed'])
        self.assertEqual(len(self.people), 1)

    def test_image_requires_the_committed_project_code_and_consumes_it_once(self):
        code = 'ABCDEFGHIJKLMNOP'
        marker = self.base / 'project-setup.json'
        seed = self.base / 'web-setup.json'
        save(marker, {'web_setup_sha256': hashlib.sha256(code.encode()).hexdigest()})
        with self.assertRaises(ValueError):
            self.broker.image_fresh(self.base)
        save(seed, 'PONMLKJIHGFEDCBA')
        with self.assertRaises(ValueError):
            self.broker.image_fresh(self.base)
        save(seed, code)
        self.assertEqual({'state': 'armed'}, self.broker.image_fresh(self.base))
        self.assertFalse(seed.exists())
        self.assertEqual({'state': 'armed'}, self.broker.image_fresh(self.base))
        self.assertEqual(code, self.broker.local('show')['code'])
        self.submit(code)
        self.assertEqual({'state': 'closed'}, self.broker.image_fresh(self.base))

    def test_upgrade_restore_and_private_state(self):
        for op in ('fresh', 'rotate', 'show', 'close'):
            with self.assertRaises(ValueError):
                self.broker.web({'action': op})
        self.assertEqual(self.broker.local('upgrade')['state'], 'closed')
        self.assertEqual(self.broker.local('fresh')['state'], 'closed')
        self.assertEqual(self.broker.local('close')['state'], 'closed')
        state = self.base / 'private/state.json'
        self.assertEqual(state.stat().st_mode & 0o777, 0o600)
        state.unlink()
        state.symlink_to(self.base / 'untouched')
        with self.assertRaises(OSError):
            self.broker.local('fresh')
        self.assertFalse((self.base / 'untouched').exists())
