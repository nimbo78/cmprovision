"""Privileged integration is opt-in and always starts its own network namespace."""
import os
from pathlib import Path
import subprocess
import unittest


@unittest.skipUnless(os.environ.get('CMP_RESCUE_NETWORK_TEST') == '1', 'isolated root NM/D-Bus integration')
class SharedRoutingTest(unittest.TestCase):
    def test_real_shared_mode_and_controller_guard_in_a_private_network_namespace(self):
        self.assertEqual(0, os.geteuid(), 'Run only in the dedicated root Linux test environment.')
        fixture = Path(__file__).with_name('shared_fixture.py')
        self.assertTrue(fixture.exists(), 'Real NetworkManager sharing fixture is missing.')
        for initial in ('0', '1'):
            with self.subTest(initial_forwarding=initial):
                result = subprocess.run(['unshare', '--net', '--mount', '--fork', 'python3', '-B', str(fixture)],
                                        capture_output=True, text=True, timeout=100,
                                        env=dict(os.environ, CMP_RESCUE_FORWARD_INITIAL=initial))
                self.assertEqual(0, result.returncode, result.stdout + result.stderr)
                self.assertIn('NM_SHARED_ACCEPTANCE_OK', result.stdout)
                print(result.stdout.strip())


if __name__ == '__main__': unittest.main()
