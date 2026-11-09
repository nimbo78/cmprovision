"""Network change transaction coordinator.

The backend owns the root-only state file, the provisioning lock and pending marker, the watchdog,
and NetworkManager operations. Its state writes must be durable before returning. All network
operations occur while the same guard used by NetworkChangeGate prevents a new module starting.
"""
import secrets
import os
import time


class TransactionError(RuntimeError):
    pass


class NetworkTransaction:
    def __init__(self, backend):
        self.backend = backend

    def begin(self, request):
        b = self.backend
        with b.guard():
            if os.path.lexists('/run/cmprovision-updates/pending.json'):
                raise TransactionError('A station update is in maintenance; wait for it to finish.')
            if b.load() is not None or b.pending():
                raise TransactionError('A network change is already pending.')
            if b.active_runs():
                raise TransactionError('Wait for the current provisioning runs to finish.')
            # Validate and capture before changing anything, including the actual active profile.
            change = b.validate(request)
            original = b.snapshot(change)
            checkpoint = b.checkpoint(change)
            state = {'version': 1, 'id': secrets.token_hex(16), 'token': secrets.token_urlsafe(32), 'change': change,
                     'original': original, 'applied': None, 'persisted': None, 'checkpoint': checkpoint,
                     'expires_at': int(time.time()) + 110}
            try:
                b.store(state)
            except BaseException:
                b.rollback_checkpoint(checkpoint)
                raise
            try:
                # If the process dies after the marker, the independent watchdog can still recover.
                b.schedule_recovery(state['id'])
                b.set_pending()
                b.stage_temporary(change)
                # Capture the canonical staged profile before changing the live interface, so
                # an address-acquisition failure can be rolled back without guessing its state.
                state = b.load() or state  # multi-profile backends may have recorded partial stages
                state['applied'] = b.snapshot(change)
                b.store(state)
                b.apply_temporary(change)
            except BaseException:
                self._recover_locked(state)
                raise
            return state['token']

    def confirm(self, token):
        b = self.backend
        with b.guard():
            state = b.load()
            if state is None or not isinstance(token, str) or not token.isascii() or not secrets.compare_digest(token, state['token']):
                raise TransactionError('The network change is not pending or the confirmation does not match.')
            try:
                b.verify_before_confirm(state['original'], state['change'], state['applied'])
                b.persist(state['change'])
                # Keep the canonical persisted state before finishing the checkpoint. The backend
                # can use it to recognize our own write during a later recovery.
                state['persisted'] = b.snapshot(state['change'])
                b.store(state)
                b.commit_checkpoint(state['checkpoint'])
            except BaseException:
                # A failed or interrupted commit restores the original persistent settings.
                self._recover_locked(state)
                raise
            b.clear_state()
            b.clear_pending()
            b.cancel_recovery(state['id'])

    def recover(self, expected_id=None):
        b = self.backend
        with b.guard():
            state = b.load()
            if state is None:
                # A completed transaction may have stopped between clearing state and the marker.
                b.clear_pending()
                return
            # A watchdog from an older change must never roll back the current transaction.
            if expected_id is not None and expected_id != state['id']:
                return
            self._recover_locked(state)

    def cancel(self, token):
        b = self.backend
        with b.guard():
            state = b.load()
            if state is None or not isinstance(token, str) or not token.isascii() or not secrets.compare_digest(token, state['token']):
                raise TransactionError('The network change is not pending or the confirmation does not match.')
            self._recover_locked(state)

    def _recover_locked(self, state):
        b = self.backend
        latest = b.load()
        if latest is not None and latest.get('id') == state.get('id'):
            state = latest
        # restore_original must tolerate a missing NM checkpoint after a daemon restart and must
        # not overwrite unrelated external changes. It also reconciles the provisioning services.
        b.restore_original(state['original'], state['change'], state['applied'], state['persisted'],
                           state['checkpoint'], state.get('partial'))
        b.clear_state()
        b.clear_pending()
        b.cancel_recovery(state['id'])
