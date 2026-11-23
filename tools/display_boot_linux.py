"""Boot-time panel commissioning on Raspberry Pi OS."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time

# The systemd unit runs isolated Python. Only load siblings from the root-owned package.
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from display_auto import AutoDisplay, HYPERPIXEL, WAVESHARE
from display_boot_probe import neutral_probe
from display_spi_probe import probe_st7735
from update_manifest import UpdateError
from update_store import UpdateStore, TERMINAL
from update_system import LinuxSystem

DT = Path('/proc/device-tree')
BOOT = Path('/boot/firmware')
STATE = Path('/var/lib/cmprovision-display-auto')
CONFIG = Path('/etc/default/cmprovision-fpms.json')


def helper(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), HERE / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def hyperpixel_bound(dt, inputs):
    try:
        if (dt / 'spi/display@0/compatible').read_bytes().rstrip(b'\0') != b'pimoroni,hyperpixel4':
            return False
    except OSError:
        return False
    for event in inputs.glob('event*'):
        try:
            device = (event / 'device').resolve(strict=True)
            client = device.parent.parent
            # Goodix exports the hardware product ID as decimal 911, formatted in hex.
            if ((device / 'id/product').read_text().strip() == '038f' and
                    (client / 'driver').resolve(strict=True).name == 'Goodix-TS' and
                    (client / 'of_node/compatible').read_bytes().rstrip(b'\0') == b'goodix,gt911'):
                return True
        except OSError:
            continue
    return False


class StationIO:
    def __init__(self):
        self.power_helper = helper('station-power')
        self.power = self.power_helper.Controller()
        self.boot = self.power.boot()
        flag = DT / 'chosen/bootloader/tryboot'
        firmware_flag = flag.read_bytes() if flag.exists() else None
        self.can_tryboot = firmware_flag == bytes(4)
        self.tryboot = firmware_flag == b'\x00\x00\x00\x01'
        self.system = LinuxSystem(None)

    now = staticmethod(time.monotonic)
    profile = staticmethod(neutral_probe)

    def heartbeat(self):
        fpms = helper('fpms-control')
        identity = fpms.display_identity(fpms.read_json(fpms.HEARTBEAT), time.time())
        if identity:
            return WAVESHARE if identity['profile'] == 'waveshare-1.44' else HYPERPIXEL
        return None

    def menu_ready(self, profile):
        return self.heartbeat() == profile

    def active_panel(self):
        if hyperpixel_bound(DT, Path('/sys/class/input')):
            return HYPERPIXEL
        profile = self.heartbeat()
        if profile:
            return profile
        if Path('/dev/spidev0.0').exists() and probe_st7735():
            return WAVESHARE
        return None

    def admission(self):
        return self.system.admission_lock()

    def idle(self):
        try:
            self.system.conflicts()
            with self.power_helper.package_locks():
                pass  # The power executor rechecks these locks immediately before reboot.
            status = UpdateStore().snapshot()
            if status['pending'] or (status['operation'] and status['operation']['state'] not in TERMINAL):
                return False
            admission = self.system.admission()
            return (isinstance(admission, dict) and admission.get('active') == [] and
                    type(admission.get('reserved_jobs')) is int and admission['reserved_jobs'] == 0)
        except (UpdateError, OSError):
            return False

    def installed(self):
        return self.system.package('fpms-lite') is not None

    def supports(self, profile):
        driver = 'hyperpixel.py' if profile == HYPERPIXEL else 'st7735_probe.py'
        return (Path('/usr/lib/fpms-lite/fpms/modules/screen') / driver).is_file()

    def install(self):
        # The existing broker schedules the worker; that worker checks release/version,
        # signature, SHA256, dependency plan and station admission before running apt.
        request = {'action': 'install-fpms', 'nonce': secrets.token_hex(32)}
        self.system.command([HERE / 'station-updates'], input=json.dumps(request), timeout=20)

    def enable_spi(self):
        node = Path('/dev/spidev0.0')
        if not node.exists():
            self.system.command(['/usr/bin/dtoverlay', 'spi0-1cs'], timeout=10)
            for _ in range(20):
                if node.exists():
                    return True
                time.sleep(0.1)
        return node.exists()

    def start(self):
        self.system.command(['/usr/bin/systemctl', 'start', 'fpms-lite.service'], timeout=20)

    def reboot(self):
        self.power.schedule('reboot', tryboot=True)


def main():
    model = (DT / 'model').read_bytes().rstrip(b'\0')
    if not model.startswith((b'Raspberry Pi Compute Module 4 ', b'Raspberry Pi 4 Model B ')):
        return 'unsupported-board'
    if (DT / 'hat/product').exists():
        return 'manual-hat'
    if not all(Path(p).is_file() for p in ('/usr/bin/pinctrl', '/usr/bin/dtoverlay')):
        return 'install Raspberry Pi OS packages raspi-utils-core and raspi-utils-dt'
    io = StationIO()
    return AutoDisplay(BOOT, STATE, CONFIG, io).step()


def finish_boot(result, marker=Path('/run/cmprovision-display-auto-done'), run=subprocess.run):
    if result in ('busy', 'install-pending', 'restarting', 'waiting-for-panel', 'waiting-for-fixed-panel',
                  'spi-unavailable'):
        return
    marker.touch(mode=0o600)
    # Leave the timer enabled for the next boot without waking an idle station every 45s.
    run(['/usr/bin/systemctl', '--no-block', 'stop', 'cmprovision-display-auto.timer'], check=True, timeout=8)


if __name__ == '__main__':
    if os.geteuid() != 0 or len(sys.argv) != 1:
        sys.exit(2)
    try:
        result = main()
        print('GPIO panel:', result, flush=True)
        finish_boot(result)
    except (OSError, RuntimeError, ValueError, subprocess.SubprocessError) as error:
        sys.exit('GPIO panel: ' + str(error))
