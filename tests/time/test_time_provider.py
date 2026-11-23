import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
from stand_time import Files, TimeError
import time_provider as provider


class ProviderMaintenanceTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.c = Mock()
        self.c.files = Files(Path(temp.name), os.getuid())
        self.c.stand.transitioning.return_value = False
        self.c.status.return_value = dict(provider=provider.OLD, managed_servers=[])
        self.c.read_config.return_value = None

    def test_missing_exact_recovery_package_leaves_client_untouched(self):
        with patch.object(provider, 'version', return_value='3'), patch.object(provider, 'run', return_value=''):
            with self.assertRaisesRegex(TimeError, 'both be available'):
                provider.cache_packages(self.c)
        self.assertFalse((self.c.files.root/provider.CACHE/'packages').exists())
        self.assertIsNone(self.c.files.read(provider.STATE))
        self.c.command.assert_not_called()

    def test_block_unrelated_removal_and_upgrades(self):
        provider.check_plan('Remv systemd-timesyncd [3]\nInst chrony (4 Debian:13)')
        for plan in ('Remv nginx [1]', 'Inst systemd [3] (4 Debian:13)'):
            with self.assertRaises(TimeError):
                provider.check_plan(plan)

    def test_corrupt_cached_deb_fails_before_package_manager(self):
        base = provider.CACHE + '/packages/'
        self.c.files.write(base+'old.deb', b'old')
        self.c.files.write(base+'new.deb', b'changed')
        import hashlib
        manifest = {provider.OLD: ['old.deb', '3', hashlib.sha256(b'old').hexdigest()],
                    'chrony': ['new.deb', '4', hashlib.sha256(b'new').hexdigest()]}
        self.c.files.write(base+'manifest.json', json.dumps(manifest).encode())
        report = json.dumps(dict(admission=dict(active=[], reserved_jobs=0)))
        with patch.object(provider, 'run', return_value=report) as commands:
            with self.assertRaisesRegex(TimeError, 'verification'):
                provider.change(self.c, 'use-chrony', Mock())
        self.assertEqual([('runuser', '-u', 'www-data')], [call.args[:3] for call in commands.call_args_list])
        self.assertIsNone(self.c.files.read(provider.STATE))

    def test_busy_station_does_not_start_a_package_change(self):
        with patch.object(provider, 'run', return_value='{"admission":{"active":[1],"reserved_jobs":0}}'):
            with self.assertRaisesRegex(TimeError, 'finish provisioning'):
                provider.change(self.c, 'return-timesyncd', Mock())
