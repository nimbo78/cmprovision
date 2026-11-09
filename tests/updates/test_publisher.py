import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

from support import keypair, REPOSITORY

path = Path(__file__).resolve().parents[2]/'image/ci/update_release.py'
spec = importlib.util.spec_from_file_location('update_publisher', path)
publisher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(publisher)


class PublisherTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bundle = self.root/'assets'
        self.bundle.mkdir()
        self.public, self.private = keypair(self.root/'keys')
        for name in ('cmprovision4', 'fpms-lite'):
            package = self.root/name
            (package/'DEBIAN').mkdir(parents=True)
            (package/'DEBIAN/control').write_text(f'Package: {name}\nVersion: 2.28.0\nArchitecture: all\nMaintainer: Test <test@example.test>\nDescription: isolated fixture\n')
            subprocess.run(['dpkg-deb', '--build', str(package), str(self.bundle/f'{name}_2.28.0_all.deb')],
                           check=True, stdout=subprocess.DEVNULL)

    def test_application_manifest_is_signed_reproducibly_and_binds_package_versions(self):
        arguments = (self.bundle, '2.28.0', REPOSITORY, 'f'*40, '2026-11-09T00:00:00Z', 'Release notes', self.private, self.public)
        publisher.application(*arguments)
        first = {name: (self.bundle/name).read_bytes() for name in ('cmprovision-update.json', 'cmprovision-update.json.minisig')}
        publisher.application(*arguments)
        self.assertEqual(first, {name: (self.bundle/name).read_bytes() for name in first})
        publisher.verify(self.bundle, REPOSITORY, 'v2.28.0', 'f'*40, self.public)
        (self.bundle/'cmprovision4_2.28.0_all.deb').write_bytes(b'changed')
        with self.assertRaises(ValueError): publisher.verify(self.bundle, REPOSITORY, 'v2.28.0', 'f'*40, self.public)

    def test_missing_or_wrong_key_cannot_produce_a_publishable_manifest(self):
        other, private = keypair(self.root/'wrong')
        with self.assertRaises(ValueError):
            publisher.application(self.bundle, '2.28.0', REPOSITORY, 'f'*40, '2026-11-09T00:00:00Z', '', private, self.public)
        with self.assertRaises(ValueError): publisher.verify(self.bundle, REPOSITORY, 'v2.28.0', 'f'*40, self.public)


if __name__ == '__main__': unittest.main()
