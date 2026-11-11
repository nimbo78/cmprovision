import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

from support import ROOT


class CryptoTest(unittest.TestCase):
    def run_crypto(self, mode, data, password='shared master password'):
        if not shutil.which('php'): self.skipTest('PHP sodium required')
        # An inherited anonymous descriptor, never password bytes in argv or environment.
        with tempfile.TemporaryFile() as secret:
            secret.write(json.dumps({'password': password}).encode())
            secret.seek(0)
            return subprocess.run(['php', str(ROOT/'tools/backup-crypto.php'), mode, str(secret.fileno())],
                input=data, pass_fds=(secret.fileno(),), capture_output=True, timeout=30)

    def test_standalone_stream_roundtrip_and_independent_random_ciphertexts(self):
        plain = b'multi-frame data'*180000
        sealed = self.run_crypto('encrypt', plain)
        self.assertEqual(0, sealed.returncode, sealed.stderr)
        self.assertNotIn(b'multi-frame data', sealed.stdout)
        another = self.run_crypto('encrypt', plain)
        self.assertNotEqual(sealed.stdout, another.stdout)
        opened = self.run_crypto('decrypt', sealed.stdout)
        self.assertEqual(0, opened.returncode, opened.stderr)
        self.assertEqual(plain, opened.stdout)

    def test_wrong_password_tampering_truncation_and_trailing_data_are_rejected(self):
        sealed = self.run_crypto('encrypt', b'private station configuration')
        self.assertEqual(0, sealed.returncode, sealed.stderr)
        for data, password in ((sealed.stdout, 'wrong'), (sealed.stdout[:-1], 'shared master password'),
                (sealed.stdout+b'extra', 'shared master password'),
                (sealed.stdout[:40]+bytes([sealed.stdout[40]^1])+sealed.stdout[41:], 'shared master password')):
            with self.subTest(size=len(data), password=password):
                result = self.run_crypto('decrypt', data, password)
                self.assertNotEqual(0, result.returncode)
                self.assertNotIn(password.encode(), result.stderr)


if __name__ == '__main__': unittest.main()
