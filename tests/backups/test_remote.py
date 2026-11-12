import base64
import importlib
import json
import os
from pathlib import Path
import struct
import textwrap
import tempfile
import unittest
from unittest.mock import patch

from support import ROOT
from update_manifest import UpdateError


KEY = 'ssh-ed25519 '+base64.b64encode(struct.pack('>I', 11)+b'ssh-ed25519'+struct.pack('>I', 32)+b'x'*32).decode()


class RemoteTest(unittest.TestCase):
    def setUp(self):
        self.module = importlib.import_module('backup_remote')
        self.profile = {'id': 'a'*32, 'type': 'sftp', 'name': 'Backup target', 'host': '127.0.0.1',
            'user': 'backup', 'path': '/backups', 'password': 'private transport password', 'host_key': KEY, 'keep': 7}

    def test_strict_profiles_do_not_accept_commands_paths_flags_or_unverified_tls(self):
        value = self.module.validate_profile(self.profile)
        self.assertEqual(22, value['port'])
        for change in ({'host': '--config=/etc/shadow'}, {'path': '../elsewhere'}, {'port': 0},
                {'extra_flags': '--no-check-certificate'}, {'password': 'bad\nconfig=true'}):
            with self.subTest(change=change), self.assertRaises(UpdateError):
                self.module.validate_profile(dict(self.profile, **change))
        ftp = {'type': 'ftp', 'name': 'FTP', 'host': '127.0.0.1', 'user': 'backup', 'password': 'private', 'path': '/backups'}
        self.assertEqual('explicit', self.module.validate_profile(ftp)['mode'])
        with self.assertRaises(UpdateError): self.module.validate_profile(dict(ftp, mode='plain'))
        self.assertEqual('plain', self.module.validate_profile(dict(ftp, mode='plain', plain_ack=True))['mode'])
        s3 = {'type': 's3', 'name': 'S3', 'bucket': 'station-backups', 'access_key': 'access', 'secret_key': 'secret', 'endpoint': 'https://s3.example.test:9000', 'path': 'stations'}
        self.assertEqual('s3', self.module.validate_profile(s3)['type'])
        with self.assertRaises(UpdateError): self.module.validate_profile(dict(s3, endpoint='http://s3.example.test'))
        with self.assertRaises(UpdateError): self.module.validate_profile(dict(s3, endpoint='https://user:password@s3.example.test'))

    def test_blank_secret_preserves_previous_and_clear_is_explicit(self):
        previous = self.module.validate_profile(self.profile)
        updated = self.module.validate_profile(dict(self.profile, password=''), previous)
        self.assertEqual('private transport password', updated['password'])
        with self.assertRaises(UpdateError):
            self.module.validate_profile(dict(self.profile, password='', clear=['password']), previous)

    def test_generated_backend_config_is_private_and_sftp_cannot_use_an_agent_or_remote_shell(self):
        with tempfile.TemporaryDirectory() as directory:
            profile = self.module.validate_profile(self.profile)
            with self.module.Remote(profile, 'b'*32, Path(directory), obscure=lambda _: 'obscured-fixture') as remote:
                text = remote.config.read_text()
                self.assertIn('known_hosts_file', text)
                self.assertIn('disable_hashcheck = true', text)
                self.assertNotIn('private transport password', text)
                self.assertEqual(0o600, remote.config.stat().st_mode & 0o777)
                args = remote.argv('rcat', remote.object('c'*32+'.cmpbackup'))
                self.assertNotIn('private transport password', ' '.join(args))
                self.assertIn('--retries=1', args)
                self.assertNotIn('--no-check-certificate', args)

    def fake(self, root):
        script = root/'rclone-fixture'
        script.write_text('#!/usr/bin/python3\n'+textwrap.dedent('''
            import pathlib, shutil, sys
            action, value = sys.argv[1:3]
            path = pathlib.Path(value.split(':', 1)[1])
            path.parent.mkdir(parents=True, exist_ok=True)
            if action == 'rcat':
                with path.open('wb') as output: shutil.copyfileobj(sys.stdin.buffer, output)
            elif action == 'cat':
                with path.open('rb') as source: shutil.copyfileobj(source, sys.stdout.buffer)
            elif action == 'moveto':
                target = pathlib.Path(sys.argv[3].split(':', 1)[1]); target.parent.mkdir(parents=True, exist_ok=True)
                path.replace(target)
            elif action == 'deletefile': path.unlink(missing_ok=True)
            else: raise SystemExit(2)
        '''))
        script.chmod(0o700)
        profile = self.module.validate_profile(dict(self.profile, path=str(root/'remote')))
        return self.module.Remote(profile, 'b'*32, root, obscure=lambda _: 'fixture', executable=str(script))

    def test_stream_upload_is_verified_then_published_and_own_retention_ignores_foreign_records(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            with self.fake(root) as remote:
                remote.test()
                names = ['20261111T000000Z-'+key*32+'.cmpbackup' for key in ('a', 'c')]
                records = []
                for index, name in enumerate(names):
                    result = remote.upload(name, lambda sink: (sink.write(b'encrypted payload'), {'schema': 2})[1])
                    self.assertEqual(name, result['archive'])
                    self.assertEqual(len(b'encrypted payload'), result['size'])
                    self.assertTrue(Path(remote.object(name+'.complete.json').split(':', 1)[1]).exists())
                    self.assertFalse(Path(remote.object(name+'.part').split(':', 1)[1]).exists())
                    records.append({'id': str(index), 'name': name, 'created_at': index, 'station_id': 'b'*32, 'namespace': remote.namespace()})
                foreign = dict(records[0], id='foreign', station_id='e'*32, created_at=9999)
                removed = remote.prune(records+[foreign], 365)
                self.assertEqual(['0'], removed)
                self.assertTrue(Path(remote.object(names[1]).split(':', 1)[1]).exists())

    def test_failed_readback_never_commits_a_backup_and_cleans_both_names(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            with self.fake(root) as remote:
                name = '20261111T000000Z-'+'c'*32+'.cmpbackup'
                with patch.object(remote, 'read_hash', return_value='0'*64), self.assertRaises(UpdateError):
                    remote.upload(name, lambda sink: (sink.write(b'ciphertext'), {})[1])
                for suffix in ('', '.part', '.complete.json'):
                    self.assertFalse(Path(remote.object(name+suffix).split(':', 1)[1]).exists())


if __name__ == '__main__': unittest.main()
