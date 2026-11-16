import importlib.util
from pathlib import Path
import subprocess
import tempfile
from types import SimpleNamespace
import unittest

SOURCE = Path(__file__).resolve().parents[2] / 'tools/station-storage.py'

class StorageTest(unittest.TestCase):
    def setUp(self):
        spec = importlib.util.spec_from_file_location('storage', SOURCE)
        self.storage = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.storage)
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def test_fixed_collector_reports_sizes_and_shared_snapshot_estimate(self):
        app = self.root/'app'; app.mkdir()
        uploads = app/'public'/'uploads'; uploads.mkdir(parents=True)
        commands=[]
        def run(argv, **kwargs):
            commands.append(argv)
            return SimpleNamespace(returncode=0, stdout='4096\tfixture\n')
        result=self.storage.collect({'application':app,'uploads':uploads}, app=app, runner=run)
        self.assertEqual(result['schema'],1)
        self.assertEqual(result['categories']['uploads']['bytes'],4096)
        self.assertEqual(result['snapshot']['required'],4096*2+128*1024**2)
        self.assertTrue(all(command[0]=='/usr/bin/du' for command in commands))
        self.assertIn('--exclude='+str(uploads), commands[0])

    def test_symlink_and_symlink_parent_are_unknown_without_running_du(self):
        target=self.root/'real'; target.mkdir()
        (target/'child').mkdir()
        link=self.root/'link'; link.symlink_to(target, target_is_directory=True)
        def run(*args,**kwargs):self.fail('Unsafe paths must not be measured')
        for path in (link,link/'child'):
            result=self.storage.measure(path, runner=run)
            self.assertIsNone(result['bytes'])
            self.assertEqual(result['state'],'unknown')

    def test_missing_directory_is_zero_but_timeout_is_unknown(self):
        self.assertEqual(self.storage.measure(self.root/'absent')['bytes'],0)
        def run(*args,**kwargs):raise subprocess.TimeoutExpired('du',1)
        value=self.storage.measure(self.root,runner=run)
        self.assertIsNone(value['bytes'])

    def test_collector_has_no_delete_operations_or_user_path_arguments(self):
        value=subprocess.run(['python3',str(SOURCE),'../../etc'],capture_output=True,text=True)
        self.assertNotEqual(value.returncode,0)
        self.assertNotIn('categories',value.stdout)

if __name__=='__main__':unittest.main()
