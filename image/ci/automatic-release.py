#!/usr/bin/env python3
"""Prepare a checked main release candidate and publish only its complete audited bundle."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess

spec = importlib.util.spec_from_file_location('automatic_release_transport', Path(__file__).with_name('release.py'))
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
STABLE = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'


def outputs(values):
    if os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a', encoding='utf-8') as stream:
            for key, value in values.items():
                if '\n' in str(value) or '\r' in str(value):
                    raise ValueError('Invalid job output')
                stream.write(f'{key}={value}\n')


def identity(args):
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', args.repository) or not re.fullmatch(r'[0-9a-f]{40}', args.source_sha):
        raise ValueError('Invalid release repository or source')
    if checkout_sha(args.source_directory) != args.source_sha:
        raise ValueError('Checkout does not match the checked source')


def checkout_sha(directory):
    return subprocess.check_output(['git', '-C', str(directory), 'rev-parse', 'HEAD'], text=True).strip()


def source_file(directory, sha, path):
    # Git blobs, not mutable working-tree files (or symlinks outside the checkout).
    try:
        return subprocess.check_output(['git', '-C', str(directory), 'show', f'{sha}:{path}'], stderr=subprocess.PIPE).decode('utf-8')
    except (subprocess.CalledProcessError, UnicodeError) as error:
        raise ValueError('Missing or invalid versioned source file: ' + path) from error


def notes(directory, sha, version, repository=None, run_id=None):
    blocks = []
    for language, prefix in (('🇬🇧 English', 'docs/releases'), ('🇷🇺 Русский', 'docs/ru/releases')):
        text = source_file(directory, sha, f'{prefix}/{version}.md').strip()
        first, _, content = text.partition('\n')
        if len(text) > 131072 or not re.match(r'^# ' + re.escape(version) + r'(?:\s|$)', first) or len(content.strip()) < 20:
            raise ValueError('Complete version-matched English and Russian release notes are required')
        text = re.sub(r'(?m)^(#{1,5}) ', r'#\1 ', text)
        if repository:
            label = 'Source' if prefix == 'docs/releases' else 'Исходный коммит'
            text += f'\n\n{label}: [{sha}](https://github.com/{repository}/commit/{sha}).'
            if run_id and run_id.isdigit():
                label = 'Build' if prefix == 'docs/releases' else 'Сборка'
                text += f'\n\n{label}: [GitHub Actions](https://github.com/{repository}/actions/runs/{run_id}).'
        blocks.append(f'# {language}\n\n{text}\n')
    return '\n---\n\n'.join(blocks)


def prepare(args):
    configured = os.environ.get('CMPROVISION_RELEASE_REPOSITORY') or 'nimbo78/cmprovision'
    if args.repository.lower() != configured.lower():
        raise ValueError('This repository has not opted in to automatic releases')
    context = {'GITHUB_EVENT_NAME': 'push', 'GITHUB_REF': 'refs/heads/main',
               'GITHUB_REPOSITORY': args.repository, 'GITHUB_SHA': args.source_sha}
    if any(os.environ.get(key) != value for key, value in context.items()):
        raise ValueError('Automatic releases require a checked push to this repository main branch')
    identity(args)
    outputs({'release_tag': '', 'source_sha': ''})
    changelog = source_file(args.source_directory, args.source_sha, 'debian/changelog')
    match = re.match(r'cmprovision4 \(([^)]+)\)', changelog)
    if not match:
        raise ValueError('Cannot read the package version')
    version = match[1]
    if not re.fullmatch(STABLE, version):
        print('No automatic release: package version is not stable:', version)
        return None
    tag = 'v' + version
    target = release.release_for_tag(args.repository, tag, missing_ok=True)
    if target and target.get('draft') is False:
        print('No automatic release: this version is already published:', tag)
        return None
    refs = release.api(f'repos/{args.repository}/git/matching-refs/tags/{tag}')
    if not isinstance(refs, list):
        raise ValueError('Cannot verify existing release tags')
    exact = [ref for ref in refs if ref.get('ref') == 'refs/tags/' + tag]
    if len(exact) > 1:
        raise ValueError('Ambiguous release tag')
    if exact and release.tag_commit(args.repository, tag) != args.source_sha:
        print('No automatic release: this version is already pinned to another commit:', tag)
        return None
    notes(args.source_directory, args.source_sha, version)  # Fail before creating any public ref.
    if not exact:
        try:
            created = release.api_write(f'repos/{args.repository}/git/refs', {'ref': 'refs/tags/' + tag, 'sha': args.source_sha})
            if created.get('ref') != 'refs/tags/' + tag or created.get('object', {}).get('sha') != args.source_sha:
                raise ValueError('Created tag does not match the checked source')
        except subprocess.CalledProcessError:
            # A concurrent creator or lost response may have pinned exactly this commit.
            if release.tag_commit(args.repository, tag) != args.source_sha:
                raise ValueError('Concurrent tag creation selected a different source; no tag was moved')
    outputs({'release_tag': tag, 'source_sha': args.source_sha})
    print('Automatic release candidate:', tag, args.source_sha)
    return tag


def published_versions(repository):
    pages = json.loads(release.gh('api', '--paginate', '--slurp', f'repos/{repository}/releases?per_page=100'))
    if not isinstance(pages, list) or any(not isinstance(page, list) for page in pages):
        raise ValueError('Cannot verify published versions')
    versions = []
    for page in pages:
        for item in page:
            tag = item.get('tag_name', '')
            if item.get('draft') is False and item.get('prerelease') is False and re.fullmatch('v' + STABLE, tag):
                versions.append(tuple(map(int, tag[1:].split('.'))))
    return versions


def update_release(path, payload):
    result = subprocess.run(['gh', 'api', '--method', 'PATCH', path, '--input', '-'],
                            input=json.dumps(payload).encode(), stdout=subprocess.PIPE, check=True)
    return json.loads(result.stdout)


def verified_target(args, report, checks):
    target = release.release_for_tag(args.repository, args.tag)
    if not target or target.get('id') != report.get('release_id') or target.get('tag_name') != args.tag \
            or type(target.get('draft')) is not bool or target.get('prerelease') is not False:
        raise ValueError('Release destination changed after verified attachment')
    release.verify_uploaded(target, checks)
    for name, asset in release.asset_map(target).items():
        if asset.get('size') != (args.directory / 'assets' / name).stat().st_size:
            raise ValueError('Uploaded asset size changed: ' + name)
    return target


def release_name(tag):
    return f'🚀 {tag}: Packages & station image / Пакеты и образ станции'


def verify_metadata(target, args, body):
    def canonical(value):
        if not isinstance(value, str):
            raise ValueError('Published release notes are missing')
        # A retry may be performed by another run. Only the optional terminal
        # same-repository build links may differ; version/source/change text stays exact.
        pattern = (r'\n\n(?:Build|Сборка): \[GitHub Actions\]\(https://github\.com/'
                   + re.escape(args.repository) + r'/actions/runs/[0-9]+\)\.(?=\n*(?:---\n|$))')
        return re.sub(pattern, '', value.replace('\r\n', '\n')).strip()
    if target.get('name') != release_name(args.tag) or canonical(target.get('body')) != canonical(body):
        raise ValueError('Published release metadata does not match the versioned source notes; no publication was edited')


def publish(args):
    identity(args)
    version = release.validate_request(args.tag, args.package_sha256, args.fpms_sha256)
    expected = {key: getattr(args, key) for key in ('repository', 'tag', 'source_sha', 'package_sha256', 'fpms_sha256')}
    state = json.loads((args.directory / 'state.json').read_text())
    if not isinstance(state, dict) or any(state.get(key) != value for key, value in expected.items()):
        raise ValueError('Artifact state does not match the publication request')
    report = json.loads((args.directory / 'release-report.json').read_text())
    checks = release.verify_bundle(args.directory / 'assets', version)
    if not isinstance(report, dict) or any(report.get(key) != expected[key] for key in ('repository', 'tag', 'source_sha')) \
            or report.get('checks') != checks or type(report.get('release_id')) is not int:
        raise ValueError('Verified attachment report does not match this release')
    if checks[f'cmprovision4_{version}_all.deb'] != args.package_sha256 \
            or checks[f'fpms-lite_{version}_all.deb'] != args.fpms_sha256:
        raise ValueError('Independent package hashes changed')
    if release.tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Source tag changed before publication')
    run_id = os.environ.get('GITHUB_RUN_ID') if os.environ.get('GITHUB_REPOSITORY') == args.repository else None
    body = notes(args.source_directory, args.source_sha, version, args.repository, run_id)
    target = verified_target(args, report, checks)
    if target['draft']:
        versions = published_versions(args.repository)
        current = tuple(map(int, version.split('.')))
        payload = {'draft': False, 'prerelease': False,
            'name': release_name(args.tag),
            'body': body, 'make_latest': 'true' if not versions or current >= max(versions) else 'false'}
        # Recheck the destination and source immediately before the only publication write.
        target = verified_target(args, report, checks)
        if release.tag_commit(args.repository, args.tag) != args.source_sha:
            raise ValueError('Source tag changed during publication checks')
        if target['draft']:
            update_release(f'repos/{args.repository}/releases/{target["id"]}', payload)
    target = verified_target(args, report, checks)
    if target['draft'] or release.tag_commit(args.repository, args.tag) != args.source_sha:
        raise ValueError('Publication could not be confirmed')
    verify_metadata(target, args, body)
    report.update(url=target['html_url'], draft=False)
    (args.directory / 'release-report.json').write_text(json.dumps(report, indent=2) + '\n')
    outputs({'release_url': target['html_url']})
    print('Complete release published and verified:', target['html_url'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('operation', choices=('prepare', 'publish', 'notes'))
    parser.add_argument('--repository', required=True)
    parser.add_argument('--source-sha', required=True)
    parser.add_argument('--source-directory', type=Path, required=True)
    parser.add_argument('--tag')
    parser.add_argument('--package-sha256')
    parser.add_argument('--fpms-sha256')
    parser.add_argument('--directory', type=Path)
    args = parser.parse_args()
    if args.operation == 'prepare':
        prepare(args)
    elif args.operation == 'notes':
        if not args.tag:
            parser.error('Notes validation requires a release tag')
        identity(args)
        notes(args.source_directory, args.source_sha, release.validate_tag(args.tag))
        print('Versioned English and Russian release notes validated.')
    else:
        if not all((args.tag, args.package_sha256, args.fpms_sha256, args.directory)):
            parser.error('Publication requires tag, both package hashes and audited directory')
        publish(args)


if __name__ == '__main__':
    main()
