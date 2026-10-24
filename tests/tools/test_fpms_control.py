import importlib.util
import json
import os
from pathlib import Path
import signal
import tempfile
import unittest
from unittest import mock

HERE = Path(__file__).resolve()
spec = importlib.util.spec_from_file_location('fpms_control', HERE.parents[2] / 'tools/fpms-control.py')
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)

ENABLED = dict(enabled=True, open_on_start=False)
CAPABILITY = dict(protocol=1)
BEAT = dict(protocol=1, at=100, hardware=True)


def within(seconds, function, *args, **kwargs):
    """A call that would wait on a pipe fails the test instead of hanging the run."""
    def alarm(*_):
        raise TimeoutError('blocked')
    signal.signal(signal.SIGALRM, alarm)
    signal.alarm(seconds)
    try:
        return function(*args, **kwargs)
    finally:
        signal.alarm(0)


class ConfigTest(unittest.TestCase):
    def test_config_fails_closed(self):
        for data in (None, [], {}, {'enabled': 'yes', 'open_on_start': False}, {'enabled': 1, 'open_on_start': False},
                     dict(enabled=True, open_on_start=False, command='stop')):
            with self.subTest(data=data), self.assertRaises(ValueError):
                control.valid_config(data)


class DesiredTest(unittest.TestCase):
    def test_data_is_requested_only_for_an_enabled_compatible_live_display(self):
        d = control.desired
        self.assertEqual((False, 'off'), d(control.DEFAULTS, CAPABILITY, BEAT, 101))
        self.assertEqual((False, 'not installed'), d(ENABLED, {}, BEAT, 101))
        self.assertEqual((False, 'incompatible'), d(ENABLED, dict(protocol=2), BEAT, 101))
        self.assertEqual((False, 'waiting for FPMS'), d(ENABLED, CAPABILITY, BEAT, 116))
        self.assertEqual((False, 'waiting for FPMS'), d(ENABLED, CAPABILITY, dict(BEAT, at=200), 101))
        self.assertEqual((False, 'no hardware'), d(ENABLED, CAPABILITY, dict(BEAT, hardware=False), 101))
        self.assertEqual((True, 'running'), d(ENABLED, CAPABILITY, BEAT, 101))

    def test_an_absurd_heartbeat_time_is_not_fresh_and_not_a_crash(self):
        for value in (10**400, -(10**400), float('inf'), float('nan'), True, '100', None):
            with self.subTest(value=value):
                self.assertEqual((False, 'waiting for FPMS'), control.desired(ENABLED, CAPABILITY, dict(BEAT, at=value), 101))

    def test_shared_data_does_not_stop_with_hdmi(self):
        root = HERE.parents[2] / 'debian'
        data = (root / 'cmprovision-wallboard-data.service').read_text()
        self.assertNotIn('PartOf=', data)
        self.assertIn('StopWhenUnneeded=yes', data)
        for consumer in ['cmprovision-wallboard-display', 'cmprovision-fpms-data']:
            self.assertIn('Wants=cmprovision-wallboard-data.service', (root / (consumer + '.service')).read_text())


class SafeReadTest(unittest.TestCase):
    """Root reads small files from places where somebody else may have put something else."""

    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.path = Path(self.dir.name)
        self.me = (os.geteuid(),)

    def tearDown(self):
        self.dir.cleanup()

    def test_a_regular_file_of_an_allowed_owner_is_read_and_a_stranger_s_is_not(self):
        (self.path / 'a.json').write_text('{"a": 1}')
        self.assertEqual({'a': 1}, control.read_json(self.path / 'a.json', owners=self.me))
        self.assertEqual({}, control.read_json(self.path / 'a.json', owners=(os.geteuid() + 1,)))

    def test_a_pipe_does_not_hang_the_reader(self):
        fifo = self.path / 'fifo.json'
        os.mkfifo(fifo)
        self.assertEqual({}, within(5, control.read_json, fifo, owners=self.me))

    def test_a_link_is_not_followed_and_a_directory_is_not_a_file(self):
        (self.path / 'target.json').write_text('{"a": 1}')
        (self.path / 'link.json').symlink_to(self.path / 'target.json')
        self.assertEqual({}, control.read_json(self.path / 'link.json', owners=self.me))
        self.assertEqual({}, control.read_json(self.path, owners=self.me))
        self.assertEqual({}, control.read_json(self.path / 'missing.json', owners=self.me))

    def test_too_big_or_not_an_object_is_empty(self):
        (self.path / 'big.json').write_text(json.dumps({'a': 'x' * 5000}))
        (self.path / 'list.json').write_text('[1]')
        (self.path / 'deep.json').write_text('[' * 3000 + ']' * 3000)
        (self.path / 'junk.json').write_bytes(b'\xff\xfe{')
        for name in ('big.json', 'list.json', 'deep.json', 'junk.json'):
            with self.subTest(name=name):
                self.assertEqual({}, control.read_json(self.path / name, owners=self.me))

    def test_the_snapshot_is_judged_by_its_age_without_opening_it(self):
        snapshot = self.path / 'snapshot.json'
        with mock.patch.object(control, 'SNAPSHOT', snapshot):
            self.assertIsNone(control.snapshot_age(1000.0))
            snapshot.write_text('{}')
            os.utime(snapshot, (990, 990))
            self.assertEqual(10, control.snapshot_age(1000.0))
            snapshot.unlink()
            os.mkfifo(snapshot)      # not a regular file: no age, and nothing opened it
            self.assertIsNone(within(5, control.snapshot_age, 1000.0))
            snapshot.unlink()
            snapshot.symlink_to(self.path / 'elsewhere')
            self.assertIsNone(control.snapshot_age(1000.0))


class WatcherTest(unittest.TestCase):
    """The state machine of the root watcher, with the world replaced."""

    def run_steps(self, watcher, steps, settings=ENABLED, capability=CAPABILITY, beat=BEAT, age=1.0, systemctl=None):
        calls = []

        def fake(action):
            calls.append(action)
            return systemctl(action) if systemctl else {'is-active': False, 'start': True, 'stop': True}[action]
        reads = {control.CONFIG: settings, control.CAPABILITY: capability, control.HEARTBEAT: beat}
        with mock.patch.object(control, 'config', lambda: settings), \
                mock.patch.object(control, 'read_json', lambda path, *a, **k: reads[path]), \
                mock.patch.object(control, 'snapshot_age', lambda now: age), \
                mock.patch.object(control, 'systemctl', fake):
            states = [watcher.step(now, mono) for now, mono in steps]
        return states, calls

    def test_an_enabled_live_display_starts_the_consumer_once_and_it_stays(self):
        watcher = control.Watcher()
        active = {'on': False}

        def ctl(action):
            if action == 'start':
                active['on'] = True
                return True
            return active['on']
        states, calls = self.run_steps(watcher, [(101, 1), (104, 4)], systemctl=ctl)
        self.assertEqual(['running', 'running'], states)
        self.assertEqual(['is-active', 'start', 'is-active'], calls)

    def test_a_start_that_fails_is_reported_and_tried_again_after_half_a_minute_not_every_cycle(self):
        watcher = control.Watcher()
        # the clock of the heartbeat stays within its fifteen seconds while the monotonic clock goes half a minute on
        states, calls = self.run_steps(watcher, [(101, 1), (104, 4), (105, 40)],
                                       systemctl=lambda action: False)
        self.assertEqual(['error', 'error', 'error'], states)
        self.assertEqual(['start', 'start'], [c for c in calls if c == 'start'])

    def test_an_old_snapshot_is_stale_and_a_missing_one_too(self):
        for age in (21.0, None):
            with self.subTest(age=age):
                states, _ = self.run_steps(control.Watcher(), [(101, 1)], age=age, systemctl=lambda action: True)
                self.assertEqual(['stale'], states)

    def test_disabling_stops_the_consumer_and_a_stop_that_fails_is_an_error(self):
        watcher = control.Watcher()
        watcher.active = True
        states, calls = self.run_steps(watcher, [(101, 1)], settings=control.DEFAULTS, systemctl=lambda action: True)
        self.assertEqual(['off'], states)
        self.assertEqual(['is-active', 'stop'], calls)

        watcher = control.Watcher()
        watcher.active = True
        states, _ = self.run_steps(watcher, [(101, 1)], settings=control.DEFAULTS, systemctl=lambda action: action == 'is-active')
        self.assertEqual(['error'], states)

    def test_with_fpms_off_the_unit_is_looked_at_seldom(self):
        watcher = control.Watcher()
        steps = [(100 + 3 * i, 1000 + 3 * i) for i in range(12)]    # thirty-six seconds
        states, calls = self.run_steps(watcher, steps, settings=control.DEFAULTS, systemctl=lambda action: False)
        self.assertEqual(['off'] * 12, states)
        self.assertEqual(2, calls.count('is-active'))      # at the start and once half a minute later

    def test_a_heartbeat_of_an_absurd_time_does_not_stop_the_watcher(self):
        states, _ = self.run_steps(control.Watcher(), [(101, 1)], beat=dict(BEAT, at=10**400), systemctl=lambda action: False)
        self.assertEqual(['waiting for FPMS'], states)


if __name__ == '__main__':
    unittest.main()
