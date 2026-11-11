"""Constrained distribution-rclone profiles and verified, station-owned remote objects."""
import base64
from contextlib import contextmanager
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading
import urllib.parse
import uuid

from update_manifest import UpdateError, canonical_bytes
from update_store import ID_RE, matches

SECRETS = ('password', 'key_pem', 'key_password', 'access_key', 'secret_key', 'session_token', 'ca_pem')
FIELDS = {'id', 'type', 'name', 'host', 'port', 'user', 'path', 'host_key', 'auth', 'keep', 'mode', 'plain_ack',
          'bucket', 'region', 'endpoint', 'path_style', 'clear', *SECRETS}
ENV = {'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'HOME': '/nonexistent', 'LANG': 'C.UTF-8'}


def _host(value):
    try: ipaddress.ip_address(value); return
    except ValueError: pass
    if not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?', value) or '..' in value:
        raise UpdateError('Enter a valid destination hostname or IP address.')


def validate_profile(value, previous=None):
    if type(value) is not dict or set(value)-FIELDS: raise UpdateError('Unknown backup destination fields.')
    previous = previous or {}
    profile = {'id': value.get('id') or previous.get('id') or uuid.uuid4().hex,
        'name': value.get('name', ''), 'type': value.get('type'), 'path': value.get('path', ''), 'keep': value.get('keep', 7)}
    if not matches(ID_RE, profile['id']) or profile['type'] not in ('sftp', 'ftp', 's3'):
        raise UpdateError('Choose SFTP, FTP/FTPS or S3 as the backup destination.')
    if type(profile['name']) is not str or not 1 <= len(profile['name'].strip()) <= 60 or any(ord(c) < 32 for c in profile['name']):
        raise UpdateError('Give the backup destination a name of 1 to 60 characters.')
    if type(profile['path']) is not str or len(profile['path']) > 1024 or any(c in profile['path'] for c in ('\x00', '\n', '\r', '\\')) or '..' in profile['path'].split('/'):
        raise UpdateError('Enter a destination path without parent traversal or control characters.')
    if type(profile['keep']) is not int or not 1 <= profile['keep'] <= 365: raise UpdateError('Retention must be 1 to 365 successful copies.')
    clear = value.get('clear', [])
    if type(clear) is not list or any(type(key) is not str or key not in SECRETS for key in clear):
        raise UpdateError('Invalid credential clearing choice.')
    for key in SECRETS:
        data = value.get(key, '')
        if type(data) is not str or len(data.encode('utf-8')) > (65536 if key in ('key_pem', 'ca_pem') else 4096) or '\x00' in data:
            raise UpdateError('A destination credential is invalid or too large.')
        if key not in ('key_pem', 'ca_pem') and any(c in data for c in ('\n', '\r')):
            raise UpdateError('Destination credentials must not contain line breaks.')
        profile[key] = '' if key in clear else data or previous.get(key, '')
    if profile['ca_pem'] and ('-----BEGIN CERTIFICATE-----' not in profile['ca_pem'] or 'PRIVATE KEY' in profile['ca_pem']):
        raise UpdateError('Upload a PEM CA certificate without a private key.')
    if profile['type'] in ('sftp', 'ftp'):
        profile.update(host=value.get('host', ''), user=value.get('user', ''))
        if type(profile['host']) is not str: raise UpdateError('Invalid backup destination host.')
        _host(profile['host'])
        if type(profile['user']) is not str or not re.fullmatch(r'[A-Za-z0-9_.@+-]{1,128}', profile['user']):
            raise UpdateError('Enter a valid destination login.')
        mode = value.get('mode', 'explicit')
        port = value.get('port')
        if port is None: port = 22 if profile['type'] == 'sftp' else 990 if mode == 'implicit' else 21
        if type(port) is not int or not 1 <= port <= 65535: raise UpdateError('Destination port must be 1 to 65535.')
        profile['port'] = port
        if profile['type'] == 'ftp':
            if mode not in ('explicit', 'implicit', 'plain') or (mode == 'plain' and value.get('plain_ack') is not True):
                raise UpdateError('Plain FTP requires explicit acknowledgement that the login is not encrypted.')
            profile.update(mode=mode, plain_ack=mode == 'plain')
            if not profile['password']: raise UpdateError('Enter the FTP password.')
        else:
            profile['auth'] = value.get('auth', 'password')
            if profile['auth'] not in ('password', 'key'): raise UpdateError('Choose SFTP password or private-key authentication.')
            if profile['auth'] == 'password' and not profile['password']: raise UpdateError('Enter the SFTP password.')
            if profile['auth'] == 'key' and not re.search(r'-----BEGIN (?:OPENSSH |RSA |EC |ENCRYPTED )?PRIVATE KEY-----', profile['key_pem']):
                raise UpdateError('Upload a supported SFTP private key.')
            key = value.get('host_key', '')
            if type(key) is not str or len(key) > 16384: raise UpdateError('Invalid SFTP server host key.')
            if key:
                parts = key.split()
                try: decoded = base64.b64decode(parts[1], validate=True) if len(parts) == 2 else b''
                except ValueError: decoded = b''
                if not parts or parts[0] not in ('ssh-ed25519', 'ssh-rsa', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521') or len(decoded) < 16:
                    raise UpdateError('Enter the full SFTP public host key, not just its fingerprint.')
            profile['host_key'] = key
    else:
        for key, default in (('bucket', ''), ('region', 'us-east-1'), ('endpoint', '')):
            data = value.get(key, default)
            if type(data) is not str or len(data) > 1024 or any(c in data for c in ('\n', '\r', '\x00')):
                raise UpdateError('Invalid S3 destination configuration.')
            profile[key] = data
        if not re.fullmatch(r'[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]', profile['bucket']) or '..' in profile['bucket']:
            raise UpdateError('Enter a valid S3 bucket name.')
        if not re.fullmatch(r'[A-Za-z0-9-]{1,64}', profile['region']): raise UpdateError('Enter a valid S3 region.')
        if profile['endpoint']:
            parsed = urllib.parse.urlsplit(profile['endpoint'])
            if parsed.scheme != 'https' or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/') or not parsed.hostname:
                raise UpdateError('Use an HTTPS S3 endpoint without embedded credentials, query or path.')
            _host(parsed.hostname)
            try: parsed.port
            except ValueError as error: raise UpdateError('Invalid S3 endpoint port.') from error
        if not profile['access_key'] or not profile['secret_key']: raise UpdateError('Enter the S3 access and secret keys.')
        profile['path_style'] = value.get('path_style', True)
        if type(profile['path_style']) is not bool: raise UpdateError('Invalid S3 path-style choice.')
    return profile


def _write(path, data):
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(fd, 'w') as stream: stream.write(data)


def _obscure(value):
    try:
        result = subprocess.run(['/usr/bin/rclone', 'obscure', '-'], input=(value+'\n').encode(),
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=ENV, timeout=10)
        text = result.stdout.decode().strip()
        if result.returncode or not re.fullmatch(r'[A-Za-z0-9_-]{1,8192}', text): raise UpdateError('Could not protect the transport configuration.')
        return text
    except (OSError, subprocess.SubprocessError) as error: raise UpdateError('Install the distribution rclone package to use remote backups.') from error


def _remote_failure(error, fallback):
    error.seek(0)
    text = error.read(8192).decode('utf-8', 'replace').lower()
    for words, message in (
        (('host key', 'knownhosts'), 'The SFTP server host key is unknown or has changed.'),
        (('certificate', 'x509', 'tls handshake'), 'The backup destination TLS certificate could not be verified.'),
        (('authentication failed', 'unable to authenticate', 'login incorrect', 'accessdenied', 'invalidaccesskeyid', 'signaturedoesnotmatch', '530 '), 'The backup destination rejected its login or credentials.'),
        (('permission denied', '550 ', 'access denied'), 'The backup destination denied permission for this operation.'),
        (('connection refused', 'no such host', 'network is unreachable', 'timeout', 'timed out'), 'The backup destination could not be reached before the timeout.'),
    ):
        if any(word in text for word in words): return UpdateError(message)
    return UpdateError(fallback)


class Remote:
    def __init__(self, profile, station, work, obscure=_obscure, executable='/usr/bin/rclone'):
        if not matches(ID_RE, station): raise UpdateError('Invalid backup station identity.')
        self.profile, self.station, self.work, self.obscure, self.executable = profile, station, Path(work), obscure, executable
        self.directory = self.config = None
        self.env = dict(ENV)

    def __enter__(self):
        self.directory = Path(tempfile.mkdtemp(prefix='remote-', dir=self.work)); self.directory.chmod(0o700)
        try:
            p = self.profile
            config = {'type': p['type']}
            if p['type'] in ('sftp', 'ftp'):
                config.update(host=p['host'], user=p['user'], port=p['port'])
                if p['type'] == 'sftp':
                    if not p['host_key']: raise UpdateError('Review and accept the SFTP server host key before using this destination.')
                    known = self.directory/'known_hosts'
                    host = p['host'] if p['port'] == 22 else '['+p['host']+']:'+str(p['port'])
                    _write(known, host+' '+p['host_key']+'\n')
                    config.update(known_hosts_file=known, disable_hashcheck='true', key_use_agent='false')
                    if p['auth'] == 'key':
                        private = self.directory/'identity'; _write(private, p['key_pem'])
                        config['key_file'] = private
                        if p['key_password']: config['key_file_pass'] = self.obscure(p['key_password'])
                    else: config['pass'] = self.obscure(p['password'])
                else:
                    config.update({'pass': self.obscure(p['password']), 'explicit_tls': str(p['mode'] == 'explicit').lower(),
                                   'tls': str(p['mode'] == 'implicit').lower(), 'disable_mlsd': 'true'})
            else:
                config.update(provider='Other' if p['endpoint'] else 'AWS', env_auth='false', region=p['region'],
                    access_key_id=p['access_key'], secret_access_key=p['secret_key'], session_token=p['session_token'],
                    force_path_style=str(p['path_style']).lower())
                if p['endpoint']: config['endpoint'] = p['endpoint']
            self.config = self.directory/'rclone.conf'
            _write(self.config, '[backup]\n'+''.join(str(key)+' = '+str(value)+'\n' for key, value in config.items()))
            if p['ca_pem']:
                _write(self.directory/'ca.pem', p['ca_pem'])
                # FTP uses Go's system cert pool, rather than rclone's HTTP --ca-cert transport.
                self.env['SSL_CERT_FILE'] = str(self.directory/'ca.pem')
            return self
        except BaseException:
            shutil.rmtree(self.directory); raise

    def __exit__(self, *args):
        if self.directory: shutil.rmtree(self.directory)

    def namespace(self):
        p = self.profile
        value = {key: p.get(key) for key in ('type', 'host', 'port', 'user', 'path', 'bucket', 'endpoint')}
        return hashlib.sha256(canonical_bytes(value)).hexdigest()

    def object(self, name):
        if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,159}', name): raise UpdateError('Invalid remote backup object identifier.')
        path = self.profile['path'].rstrip('/')+'/cmprovision/'+self.station+'/'+name
        if self.profile['type'] == 's3': path = self.profile['bucket']+'/'+path.lstrip('/')
        return 'backup:'+path

    def argv(self, action, path, destination=None):
        if action not in ('rcat', 'cat', 'deletefile', 'moveto'): raise UpdateError('Invalid remote backup operation.')
        if (action == 'moveto') != (destination is not None): raise UpdateError('Invalid remote move destination.')
        argv = [self.executable, action, path, *([destination] if destination else []), '--config', str(self.config), '--retries=1', '--low-level-retries=1',
            '--contimeout=15s', '--timeout=60s', '--transfers=1', '--checkers=1', '--buffer-size=1M',
            '--streaming-upload-cutoff=1M', '--log-level=ERROR', '--stats=0']
        if self.profile['type'] == 's3': argv += ['--s3-upload-concurrency=1', '--s3-chunk-size=128M']
        if self.profile['ca_pem']: argv += ['--ca-cert', str(self.directory/'ca.pem')]
        return argv

    def _put(self, name, data):
        try:
            with tempfile.TemporaryFile() as error:
                result = subprocess.run(self.argv('rcat', self.object(name)), input=data, stdout=subprocess.DEVNULL,
                    stderr=error, env=self.env, timeout=90)
                if result.returncode: raise _remote_failure(error, 'The backup destination rejected a write. Check credentials and write permission.')
        except (OSError, subprocess.SubprocessError) as error: raise UpdateError('The backup destination could not complete a write.') from error

    def remove(self, name):
        with tempfile.TemporaryFile() as error:
            result = subprocess.run(self.argv('deletefile', self.object(name)), stdout=subprocess.DEVNULL,
                stderr=error, env=self.env, timeout=45)
            if result.returncode not in (0, 3, 4): raise _remote_failure(error, 'The backup destination could not remove an owned object.')

    def read_hash(self, name, size, canceled=None, progress=None):
        error = tempfile.TemporaryFile()
        process = subprocess.Popen(self.argv('cat', self.object(name)), stdout=subprocess.PIPE,
            stderr=error, env=self.env)
        digest, done = hashlib.sha256(), 0
        try:
            while chunk := process.stdout.read(1024**2):
                done += len(chunk)
                if done > size: raise UpdateError('Remote verification found an oversized archive.')
                if canceled and canceled(): raise UpdateError('Backup canceled.')
                digest.update(chunk)
                if progress: progress(done, size)
            if process.wait(timeout=15) or done != size: raise _remote_failure(error, 'The backup destination could not read back the complete archive.')
            return digest.hexdigest()
        finally:
            process.stdout.close()
            if process.poll() is None: process.kill(); process.wait(timeout=10)
            error.close()

    def test(self):
        name = 'test-'+uuid.uuid4().hex+'.part'
        data = os.urandom(1024)
        verified = False
        try:
            self._put(name, data)
            if self.read_hash(name, len(data)) != hashlib.sha256(data).hexdigest(): raise UpdateError('Backup destination read-back checksum failed.')
            verified = True
        finally:
            try: self.remove(name)
            except Exception:
                if verified: raise
        return {'message': 'Connection, write, read-back and cleanup passed. No station backup was created.'}

    def upload(self, name, produce, canceled=None, progress=None):
        """Stream ciphertext through a bounded hash/copy bridge; no replay of this stream."""
        temporary = name+'.part'
        error = tempfile.TemporaryFile()
        process = subprocess.Popen(self.argv('rcat', self.object(temporary)), stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL, stderr=error, env=self.env)
        read_fd, write_fd = os.pipe()
        result = {'bytes': 0, 'digest': hashlib.sha256(), 'error': None}
        def consume():
            try:
                with os.fdopen(read_fd, 'rb') as source:
                    while chunk := source.read(1024**2):
                        if canceled and canceled(): raise UpdateError('Backup canceled.')
                        process.stdin.write(chunk)
                        result['digest'].update(chunk); result['bytes'] += len(chunk)
                        if progress: progress(result['bytes'], 0)
                process.stdin.close()
            except BaseException as error: result['error'] = error
        thread = threading.Thread(target=consume, daemon=True)
        thread.start()
        complete = False
        try:
            with os.fdopen(write_fd, 'wb') as sink: manifest = produce(sink)
            thread.join(timeout=75)
            if thread.is_alive() or result['error'] or process.wait(timeout=75):
                raise _remote_failure(error, 'The encrypted remote upload did not complete. The stream was not retried.')
            expected = result['digest'].hexdigest()
            moved = subprocess.run(self.argv('moveto', self.object(temporary), self.object(name)),
                stdout=subprocess.DEVNULL, stderr=error, env=self.env, timeout=1800)
            if moved.returncode: raise UpdateError('The uploaded archive could not be finalized at its destination.')
            actual = self.read_hash(name, result['bytes'], canceled, progress)
            if expected != actual: raise UpdateError('Remote backup read-back SHA256 verification failed.')
            # Rename is not atomic on every backend; only a completed marker makes this object usable.
            marker = {'schema': 1, 'format': 'cmprovision-full-backup', 'archive': name,
                'size': result['bytes'], 'sha256': expected, 'station_id': self.station}
            self._put(name+'.complete.json', canonical_bytes(marker))
            complete = True
            return dict(marker, name=name, manifest=manifest)
        finally:
            if process.poll() is None: process.kill(); process.wait(timeout=10)
            try: process.stdin.close()
            except (OSError, ValueError): pass
            thread.join(timeout=5)
            error.close()
            if not complete:
                for item in (temporary, name, name+'.complete.json'):
                    try: self.remove(item)
                    except Exception: pass

    def prune(self, records, keep):
        """Only the caller's immutable success ledger is eligible; no remote listing/globs."""
        removed = []
        eligible = [item for item in records if item.get('station_id') == self.station and item.get('namespace') == self.namespace()]
        ordered = sorted(enumerate(eligible), key=lambda item: (item[1].get('sequence', item[1]['created_at']), item[0]), reverse=True)
        for _, record in ordered[keep:]:
            name = record['name']
            if not re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[0-9a-f]{32}\.cmpbackup', name): continue
            if record.get('station_id') != self.station or record.get('namespace') != self.namespace(): continue
            self.remove(name+'.complete.json')
            self.remove(name)
            removed.append(record['id'])
        return removed


def discover_host_key(host, port):
    if type(host) is not str: raise UpdateError('Invalid SFTP host.')
    _host(host)
    if type(port) is not int or not 1 <= port <= 65535: raise UpdateError('Invalid SFTP port.')
    try:
        result = subprocess.run(['/usr/bin/ssh-keyscan', '-T', '5', '-p', str(port), '-t', 'ed25519,ecdsa,rsa', host],
            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, env=ENV, timeout=20)
        if len(result.stdout) > 128*1024: raise UpdateError('The SFTP server returned too many host keys.')
        keys = []
        for line in result.stdout.decode('ascii').splitlines():
            parts = line.split()
            if len(parts) != 3 or parts[0].startswith('#'): continue
            raw = base64.b64decode(parts[2], validate=True)
            keys.append({'key': parts[1]+' '+parts[2], 'fingerprint': 'SHA256:'+base64.b64encode(hashlib.sha256(raw).digest()).decode().rstrip('=')})
        if not keys: raise UpdateError('No SFTP host key could be read. Check host and port.')
        return keys[:8]
    except (OSError, ValueError, subprocess.SubprocessError) as error: raise UpdateError('SFTP host key discovery failed.') from error
