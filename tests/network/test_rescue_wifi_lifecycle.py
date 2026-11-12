import os
from pathlib import Path
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT/'tools'))
import rescue_wifi
from test_rescue_wifi_controller import FakeLinux


@unittest.skipUnless(os.name == 'posix', 'Linux controller lifecycle')
class LifecycleTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.store = rescue_wifi.Store(Path(self.tmp.name), verify_root=False)
        self.store.initialize()
        self.linux, self.now = FakeLinux(), 1000
        self.control = rescue_wifi.Controller(self.store, self.linux, monotonic=lambda:self.now,
                                              wallclock=lambda:1790000000, boot=lambda:'boot-a')

    def test_default_disabled_worker_does_not_touch_nm_or_radios(self):
        self.linux.known = False
        self.assertEqual('off', self.control.check()['state'])
        self.assertEqual([], self.linux.calls)

    def test_restart_adopts_existing_ap_without_reactivation(self):
        self.control.configure({'enabled':True})
        self.control.request('start')
        self.control.check()
        restarted = rescue_wifi.Controller(self.store, self.linux, monotonic=lambda:self.now,
                                             wallclock=lambda:1790000000, boot=lambda:'boot-a')
        restarted.check()
        self.assertTrue(restarted.busy())
        self.assertEqual(['start'], self.linux.calls)

    def test_missing_runtime_record_does_not_release_a_working_owned_ap(self):
        self.control.configure({'enabled':True})
        self.linux.active = True
        self.control.check()
        self.assertTrue(self.control.busy())
        self.assertNotIn('start', self.linux.calls)

    def test_removal_disables_automatic_restart_after_deactivating_owned_ap(self):
        self.assertTrue(hasattr(self.control, 'retire'), 'Package removal needs owned-AP cleanup.')
        self.control.configure({'enabled':True})
        self.control.request('start')
        self.control.check()
        self.control.retire()
        self.assertFalse(self.linux.active)
        self.assertFalse(self.control.config()['enabled'])
        self.assertFalse(self.control.busy())

    def test_timer_and_fixed_sudo_actions_are_packaged(self):
        service = ROOT/'debian/cmprovision-rescue-wifi.service'
        timer = ROOT/'debian/cmprovision-rescue-wifi.timer'
        sudo = ROOT/'debian/021_cmprovision-rescue-wifi'
        self.assertTrue(service.exists(), 'Package must install its rescue worker.')
        self.assertTrue(timer.exists(), 'Package must reconcile after missed NM events.')
        self.assertTrue(sudo.exists(), 'Web actions need a fixed-command privilege boundary.')
        lines = [line for line in sudo.read_text().splitlines() if line and not line.startswith('#')]
        actions = {line.rsplit(' ', 1)[-1] for line in lines}
        self.assertEqual({'status', 'configure', 'start', 'pause', 'resume', 'restart', 'reveal'}, actions)
        self.assertFalse(any('*' in line or 'ALL: ALL' in line for line in lines))


if __name__ == '__main__': unittest.main()
