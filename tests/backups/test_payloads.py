"""Actual snapshot references, with SQLite inspection confined to www-data."""
import importlib
from contextlib import closing
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

from support import ROOT
from backup_store import BackupStore
from update_manifest import UpdateError


class PayloadsTest(unittest.TestCase):
    def setUp(self):
        if os.geteuid() != 0: self.skipTest('disposable www-data boundary requires root')
        self.temp = tempfile.TemporaryDirectory(); self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name); self.root.chmod(0o755)
        self.store = BackupStore(self.root/'state', self.root/'run', owner=os.getuid())
        self.module = importlib.import_module('backup_system')
        self.system = self.module.LinuxBackupSystem(self.store, root=self.root)
        self.database = self.root/'database.sqlite'
        with closing(sqlite3.connect(self.database)) as db:
            db.executescript("CREATE TABLE images(filename_on_server TEXT, sha256 TEXT, download_state TEXT, download_error TEXT, split_filename TEXT, split_offsets TEXT);"
                "CREATE TABLE packages(filename_on_server TEXT, size INTEGER, sha256 TEXT);")
            db.execute('INSERT INTO images VALUES(?,?,?,?,?,?)', ('image.gz', 'a'*64, None, None, 'pieces.gz', '[0,1024,2048]'))
            db.execute('INSERT INTO images VALUES(?,?,?,?,?,?)', ('pending.gz', '', 'queued', None, None, None))
            db.execute('INSERT INTO packages VALUES(?,?,?)', ('sensor.deb', 5, 'b'*64))
            db.commit()
        tool = ROOT/'tools/backup_payloads.py'
        self.helper = self.root/'backup_payloads.py'
        if tool.exists(): shutil.copyfile(tool, self.helper); self.helper.chmod(0o644)
        self.system.payload_helper = self.helper

    def test_missing_selected_image_piece_or_package_rejects_the_copy(self):
        references = self.system.payload_references(self.database)
        members = [{'name': 'application/public/uploads/image.gz', 'sha256': 'a'*64, 'size': 42},
            {'name': 'application/public/uploads/pieces.gz', 'sha256': 'c'*64, 'size': 2048},
            {'name': 'application/public/uploads/packages/sensor.deb', 'sha256': 'b'*64, 'size': 5}]
        self.assertNotIn('pending.gz', json.dumps(references))
        self.module.validate_payloads(references, members, {'images': True, 'packages': True})
        for missing in members:
            with self.subTest(missing=missing['name']), self.assertRaises(UpdateError):
                self.module.validate_payloads(references, [row for row in members if row != missing], {'images': True, 'packages': True})
        self.module.validate_payloads(references, [], {'images': False, 'packages': False})

    def test_archived_payload_declaration_cannot_hide_actual_database_references(self):
        from backup_restore_system import LinuxRestoreSystem
        from types import SimpleNamespace
        system = LinuxRestoreSystem(self.store, root=self.root); system.payload_helper = self.helper
        members = [{'name': 'application/database.sqlite', 'size': self.database.stat().st_size}]
        class Archive:
            manifest = {'options': {'images': True, 'packages': True}, 'members': members}
            def copy(inner, name, output): output.write(self.database.read_bytes())
            def read(inner, name): return b'{"schema":1,"references":[]}'
        # A valid encrypted archive may still contain an internally inconsistent database/inventory.
        with self.assertRaises(UpdateError): system.validate_restore_payloads(Archive())

    def test_helper_never_loads_host_sqlite_or_opens_it_as_root(self):
        calls = []
        original = self.system.runner
        def run(argv, **kwargs):
            calls.append(argv)
            return original(argv, **kwargs)
        self.system.runner = run
        self.system.payload_references(self.database)
        self.assertEqual(['/usr/sbin/runuser', '-u', 'www-data', '--', '/usr/bin/python3', '-I'], calls[0][:6])


if __name__ == '__main__': unittest.main()
