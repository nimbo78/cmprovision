"""Automatic release decisions; every GitHub write is mocked."""
import copy
import importlib.util
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('automatic_release', ROOT / 'image/ci/automatic-release.py')
auto = importlib.util.module_from_spec(spec)
spec.loader.exec_module(auto)
SOURCE = 'a' * 40
VERSION = '2.27.0'
TAG = 'v' + VERSION


class AutomaticCandidateTest(unittest.TestCase):
    def setUp(self):
        self.addCleanup(patch.stopall)
        self.args = SimpleNamespace(repository='test/repo', source_sha=SOURCE, source_directory=Path('/unused'))
        self.context = patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'push', 'GITHUB_REF': 'refs/heads/main',
            'GITHUB_REPOSITORY': 'test/repo', 'GITHUB_SHA': SOURCE, 'CMPROVISION_RELEASE_REPOSITORY':'test/repo'})
        self.context.start()
        self.files = {'debian/changelog': 'cmprovision4 (2.27.0) unstable; urgency=medium\n',
            'docs/releases/2.27.0.md': '# 2.27.0\n\nRestart and shut down the station after confirmation.\n',
            'docs/ru/releases/2.27.0.md': '# 2.27.0\n\nПерезагрузка и выключение станции после подтверждения.\n'}
        self.read = patch.object(auto, 'source_file', side_effect=lambda directory, sha, path: self.files[path])
        self.read.start()
        self.head = patch.object(auto, 'checkout_sha', return_value=SOURCE)
        self.head.start()
        self.api = patch.object(auto.release, 'api', return_value=[])
        self.api_mock = self.api.start()
        self.existing = patch.object(auto.release, 'release_for_tag', return_value=None)
        self.existing_mock = self.existing.start()
        self.write = patch.object(auto.release, 'api_write', return_value={'ref': 'refs/tags/' + TAG,
            'object': {'type': 'commit', 'sha': SOURCE}})
        self.write_mock = self.write.start()
        self.output = patch.object(auto, 'outputs')
        self.output_mock = self.output.start()

    def tearDown(self):
        patch.stopall()

    def test_new_stable_version_pins_checked_commit_and_requests_complete_release(self):
        self.assertEqual(TAG, auto.prepare(self.args))
        self.write_mock.assert_called_once_with('repos/test/repo/git/refs', {'ref': 'refs/tags/' + TAG, 'sha': SOURCE})
        self.output_mock.assert_called_with({'release_tag': TAG, 'source_sha': SOURCE})

    def test_development_versions_skip_without_network_or_tag(self):
        self.files['debian/changelog'] = 'cmprovision4 (2.27.0~dev1) unstable; urgency=medium\n'
        self.assertIsNone(auto.prepare(self.args))
        self.api_mock.assert_not_called()
        self.write_mock.assert_not_called()

    def test_missing_or_empty_bilingual_notes_stop_before_creating_tag(self):
        for missing in ('', '# 2.27.0\n', '# 2.28.0\n\nWrong version notes.'):
            self.files['docs/ru/releases/2.27.0.md'] = missing
            with self.subTest(missing=missing), self.assertRaises(ValueError):
                auto.prepare(self.args)
        self.write_mock.assert_not_called()

    def test_matching_tag_allows_retry_without_retargeting(self):
        self.api_mock.return_value = [{'ref': 'refs/tags/' + TAG}]
        with patch.object(auto.release, 'tag_commit', return_value=SOURCE):
            self.assertEqual(TAG, auto.prepare(self.args))
        self.write_mock.assert_not_called()

    def test_version_already_pinned_to_another_commit_skips_without_build_or_write(self):
        self.api_mock.return_value = [{'ref': 'refs/tags/' + TAG}]
        with patch.object(auto.release, 'tag_commit', return_value='b' * 40):
            self.assertIsNone(auto.prepare(self.args))
        self.write_mock.assert_not_called()
        self.output_mock.assert_called_with({'release_tag': '', 'source_sha': ''})

    def test_already_published_release_skips_normal_main_push(self):
        self.existing_mock.return_value = {'id': 1, 'tag_name': TAG, 'draft': False}
        self.assertIsNone(auto.prepare(self.args))
        self.write_mock.assert_not_called()

    def test_pr_fork_or_mismatched_checkout_cannot_create_tag(self):
        for changed in ({'GITHUB_EVENT_NAME': 'pull_request'}, {'GITHUB_REF': 'refs/heads/other'},
                        {'GITHUB_REPOSITORY': 'other/repo'}, {'GITHUB_SHA': 'b' * 40}):
            with self.subTest(changed=changed), patch.dict(os.environ, changed), self.assertRaises(ValueError):
                auto.prepare(self.args)
        with patch.object(auto, 'checkout_sha', return_value='b' * 40), self.assertRaises(ValueError):
            auto.prepare(self.args)
        self.write_mock.assert_not_called()

    def test_a_tag_creation_race_reuses_only_an_identical_source(self):
        self.write_mock.side_effect = subprocess.CalledProcessError(1, ['gh'])
        with patch.object(auto.release, 'tag_commit', return_value=SOURCE):
            self.assertEqual(TAG, auto.prepare(self.args))
        self.assertEqual(1, self.write_mock.call_count)

    def test_different_racing_source_is_never_retargeted(self):
        self.write_mock.side_effect = subprocess.CalledProcessError(1, ['gh'])
        with patch.object(auto.release, 'tag_commit', return_value='b' * 40), self.assertRaises(ValueError):
            auto.prepare(self.args)
        self.assertEqual(1, self.write_mock.call_count)

    def test_matching_unconfigured_fork_context_cannot_prepare_release(self):
        self.args.repository = 'fork-owner/cmprovision'
        with patch.dict(os.environ, {'GITHUB_REPOSITORY':self.args.repository}), self.assertRaises(ValueError):
            auto.prepare(self.args)
        self.write_mock.assert_not_called()

    def test_another_project_can_explicitly_configure_its_release_repository(self):
        self.args.repository = 'other-owner/cmprovision'
        with patch.dict(os.environ, {'GITHUB_REPOSITORY':self.args.repository,
                'CMPROVISION_RELEASE_REPOSITORY':self.args.repository}):
            self.assertEqual(TAG,auto.prepare(self.args))
        self.assertEqual('repos/other-owner/cmprovision/git/refs',self.write_mock.call_args.args[0])


def bundle(root, source_sha=SOURCE):
    assets = root / 'assets'
    assets.mkdir(parents=True)
    lines = []
    for name in sorted(auto.release.asset_names(VERSION)):
        content = name.encode()
        (assets / name).write_bytes(content)
        lines.append(f'{hashlib.sha256(content).hexdigest()}  {name}\n')
    (assets / 'SHA256SUMS').write_text(''.join(lines))
    checks = auto.release.verify_bundle(assets, VERSION)
    args = SimpleNamespace(repository='test/repo', tag=TAG, source_sha=source_sha, directory=root,
        source_directory=Path('/unused'), package_sha256=checks['cmprovision4_2.27.0_all.deb'],
        fpms_sha256=checks['fpms-lite_2.27.0_all.deb'])
    state = {key: getattr(args, key) for key in ('repository','tag','source_sha','package_sha256','fpms_sha256')}
    (root / 'state.json').write_text(json.dumps(state))
    report = dict(repository=args.repository, tag=TAG, source_sha=source_sha, release_id=42, checks=checks, draft=True)
    (root / 'release-report.json').write_text(json.dumps(report))
    target = dict(id=42, tag_name=TAG, draft=True, prerelease=False, html_url='https://example.test/releases/' + TAG,
        assets=[dict(name=name, digest='sha256:' + digest, state='uploaded', size=(assets / name).stat().st_size)
                for name,digest in checks.items()])
    return args, target


class AutomaticPublishTest(unittest.TestCase):
    def setUp(self):
        self.addCleanup(patch.stopall)
        self.temp = tempfile.TemporaryDirectory()
        self.args, self.target = bundle(Path(self.temp.name))
        self.head = patch.object(auto, 'checkout_sha', return_value=SOURCE)
        self.head.start()
        self.note = patch.object(auto, 'notes', return_value='# 🇬🇧 English\n\nChanges.\n\n---\n\n# 🇷🇺 Русский\n\nИзменения.\n')
        self.note_mock = self.note.start()
        self.target.update(name=f'🚀 {TAG}: Packages & station image / Пакеты и образ станции',body=self.note_mock.return_value)
        self.tag = patch.object(auto.release, 'tag_commit', return_value=SOURCE)
        self.tag_mock = self.tag.start()
        self.remote = patch.object(auto.release, 'release_for_tag', side_effect=lambda *a, **kw: copy.deepcopy(self.target))
        self.remote.start()
        self.versions = patch.object(auto, 'published_versions', return_value=[])
        self.versions_mock = self.versions.start()
        def published(path, payload):
            self.target.update(payload)
            return copy.deepcopy(self.target)
        self.write = patch.object(auto, 'update_release', side_effect=published)
        self.write_mock = self.write.start()

    def tearDown(self):
        patch.stopall()
        self.temp.cleanup()

    def test_complete_verified_draft_publishes_with_bilingual_notes(self):
        auto.publish(self.args)
        path, payload = self.write_mock.call_args.args
        self.assertEqual('repos/test/repo/releases/42', path)
        self.assertFalse(payload['draft'])
        self.assertEqual('true', payload['make_latest'])
        self.assertLess(payload['body'].index('# 🇬🇧 English'), payload['body'].index('# 🇷🇺 Русский'))
        self.assertEqual(1, self.write_mock.call_count)
        self.assertFalse(json.loads((self.args.directory/'release-report.json').read_text())['draft'])

    def test_incomplete_or_corrupt_remote_assets_never_publish(self):
        original = copy.deepcopy(self.target['assets'])
        for change in ('missing', 'digest', 'size', 'state', 'extra'):
            self.target['assets'] = copy.deepcopy(original)
            if change == 'missing': self.target['assets'].pop()
            elif change == 'extra': self.target['assets'].append(dict(name='unexpected',digest='sha256:'+'a'*64,state='uploaded',size=1))
            else: self.target['assets'][0][change] = {'digest':'sha256:'+'0'*64,'size':0,'state':'new'}[change]
            with self.subTest(change=change), self.assertRaises(ValueError):
                auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_local_image_or_independent_hash_change_never_publishes(self):
        path = self.args.directory / 'assets' / f'cmprovision-lite-{VERSION}-arm64.img.xz'
        path.write_bytes(b'corrupt')
        with self.assertRaises(ValueError): auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_missing_upload_report_or_changed_destination_never_publishes(self):
        report = self.args.directory / 'release-report.json'
        saved = report.read_text()
        report.unlink()
        with self.assertRaises((ValueError, FileNotFoundError)): auto.publish(self.args)
        report.write_text(saved)
        self.target['id'] = 43
        with self.assertRaises(ValueError): auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_wrong_source_tag_or_artifact_binding_never_publishes(self):
        self.tag_mock.return_value = 'b' * 40
        with self.assertRaises(ValueError): auto.publish(self.args)
        self.tag_mock.return_value = SOURCE
        self.args.package_sha256 = 'f' * 64
        with self.assertRaises(ValueError): auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_tag_changed_between_checks_cannot_publish(self):
        self.tag_mock.side_effect = [SOURCE, 'b' * 40]
        with self.assertRaises(ValueError): auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_repeated_published_version_is_verified_without_update(self):
        self.target['draft'] = False
        auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_existing_publication_with_placeholder_or_changed_metadata_is_not_success(self):
        self.target['draft'] = False
        original = copy.deepcopy(self.target)
        for changed in ({'body':'This workflow leaves the release as a draft.'}, {'name':'Untitled draft'}, {'body':None}):
            self.target = dict(original, **changed)
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_existing_version_notes_can_retain_the_original_build_run_link(self):
        self.target['draft'] = False
        self.target['body'] = self.target['body'].replace('Changes.',
            'Changes.\n\nBuild: [GitHub Actions](https://github.com/test/repo/actions/runs/123).')
        auto.publish(self.args)
        self.write_mock.assert_not_called()

    def test_lost_publish_reply_is_recovered_by_retry_without_editing_release(self):
        def lost(path,payload):
            self.target.update(payload)
            raise subprocess.CalledProcessError(1, ['gh'])
        self.write_mock.side_effect = lost
        with self.assertRaises(subprocess.CalledProcessError): auto.publish(self.args)
        auto.publish(self.args)
        self.assertEqual(1, self.write_mock.call_count)

    def test_older_build_does_not_replace_newer_latest_release(self):
        self.versions_mock.return_value = [(2,28,0), (2,9,0)]
        auto.publish(self.args)
        self.assertEqual('false', self.write_mock.call_args.args[1]['make_latest'])

    def test_metadata_update_uses_patch_json_stdin_without_shell(self):
        self.write.stop()
        result = SimpleNamespace(stdout=b'{"id":42}')
        with patch.object(auto.subprocess,'run',return_value=result) as run:
            self.assertEqual({'id':42},auto.update_release('repos/test/repo/releases/42',{'draft':False}))
        command = run.call_args.args[0]
        self.assertEqual(['gh','api','--method','PATCH','repos/test/repo/releases/42','--input','-'],command)
        self.assertEqual({'draft':False},json.loads(run.call_args.kwargs['input']))
        self.assertFalse(run.call_args.kwargs.get('shell',False))


class GitSourceIntegrationTest(unittest.TestCase):
    """Use real committed blobs; all remote writes remain test doubles."""
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / 'source'
        self.repo.mkdir()
        def git(*args):
            return subprocess.check_output(['git','-C',str(self.repo),*args],stderr=subprocess.PIPE).decode().strip()
        self.git = git
        git('init','-q')
        for path, text in {
            'debian/changelog':'cmprovision4 (2.27.0) unstable; urgency=medium\n',
            'docs/releases/2.27.0.md':'# 2.27.0\n\nConfirmed station power controls and complete automatic releases.\n',
            'docs/ru/releases/2.27.0.md':'# 2.27.0\n\nПодтверждаемое управление питанием станции и полные автоматические релизы.\n',
        }.items():
            file = self.repo/path
            file.parent.mkdir(parents=True,exist_ok=True)
            file.write_text(text,encoding='utf-8')
        git('add','.')
        git('-c','user.name=Release test','-c','user.email=release@example.test','commit','-qm','Fixture')
        self.sha = git('rev-parse','HEAD')

    def test_notes_use_exact_commit_even_if_working_tree_has_changed(self):
        (self.repo/'docs/releases/2.27.0.md').write_text('# 2.27.0\n\nDIRTY WORKING TREE CONTENT.',encoding='utf-8')
        text = auto.notes(self.repo,self.sha,VERSION,'test/repo','123')
        self.assertIn('Confirmed station power',text)
        self.assertNotIn('DIRTY',text)
        self.assertLess(text.index('# 🇬🇧 English'),text.index('# 🇷🇺 Русский'))
        self.assertEqual(2,text.count('/commit/'+self.sha))
        self.assertEqual(2,text.count('/actions/runs/123'))

    def test_real_git_candidate_and_publication_share_the_same_source(self):
        args,target = bundle(self.root/'bundle',self.sha)
        args.source_directory = self.repo
        environment = {'GITHUB_EVENT_NAME':'push','GITHUB_REF':'refs/heads/main',
            'GITHUB_REPOSITORY':'test/repo','GITHUB_SHA':self.sha,'GITHUB_OUTPUT':'',
            'CMPROVISION_RELEASE_REPOSITORY':'test/repo'}
        with patch.dict(os.environ,environment), patch.object(auto.release,'release_for_tag',return_value=None), \
                patch.object(auto.release,'api',return_value=[]), patch.object(auto.release,'api_write',
                    return_value={'ref':'refs/tags/'+TAG,'object':{'sha':self.sha}}) as create:
            self.assertEqual(TAG,auto.prepare(args))
            self.assertEqual(self.sha,create.call_args.args[1]['sha'])
        def published(path,payload):
            target.update(payload)
        with patch.object(auto.release,'release_for_tag',side_effect=lambda *a,**kw:copy.deepcopy(target)), \
                patch.object(auto.release,'tag_commit',return_value=self.sha), \
                patch.object(auto,'published_versions',return_value=[]), \
                patch.object(auto,'update_release',side_effect=published) as publish:
            auto.publish(args)
        body = publish.call_args.args[1]['body']
        self.assertIn('Confirmed station power',body)
        self.assertIn('Подтверждаемое управление',body)
        self.assertIn('/commit/'+self.sha,body)

    def test_missing_committed_notes_is_a_validation_error(self):
        with self.assertRaisesRegex(ValueError,'Missing or invalid versioned source'):
            auto.notes(self.repo,self.sha,'2.28.0')

    def test_latest_selection_uses_numeric_stable_published_versions(self):
        values = [[{'tag_name':'v2.9.0','draft':False,'prerelease':False},
            {'tag_name':'v2.28.0','draft':False,'prerelease':False},
            {'tag_name':'v3.0.0','draft':True,'prerelease':False},
            {'tag_name':'v2.99.0','draft':False,'prerelease':True},
            {'tag_name':'v3.0.0~dev1','draft':False,'prerelease':False}]]
        with patch.object(auto.release,'gh',return_value=json.dumps(values).encode()):
            self.assertEqual([(2,9,0),(2,28,0)],auto.published_versions('test/repo'))


if __name__ == '__main__':
    unittest.main()
