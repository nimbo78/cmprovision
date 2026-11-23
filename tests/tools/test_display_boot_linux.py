import json
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / 'tools'))
if os.name == 'posix':
    import display_boot_linux as panel
    from update_store import validate_request


@unittest.skipUnless(os.name == 'posix', 'Linux device and updater interfaces')
class StationDisplayTest(unittest.TestCase):
    def test_requires_bound_goodix_product_and_hyperpixel_overlay(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            overlay = root / 'spi/display@0/compatible'
            overlay.parent.mkdir(parents=True)
            overlay.write_bytes(b'pimoroni,hyperpixel4\0')
            touch = root / '22-0014'
            device = touch / 'input/input0'
            (device / 'id').mkdir(parents=True)
            (touch / 'of_node').mkdir()
            (touch / 'of_node/compatible').write_bytes(b'goodix,gt911\0')
            event = root / 'event0'
            event.mkdir()
            (event / 'device').symlink_to(device, target_is_directory=True)
            (device / 'id/product').write_text('038f\n')
            self.assertFalse(panel.hyperpixel_bound(root, root))
            driver = root / 'Goodix-TS'
            driver.mkdir()
            (touch / 'driver').symlink_to(driver, target_is_directory=True)
            self.assertTrue(panel.hyperpixel_bound(root, root))
            (device / 'id/product').write_text('0000\n')
            self.assertFalse(panel.hyperpixel_bound(root, root))

    def test_auto_uses_the_same_validated_install_operation_as_settings(self):
        io = object.__new__(panel.StationIO)
        io.system = Mock()
        io.install()
        call = io.system.command.call_args
        self.assertEqual(call.args[0], [panel.HERE / 'station-updates'])
        request = json.loads(call.kwargs['input'])
        self.assertEqual('install-fpms', request['action'])
        validate_request(request)

    def test_maintenance_or_incomplete_admission_never_allows_a_reboot(self):
        io = object.__new__(panel.StationIO)
        io.system = Mock()
        with patch.object(panel, 'UpdateStore') as store:
            store.return_value.snapshot.return_value = {'pending': None, 'operation': None}
            for report in ({}, {'active': [], 'reserved_jobs': False},
                           {'active': ['test'], 'reserved_jobs': 0}, {'active': [], 'reserved_jobs': 1}):
                io.system.admission.return_value = report
                self.assertFalse(io.idle())
            io.system.admission.return_value = {'active': [], 'reserved_jobs': 0}
            self.assertTrue(io.idle())
            store.return_value.snapshot.return_value['operation'] = {'state': 'applying'}
            self.assertFalse(io.idle())
