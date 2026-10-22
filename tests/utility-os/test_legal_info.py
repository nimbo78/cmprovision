import csv
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('cmp_legal_info', Path(__file__).resolve().parents[2]/'tools/utility-os/legal_info.py')
legal = importlib.util.module_from_spec(spec)
spec.loader.exec_module(legal)


def packed(files):
    stream = io.BytesIO()
    with tarfile.open(fileobj=stream, mode='w:gz') as archive:
        for path, data in files.items():
            info = tarfile.TarInfo(path)
            info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
    return stream.getvalue()


class LegalInfoTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='uos-legal-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.repo, self.work, self.cache = (self.base/name for name in ['repo', 'work', 'cache'])
        for path in ['repo/utility-os', 'repo/tools/utility-os', 'repo/resources/shell', 'repo/docs', 'work/hashes/linux',
                     'work/hashes/cmp-rpi-utils', 'cache/dl/linux', 'cache/dl/cmp-rpi-utils']:
            (self.base/path).mkdir(parents=True)
        (self.repo/'docs/utility-os.md').write_text('build guide')
        (self.repo/'LICENSE').write_text('BSD license')
        (self.repo/'resources/shell/module-diagnostics.sh').write_text('shared collector source')
        self.values = {'UOS_LINUX_COMMIT': 'a'*40, 'UOS_RPI_UTILS_COMMIT': 'b'*40, 'UOS_BUILDROOT_VERSION': '2025.02.18'}
        for package, filename, key, files in [
            ('linux', 'linux-'+'a'*40+'.tar.gz', 'UOS_LINUX_SHA256', {'linux/LICENSES/preferred/GPL-2.0': b'GPL-2.0'}),
            ('cmp-rpi-utils', 'cmp-rpi-utils-'+'b'*40+'.tar.gz', 'UOS_RPI_UTILS_SHA256', {'utils/LICENCE': b'BSD-3-Clause'}),
        ]:
            data = packed(files)
            (self.cache/'dl'/package/filename).write_bytes(data)
            self.values[key] = hashlib.sha256(data).hexdigest()
            (self.work/'hashes'/package/(package+'.hash')).write_text('source hash\n')
        self.br_data = b'original Buildroot sources'
        (self.cache/'dl/buildroot-2025.02.18.tar.gz').write_bytes(self.br_data)
        self.values['UOS_BUILDROOT_SHA256'] = hashlib.sha256(self.br_data).hexdigest()
        self.write_versions()

    def write_versions(self):
        (self.repo/'utility-os/versions.env').write_text(''.join(key+'='+value+'\n' for key, value in self.values.items()))

    def sdk(self, filename='gcc-13.3.0.tar.xz'):
        tree = self.base/'vendor'
        tree.mkdir()
        self.source = b'corresponding compiler source'
        self.license = b'GPL-3.0'
        (tree/'gcc.hash').write_text('sha256  '+hashlib.sha256(self.source).hexdigest()+'  gcc-13.3.0.tar.xz\n'
                                   +'sha256  '+hashlib.sha256(self.license).hexdigest()+'  COPYING\n')
        (tree/'0003-tests-fix-LDADD.patch').write_bytes(b'the patch used by this SDK')
        subprocess.run(['git', 'init', '-q', str(tree)], check=True)
        subprocess.run(['git', '-C', str(tree), 'add', '.'], check=True)
        subprocess.run(['git', '-C', str(tree), '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'fixture'], check=True)
        self.commit = subprocess.check_output(['git', '-C', str(tree), 'rev-parse', 'HEAD'], text=True).strip()
        header = ['PACKAGE', 'VERSION', 'LICENSE FILES', 'SOURCE ARCHIVE']
        summary = io.StringIO()
        writer = csv.writer(summary)
        writer.writerow(header)
        writer.writerow(['gcc-final', '13.3.0', 'COPYING', filename])
        writer.writerow(['buildroot', '2021.11-100-g'+self.commit[:10], 'COPYING', 'not saved'])
        data = packed({'sdk/README.txt': b'git checkout toolchains.bootlin.com-2024.05.1\n',
                       'sdk/summary.csv': summary.getvalue().encode(), 'sdk/buildroot.config': b'config'})
        out = self.work/'output/legal-info'
        (out/'sources/toolchain-external-bootlin-2024.05-1').mkdir(parents=True)
        (out/'sources/toolchain-external-bootlin-2024.05-1/sdk.tar.xz').write_bytes(data)
        with (out/'manifest.csv').open('w', newline='') as output:
            writer = csv.writer(output)
            writer.writerow(header)
            writer.writerow(['toolchain-external-bootlin', '2024.05-1', '', 'sdk.tar.xz'])
        br = self.work/'buildroot-2025.02.18'
        br.mkdir()
        (br/'sdk.hash').write_text('sha256  '+hashlib.sha256(data).hexdigest()+'  sdk.tar.xz\n')
        self.tree = tree
        self.out = out

    def transport(self, url):
        urls = {legal.BOOTLIN+'/sources/gcc-final-13.3.0/': b'<a href="gcc-13.3.0.tar.xz">source</a>',
                legal.BOOTLIN+'/sources/gcc-final-13.3.0/gcc-13.3.0.tar.xz': self.source,
                legal.BOOTLIN+'/licenses/gcc-final-13.3.0/COPYING': self.license}
        return urls[url]

    def collect(self, transport=None):
        legal.collect(self.repo, self.work, self.cache, transport or self.transport,
                      lambda tag, expected, cache: (self.tree, self.commit))

    def test_license_hashes_are_derived_from_pinned_archives(self):
        legal.prepare(self.repo, self.work, self.cache)
        text = (self.work/'hashes/linux/linux.hash').read_text()
        self.assertIn(hashlib.sha256(b'GPL-2.0').hexdigest(), text)
        self.assertIn('LICENSES/preferred/GPL-2.0.cmprovision', text)

    def test_a_corrupted_source_cannot_change_license_hashes(self):
        (self.cache/'dl/cmp-rpi-utils'/('cmp-rpi-utils-'+'b'*40+'.tar.gz')).write_bytes(b'corrupt')
        with self.assertRaises(ValueError):
            legal.prepare(self.repo, self.work, self.cache)
        self.assertEqual('source hash\n', (self.work/'hashes/linux/linux.hash').read_text())

    def test_archive_metadata_uses_the_root_readme(self):
        data = packed({'sdk/README.txt': b'correct', 'sdk/lib/README.txt': b'unrelated library'})
        self.assertEqual(b'correct', legal.member(data, 'README.txt'))

    def test_corresponding_sources_and_recipes_are_in_the_supplement(self):
        self.sdk()
        self.collect()
        record = json.loads((self.out/'source-complete.json').read_text())
        self.assertEqual(self.commit, record['bootlin_buildroot_commit'])
        self.assertEqual(self.source, (self.out/'bootlin/sources/gcc-final-13.3.0/gcc-13.3.0.tar.xz').read_bytes())
        self.assertTrue((self.out/'cmprovision-build-recipe.tar.gz').is_file())
        with tarfile.open(self.out/'cmprovision-build-recipe.tar.gz') as recipe:
            self.assertEqual(b'shared collector source', recipe.extractfile('resources/shell/module-diagnostics.sh').read())
        self.assertEqual(self.br_data, (self.out/'sources/buildroot-2025.02.18.tar.gz').read_bytes())

    def test_wrong_source_bytes_cannot_claim_completeness(self):
        self.sdk()
        def broken(url):
            return b'wrong source' if url.endswith('.tar.xz') else self.transport(url)
        with self.assertRaises(ValueError):
            self.collect(broken)
        self.assertFalse((self.out/'source-complete.json').exists())

    def test_a_license_from_an_unrelated_package_is_rejected(self):
        self.sdk()
        wrong = b'unrelated license'
        (self.tree/'other.hash').write_text('sha256  '+hashlib.sha256(wrong).hexdigest()+'  COPYING\n')
        def broken(url):
            return wrong if '/licenses/' in url else self.transport(url)
        with self.assertRaises(ValueError):
            self.collect(broken)
        self.assertFalse((self.out/'source-complete.json').exists())

    def test_version_specific_license_hashes_are_used(self):
        self.sdk()
        common = self.tree/'gcc.hash'
        common.write_text(common.read_text().splitlines()[0]+'\n')
        for version, data in [('13.3.0', self.license), ('12.4.0', b'other version')]:
            folder = self.tree/version
            folder.mkdir()
            (folder/'gcc.hash').write_text('sha256  '+hashlib.sha256(data).hexdigest()+'  COPYING\n')
        self.collect()
        self.assertTrue((self.out/'source-complete.json').exists())

    def test_missing_license_hashes_are_derived_from_verified_source(self):
        self.sdk()
        self.source = packed({'gcc/COPYING': self.license})
        (self.tree/'gcc.hash').write_text('sha256  '+hashlib.sha256(self.source).hexdigest()+'  gcc-13.3.0.tar.xz\n')
        self.collect()
        self.assertTrue((self.out/'source-complete.json').exists())
        # A different downloaded license must not pass this fallback either.
        for saved in (self.cache/'legal-sources').iterdir():
            if saved.read_bytes() == self.license:
                saved.unlink()
        with self.assertRaises(ValueError):
            self.collect(lambda url: b'wrong license' if '/licenses/' in url else self.transport(url))

    def test_sdk_metadata_cannot_write_outside_the_source_directory(self):
        self.sdk('../../outside.tar.xz')
        with self.assertRaises(ValueError):
            self.collect()
        self.assertFalse((self.base/'outside.tar.xz').exists())

    def test_a_repeated_collection_reuses_verified_license_downloads(self):
        self.sdk()
        self.collect()
        def cached_only(url):
            self.assertNotIn('/licenses/', url)
            return self.transport(url)
        self.collect(cached_only)

    def test_transient_network_errors_are_retried(self):
        with patch.object(legal, 'urlopen', side_effect=[TimeoutError('stalled read'), io.BytesIO(b'complete')]):
            self.assertEqual(b'complete', legal.fetch('https://example.invalid/source'))

    def test_patches_come_from_the_sdk_commit_when_the_public_directory_changes(self):
        self.sdk()
        def changed(url):
            if url.endswith('/sources/gcc-final-13.3.0/'):
                return b'<a href="0003-tests-fix-LDADD.patch">patch</a>'
            if url.endswith('.patch'):
                return b'a different patch from a later SDK'
            return self.transport(url)
        self.collect(changed)
        with tarfile.open(self.out/'bootlin/buildroot-toolchains.tar.gz') as archive:
            self.assertEqual(b'the patch used by this SDK', archive.extractfile('0003-tests-fix-LDADD.patch').read())

    def test_a_modified_cached_vendor_tree_is_rejected(self):
        self.sdk()
        destination = self.cache/'bootlin-buildroot-toolchains.bootlin.com-2024.05.1'
        self.tree.rename(destination)
        (destination/'extra.hash').write_text('uncommitted hash')
        with self.assertRaises(ValueError):
            legal.checkout('toolchains.bootlin.com-2024.05.1', self.commit[:10], self.cache)


if __name__ == '__main__':
    unittest.main()
