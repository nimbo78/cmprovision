#!/usr/bin/env python3
"""Check transactional failure and recovery behavior independently of any production network."""
import copy
from contextlib import contextmanager
import importlib.machinery
import sys
import unittest

module = importlib.machinery.SourceFileLoader('network_transaction', sys.argv.pop()).load_module()


class Backend:
    def __init__(self):
        self.state = None
        self.marker = False
        self.timer = False
        self.runs = False
        self.live = 'original'
        self.config = 'original'
        self.saved = 'original'
        self.fail = None

    @contextmanager
    def guard(self):
        yield

    def load(self): return copy.deepcopy(self.state)
    def pending(self): return self.marker
    def active_runs(self): return self.runs
    def validate(self, request): return request
    def snapshot(self, _): return {'config': self.config, 'saved': self.saved}
    def checkpoint(self, _): return '/checkpoint/1'
    def store(self, value): self.state = copy.deepcopy(value)
    def schedule_recovery(self, _): self.timer = True
    def set_pending(self): self.marker = True
    def clear_state(self): self.state = None
    def clear_pending(self): self.marker = False
    def cancel_recovery(self, _): self.timer = False
    def rollback_checkpoint(self, _): pass

    def stage_temporary(self, value): self.config = value

    def apply_temporary(self, value):
        self.live = value
        if self.fail == 'apply': raise RuntimeError('apply failed')

    def persist(self, value):
        self.saved = value
        if self.fail == 'persist': raise RuntimeError('persist failed')

    def commit_checkpoint(self, _):
        if self.fail == 'commit': raise RuntimeError('commit failed')

    def verify_before_confirm(self, original, change, applied):
        if self.config != applied['config'] or self.saved != original['saved']:
            raise RuntimeError('conflicting external change')

    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        if self.fail == 'restore': raise RuntimeError('recovery failed')
        if applied is not None and self.config not in (original['config'], applied['config']):
            raise RuntimeError('conflicting external change')
        self.config = original['config']
        self.live = original['config']
        self.saved = original['saved']


class TransactionTest(unittest.TestCase):
    def setUp(self):
        self.backend = Backend()
        self.tx = module.NetworkTransaction(self.backend)

    def test_confirm_persists_and_clears_the_gate(self):
        token = self.tx.begin('new')
        self.assertEqual(('new', 'original', True, True),
                         (self.backend.live, self.backend.saved, self.backend.marker, self.backend.timer))
        self.tx.confirm(token)
        self.assertEqual(('new', 'new', False, False, None),
                         (self.backend.live, self.backend.saved, self.backend.marker, self.backend.timer, self.backend.state))

    def test_timeout_or_interrupted_commit_restores_persistent_and_live_settings(self):
        self.tx.begin('new')
        self.backend.saved = 'new'  # process stopped after writing the profile and before completing confirmation
        self.tx.recover()
        self.assertEqual(('original', 'original', False, None),
                         (self.backend.live, self.backend.saved, self.backend.marker, self.backend.state))

    def test_authorized_cancel_recovers_and_wrong_token_does_not(self):
        token = self.tx.begin('new')
        with self.assertRaises(module.TransactionError): self.tx.cancel('wrong')
        self.assertTrue(self.backend.marker)
        self.tx.cancel(token)
        self.assertEqual('original', self.backend.live)
        self.assertFalse(self.backend.marker)

    def test_failed_apply_and_failed_commit_are_rolled_back(self):
        for stage in ('apply', 'commit'):
            with self.subTest(stage=stage):
                self.setUp()
                if stage == 'apply':
                    self.backend.fail = stage
                    with self.assertRaises(RuntimeError): self.tx.begin('new')
                else:
                    token = self.tx.begin('new')
                    self.backend.fail = stage
                    with self.assertRaises(RuntimeError): self.tx.confirm(token)
                self.assertEqual(('original', 'original', False, None),
                                 (self.backend.live, self.backend.saved, self.backend.marker, self.backend.state))

    def test_failed_recovery_keeps_the_gate_and_state_for_retry(self):
        self.tx.begin('new')
        self.backend.fail = 'restore'
        with self.assertRaises(RuntimeError): self.tx.recover()
        self.assertTrue(self.backend.marker)
        self.assertTrue(self.backend.timer)
        self.assertIsNotNone(self.backend.state)
        self.backend.fail = None
        self.tx.recover()
        self.assertEqual('original', self.backend.live)
        self.assertFalse(self.backend.marker)

    def test_active_runs_and_wrong_confirmation_cannot_change_settings(self):
        self.backend.runs = True
        with self.assertRaises(module.TransactionError): self.tx.begin('new')
        self.assertEqual('original', self.backend.live)
        self.backend.runs = False
        self.tx.begin('new')
        for invalid in ('not-the-token', 'неверно', None):
            with self.subTest(token=invalid), self.assertRaises(module.TransactionError): self.tx.confirm(invalid)
        self.assertEqual('original', self.backend.saved)
        self.assertTrue(self.backend.marker)

    def test_external_change_is_not_overwritten_during_confirmation_or_recovery(self):
        token = self.tx.begin('new')
        self.backend.live = self.backend.config = 'outside-change'
        with self.assertRaises(RuntimeError): self.tx.confirm(token)
        self.assertEqual('outside-change', self.backend.live)
        self.assertTrue(self.backend.marker)
        self.assertIsNotNone(self.backend.state)

    def test_a_late_watchdog_cannot_undo_a_newer_transaction(self):
        first = self.tx.begin('first')
        first_id = self.backend.state['id']
        self.tx.confirm(first)
        second = self.tx.begin('second')
        second_id = self.backend.state['id']
        self.assertNotEqual(first_id, second_id)
        self.tx.recover(first_id)
        self.assertEqual('second', self.backend.live)
        self.assertTrue(self.backend.marker)
        self.assertEqual(second_id, self.backend.state['id'])
        self.tx.confirm(second)


unittest.main()
