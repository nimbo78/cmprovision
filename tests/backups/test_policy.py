import importlib
import json
from pathlib import Path
import tempfile
import unittest

from support import ROOT
from update_manifest import UpdateError


class PolicyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.module = importlib.import_module('backup_policy')
        self.put('var/lib/cmprovision/.env', b'APP_KEY=fixture')
        self.put('var/lib/cmprovision/etc/dnsmasq.conf', b'fixture')
        self.put('etc/passwd', b'root:x:0:0:root:/root:/bin/bash\nadmin:x:1000:1000::/home/admin:/bin/bash\nservice:x:999:999::/var/lib/service:/usr/sbin/nologin\n')
        self.put('etc/shadow', b'root:!:1:0:99999:7:::\nadmin:$6$private-fixture-hash:1:0:99999:7:::\nservice:!:1:0:99999:7:::\n')
        self.put('etc/group', b'root:x:0:\nadmin:x:1000:\nsudo:x:27:admin\n')

    def put(self, name, data):
        path = self.root/name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return path

    def test_payload_options_and_os_configuration_are_separate_from_runtime(self):
        self.put('var/lib/cmprovision/storage/app/firmware.bin', b'firmware')
        self.put('var/lib/cmprovision/storage/app/upload-tmp/temporary', b'omit')
        self.put('var/lib/cmprovision/public/uploads/image.img', b'image')
        self.put('var/lib/cmprovision/public/uploads/packages/check.deb', b'package')
        self.put('etc/NetworkManager/system-connections/wifi.nmconnection', b'[wifi-security]\npsk=private-fixture\n')
        self.put('home/admin/.ssh/authorized_keys', b'ssh-ed25519 fixture-key')
        entries, absent = self.module.inventory(self.root, images=False, packages=False)
        names = {item.name for item in entries}
        self.assertIn('application/storage/app/firmware.bin', names)
        self.assertIn('system/etc/NetworkManager/system-connections/wifi.nmconnection', names)
        self.assertNotIn('application/public/uploads/image.img', names)
        self.assertNotIn('application/public/uploads/packages/check.deb', names)
        self.assertFalse(any('upload-tmp' in name for name in names))
        accounts = json.loads(next(item.data for item in entries if item.name == 'system/accounts.json'))
        self.assertEqual(['root', 'admin'], [item['name'] for item in accounts])
        self.assertEqual('$6$private-fixture-hash', accounts[1]['password_hash'])
        self.assertIn('sudo', accounts[1]['groups'])
        self.assertEqual('ssh-ed25519 fixture-key', accounts[1]['authorized_keys'])
        entries, _ = self.module.inventory(self.root, images=True, packages=True)
        self.assertIn('application/public/uploads/image.img', {item.name for item in entries})
        self.assertIn('application/public/uploads/packages/check.deb', {item.name for item in entries})

    def test_required_missing_data_and_symlinked_application_members_fail(self):
        path = self.put('var/lib/cmprovision/storage/app/firmware.bin', b'fixture')
        path.unlink()
        path.symlink_to(self.root/'etc/shadow')
        with self.assertRaises(UpdateError): self.module.inventory(self.root)
        path.unlink()
        (self.root/'var/lib/cmprovision/.env').unlink()
        with self.assertRaises(UpdateError): self.module.inventory(self.root)

    def test_excluded_temporary_trees_are_not_traversed(self):
        directory = self.root/'var/lib/cmprovision/storage/app/upload-tmp'
        directory.mkdir(parents=True)
        (directory/'discarded-link').symlink_to(self.root/'etc/shadow')
        entries, _ = self.module.inventory(self.root)
        self.assertFalse(any('upload-tmp' in entry.name for entry in entries))

    def test_external_ssh_key_or_authorization_sources_cannot_be_silently_omitted(self):
        for text in (b'HostKey /opt/keys/host\n', b'AuthorizedKeysFile /opt/keys/%u\n', b'AuthorizedKeysCommand /opt/auth\n'):
            self.put('etc/ssh/sshd_config', text)
            with self.assertRaises(UpdateError): self.module.inventory(self.root)


if __name__ == '__main__': unittest.main()
