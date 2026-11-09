#!/usr/bin/env python3
"""Resolve tagged sources and verify production-package artifacts between jobs."""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess

spec = importlib.util.spec_from_file_location('pipeline_release', Path(__file__).with_name('release.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


def validate_changelog(text, tag):
    version = release.validate_tag(tag)
    match = re.match(r'cmprovision4 \(([^)]+)\)', text)
    if not match or match[1] != version:
        raise ValueError('Selected tag must match the source debian/changelog version')
    return version


def package_names(version):
    return {f'cmprovision4_{version}_all.deb', f'fpms-lite_{version}_all.deb',
            'fpms-upstream.txt', 'fpms-patch.sha256'}


def outputs(values):
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as stream:
            for key, value in values.items():
                value = '' if value is None else str(value)
                if '\n' in value or '\r' in value:
                    raise ValueError('Invalid multiline job output')
                stream.write(f'{key}={value}\n')


def empty_directory(path):
    if path.exists() and (path.is_symlink() or any(path.iterdir())):
        raise ValueError('Choose an empty output directory')
    path.mkdir(parents=True, exist_ok=True)


def resolve(args, version):
    source = release.tag_commit(args.repository, args.tag)
    expected = getattr(args, 'expected_source_sha', '')
    if expected and source != expected:
        raise ValueError('Tag no longer matches the checked release candidate')
    content = release.api(f'repos/{args.repository}/contents/debian/changelog?ref={source}')
    if content.get('encoding') != 'base64':
        raise ValueError('Cannot verify the tagged source changelog')
    validate_changelog(base64.b64decode(content['content']).decode(), args.tag)
    target = release.release_for_tag(args.repository, args.tag, missing_ok=True)
    if args.attach_to_draft == 'true' and target:
        release.require_draft_target(target, args.tag)
    empty_directory(args.directory)
    (args.directory / 'assets').mkdir()
    (args.directory / 'audit').mkdir()
    state = {'schema': 2, 'origin': 'source-build', 'repository': args.repository,
             'tag': args.tag, 'source_sha': source, 'release_id': target['id'] if target else None}
    (args.directory / 'state.json').write_text(json.dumps(state, indent=2) + '\n')
    outputs({'version': version, 'source_sha': source})
    print('Resolved immutable release source:', source)


def archive(args, version):
    state = json.loads((args.directory / 'state.json').read_text())
    if state['repository'] != args.repository or state['tag'] != args.tag or state['source_sha'] != args.source_sha:
        raise ValueError('Source state changed before archive creation')
    def git(*values):
        return subprocess.check_output(['git', '-C', str(args.source_directory), *values]).decode().strip()
    if git('rev-parse', 'HEAD') != args.source_sha or git('rev-parse', f'refs/tags/{args.tag}^{{commit}}') != args.source_sha:
        raise ValueError('Checkout or source tag does not match the resolved commit')
    validate_changelog((args.source_directory / 'debian/changelog').read_text(), args.tag)
    for name in ('tools/build-deb.sh', 'packaging/fpms/build.sh', 'image/build.sh'):
        if not (args.source_directory / name).is_file():
            raise ValueError('Selected tag lacks supported release build tools: ' + name)
    with args.source_tar.open('xb') as stream:
        subprocess.run(['git', '-C', str(args.source_directory), 'archive', '--format=tar', args.source_sha],
                       stdout=stream, check=True)
    state['source_archive_sha256'] = release.sha256(args.source_tar)
    (args.directory / 'state.json').write_text(json.dumps(state, indent=2) + '\n')
    print('Archived immutable source with export-subst:', state['source_archive_sha256'])


def checked_files(directory, expected):
    if directory.is_symlink() or not directory.is_dir() or {p.name for p in directory.iterdir()} != set(expected):
        raise ValueError('Unexpected package artifact files')
    for name, digest in expected.items():
        path = directory / name
        if path.is_symlink() or not path.is_file() or not path.stat().st_size or release.sha256(path) != digest:
            raise ValueError('Package input checksum does not match: ' + name)


def checked_audit(directory):
    if directory.is_symlink() or not directory.is_dir():
        raise ValueError('Invalid audit directory')
    for path in directory.iterdir():
        if path.is_symlink() or not path.is_file():
            raise ValueError('Audit artifacts must be regular files')


def record(args, version):
    state_path = args.directory / 'state.json'
    state = json.loads(state_path.read_text())
    if state['repository'] != args.repository or state['tag'] != args.tag or state['source_sha'] != args.source_sha:
        raise ValueError('Source state changed during package build')
    if release.tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Source tag changed during package build')
    if state.get('source_archive_sha256') != release.sha256(args.source_tar):
        raise ValueError('Production source archive changed')
    assets = args.directory / 'assets'
    if {p.name for p in assets.iterdir()} != package_names(version):
        raise ValueError('Unexpected production package output')
    checks = {p.name: release.sha256(p) for p in assets.iterdir() if p.is_file() and not p.is_symlink()}
    checked_files(assets, checks)
    if set(checks) != package_names(version):
        raise ValueError('Incomplete production packages')
    checked_audit(args.directory / 'audit')
    state.update(input_sha256=checks, package_sha256=checks[f'cmprovision4_{version}_all.deb'],
                 fpms_sha256=checks[f'fpms-lite_{version}_all.deb'])
    state_path.write_text(json.dumps(state, indent=2) + '\n')
    outputs({key: state[key] for key in ('source_sha', 'package_sha256', 'fpms_sha256')})
    print('Production package hashes recorded:', json.dumps(checks, sort_keys=True))


def adopt(args, version):
    state_path = args.incoming / 'state.json'
    if state_path.is_symlink() or not state_path.is_file():
        raise ValueError('Invalid package build state')
    state = json.loads(state_path.read_text())
    expected = {'repository': args.repository, 'tag': args.tag, 'source_sha': args.source_sha,
                'package_sha256': args.package_sha256, 'fpms_sha256': args.fpms_sha256}
    release.validate_request(args.tag, args.package_sha256, args.fpms_sha256)
    if state.get('schema') != 2 or state.get('origin') != 'source-build' or any(state.get(k) != v for k, v in expected.items()):
        raise ValueError('Package artifact does not match the producing job')
    if not re.fullmatch(r'[0-9a-f]{64}', state.get('source_archive_sha256', '')):
        raise ValueError('Missing source archive provenance')
    checks = state.get('input_sha256', {})
    if set(checks) != package_names(version) or any(not re.fullmatch(r'[0-9a-f]{64}', value) for value in checks.values()):
        raise ValueError('Unsafe or incomplete package input manifest')
    if checks[f'cmprovision4_{version}_all.deb'] != args.package_sha256 or checks[f'fpms-lite_{version}_all.deb'] != args.fpms_sha256:
        raise ValueError('Producer package hashes disagree')
    if release.tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Source tag moved after the package build')
    checked_files(args.incoming / 'assets', checks)
    checked_audit(args.incoming / 'audit')
    empty_directory(args.directory)
    shutil.copytree(args.incoming / 'assets', args.directory / 'assets')
    shutil.copytree(args.incoming / 'audit', args.directory / 'audit')
    shutil.copyfile(state_path, args.directory / 'state.json')
    outputs({'version': version, 'source_sha': args.source_sha, 'release_id': state.get('release_id')})
    print('Adopted verified production packages from immutable source:', args.source_sha)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('resolve', 'archive', 'record', 'adopt'))
    parser.add_argument('--repository', required=True)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--source-sha')
    parser.add_argument('--expected-source-sha', default='')
    parser.add_argument('--source-directory', type=Path)
    parser.add_argument('--source-tar', type=Path)
    parser.add_argument('--incoming', type=Path)
    parser.add_argument('--package-sha256')
    parser.add_argument('--fpms-sha256')
    parser.add_argument('--attach-to-draft', choices=('true', 'false'), default='false')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', args.repository):
        raise ValueError('Invalid repository name')
    version = release.validate_tag(args.tag)
    if args.action != 'resolve' and not re.fullmatch(r'[0-9a-f]{40}', args.source_sha or ''):
        raise ValueError('Supply the immutable source SHA')
    {'resolve': resolve, 'archive': archive, 'record': record, 'adopt': adopt}[args.action](args, version)


if __name__ == '__main__':
    main()
