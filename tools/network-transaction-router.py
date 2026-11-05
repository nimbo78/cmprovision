"""Select the profile or stand backend without trusting a browser-provided operation after recovery."""


class Router:
    def __init__(self, common, stand, connection=None):
        self.common, self.stand, self.connection = common, stand, connection

    def guard(self): return self.common.guard()
    def load(self): return self.common.load()
    def pending(self): return self.common.pending()
    def store(self, value): self.common.store(value)
    def set_pending(self): self.common.set_pending()
    def clear_state(self): self.common.clear_state()
    def clear_pending(self): self.common.clear_pending()
    def active_runs(self): return self.common.active_runs()
    def schedule_recovery(self, identifier): self.common.schedule_recovery(identifier)
    def cancel_recovery(self, identifier): self.common.cancel_recovery(identifier)
    def rollback_checkpoint(self, checkpoint): return self.common.rollback_checkpoint(checkpoint)
    def commit_checkpoint(self, checkpoint): return self.common.commit_checkpoint(checkpoint)

    def validate(self, request):
        if isinstance(request, dict) and request.get('kind') == 'connection' and self.connection:
            return self.connection.validate(request)
        if isinstance(request, dict) and request.get('kind') == 'stand':
            return self.stand.validate(request)
        result = self.common.validate(request)
        return {'kind': 'profile', **result}

    def _for(self, change):
        if change.get('kind') == 'connection' and self.connection: return self.connection
        if change.get('kind') == 'stand': return self.stand
        if change.get('kind') == 'profile': return self.common
        raise RuntimeError('Unknown network transaction type.')

    def snapshot(self, change): return self._for(change).snapshot(change)
    def checkpoint(self, change): return self._for(change).checkpoint(change)
    def stage_temporary(self, change): return self._for(change).stage_temporary(change)
    def apply_temporary(self, change): return self._for(change).apply_temporary(change)
    def verify_before_confirm(self, original, change, applied):
        return self._for(change).verify_before_confirm(original, change, applied)
    def persist(self, change): return self._for(change).persist(change)
    def restore_original(self, original, change, applied, persisted, checkpoint, partial=None):
        return self._for(change).restore_original(original, change, applied, persisted, checkpoint, partial)
