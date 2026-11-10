"""Opt-in loopback endpoints. No real destination credentials or existing SSH service changes."""
import contextlib
import importlib.util
import os
from pathlib import Path
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

from support import ROOT
from backup_remote import Remote, validate_profile
from backup_archive import Entry, create
from update_manifest import UpdateError


@unittest.skipUnless(os.environ.get('CMPROVISION_REMOTE_ACCEPTANCE') == '1', 'opt-in loopback endpoint acceptance')
class RemoteIntegrationTest(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0 or not Path('/usr/bin/rclone').exists(): self.skipTest('root and distribution rclone required')
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.port = self.free_port()
        self.processes = []
        self.addCleanup(self.stop)
        self.cert, self.key = self.root/'server.crt', self.root/'server.key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=127.0.0.1', '-addext', 'subjectAltName=IP:127.0.0.1', '-keyout', str(self.key),
            '-out', str(self.cert)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True)

    @staticmethod
    def free_port():
        with socket.socket() as server:
            server.bind(('127.0.0.1', 0)); return server.getsockname()[1]

    def launch(self, args):
        log = (self.root/('server-'+str(len(self.processes))+'.log')).open('wb')
        process = subprocess.Popen(args, stdout=log, stderr=log, start_new_session=True)
        self.processes.append((process, log))
        deadline = time.monotonic()+15
        while time.monotonic() < deadline:
            if process.poll() is not None: self.fail('Disposable endpoint did not start: '+Path(log.name).read_text(errors='replace')[-1800:])
            try:
                with socket.create_connection(('127.0.0.1', self.port), timeout=0.1): return
            except OSError: time.sleep(0.05)
        self.fail('Disposable endpoint did not become ready')

    def stop(self):
        for process, log in self.processes:
            if process.poll() is None:
                os.killpg(process.pid, signal.SIGTERM)
                try: process.wait(timeout=10)
                except subprocess.TimeoutExpired: os.killpg(process.pid, signal.SIGKILL); process.wait(timeout=10)
            log.close()

    def exercise(self, profile):
        profile = validate_profile(profile)
        name = '20261111T000000Z-'+'c'*32+'.cmpbackup'
        data = os.urandom(2*1024**2)
        metadata = {'station_id': 'b'*32, 'version': '2.28.0', 'os': {'id': 'raspbian', 'version': '13', 'architecture': 'arm64'}}
        def fixture_failure(error, fallback):
            # Only disposable test endpoint output; redact even the fixed fixture credentials.
            error.seek(0)
            detail = error.read(4096).decode('utf-8', 'replace')
            for key in ('password', 'access_key', 'secret_key', 'session_token', 'key_pem'):
                if profile.get(key): detail = detail.replace(profile[key], '[redacted]')
            detail += '\nEndpoint log: '+''.join(Path(log.name).read_text(errors='replace')[-1600:] for _, log in self.processes)
            return UpdateError(fallback+' [loopback fixture: '+detail+']')
        with patch('backup_remote._remote_failure', side_effect=fixture_failure), Remote(profile, 'b'*32, self.root) as remote:
            remote.test()
            result = remote.upload(name, lambda output: create([Entry('application/.env', data=data)], output,
                'integration master password', metadata))
            self.assertEqual(name, result['archive'])
            self.assertGreater(result['size'], len(data))
            self.assertEqual(result['sha256'], remote.read_hash(name, result['size']))
            remote.remove(name+'.complete.json'); remote.remove(name)

    def sftp(self, reject_key=False):
        if not Path('/usr/sbin/sshd').exists(): self.skipTest('sshd required')
        host = self.root/'host'
        client = self.root/'client'
        for key in (host, client):
            subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)], check=True)
        authorized = self.root/'authorized_keys'; authorized.write_bytes(client.with_suffix('.pub').read_bytes())
        authorized.chmod(0o600)
        # A separate loopback-only server; its key/authorized_keys live only in this private temporary tree.
        config = self.root/'sshd_config'
        config.write_text('\n'.join(['Port '+str(self.port), 'ListenAddress 127.0.0.1', 'HostKey '+str(host),
            'PidFile '+str(self.root/'sshd.pid'), 'AuthorizedKeysFile '+str(authorized), 'PasswordAuthentication no',
            'KbdInteractiveAuthentication no', 'PermitRootLogin yes', 'UsePAM no', 'StrictModes no',
            'Subsystem sftp internal-sftp', 'ForceCommand internal-sftp', 'AllowUsers root'])+'\n')
        runtime = Path('/run/sshd')
        created = not runtime.exists()
        runtime.mkdir(exist_ok=True)
        if created: self.addCleanup(lambda: runtime.rmdir() if runtime.exists() and not any(runtime.iterdir()) else None)
        self.launch(['/usr/sbin/sshd', '-D', '-e', '-f', str(config)])
        profile = {'name': 'Loopback SFTP', 'type': 'sftp', 'host': '127.0.0.1', 'port': self.port,
            'user': 'root', 'auth': 'key', 'key_pem': client.read_text(), 'host_key': ' '.join(host.with_suffix('.pub').read_text().split()[:2]),
            'path': str(self.root/'objects')}
        if reject_key:
            profile['host_key'] = ' '.join(client.with_suffix('.pub').read_text().split()[:2])
            with Remote(validate_profile(profile), 'b'*32, self.root) as remote, self.assertRaisesRegex(UpdateError, 'host key'):
                remote.test()
        else: self.exercise(profile)

    def test_real_sftp_with_pinned_key_and_ephemeral_authorized_key(self): self.sftp()
    def test_sftp_changed_host_key_is_rejected(self): self.sftp(True)

    def ftp(self, tls, trust=True):
        if not importlib.util.find_spec('pyftpdlib'): self.skipTest('python3-pyftpdlib required')
        objects = self.root/'objects'; objects.mkdir()
        script = self.root/'ftp-server.py'
        script.write_text('from pyftpdlib.authorizers import DummyAuthorizer\n'
            'from pyftpdlib.handlers import FTPHandler, TLS_FTPHandler\nfrom pyftpdlib.servers import FTPServer\n'
            'authorizer = DummyAuthorizer()\nauthorizer.add_user("backup", "test-transport-password", '+repr(str(objects))+', perm="elradfmwMT")\n'
            'handler = '+('TLS_FTPHandler' if tls else 'FTPHandler')+'\nhandler.authorizer = authorizer\n'
            'handler.certfile = '+repr(str(self.cert))+'\nhandler.keyfile = '+repr(str(self.key))+'\n'
            'FTPServer(("127.0.0.1", '+str(self.port)+'), handler).serve_forever()\n')
        self.launch([sys.executable, str(script)])
        profile = {'name': 'Loopback FTP', 'type': 'ftp', 'host': '127.0.0.1', 'port': self.port,
            'user': 'backup', 'password': 'test-transport-password', 'path': '', 'mode': 'explicit' if tls else 'plain',
            'plain_ack': not tls, 'ca_pem': self.cert.read_text() if tls and trust else ''}
        if not trust:
            with Remote(validate_profile(profile), 'b'*32, self.root) as remote, self.assertRaisesRegex(UpdateError, 'TLS'):
                remote.test()
        else: self.exercise(profile)

    def test_real_ftp(self): self.ftp(False)
    def test_real_explicit_ftps_with_verified_custom_ca(self): self.ftp(True)
    def test_ftps_unknown_certificate_is_rejected(self): self.ftp(True, False)

    def test_s3_protocol_over_https_with_real_rclone_and_disposable_moto(self):
        if not importlib.util.find_spec('moto'): self.skipTest('python3-moto required')
        script = self.root/'s3-server.py'
        script.write_text('from moto.server import DomainDispatcherApplication, create_backend_app\n'
            'from werkzeug.serving import make_server\n'
            'make_server("127.0.0.1", '+str(self.port)+', DomainDispatcherApplication(create_backend_app), '
            'ssl_context=('+repr(str(self.cert))+', '+repr(str(self.key))+'), threaded=True).serve_forever()\n')
        self.launch([sys.executable, str(script)])
        import boto3
        endpoint = 'https://127.0.0.1:'+str(self.port)
        client = boto3.client('s3', endpoint_url=endpoint, region_name='us-east-1', aws_access_key_id='test-access',
            aws_secret_access_key='test-secret', verify=str(self.cert))
        client.create_bucket(Bucket='station-backups')
        # Moto 5.1.1 emits VersionId="null" after unversioned multipart and rejects HEAD of that
        # ID. Exercise unversioned single PUT first, then real version IDs for multipart.
        profile = {'name': 'Loopback S3', 'type': 's3', 'bucket': 'station-backups', 'endpoint': endpoint,
            'access_key': 'test-access', 'secret_key': 'test-secret', 'ca_pem': self.cert.read_text()}
        with Remote(validate_profile(profile), 'b'*32, self.root) as remote: remote.test()
        client.put_bucket_versioning(Bucket='station-backups', VersioningConfiguration={'Status': 'Enabled'})
        self.exercise(profile)


if __name__ == '__main__': unittest.main()
