"""One serialized, recoverable backup. Copy failures never repeat or interrupt provisioning."""
from datetime import datetime, timezone
import hashlib
import time

from backup_archive import create, verify_stream
from backup_remote import Remote, discover_host_key
from backup_store import TERMINAL, BackupBusy
from update_diagnostics import journal
from update_manifest import UpdateError


def boot_recovery(store, system):
    pending = store.configuration()['pending']
    if not pending: return
    store._atomic(store.pending_file, {'schema': 1, 'id': pending['id'], 'at': pending['at']}, 0o644)
    job = store.operation(pending['id'])
    (system.quiesce_restore if job['action'] == 'restore' else system.pause)(pending['services'])
    # Never start Before= services while executing their boot ordering barrier.
    system.command(['/usr/bin/systemd-run', '--quiet', '--collect', '--unit=cmprovision-backup-recovery', '--',
        '/usr/bin/python3', '-I', '/usr/lib/cmprovision/station-backups.py', '--recover'], timeout=8)


class Worker:
    def __init__(self, store, system, remote=Remote):
        self.store, self.system, self.remote = store, system, remote
        self.last_progress = 0

    def canceled(self, identity): return self.store.operation(identity)['cancel_requested']

    def progress(self, identity, phase, done, total):
        if time.monotonic()-self.last_progress >= 1 or total and done == total:
            self.last_progress = time.monotonic()
            self.store.update(identity, state=phase, bytes=done, total=total,
                message='Verifying the saved encrypted archive.' if phase == 'verifying' else 'Encrypting and transferring the station backup.')

    def _idle(self):
        value = self.system.admission()
        if type(value) is not dict or type(value.get('active')) is not list or type(value.get('reserved_jobs')) is not int:
            raise UpdateError('The station idle state could not be verified.')
        return not value['active'] and value['reserved_jobs'] == 0

    def _freeze(self, identity):
        try: return self._freeze_locked(identity)
        except BackupBusy:
            self.store.update(identity, state='waiting', message='Waiting for another station operation to finish. No services have been paused.')
            return False

    def _freeze_locked(self, identity):
        with self.system.admission_lock():
            try: self.system.conflicts()
            except UpdateError as error:
                self.store.update(identity, state='waiting', message=str(error)+' Waiting without interrupting the station.')
                return False
            if not self._idle():
                self.store.update(identity, state='waiting', message='Waiting for provisioning and image work to finish. The station has not been paused.')
                return False
            services = self.system.capture_services()
            self.store.mark_pending(identity, services)
            self.system.pause(services)
            if not self._idle(): raise UpdateError('Work started before the backup gate closed; no snapshot was taken.')
        return True

    def run(self, identity):
        try:
            lock = self.store.lock('worker', blocking=False); lock.__enter__()
        except UpdateError: return
        try:
            job = self.store.operation(identity)
            if job['state'] not in ('queued', 'waiting'): return
            if job['action'] == 'restore':
                self.store.update(identity, state='recovery-required', message='Administrator restore was interrupted. Inspect it over SSH; no backup or restore was repeated.')
                return
            if job['cancel_requested']:
                self.store.update(identity, state='canceled', message='Backup canceled before starting.'); return
            try:
                if job['action'] == 'host-key':
                    arguments = job['snapshot']['arguments']
                    keys = discover_host_key(arguments['host'], arguments['port'])
                    self.store.update(identity, state='succeeded', host_keys=keys,
                        message='Host keys read. Compare the fingerprint with the server before explicitly accepting a key.'); return
                if job['action'] == 'test':
                    with self.remote(job['snapshot']['profile'], job['snapshot']['station_id'], self.store.job_dir(identity)) as remote:
                        result = remote.test()
                    self.store.update(identity, state='succeeded', message=result['message']); return
                self._backup(job)
            except Exception as error: self._failed(identity, error)
        finally: lock.__exit__(None, None, None)

    def _backup(self, job):
        identity = job['id']
        if not self._freeze(identity): return
        directory = self.store.job_dir(identity)
        captured_at = int(self.store.clock())
        self.store.update(identity, state='collecting', captured_at=captured_at, message='Collecting consistent application data and station settings.')
        job = self.store.operation(identity)
        entries, metadata = self.system.collect(job, directory)
        password = job['snapshot']['master_password']
        canceled = lambda: self.canceled(identity)
        if canceled(): raise UpdateError('Backup canceled.')
        name = datetime.fromtimestamp(captured_at, timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'-'+identity+'.cmpbackup'
        self.store.update(identity, state='archiving', message='Building and encrypting the station backup.', name=name)
        receipt = None
        if job['target'] == 'download':
            path = directory/'backup.cmpbackup'
            manifest = create(entries, path, password, metadata,
                progress=lambda done, total: self.progress(identity, 'archiving', done, total), canceled=canceled)
            self.store.update(identity, state='verifying', bytes=0, total=0, message='Authenticating the encrypted file by streaming read-back.')
            if verify_stream(path, password, directory, canceled=canceled) != manifest: raise UpdateError('Backup read-back manifest does not match.')
            size = path.stat().st_size
            hashed, fingerprint = 0, hashlib.sha256()
            with path.open('rb') as stream:
                while chunk := stream.read(1024**2):
                    if canceled(): raise UpdateError('Backup canceled.')
                    fingerprint.update(chunk); hashed += len(chunk)
                    self.progress(identity, 'verifying', hashed, size)
            digest = fingerprint.hexdigest()
            size, outcome = path.stat().st_size, 'ready'
        else:
            with self.remote(job['snapshot']['profile'], job['snapshot']['station_id'], directory) as remote:
                receipt = remote.upload(name, lambda sink: create(entries, sink, password, metadata, canceled=canceled), canceled,
                    lambda done, total: self.progress(identity, 'verifying' if total else 'uploading', done, total))
                receipt['namespace'] = remote.namespace()
                self.store.record_remote(identity, receipt)
                digest, size, outcome = receipt['sha256'], receipt['size'], 'succeeded'
        # Commit the copy outcome before service cleanup. Recovery never repeats an already usable copy.
        if canceled(): raise UpdateError('Backup canceled.')
        self.store.update(identity, committed=True, committed_at=int(self.store.clock()), outcome=outcome, bytes=size, total=size, sha256=digest,
            expires_at=int(self.store.clock())+3600 if outcome == 'ready' else None)
        self._finish(identity, outcome, 'Encrypted backup ready to download.' if outcome == 'ready' else 'Remote backup verified and committed.')
        if receipt:
            try:
                with self.remote(job['snapshot']['profile'], job['snapshot']['station_id'], directory) as remote:
                    removed = remote.prune(self.store.configuration()['remote_copies'], job['snapshot']['profile']['keep'])
                    self.store.forget_remote(removed)
            except Exception:
                self.store.update(identity, warning='The new backup is safe, but cleanup of older remote copies failed. Check destination delete permission.')

    def _finish(self, identity, outcome, message):
        pending = self.store.configuration()['pending']
        if pending and pending['id'] == identity:
            self.system.resume(pending['services'])
        self.system.cleanup(self.store.job_dir(identity), keep_download=outcome == 'ready')
        self.store.update(identity, state=outcome, message=message)
        if pending:
            with self.system.admission_lock(): self.store.clear_pending(identity)
        if outcome == 'ready':
            from backup_retention import prune_downloads
            try: prune_downloads(self.store)
            except (UpdateError, OSError):
                self.store.update(identity, warning='The new backup is safe, but cleanup of older downloads failed.')

    def _failed(self, identity, error):
        job = self.store.operation(identity)
        journal(error, identity, job['state'], event='cmprovision-backup-failure')
        message = str(error) if isinstance(error, UpdateError) else 'Unexpected backup failure ('+type(error).__name__+'). Reference: '+identity+'.'
        if job.get('committed'):
            try:
                self._finish(identity, job['outcome'], 'Backup committed; station cleanup recovered.')
                return
            except Exception as recovery:
                journal(recovery, identity, 'recovery', event='cmprovision-backup-failure')
                self.store.update(identity, state='recovery-required', message='The backup is committed, but station services need recovery. Use SSH/local console.')
                return
        pending = self.store.configuration()['pending']
        try:
            if pending and pending['id'] == identity: self.system.resume(pending['services'])
            self.system.cleanup(self.store.job_dir(identity), keep_download=False)
            self.store.update(identity, state='canceled' if job['cancel_requested'] else 'failed', message=message)
            if pending:
                with self.system.admission_lock(): self.store.clear_pending(identity)
        except Exception as recovery:
            journal(recovery, identity, 'recovery', event='cmprovision-backup-failure')
            self.store.update(identity, state='recovery-required', message=message+' Station service recovery did not complete; use SSH/local console.')

    def recover_pending(self):
        pending = self.store.configuration()['pending']
        if not pending: return
        with self.store.lock('worker', blocking=False):
            identity = pending['id']
            self.store._atomic(self.store.pending_file, {'schema': 1, 'id': identity, 'at': pending['at']}, 0o644)
            job = self.store.operation(identity)
            if job['action'] == 'restore':
                self.system.quiesce_restore(pending['services'])
                self.store.update(identity, state='recovery-required', message='An administrator restore was interrupted. Keep provisioning stopped and run cmprovision-full-restore --recover over SSH.')
                return
            if job.get('committed'):
                try: self._finish(identity, job['outcome'], 'Committed backup retained; interrupted station cleanup recovered.')
                except Exception as error: self._failed(identity, error)
            else:
                self._failed(identity, UpdateError('The backup was interrupted. No upload or snapshot was repeated; create a fresh backup.'))
