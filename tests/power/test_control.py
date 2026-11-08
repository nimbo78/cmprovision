"""All system commands are mocked; tests must never reboot their host."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('station_power', ROOT / 'tools/station-power.py')
power = importlib.util.module_from_spec(spec)
spec.loader.exec_module(power)
BOOT = '11111111-1111-4111-8111-111111111111'


class PowerControlTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name) / 'power'
        self.run = Mock(return_value=subprocess.CompletedProcess([], 0, '', ''))
        self.owner = patch.object(power, 'ROOT_UID', os.geteuid())
        self.owner.start()
        self.controller = power.Controller(self.root, self.run, clock=lambda: 100, boot=lambda: BOOT)

    def tearDown(self):
        self.owner.stop()
        self.temp.cleanup()

    def test_only_normal_reboot_and_poweroff_are_scheduled_with_fixed_delay(self):
        for action in ('reboot', 'poweroff'):
            with self.subTest(action=action):
                if self.root.exists(): (self.root / 'pending.json').unlink(missing_ok=True)
                result = self.controller.schedule(action)
                self.assertEqual(action, result['action'])
                self.assertEqual(5, result['delay'])
                args, kwargs = self.run.call_args
                self.assertEqual(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-station-power',
                    '--on-active=5s', '--timer-property=AccuracySec=1s', '--', '/usr/bin/systemctl', action], args[0])
                self.assertNotIn('--force', args[0])
                self.assertEqual(5, kwargs['timeout'])
                self.assertEqual(result, json.loads((self.root / 'pending.json').read_text()))
                self.assertEqual(0o644, (self.root / 'pending.json').stat().st_mode & 0o777)

    def test_bad_actions_never_spawn_a_process(self):
        for action in ('halt', 'reboot; id', '--force', None, True, ['reboot']):
            with self.subTest(action=action), self.assertRaises(power.PowerError):
                self.controller.schedule(action)
        self.run.assert_not_called()

    def test_duplicate_requests_do_not_schedule_twice(self):
        self.controller.schedule('reboot')
        with self.assertRaises(power.PowerError): self.controller.schedule('poweroff')
        self.assertEqual(1, self.run.call_count)

    def test_scheduler_failure_or_timeout_keeps_an_uncertain_pending_marker(self):
        for failure in (subprocess.CompletedProcess([], 1, '', 'error'), subprocess.TimeoutExpired([], 5)):
            if self.root.exists(): (self.root / 'pending.json').unlink(missing_ok=True)
            self.run.reset_mock()
            self.run.side_effect = failure if isinstance(failure, Exception) else None
            self.run.return_value = failure
            with self.assertRaises(power.PowerError): self.controller.schedule('poweroff')
            self.assertFalse(json.loads((self.root / 'pending.json').read_text())['confirmed'])

    def test_symlink_or_group_writable_runtime_is_refused(self):
        self.root.mkdir(mode=0o755)
        self.root.chmod(0o777)
        with self.assertRaises(power.PowerError): self.controller.schedule('reboot')
        self.root.chmod(0o755)
        (self.root / 'control.lock').symlink_to('/dev/null')
        with self.assertRaises((power.PowerError, OSError)): self.controller.schedule('reboot')
        self.run.assert_not_called()

    def test_runtime_is_readable_by_the_web_gate_even_with_restrictive_umask(self):
        previous = os.umask(0o077)
        try: self.controller.schedule('reboot')
        finally: os.umask(previous)
        self.assertEqual(0o755, self.root.stat().st_mode & 0o777)

    def test_existing_private_or_wrong_owner_runtime_is_refused(self):
        self.root.mkdir(mode=0o700)
        with self.assertRaises(power.PowerError): self.controller.schedule('reboot')
        self.root.chmod(0o755)
        with patch.object(power, 'ROOT_UID', os.geteuid() + 1), self.assertRaises(power.PowerError):
            self.controller.schedule('reboot')
        self.run.assert_not_called()

    def test_pending_file_symlink_is_never_followed(self):
        self.root.mkdir(mode=0o755)
        target = Path(self.temp.name) / 'untouched'
        target.write_text('keep')
        (self.root / 'pending.json').symlink_to(target)
        with self.assertRaises(power.PowerError): self.controller.schedule('reboot')
        self.assertEqual('keep', target.read_text())
        self.run.assert_not_called()

    def test_old_boot_and_expired_pending_do_not_block_new_requests(self):
        self.controller.schedule('reboot')
        data = json.loads((self.root / 'pending.json').read_text())
        for change in ({'boot_id': '22222222-2222-4222-8222-222222222222'}, {'scheduled_at': -21}):
            (self.root / 'pending.json').write_text(json.dumps(dict(data, **change)))
            self.assertIsNone(self.controller.pending())

    def test_panel_request_uses_fixed_www_data_transient_service_and_json_stdin(self):
        self.run.return_value = subprocess.CompletedProcess([], 0, '{"ok":true,"result":{"stage":"confirm"}}', '')
        request = {'operation': 'prepare', 'action': 'reboot'}
        result = power.panel(request, self.run)
        self.assertTrue(result['ok'])
        command = self.run.call_args.args[0]
        self.assertEqual('/usr/bin/systemd-run', command[0])
        self.assertIn('--uid=www-data', command)
        self.assertEqual(['/usr/bin/php', '/var/lib/cmprovision/artisan', 'station:power', '--json'], command[-4:])
        self.assertEqual(request, json.loads(self.run.call_args.kwargs['input']))
        self.assertNotIn('reboot', command)

    def test_invalid_panel_payloads_never_start_a_service(self):
        for request in ({'operation': 'shell', 'command': 'id'}, {'operation': 'prepare', 'action': 'reboot;id'},
                        {'operation': 'confirm', 'token': '../x', 'interrupt': True},
                        {'operation': 'confirm', 'token': 'a'*64, 'interrupt': 'yes'}, {'operation': 'status', 'path': '/tmp/x'}):
            with self.subTest(request=request), self.assertRaises(power.PowerError): power.panel(request, self.run)
        self.run.assert_not_called()


if __name__ == '__main__': unittest.main()
