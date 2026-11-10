#!/usr/bin/env python3
"""Publish a complete signed Utility OS bundle, never replacing existing version assets."""
import argparse
import json
from pathlib import Path
import subprocess

import release
from update_release import descriptor, verify


def publish(directory, repository, source, version, public):
    tag = 'utility-os-'+version
    value = verify(directory, repository, tag, source, public)
    checks = {path.name: descriptor(path) for path in directory.iterdir()}
    target = release.release_for_tag(repository, tag, missing_ok=True)
    if target and not target['draft']: raise ValueError('This immutable Utility OS version is already published; bump UOS_VERSION.')
    refs = release.api(f'repos/{repository}/git/matching-refs/tags/{tag}')
    if not isinstance(refs, list): raise ValueError('Could not verify existing Utility OS tags.')
    exact = [item for item in refs if item.get('ref') == 'refs/tags/'+tag]
    if len(exact) > 1: raise ValueError('Ambiguous Utility OS tag.')
    if exact:
        if release.tag_commit(repository, tag) != source: raise ValueError('Utility OS tag selects a different source.')
    else:
        try:
            created = release.api_write(f'repos/{repository}/git/refs', {'ref': 'refs/tags/'+tag, 'sha': source})
            if created.get('ref') != 'refs/tags/'+tag or created.get('object', {}).get('sha') != source:
                raise ValueError('Created Utility OS tag does not match the checked source.')
        except subprocess.CalledProcessError:
            if release.tag_commit(repository, tag) != source:
                raise ValueError('Concurrent Utility OS tag creation selected a different source; no tag was moved.')
    if not target:
        body = (f'# 🇬🇧 English\n\nTemporary provisioning OS {version}. Includes signed boot files and matching complete legal-info sources. '
                f'Source: `{source}`. Update it independently in Settings → Updates.\n\n---\n\n'
                f'# 🇷🇺 Русский\n\nВременная ОС для прошивки {version}. Включает подписанные загрузочные файлы и полный комплект соответствующих исходников. '
                f'Исходный коммит: `{source}`. Обновляется независимо в Settings → Updates.\n')
        target = release.api_write(f'repos/{repository}/releases', {'tag_name': tag, 'target_commitish': source,
            'draft': True, 'prerelease': False, 'make_latest': 'false',
            'name': f'📦 Utility OS {version} / Утилитарная ОС {version}', 'body': body})
    identity = target['id']
    if release.tag_commit(repository, tag) != source: raise ValueError('Utility OS tag selects a different source.')
    for name, item in checks.items():
        target = release.release_for_tag(repository, tag, release_id=identity)
        if target['id'] != identity or not target['draft']: raise ValueError('Utility OS publication destination changed.')
        existing = release.asset_map(target)
        for previous, asset in existing.items():
            if previous not in checks or asset.get('digest') != 'sha256:'+checks[previous]['sha256'] or asset.get('size') != checks[previous]['size']:
                raise ValueError('Existing Utility OS assets differ; no files were replaced.')
        if name not in existing:
            subprocess.run(['gh', 'release', 'upload', tag, str(directory/name), '--repo', repository], check=True)
    target = release.release_for_tag(repository, tag, release_id=identity)
    release.verify_uploaded(target, {name: item['sha256'] for name, item in checks.items()})
    if target['id'] != identity or not target['draft'] or release.tag_commit(repository, tag) != source:
        raise ValueError('Utility OS release changed before publication.')
    subprocess.run(['gh', 'api', '--method', 'PATCH', f'repos/{repository}/releases/{identity}', '--input', '-'],
        input=json.dumps({'draft': False, 'make_latest': 'false'}).encode(), check=True, stdout=subprocess.DEVNULL)
    target = release.release_for_tag(repository, tag, release_id=identity)
    release.verify_uploaded(target, {name: item['sha256'] for name, item in checks.items()})
    if target['draft']: raise ValueError('Utility OS publication was not confirmed.')
    print('Published verified Utility OS:', tag)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    for name in ('directory', 'public-key'): parser.add_argument('--'+name, type=Path, required=True)
    for name in ('repository', 'source', 'version'): parser.add_argument('--'+name, required=True)
    args = parser.parse_args()
    publish(args.directory, args.repository, args.source, args.version, args.public_key)
