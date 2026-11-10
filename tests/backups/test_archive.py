import importlib
import io
import json
import os
import hashlib
import subprocess
import tarfile
import tracemalloc
from pathlib import Path
import tempfile
import unittest

from support import ROOT
from update_manifest import UpdateError


class ArchiveTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.module = importlib.import_module('backup_archive')
        self.metadata = {'station_id': 'a'*32, 'version': '2.28.0', 'source_commit': 'd918911',
            'os': {'id': 'raspbian', 'version': '13', 'architecture': 'arm64'},
            'options': {'images': False, 'packages': False}, 'software': [], 'absent': []}

    def test_archive_round_trip_validates_manifest_and_fixed_files(self):
        entries = [self.module.Entry('application/.env', data=b'APP_KEY=private application key', group='application'),
                   self.module.Entry('system/accounts.json', data=b'[]', group='accounts')]
        archive = self.root/'backup.cmpbackup'
        self.module.create(entries, archive, 'sufficient master password', self.metadata)
        with self.module.inspect_archive(archive, 'sufficient master password', self.root) as verified:
            self.assertEqual(2, verified.manifest['schema'])
            self.assertEqual(2, len(verified.manifest['members']))
            self.assertEqual(b'APP_KEY=private application key', verified.read('application/.env'))
            self.assertEqual(0o600, verified.path.stat().st_mode & 0o777)
        self.assertEqual([archive], list(self.root.iterdir()))

    def test_wrong_password_and_tampering_leave_no_staging_or_destination_changes(self):
        archive = self.root/'backup.cmpbackup'
        self.module.create([self.module.Entry('application/.env', data=b'private')], archive, 'sufficient master password', self.metadata)
        with self.assertRaises(UpdateError):
            with self.module.inspect_archive(archive, 'wrong password', self.root): self.fail('must not authenticate')
        with archive.open('r+b') as stream:
            stream.seek(80); old = stream.read(1); stream.seek(80); stream.write(bytes([old[0]^1]))
        with self.assertRaises(UpdateError):
            with self.module.inspect_archive(archive, 'sufficient master password', self.root): self.fail('must not authenticate')
        self.assertEqual([archive], list(self.root.iterdir()))

    def test_unsafe_members_duplicates_and_symlink_sources_are_rejected(self):
        for name in ('../etc/shadow', '/etc/shadow', 'application/../etc/shadow', 'system/etc/shadow', 'application/storage/app/../../.env'):
            with self.subTest(name=name), self.assertRaises(UpdateError):
                self.module.create([self.module.Entry(name, data=b'x')], self.root/'unsafe.cmpbackup', 'sufficient master password', self.metadata)
        source = self.root/'source'
        source.write_bytes(b'data')
        (self.root/'link').symlink_to(source)
        with self.assertRaises(UpdateError):
            self.module.create([self.module.Entry('application/.env', root=self.root, source='link')], self.root/'unsafe.cmpbackup', 'sufficient master password', self.metadata)
        with self.assertRaises(UpdateError):
            self.module.create([self.module.Entry('application/.env', data=b'x')]*2, self.root/'unsafe.cmpbackup', 'sufficient master password', self.metadata)

    def test_authenticated_but_invalid_tar_members_and_inventory_are_rejected(self):
        for kind in ('link', 'device', 'duplicate', 'traversal', 'hash', 'manifest-duplicate', 'json-duplicate', 'boolean-size'):
            with self.subTest(kind=kind):
                plain = io.BytesIO()
                with tarfile.open(fileobj=plain, mode='w:gz') as archive:
                    info = tarfile.TarInfo('../etc/shadow' if kind == 'traversal' else 'application/.env')
                    info.size, info.mode = 1, 0o600
                    if kind == 'link': info.type = tarfile.SYMTYPE; info.linkname = '/etc/shadow'; info.size = 0
                    if kind == 'device': info.type = tarfile.CHRTYPE; info.size = 0
                    archive.addfile(info, io.BytesIO(b'x'))
                    if kind == 'duplicate': archive.addfile(info, io.BytesIO(b'x'))
                    member = {'name': info.name, 'size': 1, 'mode': 0o600, 'sha256': 'a'*64 if kind == 'hash' else hashlib.sha256(b'x').hexdigest(), 'group': 'application'}
                    manifest = dict(self.metadata, format='cmprovision-full-backup', schema=2, members=[member, member] if kind == 'manifest-duplicate' else [member])
                    if kind == 'boolean-size': member['size'] = True
                    raw = json.dumps(manifest).encode()
                    if kind == 'json-duplicate': raw = b'{"schema":999,'+raw[1:]
                    metadata = tarfile.TarInfo('manifest.json'); metadata.size = len(raw)
                    archive.addfile(metadata, io.BytesIO(raw))
                with tempfile.TemporaryFile() as secret:
                    secret.write(json.dumps({'password': 'sufficient master password'}).encode()); secret.seek(0)
                    sealed = subprocess.run(['php', str(ROOT/'tools/backup-crypto.php'), 'encrypt', str(secret.fileno())],
                        input=plain.getvalue(), pass_fds=(secret.fileno(),), capture_output=True, check=True)
                path = self.root/(kind+'.cmpbackup'); path.write_bytes(sealed.stdout)
                with self.assertRaises(UpdateError):
                    with self.module.inspect_archive(path, 'sufficient master password', self.root): self.fail('unsafe archive accepted')

    def test_large_source_is_streamed_and_changed_source_cleans_its_partial(self):
        source = self.root/'large.img'
        with source.open('wb') as stream: stream.truncate((2048 if os.environ.get('CMPROVISION_LARGE_BACKUP_TEST') else 256)*1024**2)
        entry = self.module.Entry('application/public/uploads/large.img', root=self.root, source=source.name)
        tracemalloc.start()
        try:
            self.module.create([entry], self.root/'large.cmpbackup', 'sufficient master password', self.metadata)
            self.assertLess(tracemalloc.get_traced_memory()[1], 16*1024**2)
        finally: tracemalloc.stop()
        changed = False
        def progress(done, total):
            nonlocal changed
            if done > 0 and not changed:
                changed = True
                with source.open('r+b') as stream: stream.write(b'changed')
        with self.assertRaises(UpdateError):
            self.module.create([entry], self.root/'changed.cmpbackup', 'sufficient master password', self.metadata, progress=progress)
        self.assertFalse((self.root/'changed.cmpbackup').exists())


if __name__ == '__main__': unittest.main()
