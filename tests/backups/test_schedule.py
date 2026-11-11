from datetime import datetime, timezone
import importlib
import unittest

from support import ROOT


class ScheduleTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_schedule')
        self.config = {'enabled': True, 'frequency': 'daily', 'time': '03:00', 'weekday': 0,
            'profile': 'a'*32, 'keep': 7, 'images': False, 'packages': False, 'not_before': 0}

    @staticmethod
    def timestamp(value): return int(datetime.fromisoformat(value).replace(tzinfo=timezone.utc).timestamp())

    def test_disabled_daily_missed_and_repeated_ticks(self):
        now = self.timestamp('2026-11-11T08:00:00')
        self.assertIsNone(self.module.due(dict(self.config, enabled=False), [], now, 'Europe/London'))
        slot = self.module.due(self.config, [], now, 'Europe/London')
        self.assertIsInstance(slot, str)
        self.assertIsNone(self.module.due(self.config, [slot], now+60, 'Europe/London'))
        self.assertIsNone(self.module.due(dict(self.config, not_before=now+1), [], now, 'Europe/London'))
        self.assertIsNotNone(self.module.next_run(self.config, now, 'Europe/London'))

    def test_weekly_respects_weekday_and_spring_gap_runs_once(self):
        weekly = dict(self.config, frequency='weekly', weekday=0)
        now = self.timestamp('2026-11-11T08:00:00')
        slot = self.module.due(weekly, [], now, 'Europe/London')
        self.assertIn('2026-11-09', slot)
        spring = dict(self.config, time='01:30')
        before = self.timestamp('2026-03-29T00:59:00')
        yesterday = self.module.due(spring, [], before, 'Europe/London')
        slot = self.module.due(spring, [yesterday], self.timestamp('2026-03-29T01:05:00'), 'Europe/London')
        self.assertIn('2026-03-29', slot)
        self.assertIsNone(self.module.due(spring, [slot], self.timestamp('2026-03-29T01:45:00'), 'Europe/London'))

    def test_repeated_autumn_hour_does_not_create_two_copies(self):
        autumn = dict(self.config, time='01:30')
        first = self.module.due(autumn, [], self.timestamp('2026-10-25T00:40:00'), 'Europe/London')
        self.assertIsNotNone(first)
        self.assertIsNone(self.module.due(autumn, [first], self.timestamp('2026-10-25T01:40:00'), 'Europe/London'))


if __name__ == '__main__': unittest.main()
