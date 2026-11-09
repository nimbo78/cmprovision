import base64
import hashlib
import io
import os
from pathlib import Path
import tarfile
import tempfile
import unittest

from support import manifest, signed, CONTEXT
from update_manifest import UpdateError
from update_store import UpdateStore
from update_utility import ManagedUtility, verify_tree


FILES = ['kernel8.img', 'scriptexecute.img', 'config.txt', 'cmdline.txt', 'start4.elf', 'fixup4.dat',
         'bcm2711-rpi-cm4.dtb', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb',
         'overlays/dwc2.dtbo', 'overlays/spi-gpio40-45.dtbo', 'usb/bootfiles.bin', 'usb/boot.img', 'usb/config.txt']


class UtilityTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.store = UpdateStore(self.root/'updates', self.root/'run', owner=os.getuid())
        self.files = {name: ('payload '+name).encode() for name in FILES}
        lines = ['version=2026.11.1', 'bits=64', 'kernel=6.18.50-cmprovision']
        lines += [f'file={hashlib.sha256(data).hexdigest()} {len(data)} {name}' for name, data in self.files.items()]
        self.files['MANIFEST'] = ('\n'.join(lines)+'\n').encode()
        self.value = manifest('utility-os')

    def archive(self, extra=None):
        archive = self.root/'payload.tar.gz'
        with tarfile.open(archive, 'w:gz') as output:
            for name, data in self.files.items():
                member = tarfile.TarInfo('boot/'+name)
                member.size = len(data)
                output.addfile(member, io.BytesIO(data))
            if extra: output.addfile(extra, io.BytesIO(b'x'*extra.size))
        asset = self.value['assets'][0]
        asset.update(size=archive.stat().st_size, sha256=hashlib.sha256(archive.read_bytes()).hexdigest())
        raw, signature, key = signed(self.root/('signing-'+str(len(list(self.root.glob('signing-*'))))), self.value)
        envelope = {'id': hashlib.sha256(raw).hexdigest(), 'raw': base64.b64encode(raw).decode(),
            'signature': base64.b64encode(signature).decode(), 'manifest': self.value}
        return archive, envelope, key

    def test_checked_archive_is_installed_without_changing_active_link(self):
        archive, envelope, key = self.archive()
        utility = ManagedUtility(self.store, self.root/'managed', key)
        selector = utility.prepare(envelope, archive)
        self.assertEqual('managed:2026.11.1', selector)
        self.assertEqual('2026.11.1', utility.validate(selector)['version'])
        with self.assertRaises(UpdateError): utility.validate(selector, dict(CONTEXT, cmprovision='2.0.0'))
        self.assertFalse((self.root/'managed/current').exists())
        self.assertEqual(0o755, (self.root/'managed/releases/2026.11.1/boot').stat().st_mode & 0o777)

    def test_paths_links_duplicates_special_files_and_oversized_members_are_rejected(self):
        for name, kind, size in [('../escape', tarfile.REGTYPE, 1), ('/tmp/escape', tarfile.REGTYPE, 1),
                ('boot/../escape', tarfile.REGTYPE, 1), ('boot/kernel8.img', tarfile.REGTYPE, 1),
                ('boot/link', tarfile.SYMTYPE, 0), ('boot/device', tarfile.CHRTYPE, 0)]:
            with self.subTest(name=name, kind=kind):
                extra = tarfile.TarInfo(name)
                extra.type, extra.size = kind, size
                archive, envelope, key = self.archive(extra)
                with self.assertRaises(UpdateError): ManagedUtility(self.store, self.root/'managed', key).prepare(envelope, archive)
                self.assertFalse((self.root/'managed/releases/2026.11.1').exists())

    def test_missing_or_corrupt_inventory_usb_files_and_version_are_rejected(self):
        for name in ('MANIFEST', 'usb/boot.img', 'kernel8.img'):
            with self.subTest(name=name):
                old = self.files.pop(name)
                archive, envelope, key = self.archive()
                with self.assertRaises(UpdateError): ManagedUtility(self.store, self.root/'managed', key).prepare(envelope, archive)
                self.files[name] = old


if __name__ == '__main__': unittest.main()
