"""Offline-recoverable Debian provider replacement. Called only by the root console tool."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile

from stand_time import CHRONY, CONF, INTENT, TimeError, chrony_servers, stock_chrony_options, timesync_config, valid_servers

CACHE = 'var/lib/cmprovision-time-provider'
STATE = CACHE + '/previous.json'
OLD = 'systemd-timesyncd'


def run(*argv, cwd=None):
    result = subprocess.run(argv, cwd=cwd, capture_output=True, text=True, check=False,
                            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL': 'C',
                                 'DEBIAN_FRONTEND': 'noninteractive'})
    if result.returncode:
        raise TimeError(' '.join(argv[:2]) + ' failed: ' + (result.stderr or result.stdout)[-650:].strip())
    return result.stdout.strip()


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def version(package):
    state = run('dpkg-query', '-W', '-f=${db:Status-Status} ${Version}', package).split()
    if len(state) != 2 or state[0] != 'installed':
        raise TimeError('The expected ' + package + ' package is not installed.')
    return state[1]


def check_plan(text, removing=OLD, installing=None):
    for row in text.splitlines():
        if ((row.startswith(('Remv ', 'Purg ')) and row.split()[1].split(':')[0] != removing)
                or re.match(r'^Inst \S+ \[', row)
                or (installing and row.startswith('Inst ') and row.split()[1].split(':')[0] != installing)):
            raise TimeError('APT would upgrade or remove unrelated packages. Finish OS maintenance first.')


def legacy_profile(c):
    config = c.read_config()
    if config is not None and config != timesync_config(c.status()['managed_servers']):
        raise TimeError('Review the locally modified timesyncd drop-in before changing providers.')
    for name in ('etc/systemd/timesyncd.conf', 'etc/systemd/timesyncd.conf.d',
                 'run/systemd/timesyncd.conf.d', 'etc/systemd/system/systemd-timesyncd.service.d'):
        path = c.files.root/name
        if path.is_dir() and any(p != c.config for p in path.glob('*.conf')):
            raise TimeError('Review local timesyncd overrides before changing providers.')
        if path.is_file() and any(line.strip() and not line.lstrip().startswith((b'#', b';', b'['))
                                  for line in path.read_bytes().splitlines()):
            raise TimeError('Review the custom timesyncd profile before changing providers.')


def cache_packages(c):
    base = c.files.directory(CACHE, create=True)
    packages = base/'packages'
    if packages.exists():
        verify(c)
        return
    current = c.status()['provider']
    if current not in (OLD, 'chrony'):
        raise TimeError('Prepare the recovery packages with a supported local provider.')
    if current == OLD:
        legacy_profile(c)
    old = version(OLD if current == OLD else 'systemd')
    with tempfile.TemporaryDirectory(dir=base, prefix='.fetch-') as name:
        work = Path(name)
        if current == OLD:
            flags = ('-o', 'Dir::Cache::archives='+name, '--no-install-recommends', 'install', 'chrony')
            check_plan(run('apt-get', '-s', *flags))
            run('apt-get', '-y', '--download-only', *flags)
        else:
            check_plan(run('apt-get', '-s', '--no-install-recommends', 'install', OLD+'='+old), 'chrony', OLD)
            run('apt-get', 'download', 'chrony='+version('chrony'), cwd=work)
        run('apt-get', 'download', OLD+'='+old, cwd=work)
        records = {}
        for item in sorted(work.glob('*.deb')):
            pkg = run('dpkg-deb', '-f', str(item), 'Package')
            records[pkg] = [item.name, run('dpkg-deb', '-f', str(item), 'Version'), digest(item)]
        if OLD not in records or records[OLD][1] != old or 'chrony' not in records:
            raise TimeError('The exact old and new provider packages must both be available.')
        (work/'manifest.json').write_text(json.dumps(records))
        run('sync', '-f', name)
        os.rename(work, packages)
        run('sync', '-f', str(base))


def verify(c):
    directory = c.files.directory(CACHE+'/packages')
    records = json.loads(c.files.read(CACHE+'/packages/manifest.json') or b'{}')
    if not {OLD, 'chrony'} <= set(records):
        raise TimeError('Cache both provider packages before changing time service.')
    for file, _, checksum in records.values():
        if Path(file).name != file or not file.endswith('.deb') or (directory/file).is_symlink() or digest(directory/file) != checksum:
            raise TimeError('A cached provider package failed verification.')
    return directory, records


def state_record(c, state):
    saved = dict(enabled=state['ntp_enabled'], active=state['ntp_active'], servers=state['managed_servers'])
    c.files.write(STATE, (json.dumps(saved)+'\n').encode())
    return saved


def service(c, unit, saved):
    c.command('systemctl', 'enable' if saved['enabled'] else 'disable', unit)
    c.command('systemctl', 'restart' if saved['active'] else 'stop', unit)


def change(c, action, prepare):
    if c.stand.transitioning():
        raise TimeError('Wait for pending network, backup and update operations to finish.')
    report = json.loads(run('runuser', '-u', 'www-data', '--', '/usr/bin/php',
                            '/var/lib/cmprovision/artisan', 'updates:inspect'))
    admission = report.get('admission') or {}
    if admission.get('active') != [] or type(admission.get('reserved_jobs')) is not int or admission['reserved_jobs']:
        raise TimeError('Wait for the station to finish provisioning and image work.')
    if action == 'cache-provider':
        cache_packages(c)
        return
    directory, records = verify(c)
    saved = json.loads(c.files.read(STATE) or b'null')
    if action == 'use-chrony':
        if not saved:
            legacy_profile(c)
            state = c.status()
            if state['provider'] != OLD or version(OLD) != records[OLD][1]:
                raise TimeError('Refresh the recovery packages for the current timesyncd version.')
            if c.unit(CHRONY).get('LoadState') == 'masked' or not stock_chrony_options(c.files):
                raise TimeError('Review the existing chrony mask or local overrides first.')
            previous = c.files.read(CONF)
            if previous is not None:
                with tempfile.TemporaryDirectory(dir=directory.parent) as name:
                    run('dpkg-deb', '-x', str(directory/records['chrony'][0]), name)
                    if previous != (Path(name)/'usr/share/chrony/chrony.conf').read_bytes():
                        raise TimeError('Review the retained custom chrony profile before replacing timesyncd.')
            saved = state_record(c, state)
        if c.status()['provider'] == 'chrony' and saved.get('applied'):
            return
        target = 'chrony='+records['chrony'][1]
        with tempfile.TemporaryDirectory(dir=directory.parent, prefix='.install-') as name:
            for file, _, _ in records.values():
                os.link(directory/file, Path(name)/file)
            flags = ('-o', 'Dir::Cache::archives='+name, '--no-install-recommends', 'install', target)
            check_plan(run('apt-get', '-s', *flags))
            # Even dpkg maintainer scripts and a reboot mid-install must respect the old client's state.
            c.command('systemctl', 'mask', CHRONY)
            run('apt-get', '-y', '--no-download', *flags)
        prepare(c, True)
        c.command('systemctl', 'unmask', CHRONY)
        service(c, CHRONY, saved)
        saved['applied'] = True
        c.files.write(STATE, (json.dumps(saved)+'\n').encode())
    else:
        if saved is None:
            state = c.status()
            if state['provider'] != 'chrony':
                raise TimeError('No provider change with a saved client state was found.')
            if c.unit(c.UNIT).get('LoadState') == 'masked':
                raise TimeError('Review the existing timesyncd mask before changing providers.')
            saved = state_record(c, state)
        # A cached timesyncd may no longer match libsystemd-shared following a later OS upgrade.
        # The local .deb supplies the exact version even if package indexes have since expired.
        old_deb = str(directory/records[OLD][0])
        check_plan(run('apt-get', '-s', '--no-install-recommends', 'install', old_deb), 'chrony', OLD)
        c.command('systemctl', 'mask', c.UNIT)
        c.stand.close()
        c.files.write(INTENT, None)
        # Restore Debian's canonical profile before removing the package (dpkg retains conffiles).
        if chrony_servers(c.files) is not None:
            stock = c.files.read('usr/share/chrony/chrony.conf')
            if not stock:
                raise TimeError('Missing stock chrony profile. Review the installed package.')
            c.files.write(CONF, stock)
        state = run('dpkg-query', '-W', '-f=${db:Status-Status}', 'chrony') if (c.files.root/'var/lib/dpkg/info/chrony.list').exists() else ''
        if state and state not in ('not-installed', 'config-files'):
            run('dpkg', '--remove', 'chrony')
        run('dpkg', '--install', old_deb)
        c.command('systemctl', 'unmask', CHRONY)
        c.write_config(timesync_config(valid_servers(saved['servers'])))
        c.command('systemctl', 'unmask', c.UNIT)
        service(c, c.UNIT, saved)
        c.files.write(STATE, None)
