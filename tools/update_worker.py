"""One durable update operation; filesystem trust and system effects are separate boundaries."""
import base64
import hashlib
import os
import stat

from update_diagnostics import record

from update_manifest import UpdateError, canonical_bytes, compatibility_error, newer, verify_manifest
from update_recovery import BOOT_SERVICES
from update_store import TERMINAL


class Worker:
    def __init__(self, store, source, system):
        self.store, self.source, self.system = store, source, system

    def _phase(self, identity, state, message, **fields):
        operation = self.store.operation(identity)
        log = operation['log']+[message]
        return self.store.update(identity, state=state, message=message, log=log, **fields)

    def _verified(self, envelope, tag=None):
        try:
            raw = base64.b64decode(envelope['raw'], validate=True)
            signature = base64.b64decode(envelope['signature'], validate=True)
            if hashlib.sha256(raw).hexdigest() != envelope['id']:
                raise UpdateError('Cached catalogue identifier does not match.')
            value = verify_manifest(raw, signature, self.source.key, self.source.repository, tag)
            if value != envelope['manifest']: raise UpdateError('Cached release metadata does not match its signature.')
            return value
        except (ValueError, TypeError, KeyError) as error:
            if isinstance(error, UpdateError): raise
            raise UpdateError('Cached update catalogue is invalid.') from error

    def _compatible(self, value, context):
        error = compatibility_error(value, context)
        if error: raise UpdateError(error)
        installed = context['cmprovision'] if value['component'] == 'application' else context.get('utility_os_version', '0')
        if not newer(value['version'], installed): raise UpdateError('This version is already installed or would be a downgrade.')

    @staticmethod
    def _assets(value, context):
        return [asset for asset in value['assets'] if context.get('fpms_installed') or not asset['name'].startswith('fpms-lite_')]

    def _file(self, identity, asset):
        path = self.store.payload_dir(identity)/asset['name']
        try:
            info = path.lstat()
            if (not stat.S_ISREG(info.st_mode) or info.st_uid != self.store.owner or info.st_mode & 0o022
                    or info.st_size != asset['size']):
                raise UpdateError('Prepared update file is unsafe or has the wrong size.')
            with path.open('rb') as stream: digest = hashlib.file_digest(stream, 'sha256').hexdigest()
            if digest != asset['sha256']: raise UpdateError('Prepared update checksum verification failed.')
        except OSError as error:
            raise UpdateError('A prepared update file is missing or unreadable.') from error
        return path

    def _idle(self):
        admission = self.system.admission()
        if (type(admission) is not dict or type(admission.get('active')) is not list
                or type(admission.get('reserved_jobs')) is not int):
            raise UpdateError('Could not verify whether the station is idle.')
        if admission['active'] or admission['reserved_jobs']:
            raise UpdateError('The station has active or unfinished provisioning/image work. Apply after it finishes.')

    def run(self, identity):
        try:
            lock = self.store.lock('worker', blocking=False)
            lock.__enter__()
        except UpdateError:
            return # A duplicate worker must not overwrite the real worker's result.
        if os.path.lexists(self.store.runtime.with_name('cmprovision-backups')/'pending.json'):
            lock.__exit__(None, None, None)
            return # The backup may be streaming signed payloads that prune() would remove.
        try:
            operation = self.store.operation(identity)
            if operation['state'] != 'queued': return
            if operation['cancel_requested']:
                self._phase(identity, 'canceled', 'Update operation canceled before starting.')
                return
            try:
                action = operation['action']
                if action == 'check': self._check(identity, operation['request'])
                elif action == 'download': self._download(identity, operation['request'])
                elif action == 'apply': self._apply(identity, operation['request'])
                elif action == 'activate': self._activate(identity, operation['request'])
                elif action == 'rollback': self._rollback(identity, operation['request'])
                else: raise UpdateError('Unknown update operation.')
            except Exception as error:
                self._failed(identity, error)
        finally:
            self._prune()
            lock.__exit__(None, None, None)

    def _prune(self):
        try:
            if not self.store.snapshot()['pending']:
                self.store.release_journal()
                self.system.prune()
        except Exception:
            pass # Cleanup cannot undo a verified install; never guess at unsafe/unowned artifacts.

    def _check(self, identity, request):
        self._phase(identity, 'checking', 'Checking signed stable component releases.')
        context = self.system.context()
        self.store.metadata(context, {'repository': self.source.repository, 'configured': True})
        errors = []
        for component in request['components']:
            if self.store.operation(identity)['cancel_requested']:
                self._phase(identity, 'canceled', 'Update check canceled.')
                return
            try:
                envelope = self.source.check(component, context)
                if envelope: self._verified(envelope)
                self.store.record_check(component, envelope)
            except UpdateError as error:
                self.store.record_check(component, error=str(error))
                errors.append(str(error))
                diagnostic = record(self.store, identity, error, 'checking')
                try: self.store.update(identity, diagnostic=diagnostic)
                except Exception: pass # Optional diagnostics cannot interrupt checking the other component.
        self._phase(identity, 'failed' if errors else 'succeeded',
                    errors[0] if errors else 'Update check completed.')

    def _download(self, identity, request):
        self._phase(identity, 'checking', 'Resolving the selected signed release.')
        context = self.system.context()
        envelope = self.source.release(request['tag'], request['component'], context)
        value = self._verified(envelope, request['tag'])
        if value['component'] != request['component']: raise UpdateError('Selected update component does not match.')
        self._compatible(value, context)
        self.store.cache_catalogue(envelope)
        assets = self._assets(value, context)
        total = sum(asset['size'] for asset in assets)
        directory = self.store.payload_dir(envelope['id'])
        missing = sum(asset['size'] for asset in assets if not (directory/asset['name']).exists())
        self.system.download_space(missing)
        self._phase(identity, 'downloading', 'Downloading verified release files.', version=value['version'], total=total)
        completed, paths = 0, []
        for asset in assets:
            destination = self.store.payload_dir(envelope['id'])/asset['name']
            def progress(done, unused_total):
                if self.store.operation(identity)['cancel_requested']: return False
                self.store.update(identity, downloaded=completed+done, total=total)
                return True
            if destination.exists() or destination.is_symlink():
                self._file(envelope['id'], asset) # A cache hit is verified again, never blindly overwritten.
                progress(asset['size'], asset['size'])
            else:
                self.source.fetch(value['tag'], asset, destination, progress)
            paths.append(self._file(envelope['id'], asset))
            completed += asset['size']
        if self.store.operation(identity)['cancel_requested']:
            self._phase(identity, 'canceled', 'Update download canceled.')
            return
        self._phase(identity, 'verifying', 'Verifying package metadata and required dependencies.')
        if value['component'] == 'application':
            plan = self.system.plan_install(paths, value)
            self.system.prefetch(paths, plan)
            prepared = {'plan': plan, 'files': [asset['name'] for asset in assets]}
        else:
            prepared = self.system.prepare_utility(envelope, paths[0], self.store)
        if self.store.operation(identity)['cancel_requested']:
            self._phase(identity, 'canceled', 'Update preparation canceled.')
            return
        self.store.save_receipt(envelope['id'], dict(prepared, component=value['component'], version=value['version'],
            context={key: context.get(key) for key in ('cmprovision', 'fpms_version', 'fpms_installed')}))
        self._phase(identity, 'ready', 'Verified update is ready. Apply it when the station is idle.')

    def _prepare(self, identity, component, selected=None, restore_role=None):
        self._idle()
        self.system.conflicts()
        if restore_role is not None: self.system.check_boot_services(restore_role, role_only=True)
        space = None
        if component == 'application':
            self._phase(identity, 'preparing', 'Checking recovery space while the station is online.')
            space = self.system.plan_snapshot_space(selected)
            # A board can arrive during the directory walk. Retire an old point only for
            # a confirmed, still-idle operation. The worker lock protects the selected rollback.
            self.system.conflicts()
            self._idle()
            self.system.prune(before_snapshot=True)
            self.system.require_snapshot_space(space)
        runner = self.system.retain_runner(identity)
        self.system.ensure_boot_guard()
        with self.system.admission_lock():
            self.system.conflicts()
            self._idle()
            if restore_role is not None: self.system.check_boot_services(restore_role, role_only=True)
            services = self.system.capture_services()
            previous = self.system.context()
            if space is not None:
                # The measured requirement already includes this fixed-size reserve.
                self.system.require_snapshot_space(space)
                self.store.reserve_journal()
            self._phase(identity, 'preparing', 'Pausing new boots and preparing a recovery snapshot.',
                        services=services, previous=previous, runner=runner, boot_guard=True)
            self.store.mark_pending(identity)
        self.system.pause(services)
        self._idle()
        return services, previous

    def _apply(self, identity, request):
        receipt = self.store.receipt(request['candidate'])
        fingerprint = hashlib.sha256(canonical_bytes({key: value for key, value in receipt.items() if key != 'fingerprint'})).hexdigest()
        if request['receipt'] != receipt['fingerprint'] or fingerprint != receipt['fingerprint']:
            raise UpdateError('The prepared update changed; review and confirm it again.')
        envelope = self.store.catalogue(request['candidate'])
        value = self._verified(envelope)
        context = self.system.context()
        self._compatible(value, context)
        assets = self._assets(value, context)
        if receipt['files'] != [asset['name'] for asset in assets]:
            raise UpdateError('Installed components changed; download the matching files again.')
        paths = [self._file(request['candidate'], asset) for asset in assets]
        self.store.update(identity, component=value['component'], version=value['version'])
        if value['component'] == 'application' and self.system.plan_install(paths, value) != receipt['plan']:
            raise UpdateError('Required package changes changed; download and review the plan again.')
        services, previous = self._prepare(identity, value['component'])
        if value['component'] == 'application':
            backup = self.system.backup(identity)
            self.store.update(identity, backup=backup, mutation_started=False)

            def start_install():
                self._phase(identity, 'applying', 'Installing the application and approved dependency changes.',
                            mutation_started=True)

            self.system.install(paths, receipt['plan'], value, before_mutation=start_install)
            self.system.resume(services, boot=False)
            self._phase(identity, 'reconnecting', 'Checking the updated application and services.')
            self.system.health(dict(value, fpms_installed=previous['fpms_installed'],
                                    fpms_version=value['version']), services)
        else:
            self._phase(identity, 'applying', 'Activating the prepared Utility OS for subsequent boots.')
            self.system.activate_utility(receipt['selector'])
            self.system.utility_health(receipt['selector'])
        self._finish(identity, services, 'Update installed and verified.')

    def _finish(self, identity, services, message, state='succeeded'):
        # A reboot can erase every runtime mask. Authorize only the saved role in
        # the durable journal before asking systemd to reopen the boot services.
        self._phase(identity, state, message, committed=True, outcome=state)
        self.system.resume(services, boot=True)
        self.system.check_boot_services(services)
        self.store.clear_pending(identity)
        try:
            self.store.metadata(self.system.context(), {'repository': self.source.repository, 'configured': True})
            operation = self.store.operation(identity)
            if state == 'succeeded' and operation['action'] == 'apply':
                self.store.consume_receipt(operation['request']['candidate'])
        except Exception:
            pass # A committed healthy install is not undone by a stale display projection.

    def _failed(self, identity, error):
        operation = self.store.operation(identity)
        diagnostic = record(self.store, identity, error, operation['state'])
        try: self.store.update(identity, diagnostic=diagnostic)
        except Exception: pass # Optional diagnostic metadata cannot interrupt automatic recovery.
        message = str(error) if isinstance(error, UpdateError) else 'Unexpected '+diagnostic['type']+' during '+diagnostic['phase']+'. Diagnostic reference: '+identity+'.'
        pending = self.store.snapshot()['pending']
        if not pending or pending['id'] != identity:
            state = 'canceled' if operation['cancel_requested'] else 'failed'
            self._phase(identity, state, message)
            return
        try:
            if operation.get('committed'):
                raise UpdateError('Update result is committed, but maintenance cleanup needs SSH/local-console recovery.')
            # Older runners recorded only the safety copy. Their packages may already have changed.
            if operation.get('backup') and operation.get('mutation_started', True):
                self._phase(identity, 'applying', 'The update failed; automatically restoring the previous snapshot.')
                self.system.pause(operation['services']) # Close every new DB connection before restoring SQLite.
                self.system.recover(operation['backup'])
                self.system.resume(operation['services'], boot=False)
                self.system.health(operation['previous'], operation['services'])
                self._finish(identity, operation['services'], 'Previous working state restored after: '+message, 'rolled-back')
            elif operation.get('component') == 'utility-os' and operation.get('previous'):
                self.system.activate_utility(operation['previous']['utility_selector'])
                self.system.utility_health(operation['previous']['utility_selector'])
                self._finish(identity, operation['services'], 'Previous Utility OS restored after: '+message, 'rolled-back')
            else:
                self._finish(identity, operation['services'], message, 'failed')
        except Exception as recovery_error:
            recovery = record(self.store, identity, recovery_error, 'recovery')
            try: self.store.update(identity, recovery_diagnostic=recovery)
            except Exception: pass
            self._phase(identity, 'recovery-required', message+' Automatic recovery/cleanup did not complete. Use SSH or the local console.')
            # One server may have started before its peer failed. The journal now
            # prevents new starts. Stop both, also if stopping the first one fails.
            for name in sorted(BOOT_SERVICES.intersection(operation.get('services', {}))):
                try: self.system.pause({name: operation['services'][name]})
                except Exception as stop_error: record(self.store, identity, stop_error, 'recovery')

    def recover_pending(self):
        pending = self.store.snapshot()['pending']
        if not pending: return
        identity = pending['id']
        with self.store.lock('worker', blocking=False):
            operation = self.store.operation(identity)
            self.store.mark_pending(identity) # Recreate the runtime marker after a host reboot.
            if operation['state'] == 'recovery-required' and not operation.get('committed'): return
            if operation.get('committed'):
                try:
                    self._finish(identity, operation['services'], 'Committed update maintenance cleanup completed.',
                                 operation.get('outcome', operation['state'] if operation['state'] in TERMINAL else 'succeeded'))
                except Exception as error:
                    self._failed(identity, error)
            else:
                self._failed(identity, UpdateError('Update was interrupted before its final health result.'))
            self._prune()

    def _activate(self, identity, request):
        self.system.validate_utility(request['selector'])
        self.store.update(identity, component='utility-os')
        services, previous = self._prepare(identity, 'utility-os')
        self._phase(identity, 'applying', 'Switching the selected Utility OS.')
        self.system.activate_utility(request['selector'])
        self.system.utility_health(request['selector'])
        self._finish(identity, services, 'Selected Utility OS activated for subsequent boots.')

    def _rollback(self, identity, request):
        if request['operation'] not in {operation['id'] for operation in self.store.snapshot()['recoveries']}:
            raise UpdateError('The selected recovery snapshot is no longer retained.')
        previous_operation = self.store.operation(request['operation'])
        if previous_operation['state'] != 'succeeded' or not previous_operation.get('previous'):
            raise UpdateError('This operation has no recoverable previous version.')
        component = previous_operation['component']
        self.store.update(identity, component=component)
        if component == 'application':
            self._idle()
            self.system.conflicts()
            self._phase(identity, 'preparing', 'Checking the selected recovery snapshot before pausing the station.')
            selected = previous_operation.get('backup')
            if not selected or selected.get('identity') != previous_operation['id']:
                raise UpdateError('The recovery snapshot does not belong to the selected operation.')
            verified = self.system.validate_backup(selected)
        services, previous = self._prepare(identity, component, selected=request['operation'],
            restore_role=previous_operation['services'] if component == 'application' else None)
        if component == 'application':
            # A new snapshot allows recovery if this explicitly requested later data rollback itself fails.
            safety = self.system.backup(identity)
            self.store.update(identity, backup=safety, mutation_started=False)

            def start_restore():
                self._phase(identity, 'applying', 'Restoring the selected application snapshot.', mutation_started=True)

            self.system.recover(selected, verified=verified, before_mutation=start_restore, safety=safety)
            self.system.resume(services, boot=False)
            self.system.health(previous_operation['previous'], services)
        else:
            selector = previous_operation['previous']['utility_selector']
            self.system.validate_utility(selector)
            self.system.activate_utility(selector)
            self.system.utility_health(selector)
        self._finish(identity, services, 'Selected previous version and its recorded state restored.')
