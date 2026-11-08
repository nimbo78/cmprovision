#!/usr/bin/env python3
"""Verify image inputs; attach to a matching draft without overwriting existing assets."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess


def validate_tag(tag):
    if not re.fullmatch(r'v[0-9]+\.[0-9]+\.[0-9]+', tag):
        raise ValueError('Choose a stable release tag: vX.Y.Z')
    return tag[1:]


def validate_request(tag, package_sha, fpms_sha):
    version = validate_tag(tag)
    if not all(re.fullmatch(r'[0-9a-f]{64}', value) for value in (package_sha, fpms_sha)):
        raise ValueError('Supply independent lowercase SHA-256 hashes for both packages')
    return version


def asset_names(version):
    return {f'cmprovision4_{version}_all.deb', f'fpms-lite_{version}_all.deb',
            f'cmprovision-lite-{version}-arm64.img.xz', 'cmprovision-build-manifest.txt',
            f'cmprovision-lite-{version}-packages.txt', 'cmprovision-image-source.sha256',
            'fpms-upstream.txt', 'fpms-patch.sha256', 'image-uncompressed.sha256'}


def asset_map(release):
    assets = {asset['name']: asset for asset in release['assets']}
    if len(assets) != len(release['assets']):
        raise ValueError('Duplicate release asset names')
    return assets


def require_packages(release, tag, package_sha, fpms_sha):
    version = validate_request(tag, package_sha, fpms_sha)
    if release['tag_name'] != tag:
        raise ValueError('Release does not match the selected tag')
    assets = asset_map(release)
    for name, digest in ((f'cmprovision4_{version}_all.deb', package_sha),
                         (f'fpms-lite_{version}_all.deb', fpms_sha)):
        asset = assets.get(name, {})
        if asset.get('state') != 'uploaded' or asset.get('digest') != 'sha256:' + digest:
            raise ValueError('Release package does not match the independent SHA-256: ' + name)
    return assets


def require_draft(release, tag, package_sha, fpms_sha):
    require_draft_target(release, tag)
    return require_packages(release, tag, package_sha, fpms_sha)


def require_draft_target(release, tag):
    if not release or release.get('tag_name') != tag or release.get('draft') is not True:
        raise ValueError('Only a still-draft release may be updated')
    return asset_map(release)


def parse_sums(text, version):
    checks = {}
    for line in text.splitlines():
        parts = line.split()
        if len(parts) != 2 or not re.fullmatch(r'[0-9a-f]{64}', parts[0]):
            raise ValueError('Invalid checksum row')
        digest, name = parts
        name = name.removeprefix('./')
        if name not in asset_names(version) or name in checks:
            raise ValueError('Unexpected, unsafe or duplicate checksum filename: ' + name)
        checks[name] = digest
    if set(checks) != asset_names(version):
        raise ValueError('Incomplete image release checksum manifest')
    return checks


def sha256(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def verify_bundle(directory, version):
    if {p.name for p in directory.iterdir()} != asset_names(version) | {'SHA256SUMS'}:
        raise ValueError('The artifact directory must contain exactly the release bundle')
    checks = parse_sums((directory / 'SHA256SUMS').read_text(), version)
    for name, expected in checks.items():
        path = directory / name
        if path.is_symlink() or not path.is_file() or sha256(path) != expected:
            raise ValueError('Artifact checksum does not match: ' + name)
    checks['SHA256SUMS'] = sha256(directory / 'SHA256SUMS')
    return checks


def verify_uploaded(release, checks):
    assets = asset_map(release)
    if set(assets) != set(checks):
        raise ValueError('Uploaded asset set does not match the verified bundle')
    for name, digest in checks.items():
        if assets[name].get('state') != 'uploaded' or assets[name].get('digest') != 'sha256:' + digest:
            raise ValueError('Uploaded asset digest does not match: ' + name)


def gh(*args):
    return subprocess.check_output(['gh', *args])


def api(path):
    return json.loads(gh('api', path))


def api_write(path, payload):
    result = subprocess.run(['gh', 'api', '--method', 'POST', path, '--input', '-'],
                            input=json.dumps(payload).encode(), stdout=subprocess.PIPE, check=True)
    return json.loads(result.stdout)


def release_for_tag(repository, tag, missing_ok=False):
    # GitHub's tag endpoint does not return every authenticated draft.
    pages = json.loads(gh('api', '--paginate', '--slurp', f'repos/{repository}/releases?per_page=100'))
    matches = [item for page in pages for item in page if item['tag_name'] == tag]
    if not matches and missing_ok:
        return None
    if len(matches) != 1:
        raise ValueError('Expected exactly one existing release for the selected tag')
    return matches[0]


def tag_commit(repository, tag):
    obj = api(f'repos/{repository}/git/ref/tags/{tag}')['object']
    for _ in range(5):
        if obj['type'] == 'commit' and re.fullmatch(r'[0-9a-f]{40}', obj['sha']):
            return obj['sha']
        if obj['type'] != 'tag' or not re.fullmatch(r'[0-9a-f]{40}', obj['sha']):
            break
        obj = api(f'repos/{repository}/git/tags/{obj["sha"]}')['object']
    raise ValueError('Release tag must resolve to a commit')


def create_draft(repository, tag, source, run_url=None):
    validate_tag(tag)
    if not re.fullmatch(r'[0-9a-f]{40}', source):
        raise ValueError('Invalid source commit')
    if run_url and not re.fullmatch(r'https://github\.com/' + re.escape(repository) + r'/actions/runs/[0-9]+', run_url):
        raise ValueError('Invalid build provenance URL')
    english_run = f'\nBuild: [GitHub Actions]({run_url}).\n' if run_url else ''
    russian_run = f'\nСборка: [GitHub Actions]({run_url}).\n' if run_url else ''
    body = (f'# 🇬🇧 English\n\n## 📦 Verified build artifacts\n\nSource: `{source}`.\n'
            + english_run + '\nPackages and the experimental station image were built from the selected tag. '
            'Review the changes, hardware acceptance and checksums before publishing. '
            'This workflow leaves the release as a draft.\n\n---\n\n'
            f'# 🇷🇺 Русский\n\n## 📦 Проверенные артефакты сборки\n\nИсходный коммит: `{source}`.\n'
            + russian_run + '\nПакеты и экспериментальный образ станции собраны из выбранного тега. '
            'Перед публикацией проверьте описание изменений, аппаратную приёмку и контрольные суммы. '
            'Workflow оставляет релиз черновиком.\n')
    result = api_write(f'repos/{repository}/releases', {
        'tag_name': tag, 'target_commitish': source, 'draft': True, 'prerelease': False,
        'name': f'📦 {tag} Release / Релиз {tag} 🚀', 'body': body})
    require_draft_target(result, tag)
    return result


def prepare(args, version):
    release = release_for_tag(args.repository, args.tag)
    guard = require_draft if args.attach_to_draft == 'true' else require_packages
    assets = guard(release, args.tag, args.package_sha256, args.fpms_sha256)
    source = tag_commit(args.repository, args.tag)
    directory = args.directory
    if directory.exists() and any(directory.iterdir()):
        raise ValueError('Choose an empty image release directory')
    bundle = directory / 'assets'
    bundle.mkdir(parents=True)
    names = [f'cmprovision4_{version}_all.deb', f'fpms-lite_{version}_all.deb',
             'fpms-upstream.txt', 'fpms-patch.sha256']
    for name in names:
        asset = assets.get(name, {})
        digest = asset.get('digest', '')
        if asset.get('state') != 'uploaded' or not re.fullmatch(r'sha256:[0-9a-f]{64}', digest):
            raise ValueError('Input asset is incomplete or lacks a server digest: ' + name)
        with (bundle / name).open('xb') as output:
            subprocess.run(['gh', 'api', f'repos/{args.repository}/releases/assets/{asset["id"]}',
                            '-H', 'Accept: application/octet-stream'], stdout=output, check=True)
        if sha256(bundle / name) != digest.removeprefix('sha256:'):
            raise ValueError('Downloaded asset bytes do not match: ' + name)
    state = {'repository': args.repository, 'tag': args.tag, 'source_sha': source,
             'release_id': release['id'], 'package_sha256': args.package_sha256,
             'fpms_sha256': args.fpms_sha256}
    (directory / 'state.json').write_text(json.dumps(state, indent=2) + '\n')
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as output:
            output.write(f'version={version}\nsource_sha={source}\nrelease_id={release["id"]}\n')
    print('Verified release packages and resolved source:', source)


def upload(args, version):
    if not args.source_sha or not re.fullmatch(r'[0-9a-f]{40}', args.source_sha):
        raise ValueError('Supply the immutable source SHA produced by the build job')
    state = json.loads((args.directory / 'state.json').read_text())
    expected = {'repository': args.repository, 'tag': args.tag, 'source_sha': args.source_sha,
                'package_sha256': args.package_sha256, 'fpms_sha256': args.fpms_sha256}
    if any(state.get(key) != value for key, value in expected.items()):
        raise ValueError('Artifact state does not match the dispatched build')
    bundle = args.directory / 'assets'
    checks = verify_bundle(bundle, version)
    if checks[f'cmprovision4_{version}_all.deb'] != args.package_sha256 or \
            checks[f'fpms-lite_{version}_all.deb'] != args.fpms_sha256:
        raise ValueError('Built bundle changed an independently verified package')
    if tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Source tag changed after the build')
    target = release_for_tag(args.repository, args.tag, missing_ok=True)
    if target is None:
        if not getattr(args, 'create_draft', False) or state.get('release_id') is not None:
            raise ValueError('Release is missing; explicit draft creation permission is required')
        run_id = os.environ.get('GITHUB_RUN_ID', '')
        run_url = f'https://github.com/{args.repository}/actions/runs/{run_id}' if run_id.isdigit() else None
        target = create_draft(args.repository, args.tag, args.source_sha, run_url)
    require_draft_target(target, args.tag)
    target_id = target['id']
    if state.get('release_id') is not None and state['release_id'] != target_id:
        raise ValueError('Release changed after the build')
    for name, digest in sorted(checks.items()):
        release = release_for_tag(args.repository, args.tag)
        assets = require_draft_target(release, args.tag)
        if release['id'] != target_id or tag_commit(args.repository, args.tag) != args.source_sha:
            raise ValueError('Release or source tag changed after the image build')
        # Never delete or overwrite an asset: publication can race with client checks.
        # Check every existing name before the first write, including later-sorted files.
        for existing_name, existing in assets.items():
            if existing_name not in checks:
                raise ValueError('Unexpected existing release asset: ' + existing_name)
            if existing.get('state') != 'uploaded' or existing.get('digest') != 'sha256:' + checks[existing_name]:
                raise ValueError('Existing release asset differs; no files were replaced: ' + existing_name)
        if name in assets:
            continue
        subprocess.run(['gh', 'release', 'upload', args.tag, str(bundle / name),
                        '--repo', args.repository], check=True)
    release = release_for_tag(args.repository, args.tag)
    require_draft_target(release, args.tag)
    verify_uploaded(release, checks)
    if tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Source tag changed during attachment')
    for name, asset in asset_map(release).items():
        if asset['size'] != (bundle / name).stat().st_size:
            raise ValueError('Uploaded asset size differs: ' + name)
    report = {'url': release['html_url'], 'source_sha': args.source_sha, 'draft': True, 'checks': checks}
    (args.directory / 'release-report.json').write_text(json.dumps(report, indent=2) + '\n')
    print('All release files verified on GitHub. Release remains a draft:', release['html_url'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=('prepare', 'upload'))
    parser.add_argument('--repository', required=True)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--package-sha256', required=True)
    parser.add_argument('--fpms-sha256', required=True)
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--source-sha')
    parser.add_argument('--attach-to-draft', choices=('true', 'false'), default='false')
    parser.add_argument('--create-draft', action='store_true')
    args = parser.parse_args()
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', args.repository):
        raise ValueError('Invalid repository name')
    version = validate_request(args.tag, args.package_sha256, args.fpms_sha256)
    {'prepare': prepare, 'upload': upload}[args.action](args, version)


if __name__ == '__main__':
    main()
