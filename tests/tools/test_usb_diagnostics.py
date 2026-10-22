import base64
import hashlib
import importlib.util
import os
from pathlib import Path
import pty
import shutil
import subprocess
import sys
import tempfile
import termios
import tty
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('usb_receiver', ROOT / 'tools/usb-diagnostics.py')
receiver = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = receiver
spec.loader.exec_module(receiver)
SERIAL = '100000002b205d97'
BOOT = '11111111-2222-3333-4444-555555555555'
EVENT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'

class FakeChannel:
    def __init__(self, lines): self.lines = lines
    def send(self, line): self.lines = [s.replace('NONCE', line.split()[2]) for s in self.lines]
    def line(self, until):
        if not self.lines: raise EOFError('truncated')
        return self.lines.pop(0)

class FramingTest(unittest.TestCase):
    def channel(self, data=b'read only', **over):
        length = over.get('length', len(data)); digest = over.get('digest', hashlib.sha256(data).hexdigest())
        serial = over.get('serial', SERIAL)
        return FakeChannel([f'CMPDIAG1 BEGIN NONCE {serial} {BOOT} {length} {digest} -', base64.b64encode(data).decode(), 'CMPDIAG1 END NONCE'])
    def receive(self, channel):
        return receiver.receive(channel, dict(serial=SERIAL, boot_id=BOOT, token='-'), dict(event_id=EVENT, context='discovery'))
    def test_length_digest_and_identity(self):
        data = b'bounded report'
        self.assertEqual(data, base64.b64decode(self.receive(self.channel(data))[0]))
        for values in [dict(length=0), dict(length=32769), dict(length=len(data)+1), dict(digest='0'*64), dict(serial='1000000000000001')]:
            with self.subTest(values=values), self.assertRaises(ValueError): self.receive(self.channel(data, **values))
    def test_truncated_and_oversized_frames(self):
        channel = self.channel(); channel.lines = channel.lines[:1]
        with self.assertRaises(EOFError): self.receive(channel)
        channel = self.channel(); channel.lines[1] = 'a'*44000
        with self.assertRaises(ValueError): self.receive(channel)
    def test_status_nonce_matches(self):
        device = receiver.Device('/dev/ttyACM0', SERIAL, '1-1')
        channel = FakeChannel([f'CMPDIAG1 STATUS NONCE {SERIAL} {BOOT} {EVENT} discovery 90 0 -'])
        self.assertEqual(90, receiver.hello(channel, device)['age'])
        channel = FakeChannel([f'CMPDIAG1 STATUS wrong {SERIAL} {BOOT} {EVENT} discovery 90 0 -'])
        with self.assertRaises(ValueError): receiver.hello(channel, device)
    def test_generic_usb_console_is_ignored(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); usb = root/'devices/1-1'; usb.mkdir(parents=True)
            for key,value in {'idVendor':'0a5c','idProduct':'0104','manufacturer':'Raspberry Pi','product':'Mass Storage Gadget','serial':SERIAL}.items(): (usb/key).write_text(value)
            tty=root/'class/tty/ttyACM0'; tty.mkdir(parents=True); (tty/'device').symlink_to(usb)
            self.assertEqual([], receiver.discover(root, Path('/dev')))
            for key,value in {'idVendor':'1d6b','manufacturer':'cmprovision','product':'cmprovision diagnostics'}.items(): (usb/key).write_text(value)
            self.assertEqual([SERIAL], [d.serial for d in receiver.discover(root, Path('/dev'))])

@unittest.skipUnless(shutil.which('busybox'), 'BusyBox ash required')
class RealAgentTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(); self.root=Path(self.temp.name)
        files={'proc/device-tree/serial-number':SERIAL+'\0','proc/device-tree/model':'Raspberry Pi Compute Module 4 Rev 1.1\0',
            'proc/sys/kernel/random/boot_id':BOOT,'proc/sys/kernel/random/uuid':EVENT,'proc/uptime':'123.0 0.0',
            'proc/meminfo':'MemTotal: 4000000 kB\nMemAvailable: 2000000 kB\n','proc/partitions':'',
            'sys/class/thermal/thermal_zone0/type':'cpu-thermal','sys/class/thermal/thermal_zone0/temp':'43000',
            'state/network-stage':'waiting_interface','sentinel':'never change this storage data'}
        for name,value in files.items():
            path=self.root/name; path.parent.mkdir(parents=True,exist_ok=True); path.write_text(value)
        (self.root/'bin').mkdir()
        for name,body in {'dmesg':'exit 0','vcgencmd':'echo throttled=0x0','ip':'exit 0'}.items():
            path=self.root/'bin'/name; path.write_text('#!/bin/sh\n'+body+'\n'); path.chmod(0o755)
        self.master,self.slave=pty.openpty()
        tty.setraw(self.slave)  # Like the real agent: raw mode before the first blocking read.
        env=os.environ|{'PATH':str(self.root/'bin')+':'+os.environ['PATH'],'CMP_DIAG_LIB':str(ROOT/'resources/shell/module-diagnostics.sh'),
            'CMP_DIAG_PROC':str(self.root/'proc'),'CMP_DIAG_SYS':str(self.root/'sys'),'CMP_DIAG_ETC':str(self.root/'etc'),'CMP_DIAG_STATE':str(self.root/'state')}
        self.agent=subprocess.Popen([shutil.which('busybox'),'sh',str(ROOT/'utility-os/board/cmprovision/rootfs-overlay/usr/sbin/cmprovision-diagnostics-agent'),'--stdio'],
            stdin=self.master,stdout=self.master,stderr=subprocess.PIPE,env=env)
        self.device=receiver.Device(os.ttyname(self.slave),SERIAL,'1-1')
    def tearDown(self):
        self.agent.terminate(); self.agent.communicate(timeout=3); os.close(self.master); os.close(self.slave); self.temp.cleanup()
    def test_real_agent_and_receiver_without_ethernet(self):
        calls=[]
        def app(path,data):
            calls.append(data)
            if data['operation']=='status':
                self.assertEqual('discovery',data['status']['context']); return dict(action='collect',event_id=BOOT,context='discovery')
            payload=base64.b64decode(data['report']).decode()
            for expected in ['network_stage=waiting_interface','temperature_mc=43000','event_id='+BOOT,'ethernet=missing']: self.assertIn(expected,payload)
            return dict(action='stored')
        self.assertEqual('stored',receiver.inspect(self.device,'/unused',app))
        self.assertEqual(['status','accept'],[c['operation'] for c in calls])
        self.assertEqual('never change this storage data',(self.root/'sentinel').read_text())
    def test_ethernet_success_skips_usb_snapshot(self):
        token='a'*40
        (self.root/'state/binding').write_text(token+' /dev/mmcblk0')
        (self.root/'state/ethernet-event').write_text(token+' '+EVENT)
        def app(path,payload):
            self.assertEqual('idle',payload['status']['context'])
            return dict(action='skip')
        self.assertEqual('skip',receiver.inspect(self.device,'/unused',app))
        self.assertFalse((self.root/'state/usb-report').exists())


    def test_a_downloaded_script_without_a_delivered_report_still_allows_fallback(self):
        token='a'*40
        (self.root/'state/binding').write_text(token+' /dev/mmcblk0')
        def app(path,payload):
            self.assertEqual('discovery',payload['status']['context'])
            self.assertEqual(token,payload['status']['token'])
            return dict(action='skip')
        self.assertEqual('skip',receiver.inspect(self.device,'/unused',app))

    def test_a_failed_ethernet_delivery_transfers_its_original_snapshot(self):
        token='a'*40
        (self.root/'state/binding').write_text(token+' /dev/mmcblk0')
        (self.root/'state/pending').write_text(EVENT+' failure 20 '+token)
        body='CMPROVISION_DIAGNOSTICS_V1\nevent_id='+EVENT+'\nuptime_seconds=20\n[kernel]\noriginal error\n'
        (self.root/'state/pending-report').write_text(body)
        def app(path,payload):
            if payload['operation']=='status':
                self.assertEqual('failure',payload['status']['context'])
                self.assertEqual(103,payload['status']['age'])
                return dict(action='collect',event_id=EVENT,context='failure')
            self.assertEqual(body,base64.b64decode(payload['report']).decode())
            return dict(action='stored')
        self.assertEqual('stored',receiver.inspect(self.device,'/unused',app))

    @unittest.skipUnless((ROOT/'vendor/autoload.php').exists(), 'Application dependencies required')
    def test_real_cli_stores_a_usb_report_without_registering_or_flashing_a_module(self):
        import sqlite3
        keys=['DB_CONNECTION','DB_DATABASE','APP_ENV','CACHE_DRIVER']
        previous={k:os.environ.get(k) for k in keys}
        database=self.root/'isolated.sqlite'; database.touch()
        try:
            os.environ.update(DB_CONNECTION='sqlite',DB_DATABASE=str(database),APP_ENV='testing',CACHE_DRIVER='file')
            subprocess.run(['/usr/bin/php',str(ROOT/'artisan'),'migrate','--force'],cwd=ROOT,check=True,stdout=subprocess.DEVNULL)
            self.assertEqual('stored',receiver.inspect(self.device,str(ROOT)))
            with sqlite3.connect(database) as db:
                self.assertEqual(0,db.execute('select count(*) from cms').fetchone()[0])
                row=db.execute('select serial,report from usb_diagnostic_reports').fetchone()
                self.assertEqual(SERIAL,row[0]); self.assertIn('"source":"usb"',row[1])
        finally:
            for key,value in previous.items():
                if value is None: os.environ.pop(key,None)
                else: os.environ[key]=value

class IsolationTest(unittest.TestCase):
    """One misbehaving device costs that device, never the whole cycle."""
    def test_termios_error_is_an_error_for_that_device(self):
        master, slave = pty.openpty()
        try:
            device = receiver.Device(os.ttyname(slave), SERIAL, '1-1')
            with mock.patch.object(receiver.termios, 'tcsetattr', side_effect=termios.error(5, 'Input/output error')):
                self.assertEqual('error', receiver.inspect(device, '/unused', lambda *args: self.fail('the application must not be called')))
        finally:
            os.close(master); os.close(slave)
    def test_a_malformed_application_answer_is_an_error_for_that_device(self):
        class Stub:
            def __init__(self, path): pass
            def __enter__(self): return self
            def __exit__(self, *args): pass
        device = receiver.Device('/dev/ttyACM0', SERIAL, '1-1')
        with mock.patch.object(receiver, 'Channel', Stub), mock.patch.object(receiver, 'hello', return_value={}):
            self.assertEqual('error', receiver.inspect(device, '/unused', lambda app, data: {}))
    def test_an_unexpected_exception_does_not_escape_the_cycle(self):
        device = receiver.Device('/dev/ttyACM0', SERIAL, '1-1')
        with mock.patch.object(receiver, 'inspect', side_effect=RuntimeError('boom')):
            self.assertEqual('error', receiver.inspect_safely(device, '/unused'))

if __name__=='__main__': unittest.main()
