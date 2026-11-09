import hashlib
import importlib.util
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

from support import REPOSITORY
sys.path.insert(0, str(Path(__file__).resolve().parents[2]/'image/ci'))
import publish_utility as publisher


class UtilityPublishTest(unittest.TestCase):
    def test_first_release_creates_immutable_tag_before_draft_and_verifies_publication(self):
        with tempfile.TemporaryDirectory() as temp:
            directory = Path(temp)
            (directory/'fixture').write_bytes(b'tested artifact')
            target, tag = None, None
            writes = []
            def api(path): return []
            def create(path, payload):
                nonlocal target, tag
                writes.append(path)
                if path.endswith('/git/refs'):
                    tag = payload['sha']
                    return {'ref': payload['ref'], 'object': {'sha': tag}}
                if path.endswith('/releases'):
                    if tag is None: raise ValueError('A draft does not create a Git tag.')
                    target = dict(id=1, tag_name=payload['tag_name'], draft=True, prerelease=False, assets=[])
                    return target
                self.fail('Unexpected API write')
            def commit(*args):
                if tag is None: raise ValueError('The Git tag does not exist.')
                return tag
            def run(argv, **kwargs):
                if argv[1:3] == ['release', 'upload']:
                    path = Path(argv[4])
                    target['assets'].append({'name': path.name, 'size': path.stat().st_size,
                        'digest': 'sha256:'+hashlib.sha256(path.read_bytes()).hexdigest(), 'state': 'uploaded'})
                elif argv[1:4] == ['api', '--method', 'PATCH']: target['draft'] = False
                else: self.fail('Unexpected external command')
                return subprocess.CompletedProcess(argv, 0)
            with patch.object(publisher, 'verify', return_value={}), patch.object(publisher.release, 'api', side_effect=api), \
                 patch.object(publisher.release, 'api_write', side_effect=create), patch.object(publisher.release, 'tag_commit', side_effect=commit), \
                 patch.object(publisher.release, 'release_for_tag', side_effect=lambda *a, **kw: target), patch.object(publisher.subprocess, 'run', side_effect=run):
                publisher.publish(directory, REPOSITORY, 'f'*40, '2026.11.1', directory/'public')
            self.assertTrue(writes[0].endswith('/git/refs'))
            self.assertFalse(target['draft'])


if __name__ == '__main__': unittest.main()
