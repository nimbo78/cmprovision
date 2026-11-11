"""Unprivileged, standalone SQLite reference reader. Database bytes arrive only on stdin."""
import json
from contextlib import closing
import os
from pathlib import Path
import re
import shutil
import sqlite3
import sys
import tempfile

MAX_DATABASE = 512*1024**2
MAX_REFERENCES = 4000


def references(database):
    result = []
    def add(name, group, digest=None, size=None):
        if (type(name) is not str or not name or len(name) > 4096 or '\\' in name or ':' in name
                or any(ord(char) < 32 or ord(char) == 127 for char in name)
                or any(part in ('', '.', '..') or len(part) > 255 for part in name.split('/'))
                or name.split('/')[0] in ('upload-tmp', 'packages') and group == 'images'
                or any(part.startswith('.upload-') for part in name.split('/'))):
            raise ValueError('Unsafe payload reference')
        if digest is not None and (type(digest) is not str or not re.fullmatch('[0-9a-f]{64}', digest)):
            raise ValueError('Invalid payload checksum')
        if size is not None and (type(size) is not int or not 0 <= size <= 2**40): raise ValueError('Invalid payload size')
        prefix = 'application/public/uploads/'+('packages/' if group == 'packages' else '')
        result.append({'name': prefix+name, 'group': group, 'sha256': digest, 'size': size})
        if len(result) > MAX_REFERENCES: raise ValueError('Too many payload references')
    with closing(sqlite3.connect(database.as_uri()+'?mode=ro&immutable=1', uri=True)) as db:
        db.setlimit(sqlite3.SQLITE_LIMIT_LENGTH, 1024**2)
        db.enable_load_extension(False)
        db.execute('PRAGMA trusted_schema=OFF'); db.execute('PRAGMA query_only=ON')
        for name in ('images', 'packages'):
            row = db.execute('SELECT type,sql FROM sqlite_master WHERE name=?', (name,)).fetchone()
            if not row or row[0] != 'table' or not row[1].upper().startswith('CREATE TABLE'):
                raise ValueError('Unsupported payload schema')
        for filename, digest, split, offsets in db.execute('SELECT filename_on_server,sha256,split_filename,split_offsets FROM images WHERE download_state IS NULL AND download_error IS NULL'):
            add(filename, 'images', digest)
            if split:
                values = json.loads(offsets)
                if (type(values) is not list or len(values) < 3 or len(values) > 100000 or values[0] != 0
                        or any(type(value) is not int or value < 0 for value in values)
                        or any(b <= a for a, b in zip(values, values[1:]))): raise ValueError('Invalid split payload reference')
                add(split, 'images', size=values[-1])
        for filename, size, digest in db.execute('SELECT filename_on_server,size,sha256 FROM packages'):
            add(filename, 'packages', digest, size)
    names = [row['name'] for row in result]
    if len(set(names)) != len(names): raise ValueError('Duplicate payload references')
    return {'schema': 1, 'references': sorted(result, key=lambda row: row['name'])}


def main():
    if os.geteuid() == 0 or sys.argv[1:]: return 1
    try:
        with tempfile.TemporaryDirectory(prefix='cmprovision-payloads-', dir='/var/tmp') as directory:
            path = Path(directory)/'snapshot.sqlite'
            total = 0
            with path.open('xb') as stream:
                while chunk := sys.stdin.buffer.read(1024**2):
                    total += len(chunk)
                    if total > MAX_DATABASE or shutil.disk_usage(directory).free < 256*1024**2: raise ValueError('Snapshot exceeds inspection limits')
                    stream.write(chunk)
            raw = json.dumps(references(path), separators=(',', ':')).encode()
            if len(raw) > 1024**2: raise ValueError('Payload inventory is too large')
            sys.stdout.buffer.write(raw)
        return 0
    except (OSError, ValueError, TypeError, sqlite3.Error):
        return 1 # Never copy database values, SQL errors or credential-bearing paths into logs.


if __name__ == '__main__': raise SystemExit(main())
