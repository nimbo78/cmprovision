import json
import os
from pathlib import Path
import tempfile
import unittest

from support import manifest
from update_store import UpdateStore
from update_retention import prune


class RetentionTest(unittest.TestCase):
    def test_only_unreferenced_owned_update_artifacts_are_removed(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            store = UpdateStore(root/'updates', root/'run', owner=os.getuid())
            entries = []
            for number in range(7):
                operation, _ = store.begin({'action': 'check', 'nonce': f'{number:064x}', 'components': ['application']})
                identity = operation['id']
                directory = store.private/'recovery'/identity
                store._directory(directory, 0o700)
                (directory/'evidence').write_text('old snapshot')
                os.utime(directory, (1, 1))
                store.update(identity, state='succeeded', backup={'directory': str(directory)},
                    previous={'utility_selector': 'managed:2026.10.1'})
                entries.append(directory)
            unrelated = store.private/'recovery'/'operator-backup'
            unrelated.mkdir()
            (unrelated/'keep').write_text('unrelated')
            orphan = store.payload_dir('a'*64)
            (orphan/'partial.deb').write_bytes(b'old')
            os.utime(orphan, (1, 1))
            managed = root/'managed/releases'
            managed.mkdir(parents=True)
            incomplete = managed/'.prepare-owned-crash'
            incomplete.mkdir()
            os.utime(incomplete, (1, 1))
            prune(store, root/'managed', now=10000)
            self.assertFalse(entries[0].exists())
            self.assertFalse(entries[1].exists())
            self.assertTrue(all(path.exists() for path in entries[2:]))
            self.assertTrue(unrelated.exists())
            self.assertFalse(orphan.exists())
            self.assertFalse(incomplete.exists())


if __name__ == '__main__': unittest.main()
