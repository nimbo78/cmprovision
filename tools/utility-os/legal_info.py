"""Complete Buildroot legal-info with verified source-derived hashes and Bootlin's corresponding sources.

    legal_info.py prepare|collect <repository> <work directory> <cache directory>

The SDK's verified README and summary.csv identify its original Buildroot commit and components.
Nothing downloaded here is executed. Source/patch/license bytes must match that vendor tree's hashes.
"""
import csv
import hashlib
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tarfile
import time
from http.client import HTTPException
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import urlopen

BOOTLIN = 'https://toolchains.bootlin.com/downloads/releases'


def name(value):
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._+-]*', value):
        raise ValueError('Unsafe source name: '+value)
    return value


def versions(repo):
    return dict(re.findall(r'^(UOS_[A-Z0-9_]+)=([^\r\n]+)$', (repo/'utility-os/versions.env').read_text(), re.M))


def hashes(tree, for_archive=None, version=None):
    result = {}
    for file in tree.rglob('*.hash'):
        entries = {}
        for algorithm, digest, filename in re.findall(r'^(sha256|sha512)\s+([0-9a-f]+)\s+(\S+)\s*$', file.read_text(), re.M):
            if len(digest) != hashlib.new(algorithm).digest_size * 2:
                raise ValueError('Malformed hash in '+str(file))
            entries.setdefault(filename, set()).add((algorithm, digest))
        if for_archive is None or for_archive in entries:
            for filename, checks in entries.items():
                result.setdefault(filename, set()).update(checks)
            # Buildroot can keep license hashes under package/<name>/<version>/<name>.hash.
            specific = file.parent/version/file.name if version is not None else None
            if for_archive is not None and specific is not None and specific.is_file():
                for filename, checks in hashes(specific.parent).items():
                    result.setdefault(filename, set()).update(checks)
    return result


def verified(data, expected, label):
    if not expected or not any(hashlib.new(algorithm, data).hexdigest() == digest for algorithm, digest in expected):
        raise ValueError('Source hash mismatch or missing: '+label)
    return data


def member(data, suffix):
    with tarfile.open(fileobj=io.BytesIO(data)) as archive:
        matches = [m for m in archive.getmembers() if m.isfile() and m.name.endswith('/'+suffix)]
        if matches:
            depth = min(m.name.count('/') for m in matches)
            matches = [m for m in matches if m.name.count('/') == depth]
        if len(matches) != 1:
            raise ValueError('Expected one archive member: '+suffix)
        return archive.extractfile(matches[0]).read()


def prepare(repo, work, cache):
    v = versions(repo)
    records = []
    for package, filename, key, source, license_name in [
        ('linux', 'linux-'+v['UOS_LINUX_COMMIT']+'.tar.gz', 'UOS_LINUX_SHA256',
         'LICENSES/preferred/GPL-2.0', 'LICENSES/preferred/GPL-2.0.cmprovision'),
        ('cmp-rpi-utils', 'cmp-rpi-utils-'+v['UOS_RPI_UTILS_COMMIT']+'.tar.gz', 'UOS_RPI_UTILS_SHA256',
         'LICENCE', 'LICENCE'),
    ]:
        data = verified((cache/'dl'/package/filename).read_bytes(), {('sha256', v[key])}, filename)
        line = 'sha256  '+hashlib.sha256(member(data, source)).hexdigest()+'  '+license_name
        records.append((work/'hashes'/package/(package+'.hash'), line))
    for file, line in records:
        text = file.read_text()
        if line not in text.splitlines():
            file.write_text(text.rstrip()+'\n'+line+'\n')
    print('legal-info: license hashes derived from the pinned source archives', flush=True)


def fetch(url):
    for attempt in range(3):
        try:
            with urlopen(url, timeout=30) as response:
                return response.read()
        except HTTPError as error:
            if error.code not in [408, 429, 500, 502, 503, 504] or attempt == 2:
                raise
        except (OSError, HTTPException):
            if attempt == 2:
                raise
        time.sleep(attempt + 1)


def checkout(tag, expected, cache):
    destination = cache/('bootlin-buildroot-'+name(tag))
    if not destination.exists():
        temporary = destination.with_name(destination.name+'.new')
        if temporary.exists():
            raise ValueError('Incomplete vendor checkout: '+str(temporary))
        subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', 'clone', '--quiet', '--depth', '1', '--branch', tag,
                        'https://github.com/bootlin/buildroot-toolchains.git', str(temporary)], check=True,
                       env={**os.environ, 'GIT_TERMINAL_PROMPT': '0'})
        temporary.rename(destination)
    commit = subprocess.check_output(['git', '-C', str(destination), 'rev-parse', 'HEAD'], text=True).strip()
    if not re.fullmatch('[0-9a-f]{40}', commit) or not commit.startswith(expected):
        raise ValueError('Vendor Buildroot does not match the verified SDK summary')
    if subprocess.check_output(['git', '-C', str(destination), 'status', '--porcelain', '--untracked-files=all'], text=True).strip():
        raise ValueError('The cached vendor Buildroot has uncommitted changes')
    return destination, commit


def collect(repo, work, cache, transport=fetch, vendor=checkout):
    v = versions(repo)
    br = work/('buildroot-'+v['UOS_BUILDROOT_VERSION'])
    out = work/'output/legal-info'
    complete = out/'source-complete.json'
    complete.unlink(missing_ok=True)
    with (out/'manifest.csv').open() as manifest:
        rows = list(csv.DictReader(manifest))
    sdk = next(row for row in rows if row['PACKAGE'] == 'toolchain-external-bootlin')
    archive_name = name(sdk['SOURCE ARCHIVE'])
    sdk_data = (out/'sources'/(name(sdk['PACKAGE'])+'-'+name(sdk['VERSION']))/archive_name).read_bytes()
    verified(sdk_data, hashes(br).get(archive_name), archive_name)
    readme = member(sdk_data, 'README.txt').decode()
    summary = member(sdk_data, 'summary.csv').decode()
    configuration = member(sdk_data, 'buildroot.config')
    tag = re.search(r'git checkout (toolchains\.bootlin\.com-[0-9.]+)', readme)
    components = list(csv.DictReader(io.StringIO(summary)))
    original = next(row for row in components if row['PACKAGE'] == 'buildroot')
    source_id = re.search(r'-g([0-9a-f]{10,40})$', original['VERSION'])
    if not tag or not source_id:
        raise ValueError('The verified SDK does not identify its source Buildroot')
    tree, commit = vendor(tag[1], source_id[1], cache)
    if not re.fullmatch('[0-9a-f]{40}', commit) or not commit.startswith(source_id[1]):
        raise ValueError('Vendor Buildroot does not match the verified SDK summary')
    expected = hashes(tree)
    supplementary = out/'bootlin'
    supplementary.mkdir(exist_ok=True)
    (supplementary/'README.txt').write_text(readme)
    (supplementary/'summary.csv').write_text(summary)
    (supplementary/'buildroot.config').write_bytes(configuration)
    inventory = []

    def save(target, data, origin):
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(data)
        inventory.append({'file': target.relative_to(out).as_posix(), 'sha256': hashlib.sha256(data).hexdigest(), 'source': origin})

    downloads = cache/'legal-sources'
    downloads.mkdir(exist_ok=True)

    def download(url, filename, checks=None):
        saved = downloads/hashlib.sha256(url.encode()).hexdigest()
        data = saved.read_bytes() if saved.exists() else transport(url)
        verified(data, checks or expected.get(filename), filename)
        if not saved.exists():
            saved.write_bytes(data)
        return data

    seen = set()
    scopes = {}
    for row in components:
        filename = row['SOURCE ARCHIVE']
        if filename == 'not saved':
            continue
        filename = name(filename)
        if filename not in scopes:
            scopes[filename] = hashes(tree, for_archive=filename, version=name(row['VERSION']))
        package = name(row['PACKAGE'])+'-'+name(row['VERSION'])
        url = BOOTLIN+'/sources/'+package+'/'
        source_data = download(url+quote(filename), filename)
        if (package, filename) not in seen:
            print('legal-info: Bootlin sources '+package, flush=True)
            save(supplementary/'sources'/package/filename, source_data, url+filename)
            seen.add((package, filename))
        for license_file in row['LICENSE FILES'].split():
            pieces = license_file.split('/')
            for piece in pieces:
                name(piece)
            license_url = BOOTLIN+'/licenses/'+package+'/'+quote(license_file, safe='/')
            license_hashes = scopes[filename].get(license_file) or scopes[filename].get(pieces[-1])
            if not license_hashes:
                license_hashes = {('sha256', hashlib.sha256(member(source_data, license_file)).hexdigest())}
            data = download(license_url, license_file, license_hashes)
            save(supplementary/'licenses'/package/license_file, data, license_url)

    # Public source directories are shared by SDKs and their numbered patches can change.
    # The exact SDK commit contains the corresponding patches, series rules and build recipes.
    vendor_source = subprocess.check_output(['git', '-C', str(tree), 'archive', '--format=tar.gz', 'HEAD'])
    save(supplementary/'buildroot-toolchains.tar.gz', vendor_source, 'https://github.com/bootlin/buildroot-toolchains/commit/'+commit)
    br_archive = cache/'dl'/('buildroot-'+v['UOS_BUILDROOT_VERSION']+'.tar.gz')
    save(out/'sources'/br_archive.name, verified(br_archive.read_bytes(), {('sha256', v['UOS_BUILDROOT_SHA256'])}, br_archive.name),
         'https://buildroot.org/downloads/'+br_archive.name)
    recipe = out/'cmprovision-build-recipe.tar.gz'
    def recipe_file(info):
        path = (repo/info.name).resolve()
        if '__pycache__' in Path(info.name).parts or info.name.endswith('.pyc'):
            return None
        if path.is_relative_to(work.resolve()) or path.is_relative_to(cache.resolve()):
            return None
        return info
    with tarfile.open(recipe, 'w:gz') as archive:
        for path in ['utility-os', 'tools/utility-os', 'resources/shell', 'docs/utility-os.md', 'LICENSE']:
            archive.add(repo/path, arcname=path, filter=recipe_file)
    record = {'bootlin_buildroot_commit': commit, 'sdk_archive': archive_name, 'files': inventory,
              'note': 'Supplements the original Buildroot README warnings with Buildroot and Bootlin sources, patches, licenses and build recipes.'}
    temporary = complete.with_suffix('.new')
    temporary.write_text(json.dumps(record, indent=2)+'\n')
    temporary.replace(complete)
    print('legal-info: complete source supplement ('+str(len(inventory))+' files)', flush=True)


if __name__ == '__main__':
    action, repository, directory, downloads = sys.argv[1:]
    if action not in ['prepare', 'collect']:
        raise SystemExit('usage: legal_info.py prepare|collect <repo> <work> <cache>')
    globals()[action](Path(repository), Path(directory), Path(downloads))
