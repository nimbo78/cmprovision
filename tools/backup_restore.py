"""Authenticated fixed-policy station restore. Preview never runs archived software."""
import base64
from contextlib import contextmanager
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import uuid

from backup_policy import APP, FIXED, TREES, read, relative, safe_open, system_member, validate_payloads
from update_manifest import DEB_VERSION_RE, UpdateError, compatibility_error, verify_manifest

GROUPS = frozenset(('network', 'ssh', 'host', 'time', 'display', 'web', 'role', 'publisher', 'sudo'))
DEFAULT_GROUPS = ('time', 'display', 'web')


def selected_groups(text, recovery=False):
    if text is None: return ['network', 'ssh', 'host', 'time', 'display', 'web', 'role', 'sudo'] if recovery else list(DEFAULT_GROUPS)
    values = [item for item in text.split(',') if item]
    if len(set(values)) != len(values) or not set(values) <= GROUPS: raise UpdateError('Choose supported, unique OS groups.')
    return values


def json_member(archive, name):
    try: return json.loads(archive.read(name))
    except (ValueError, TypeError) as error: raise UpdateError('A required restore metadata file is invalid.') from error


def restore_backup_configuration(value, now):
    from backup_remote import validate_profile
    from backup_schedule import validate_schedule
    if (type(value) is not dict or value.get('schema') != 1 or type(value.get('profiles')) is not dict
            or len(value['profiles']) > 8 or type(value.get('schedule')) is not dict):
        raise UpdateError('Invalid saved backup configuration.')
    profiles = {}
    for key, profile in value['profiles'].items():
        if not re.fullmatch(r'[0-9a-f]{32}', key) or type(profile) is not dict or profile.get('id') != key:
            raise UpdateError('Invalid saved backup destination identifier.')
        profiles[key] = validate_profile(profile)
    schedule = dict(value['schedule'], enabled=False)
    schedule.pop('not_before', None) # Runtime boundary is always regenerated for the destination clock.
    schedule = validate_schedule(schedule, profiles, now)
    return {'profiles': profiles, 'schedule': schedule}


def file_group(name):
    if name.startswith(('etc/NetworkManager/', 'var/lib/cmprovision-network/')) or name == 'etc/dhcpcd.conf': return 'network'
    if name.startswith('etc/ssh/'): return 'ssh'
    if name.startswith('etc/sudoers'): return 'sudo'
    if name in ('etc/hostname', 'etc/hosts', 'etc/default/locale', 'etc/locale.conf', 'etc/default/keyboard', 'etc/locale.gen'): return 'host'
    if name.startswith('etc/systemd/timesyncd') or name in ('etc/localtime', 'etc/timezone'): return 'time'
    if name in ('etc/default/cmprovision-wallboard.json', 'etc/default/cmprovision-fpms.json'): return 'display'
    if name in ('etc/default/cmprovision-stand-interface', 'etc/default/cmprovision-uplink'): return 'role'
    if name.startswith('etc/cmprovision-updates/'): return 'publisher'
    return 'web'


def _accounts(archive, context, names, emergency):
    rows = json_member(archive, 'system/accounts.json')
    if type(rows) is not list or len(rows) > 1000 or len(set(names)) != len(names): raise UpdateError('Invalid administrator selection.')
    result = []
    for name in names:
        if name == emergency: raise UpdateError('The connected emergency administrator must be preserved. Select a different account.')
        matches = [row for row in rows if type(row) is dict and row.get('name') == name]
        if len(matches) != 1: raise UpdateError('A selected administrator is absent or duplicated.')
        row = matches[0]
        if (type(name) is not str or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', name)
                or type(row.get('uid')) is not int or row['uid'] != 0 and not 1000 <= row['uid'] < 65534
                or type(row.get('gid')) is not int or not 0 <= row['gid'] < 65534
                or row.get('home') != ('/root' if row['uid'] == 0 and name == 'root' else '/home/'+name)
                or row.get('shell') not in ('/bin/bash', '/bin/sh', '/usr/bin/bash', '/usr/bin/zsh', '/bin/zsh')
                or type(row.get('password_hash')) is not str or len(row['password_hash']) > 4096
                or any(char in row['password_hash'] for char in ':\r\n\x00')
                or type(row.get('authorized_keys')) is not str or '\x00' in row['authorized_keys']
                or type(row.get('aging')) is not list or len(row['aging']) != 7
                or any(type(value) is not str or not re.fullmatch(r'[0-9]*', value) for value in row['aging'])
                or type(row.get('groups')) is not list): raise UpdateError('Unsupported administrator metadata.')
        group = row.get('primary_group')
        if not isinstance(group, str) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', group): raise UpdateError('Invalid administrator primary group.')
        for current in context['accounts']:
            if (current['uid'] == row['uid'] and current['name'] != name
                    or current['name'] == name and (current['uid'] != row['uid'] or current['gid'] != row['gid'])):
                raise UpdateError('An administrator name or UID/GID collides with a destination account. Resolve it over SSH first.')
        groups = context['groups']
        if (group in groups and groups[group] != row['gid'] or any(gid == row['gid'] and key != group for key, gid in groups.items())
                or any(type(key) is not str or key not in groups for key in row['groups'])):
            raise UpdateError('Administrator groups differ from the destination OS. Resolve the GID/missing group over SSH first.')
        result.append(row)
    return result


def plan_restore(archive, context, mode='migrate', groups=DEFAULT_GROUPS, selected_accounts=(), interfaces=None,
        allow_snapshot=False, publisher=None, repository=None, emergency='root', preview=False):
    value = archive.manifest
    if mode not in ('migrate', 'same-station') or not set(groups) <= GROUPS: raise UpdateError('Choose a supported restore mode and system groups.')
    if mode == 'migrate' and 'sudo' in groups: raise UpdateError('Preserve destination sudo configuration during migration; selected accounts may join existing sudo groups.')
    if (type(value.get('station_id')) is not str or not re.fullmatch(r'[0-9a-f]{32}', value['station_id'])
            or type(value.get('version')) is not str or not re.fullmatch(DEB_VERSION_RE, value['version'])
            or value.get('os', {}).get('version') != '13' or value['os'].get('id') not in ('debian', 'raspbian')
            or value['os'].get('architecture') != context['os']['architecture'] or context['os']['version'] != '13'):
        raise UpdateError('The backup platform/version is incompatible with this station.')
    options = value.get('options')
    if type(options) is not dict or set(options) != {'images', 'packages'} or any(type(v) is not bool for v in options.values()):
        raise UpdateError('Invalid backup payload selection metadata.')
    members = {item['name']: item for item in value['members']}
    required = {'application/.env', 'application/database.sqlite', 'application/etc/dnsmasq.conf', 'system/accounts.json',
        'system/service-state.json', 'system/backup-config.json', 'system/time.json', 'system/payloads.json'}
    if not required <= members.keys(): raise UpdateError('The full backup lacks required application/system metadata.')
    restore_backup_configuration(json_member(archive, 'system/backup-config.json'), now=context.get('now', 0))
    validate_payloads(json_member(archive, 'system/payloads.json'), value['members'], options)
    software = value.get('software')
    if type(software) is not list or not 2 <= len(software) <= 3: raise UpdateError('The software inventory is incomplete.')
    install, evidence, packages, needs_opt_in = [], {}, set(), False
    for item in software:
        if type(item) is not dict: raise UpdateError('Invalid software provenance record.')
        package = item.get('package')
        if package:
            if package not in ('cmprovision4', 'fpms-lite') or package in packages or item.get('version') != value['version']:
                raise UpdateError('Station packages do not have one matching supported version.')
            packages.add(package)
            member = item.get('archive')
            if member not in members or not member.startswith('software/'+package+'_'): raise UpdateError('A required station package is missing.')
            current = context.get('cmprovision' if package == 'cmprovision4' else 'fpms_version')
            if current == item['version'] and context.get('verified_packages', {}).get(package) and not context.get('recovering'):
                continue # Reuse checked installed software, never assume matching versions imply intact files.
        else:
            if item.get('component') != 'utility-os' or item.get('selector') != value.get('utility_selector'):
                raise UpdateError('The active Utility OS record is invalid.')
            if item.get('covered_by') == 'cmprovision4': continue
            if context.get('utility_selector') == item['selector'] and not context.get('recovering'): continue
            member = item.get('archive')
            if member is None:
                needs_opt_in = True
                if not allow_snapshot and not preview: raise UpdateError('Restoring a local Utility OS tree requires --allow-snapshot-software.')
                continue
            if member not in members: raise UpdateError('The Utility OS release archive is missing.')
        if item.get('provenance') == 'signed-release':
            envelope = item.get('envelope', {})
            if not publisher or not repository: raise UpdateError('A destination publisher key/repository is required to trust signed archived software.')
            try:
                raw = base64.b64decode(envelope['raw'], validate=True)
                signed = verify_manifest(raw, base64.b64decode(envelope['signature'], validate=True), publisher, repository)
                asset = next(asset for asset in signed['assets'] if 'software/'+asset['name'] == member)
            except (ValueError, KeyError, StopIteration) as error: raise UpdateError('The signed software evidence is invalid.') from error
            expected_version = item['version'] if package else item['selector'].split(':')[-1]
            if (signed != envelope.get('manifest') or signed['version'] != expected_version
                    or any(asset[key] != members[member][key] for key in ('size', 'sha256'))):
                raise UpdateError('The archived software differs from its signed release.')
            error = compatibility_error(signed, dict(context, cmprovision=value['version'], debian='13', architecture=context['os']['architecture']))
            if error: raise UpdateError(error)
            evidence[member] = envelope
        elif item.get('provenance') != 'local-snapshot': raise UpdateError('Unknown archived software provenance.')
        else:
            needs_opt_in = True
            if not allow_snapshot and not preview:
                raise UpdateError('Installing captured local software requires explicit --allow-snapshot-software, or preinstall the matching station image.')
        install.append(member)
    if 'cmprovision4' not in packages: raise UpdateError('The application package is absent from the software inventory.')
    accounts = _accounts(archive, context, selected_accounts, emergency)
    selected = [name[7:] for name in members if name.startswith('system/') and system_member(name[7:]) and file_group(name[7:]) in groups]
    absent = value.get('absent', [])
    if type(absent) is not list or any(type(path) is not str or path not in FIXED for path in absent): raise UpdateError('Invalid absent-settings inventory.')
    remove = [path for path in absent if file_group(path) in groups]
    clock = json_member(archive, 'system/time.json')
    from zoneinfo import ZoneInfo
    try: ZoneInfo(clock['timezone'])
    except (TypeError, KeyError, ValueError) as error: raise UpdateError('Invalid saved station time zone.') from error
    if (clock.get('enabled') not in ('enabled', 'enabled-runtime', 'disabled', 'masked', 'masked-runtime', 'static', 'not-found')
            or clock.get('active') not in ('active', 'inactive', 'failed')): raise UpdateError('Invalid saved synchronization service state.')
    clock = dict(clock, restore_ntp=clock['enabled'] != 'not-found')
    if not clock['restore_ntp']:
        selected = [path for path in selected if not path.startswith('etc/systemd/timesyncd')]
        remove = [path for path in remove if not path.startswith('etc/systemd/timesyncd')]
    rewritten = {}
    mappings = interfaces or {}
    if any(key not in context['interfaces'] for key in mappings.values()): raise UpdateError('An interface mapping names a missing destination interface.')
    for name in selected:
        if not name.startswith('etc/NetworkManager/system-connections/'): continue
        text = archive.read('system/'+name).decode('utf-8')
        source = re.search(r'^interface-name=(.+)$', text, re.M)
        macs = re.findall(r'^mac-address=(.+)$', text, re.M)
        if source and source[1] in mappings:
            target = mappings[source[1]]
            text = re.sub(r'^interface-name=.+$', 'interface-name='+target, text, flags=re.M)
            text = re.sub(r'^mac-address=.+$', 'mac-address='+context['interfaces'][target], text, flags=re.M)
        elif (mode == 'migrate' and (source or macs) or source and source[1] not in context['interfaces']
                or any(mac.lower() not in [value.lower() for value in context['interfaces'].values()] for mac in macs)):
            raise UpdateError('A saved network profile is bound to source hardware. Supply --map-interface source=destination.')
        rewritten[name] = text.encode()
    preview = {'mode': mode, 'source_station': value['station_id'], 'source_version': value['version'],
        'source_commit': value.get('source_commit', 'unknown'), 'groups': list(groups), 'accounts': list(selected_accounts),
        'system_files': selected, 'remove_absent_settings': remove, 'install': install,
        'preserved': [name for name in ('network', 'ssh', 'host', 'role') if name not in groups],
        'images_included': options['images'], 'packages_included': options['packages'],
        'projects_deactivated': not options['images'] or not options['packages'],
        'snapshot_software_opt_in': bool(allow_snapshot), 'requires_snapshot_software_opt_in': needs_opt_in,
        'emergency_administrator': emergency, 'preserve_ntp_provider': not clock['restore_ntp']}
    return {'preview': preview, 'system': selected, 'remove': remove, 'rewritten': rewritten, 'accounts': accounts,
        'install': install, 'evidence': evidence, 'restore_role': 'role' in groups, 'mode': mode, 'groups': list(groups), 'time': clock}


@contextmanager
def parent_fd(root, name, create=True):
    if not relative(name): raise UpdateError('Unsafe restore destination.')
    descriptors = []
    try:
        fd = os.open(root, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW); descriptors.append(fd)
        for part in name.split('/')[:-1]:
            if create:
                try: os.mkdir(part, 0o755, dir_fd=fd)
                except FileExistsError: pass
            fd = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd); descriptors.append(fd)
        yield fd, name.split('/')[-1]
    except FileNotFoundError: raise
    except OSError as error: raise UpdateError('A restore destination is linked, missing or unsafe.') from error
    finally:
        for fd in reversed(descriptors): os.close(fd)


def write_file(root, name, source, mode=0o600, uid=0, gid=0):
    with parent_fd(root, name) as (parent, leaf):
        try:
            info = os.stat(leaf, dir_fd=parent, follow_symlinks=False)
            if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1: raise UpdateError('A restore destination is not a private regular file.')
        except FileNotFoundError: pass
        temporary = '.cmprovision-restore-'+uuid.uuid4().hex
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600, dir_fd=parent)
        try:
            with os.fdopen(fd, 'wb') as output:
                if type(source) is bytes: output.write(source)
                else: source(output)
                os.fchmod(output.fileno(), mode & 0o777)
                os.fchown(output.fileno(), uid, gid)
                output.flush(); os.fsync(output.fileno())
            os.replace(temporary, leaf, src_dir_fd=parent, dst_dir_fd=parent); os.fsync(parent)
        finally:
            try: os.unlink(temporary, dir_fd=parent)
            except FileNotFoundError: pass


class Restorer:
    def __init__(self, store, system): self.store, self.system = store, system

    def run(self, source, password, apply=False, recover=False, **options):
        from backup_archive import inspect_archive
        from update_manifest import canonical_bytes
        self.system.recovering = recover
        with inspect_archive(source, password, self.store.private) as archive:
            plan = plan_restore(archive, self.system.restore_context(recovery=recover), preview=not apply, **options)
            self.system.validate_restore_payloads(archive)
            if not apply: return plan['preview']
            job = None
            if not recover:
                job, _ = self.store.begin(uuid.uuid4().hex*2, 'download', {'images': True, 'packages': True}, action='restore')
            with self.store.lock('worker', blocking=False):
                if recover:
                    pending = self.store.configuration()['pending']
                    if not pending: raise UpdateError('No interrupted restore exists.')
                    job = self.store.operation(pending['id'])
                    if job['action'] != 'restore' or str(source) != job.get('recovery_file'):
                        raise UpdateError('Recovery must use the protected pre-restore copy recorded by this station.')
                changed = False
                try:
                    if not recover: self.system.freeze_restore(job)
                    plan = plan_restore(archive, self.system.restore_context(recovery=recover), **options)
                    if not recover: self.system.recovery_snapshot(archive, job, password)
                    directory = self.store.job_dir(job['id'])
                    self.store.update(job['id'], state='collecting', message='Administrator restore in progress. Provisioning is stopped.')
                    changed = True
                    self.system.apply_restore(archive, plan, directory)
                    self.system.finish_restore(archive, plan, job)
                    self.store.update(job['id'], state='succeeded', message='Full station restore completed. Configure a new backup master password and verify a new SSH session.')
                    return plan['preview']
                except Exception:
                    if changed:
                        self.store.update(job['id'], state='recovery-required', message='Full restore did not finish. Provisioning remains stopped. Run cmprovision-full-restore --recover over SSH.')
                    else:
                        self.system.release_restore(job)
                        self.store.update(job['id'], state='failed', message='Restore stopped before destination changes; the original station was retained.')
                    raise
