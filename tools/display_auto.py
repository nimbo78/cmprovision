"""Select a station panel at boot and coordinate its signed package and one-shot profile."""
import ctypes
import json
import os
from pathlib import Path
import re
import stat
import tempfile

HYPERPIXEL = 'hyperpixel-4-touch'
WAVESHARE = 'waveshare-144'
TRIAL = ('# cmprovision GPIO display: one-shot HyperPixel v1\n'
         'include config.txt\n[all]\ndtparam=i2c_arm=off\ndtparam=spi=off\n'
         'dtoverlay=vc4-kms-dpi-hyperpixel4\n')


def read_text(path, limit=16384):
    try:
        fd = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    except FileNotFoundError:
        return None
    with os.fdopen(fd, 'rb') as f:
        info = os.fstat(f.fileno())
        if (not stat.S_ISREG(info.st_mode) or info.st_size > limit or
                (os.name == 'posix' and (info.st_uid != os.geteuid() or info.st_mode & 0o022))):
            raise ValueError('Unsafe display configuration: ' + path.name)
        data = f.read(limit + 1)
        if len(data) > limit:
            raise ValueError('Display configuration is too large')
        return data.decode('utf-8')


def sync_dir(path):
    if os.name == 'posix':
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


def exclusive_file(path, content):
    fd, name = tempfile.mkstemp(dir=path.parent, prefix='.cmprovision-display-')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as output:
            output.write(content)
            output.flush()
            if os.name == 'posix' and os.fstat(fd).st_mode & 0o444 != 0o444:
                os.fchmod(fd, 0o644)
            os.fsync(fd)
        if os.name == 'nt':
            os.rename(name, path)  # Windows rename refuses an existing destination.
        else:
            # Linux RENAME_NOREPLACE works across both supported filesystems, ext4 and FAT.
            rename = ctypes.CDLL(None, use_errno=True).renameat2
            if rename(-100, os.fsencode(name), -100, os.fsencode(path), 1) != 0:
                code = ctypes.get_errno()
                raise OSError(code, os.strerror(code), path)
        sync_dir(path.parent)
    finally:
        Path(name).unlink(missing_ok=True)


def save_state(path, data):
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, delete=False, encoding='utf-8') as f:
        tmp = Path(f.name)
        try:
            json.dump(data, f)
            f.flush()
            os.fsync(f.fileno())
        except BaseException:
            tmp.unlink(missing_ok=True)
            raise
    try:
        os.replace(tmp, path)
        sync_dir(path.parent)
    finally:
        tmp.unlink(missing_ok=True)


def integration_off(path):
    raw = read_text(path, 4096)
    if raw is None:
        return False
    value = json.loads(raw)
    return not (isinstance(value, dict) and set(value) == {'enabled', 'open_on_start'} and
                value['enabled'] is True and type(value['open_on_start']) is bool)


def defer_handoff(path, boot):
    """Record that the delayed executor did not ask firmware to reboot."""
    record = json.loads(read_text(path, 4096) or '{}')
    if record.get('handoff') == boot:
        save_state(path, {'deferred': boot})


def boot_mode(config):
    if config is None:
        return 'manual'
    lines = [line.partition('#')[0].strip().lower() for line in config.splitlines()]
    for line in lines:
        if re.match(r'(include\s|(?:overlay_prefix|os_prefix|tryboot_a_b)\s*=)', line):
            return 'manual'
    if any(re.match(r'dtoverlay\s*=\s*(?:vc4-kms-dpi-|hyperpixel)', line) or
           re.match(r'dtparam\s*=\s*spi\s*=\s*(?:on|true|1)(?:\s|,|$)', line) for line in lines):
        return 'fixed'
    # The DPI profile uses the whole header, including pins outside the ID probe.
    for line in lines:
        key, _, value = (part.strip() for part in line.partition('='))
        if key == 'dtoverlay' and value not in ('vc4-kms-v3d', 'dwc2,dr_mode=host', 'nospi10'):
            return 'manual'
        if key in ('gpio', 'enable_dpi_lcd') or (key == 'enable_uart' and value != '0'):
            return 'manual'
        if key == 'dtparam' and value not in ('audio=on', 'audio=off', 'spi=off', 'i2c_arm=off'):
            return 'manual'
    return 'neutral'


class AutoDisplay:
    def __init__(self, boot, state, config, io):
        self.boot, self.record, self.config, self.io = Path(boot), Path(state)/'boot.json', Path(config), io
        self.trial = self.boot/'tryboot.txt'

    def enable(self):
        if integration_off(self.config):
            return False
        if not os.path.lexists(self.config):
            try:
                exclusive_file(self.config, '{"enabled":true,"open_on_start":true}\n')
            except FileExistsError:
                pass  # A simultaneous explicit choice wins.
        return not integration_off(self.config)

    def clear_trial(self):
        if read_text(self.trial) == TRIAL:
            self.trial.unlink()
            sync_dir(self.boot)

    def step(self):
        record = json.loads(read_text(self.record, 4096) or '{}')
        if record.get('deferred'):
            if read_text(self.trial) not in (None, TRIAL):
                return 'manual'
            self.clear_trial()
            record = {}
            save_state(self.record, record)
        handoff = record.get('handoff')
        if handoff:
            if read_text(self.trial) not in (None, TRIAL):
                return 'manual'
            now = int(self.io.now())
            if not integration_off(self.config):
                if self.io.tryboot and handoff != self.io.boot:
                    if self.io.active_panel() == HYPERPIXEL:
                        self.io.start()
                        if self.io.menu_ready(HYPERPIXEL):
                            self.clear_trial()
                            save_state(self.record, {'boot': self.io.boot, 'result': 'active'})
                            return 'active'
                    if record.get('checking') != self.io.boot:
                        record.update(checking=self.io.boot, deadline=now + 120)
                        save_state(self.record, record)
                    if now < record['deadline']:
                        return 'waiting-for-panel'
                elif handoff == self.io.boot and now < record['deadline']:
                    return 'restarting'
            self.clear_trial()
            record = {'failed': HYPERPIXEL, 'failed_boot': self.io.boot}
            save_state(self.record, record)
            if not integration_off(self.config) and (self.io.tryboot or handoff == self.io.boot):
                return 'trial-failed'
        if integration_off(self.config):
            return 'off'
        if record.get('boot') == self.io.boot and record.get('result') in ('active', 'no-panel'):
            return record['result']
        if self.io.tryboot or os.path.lexists(self.trial) or os.path.lexists(self.boot/'autoboot.txt'):
            return 'manual'
        mode = boot_mode(read_text(self.boot/'config.txt'))
        if mode == 'manual':
            return 'manual'
        if record.get('spi_boot') == self.io.boot:
            profile = WAVESHARE  # The preceding confirmed probe enabled SPI on this boot.
        else:
            profile = self.io.active_panel() if mode == 'fixed' else self.io.profile()
        if profile not in (HYPERPIXEL, WAVESHARE):
            value = 'waiting-for-fixed-panel' if mode == 'fixed' else 'no-panel'
            if mode == 'neutral':
                save_state(self.record, {'boot': self.io.boot, 'result': value})
            return value
        if record.get('failed') == profile and record.get('failed_boot') == self.io.boot:
            return 'trial-failed'
        with self.io.admission():
            if not self.io.idle():
                return 'busy'
            if integration_off(self.config):
                return 'off'
            if not self.io.installed():
                now = int(self.io.now())
                if record.get('install_boot') != self.io.boot or now >= record.get('retry_after', 0):
                    save_state(self.record, {'install_boot': self.io.boot, 'retry_after': now + 900})
                    self.io.install()
                return 'install-pending'
            if not self.io.supports(profile):
                return 'update-fpms-lite'
            if not self.enable():
                return 'off'
            if mode == 'neutral' and profile == WAVESHARE:
                save_state(self.record, {'spi_boot': self.io.boot})
                if not self.io.enable_spi():
                    return 'spi-unavailable'
            if mode == 'fixed' or profile == WAVESHARE:
                self.io.start()
                if not self.io.menu_ready(profile):
                    return 'waiting-for-panel'
                save_state(self.record, {'boot': self.io.boot, 'result': 'active'})
                return 'active'
            if not self.io.can_tryboot:
                return 'unsupported-firmware'
            if not (self.boot/'overlays/vc4-kms-dpi-hyperpixel4.dtbo').is_file():
                return 'overlay-missing'
            # Publish intent before the FAT file and power marker. Losing power anywhere
            # in the handoff always boots the original config and bounds automatic retries.
            save_state(self.record, {'handoff': self.io.boot, 'deadline': int(self.io.now()) + 120})
            exclusive_file(self.trial, TRIAL)
            self.io.reboot()
            return 'restarting'
