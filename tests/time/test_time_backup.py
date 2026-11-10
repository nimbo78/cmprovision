import json
from pathlib import Path
import subprocess
import tempfile
import unittest


class TimeBackupTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.config = self.root/'90-cmprovision.conf'
        self.snapshot = self.root/'snapshot'
        self.snapshot.mkdir()
        self.state = self.root/'state.json'
        self.state.write_text(json.dumps(dict(enabled=True, active=True, effective='system-defaults', calls=[])))
        fake = self.root/'systemctl'
        fake.write_text('''#!/usr/bin/python3
import json,sys
from pathlib import Path
p=Path(__file__).with_name('state.json'); s=json.loads(p.read_text()); a=sys.argv[1:]; s['calls'].append(a)
if a[0]=='is-enabled': print('enabled' if s['enabled'] else 'disabled')
elif a[0]=='is-active': print('active' if s['active'] and a[-1]=='systemd-timesyncd.service' else 'inactive')
elif a[0]=='show': print('loaded')
elif a[0]=='enable': s['enabled']=True
elif a[0]=='disable': s['enabled']=False
elif a[0]=='stop': s['active']=False
elif a[0] in ('restart','try-restart'):
    if a[0]=='restart' or s['active']:
        s['active']=True; c=Path(__file__).with_name('90-cmprovision.conf'); s['effective']=c.read_text() if c.exists() else 'system-defaults'
else: raise SystemExit('Unexpected command')
p.write_text(json.dumps(s))
''')
        fake.chmod(0o755)
        source = Path(__file__).resolve().parents[2]/'tools/time-backup.sh'
        self.lib = self.root/'time-backup.sh'
        self.lib.write_text(source.read_text().replace('/usr/bin/systemctl', str(fake)).replace(
            '/etc/systemd/timesyncd.conf.d/90-cmprovision.conf', str(self.config)))

    def command(self, function, data_only='0'):
        return subprocess.run(['sh','-c','. "$1"; '+function+' "$2" "$3"', '_', str(self.lib), str(self.snapshot), data_only],
                              capture_output=True,text=True,check=True)
    def value(self): return json.loads(self.state.read_text())
    def set_state(self, **values):
        state=self.value(); state.update(values); self.state.write_text(json.dumps(state))

    def test_snapshot_absence_removes_later_override_and_reloads_active_service(self):
        self.command('cmprovision_time_snapshot')
        self.config.write_text('server-B')
        self.set_state(effective='server-B')
        self.command('cmprovision_time_restore')
        self.assertFalse(self.config.exists())
        self.assertEqual('system-defaults',self.value()['effective'])

    def test_existing_restored_file_is_loaded_by_the_running_daemon(self):
        self.config.write_text('server-A')
        self.command('cmprovision_time_snapshot')
        self.set_state(effective='server-B')
        self.command('cmprovision_time_restore')
        self.assertEqual('server-A',self.value()['effective'])

    def test_disabled_snapshot_does_not_reenable_or_restart_ntp(self):
        self.set_state(enabled=False,active=False)
        self.command('cmprovision_time_snapshot')
        self.set_state(enabled=True,active=True,calls=[])
        self.command('cmprovision_time_restore')
        self.assertFalse(self.value()['enabled'])
        self.assertFalse(self.value()['active'])
        self.assertFalse(any(call[0]=='restart' and 'systemd-timesyncd.service' in call for call in self.value()['calls']))

    def test_legacy_snapshot_does_not_reset_unrecorded_time_settings(self):
        self.config.write_text('keep')
        self.command('cmprovision_time_restore')
        self.assertEqual('keep',self.config.read_text())
        self.assertEqual([],self.value()['calls'])

    def test_data_only_restore_preserves_system_time_and_servers(self):
        self.command('cmprovision_time_snapshot')
        self.config.write_text('keep')
        self.set_state(calls=[])
        self.command('cmprovision_time_restore','1')
        self.assertEqual('keep',self.config.read_text())
        self.assertEqual([],self.value()['calls'])

    def test_invalid_record_does_not_delete_config_or_execute_its_text(self):
        (self.snapshot/'time-settings.saved').write_text('1\nabsent\n$(touch bad)\n')
        self.config.write_text('keep')
        self.command('cmprovision_time_restore')
        self.assertEqual('keep',self.config.read_text())
        self.assertEqual([],self.value()['calls'])


if __name__ == '__main__': unittest.main()
