"""Source builds and cross-job package handoffs must retain immutable provenance."""
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('image_release_pipeline', ROOT / 'image/ci/pipeline.py')
pipeline = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pipeline)
TAG = 'v2.26.0'
SOURCE = 'c' * 40


def incoming(root):
    directory = root / 'incoming'
    assets = directory / 'assets'
    assets.mkdir(parents=True)
    checks = {}
    for name in ('cmprovision4_2.26.0_all.deb', 'fpms-lite_2.26.0_all.deb',
                 'fpms-upstream.txt', 'fpms-patch.sha256'):
        body = name.encode()
        (assets / name).write_bytes(body)
        checks[name] = hashlib.sha256(body).hexdigest()
    state = {'schema': 2, 'origin': 'source-build', 'repository': 'test/repo', 'tag': TAG,
             'source_sha': SOURCE, 'release_id': None, 'source_archive_sha256': 'd' * 64,
             'package_sha256': checks['cmprovision4_2.26.0_all.deb'],
             'fpms_sha256': checks['fpms-lite_2.26.0_all.deb'], 'input_sha256': checks}
    (directory / 'state.json').write_text(json.dumps(state))
    (directory / 'audit').mkdir()
    (directory / 'audit' / 'packages.log').write_text('checked\n')
    args = SimpleNamespace(repository='test/repo', tag=TAG, source_sha=SOURCE,
                           package_sha256=state['package_sha256'], fpms_sha256=state['fpms_sha256'],
                           incoming=directory, directory=root / 'output', attach_to_draft='false')
    return args, state


class ReleasePipelineTest(unittest.TestCase):
    def test_archive_stamps_selected_lightweight_tag_and_exact_source(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'source'
            source.mkdir()
            def git(*args):
                return subprocess.check_output(['git', '-C', str(source), *args], stderr=subprocess.DEVNULL).decode().strip()
            git('init')
            git('config', 'user.name', 'Release fixture')
            git('config', 'user.email', 'release@example.test')
            (source/'config').mkdir()
            (source/'config/version.php').write_bytes((ROOT/'config/version.php').read_bytes())
            (source/'.gitattributes').write_text('config/version.php export-subst\n')
            (source/'debian').mkdir()
            (source/'debian/changelog').write_text('cmprovision4 (2.25.0) unstable; urgency=medium\n')
            for name in ('tools/build-deb.sh', 'packaging/fpms/build.sh', 'image/build.sh'):
                path=source/name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('#!/bin/sh\n')
            git('add', '.')
            git('commit', '-m', 'Previous release')
            git('tag', '-a', 'v2.25.0', '-m', 'Previous annotated release')
            (source/'debian/changelog').write_text('cmprovision4 (2.26.0) unstable; urgency=medium\n')
            git('add', '.')
            git('commit', '-m', 'Selected source')
            git('tag', TAG)
            sha=git('rev-parse', 'HEAD')
            directory=root/'output'
            directory.mkdir()
            (directory/'state.json').write_text(json.dumps({'repository':'test/repo','tag':TAG,'source_sha':sha}))
            args=SimpleNamespace(directory=directory,repository='test/repo',tag=TAG,source_sha=sha,
                source_directory=source,source_tar=root/'source.tar')
            pipeline.archive(args, TAG[1:])
            with tarfile.open(args.source_tar) as archive:
                self.assertEqual(1, archive.getnames().count('config/version.php'))
                version=archive.extractfile('config/version.php').read().decode()
                self.assertIn("'describe' => '"+TAG+"'",version)
                self.assertIn("'commit' => '"+sha[:7]+"'",version)
                self.assertEqual((source/'debian/changelog').read_bytes(),archive.extractfile('debian/changelog').read())
            self.assertEqual(sha,git('rev-parse', 'refs/tags/'+TAG))
            self.assertEqual('',git('status', '--porcelain'))
            self.assertEqual(pipeline.release.sha256(args.source_tar),json.loads((directory/'state.json').read_text())['source_archive_sha256'])

    @unittest.skipUnless(shutil.which('php'), 'PHP CLI is required for the relocated payload audit')
    def test_payload_audit_supports_container_test_mount_outside_repository(self):
        with tempfile.TemporaryDirectory() as directory:
            script = Path(directory) / 'test_release_package.py'
            shutil.copyfile(ROOT / 'tests/image/test_release_package.py', script)
            result = subprocess.run([sys.executable, str(script)], capture_output=True, text=True,
                env=dict(os.environ, CMP_RELEASE_PACKAGE_CHECK=str(ROOT / 'image/ci/check-package.php')))
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_changelog_must_match_selected_release(self):
        self.assertEqual('2.26.0', pipeline.validate_changelog('cmprovision4 (2.26.0) stable; urgency=medium\n', TAG))
        for text in ('cmprovision4 (2.25.0) stable;', 'other (2.26.0) stable;', ''):
            with self.subTest(text=text), self.assertRaises(ValueError):
                pipeline.validate_changelog(text, TAG)

    def test_safe_handoff_copies_only_verified_package_inputs(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, {'GITHUB_OUTPUT': ''}):
            args, state = incoming(Path(tmp))
            with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE):
                pipeline.adopt(args, '2.26.0')
            copied = json.loads((args.directory / 'state.json').read_text())
            self.assertEqual(state, copied)
            self.assertEqual(set(state['input_sha256']), {p.name for p in (args.directory / 'assets').iterdir()})
            self.assertEqual('checked\n', (args.directory / 'audit/packages.log').read_text())

    def test_corrupted_package_never_reaches_image_inputs(self):
        with tempfile.TemporaryDirectory() as tmp:
            args, state = incoming(Path(tmp))
            (args.incoming / 'assets/cmprovision4_2.26.0_all.deb').write_bytes(b'corrupt')
            with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE), self.assertRaises(ValueError):
                pipeline.adopt(args, '2.26.0')
            self.assertFalse(args.directory.exists())

    def test_wrong_source_or_producer_digest_is_refused(self):
        for field, value in (('source_sha', 'a' * 40), ('package_sha256', 'a' * 64), ('repository', 'other/repo')):
            with self.subTest(field=field), tempfile.TemporaryDirectory() as tmp:
                args, state = incoming(Path(tmp))
                setattr(args, field, value)
                with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE), self.assertRaises(ValueError):
                    pipeline.adopt(args, '2.26.0')
                self.assertFalse(args.directory.exists())

    def test_tag_move_after_package_build_is_refused(self):
        with tempfile.TemporaryDirectory() as tmp:
            args, state = incoming(Path(tmp))
            with patch.object(pipeline.release, 'tag_commit', return_value='b' * 40), self.assertRaises(ValueError):
                pipeline.adopt(args, '2.26.0')
            self.assertFalse(args.directory.exists())

    def test_extra_asset_or_manifest_path_escape_is_refused(self):
        for unsafe in ('extra', '../secret'):
            with self.subTest(unsafe=unsafe), tempfile.TemporaryDirectory() as tmp:
                args, state = incoming(Path(tmp))
                state['input_sha256'][unsafe] = 'f' * 64
                (args.incoming / 'state.json').write_text(json.dumps(state))
                with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE), self.assertRaises(ValueError):
                    pipeline.adopt(args, '2.26.0')
                self.assertFalse(args.directory.exists())

    def test_resolve_checks_changelog_and_emits_no_draft_mutation(self):
        with tempfile.TemporaryDirectory() as tmp, patch.dict(os.environ, {'GITHUB_OUTPUT': ''}):
            args = SimpleNamespace(repository='test/repo', tag=TAG, directory=Path(tmp) / 'output', attach_to_draft='false')
            content = base64.b64encode(b'cmprovision4 (2.26.0) stable; urgency=medium\n').decode()
            with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE), \
                    patch.object(pipeline.release, 'api', return_value={'encoding': 'base64', 'content': content}), \
                    patch.object(pipeline.release, 'release_for_tag', return_value=None), \
                    patch.object(pipeline.release, 'api_write') as write:
                pipeline.resolve(args, '2.26.0')
            state = json.loads((args.directory / 'state.json').read_text())
            self.assertEqual(SOURCE, state['source_sha'])
            self.assertIsNone(state['release_id'])
            write.assert_not_called()

    def test_attach_to_published_release_fails_before_build(self):
        with tempfile.TemporaryDirectory() as tmp:
            args = SimpleNamespace(repository='test/repo', tag=TAG, directory=Path(tmp) / 'output', attach_to_draft='true')
            content = base64.b64encode(b'cmprovision4 (2.26.0) stable; urgency=medium\n').decode()
            with patch.object(pipeline.release, 'tag_commit', return_value=SOURCE), \
                    patch.object(pipeline.release, 'api', return_value={'encoding': 'base64', 'content': content}), \
                    patch.object(pipeline.release, 'release_for_tag', return_value={'id': 42, 'tag_name': TAG, 'draft': False}), \
                    self.assertRaises(ValueError):
                pipeline.resolve(args, '2.26.0')
            self.assertFalse(args.directory.exists())

    def test_automatic_candidate_source_cannot_change_before_package_build(self):
        with tempfile.TemporaryDirectory() as tmp:
            args = SimpleNamespace(repository='test/repo', tag=TAG, directory=Path(tmp)/'output',
                attach_to_draft='false', expected_source_sha=SOURCE)
            with patch.object(pipeline.release,'tag_commit',return_value='b'*40), \
                    patch.object(pipeline.release,'api') as read, \
                    self.assertRaisesRegex(ValueError,'checked release candidate'):
                pipeline.resolve(args,'2.26.0')
            read.assert_not_called()
            self.assertFalse(args.directory.exists())


if __name__ == '__main__':
    unittest.main()
