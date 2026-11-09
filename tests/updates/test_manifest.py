import copy
from pathlib import Path
import shutil
import tempfile
import unittest

from support import CONTEXT, REPOSITORY, encode, keypair, manifest, signed
from update_manifest import UpdateError, compatibility_error, newer, parse_manifest, verify_manifest


class ManifestTest(unittest.TestCase):
    def test_both_exact_release_schemas_are_accepted(self):
        for component in ['application', 'utility-os']:
            with self.subTest(component=component):
                value = manifest(component)
                self.assertEqual(value, parse_manifest(encode(value), REPOSITORY, value['tag']))

    def test_version_order_is_debian_package_order_not_git_describe(self):
        self.assertFalse(newer('2.26.0', '2.27.0~dev1'))
        self.assertTrue(newer('2.27.0', '2.27.0~dev1'))
        self.assertFalse(newer('2.27.0', '2.27.0'))
        self.assertTrue(newer('2026.11.1', '2026.10.2'))
        for value in ['2.27;echo hi', '-1', '', '1 /tmp/file']:
            with self.subTest(value=value), self.assertRaises(UpdateError):
                newer(value, '2.26.0')

    def test_valid_signature_and_exact_release_binding(self):
        if not shutil.which('minisign'): self.skipTest('minisign required')
        with tempfile.TemporaryDirectory() as folder:
            value = manifest()
            raw, signature, key = signed(Path(folder)/'a', value)
            self.assertEqual(value, verify_manifest(raw, signature, key, REPOSITORY, value['tag']))
            other, _ = keypair(Path(folder)/'b')
            bad = [(raw+b' ', signature, key, REPOSITORY, value['tag']),
                   (raw, signature, other, REPOSITORY, value['tag']),
                   (raw, signature, key, 'attacker/project', value['tag']),
                   (raw, signature, key, REPOSITORY, 'v2.29.0'),
                   (raw, b'invalid signature', key, REPOSITORY, value['tag'])]
            for arguments in bad:
                with self.subTest(arguments=arguments[-2:]), self.assertRaises(UpdateError):
                    verify_manifest(*arguments)

    def test_duplicate_unknown_or_mismatched_metadata_is_rejected(self):
        changes = [('schema', True), ('schema', 2), ('component', 'firmware'), ('version', '2.28.0~dev1'),
                   ('tag', 'v2.29.0'), ('repository', 'attacker/project'), ('source_sha', 'f'*39),
                   ('published_at', 'tomorrow'), ('notes', 'bad\x00note'), ('unknown', 1), ('assets', [])]
        for field, value in changes:
            candidate = manifest()
            candidate[field] = value
            with self.subTest(field=field), self.assertRaises(UpdateError):
                parse_manifest(encode(candidate), REPOSITORY)
        raw = encode(manifest()).replace(b'"schema":1', b'"schema":1,"schema":1')
        with self.assertRaises(UpdateError): parse_manifest(raw, REPOSITORY)
        with self.assertRaises(UpdateError): parse_manifest(b' '*100000, REPOSITORY)

    def test_hostile_json_numbers_and_unicode_are_controlled_rejections(self):
        raw = encode(manifest()).replace(b'"schema":1', b'"schema":'+b'9'*5000)
        with self.assertRaises(UpdateError): parse_manifest(raw, REPOSITORY)
        raw = encode(manifest()).replace(b'A checked release.', b'\\ud800')
        with self.assertRaises(UpdateError): parse_manifest(raw, REPOSITORY)

    def test_asset_paths_sizes_hashes_and_sets_are_strict(self):
        changes = [('name', '../../bad.deb'), ('name', 'https://evil.invalid/file.deb'),
                   ('name', 'cmprovision4_2.29.0_all.deb'), ('size', True), ('size', 0),
                   ('size', 1024*1024*1024), ('sha256', 'F'*64), ('sha256', 'a'*63), ('url', 'http://evil.invalid')]
        for field, value in changes:
            candidate = manifest()
            candidate['assets'][0][field] = value
            with self.subTest(field=field, value=value), self.assertRaises(UpdateError):
                parse_manifest(encode(candidate), REPOSITORY)
        candidate = manifest()
        candidate['assets'].append(copy.deepcopy(candidate['assets'][0]))
        with self.assertRaises(UpdateError): parse_manifest(encode(candidate), REPOSITORY)
        candidate = manifest('utility-os')
        candidate.pop('sources')
        with self.assertRaises(UpdateError): parse_manifest(encode(candidate), REPOSITORY)

    def test_compatibility_cannot_be_weakened_by_invalid_values(self):
        changes = [('debian', []), ('architectures', ['unknown']), ('php', {'8.4': '8.3.36'}),
                   ('min_cmprovision', 'x;sh'), ('updater_protocol', True), ('surprise', 1)]
        for key, value in changes:
            candidate = manifest()
            candidate['compatibility'][key] = value
            with self.subTest(key=key), self.assertRaises(UpdateError):
                parse_manifest(encode(candidate), REPOSITORY)

    def test_compatibility_has_actionable_errors(self):
        value = manifest()
        self.assertIsNone(compatibility_error(value, CONTEXT))
        for field, actual in [('debian', '12'), ('architecture', 'i386'), ('php', '8.4.26'),
                              ('php', '8.2.30'), ('cmprovision', '2.26.0'), ('updater_protocol', 0)]:
            context = dict(CONTEXT, **{field: actual})
            with self.subTest(field=field, actual=actual):
                self.assertTrue(compatibility_error(value, context))


if __name__ == '__main__': unittest.main()
