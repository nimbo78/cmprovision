"""Identify either supported panel on a neutral GPIO header before FPMS is installed."""
import errno
from pathlib import Path
import re
import struct
import subprocess
import time

from display_st7735_id import identify as st7735_id

PINS = (8, 10, 11, 25, 27)  # CE0, SDA/MOSI, SCL/SCLK, LCD D/C, LCD reset / Goodix IRQ


def target_available(profile, used):
    # DPI takes the full header. Waveshare additionally claims SPI, backlight and keys.
    pins = range(28) if profile == 'hyperpixel-4-touch' else (5, 6, 8, 9, 10, 11, 13, 16, 19, 20, 21, 24, 25, 26, 27)
    return all(not used(pin) for pin in pins if pin not in PINS)


def identify(read_i2c, read_spi):
    touch = False
    for address in (0x14, 0x5D):
        try:
            identity = read_i2c(address, 0x8140, 6)
        except OSError as error:
            if error.errno == errno.ENXIO:  # Only a NACK on the initial address means absent.
                continue
            return None
        try:
            if (len(identity) != 6 or identity[:4] != b'911\0' or touch
                    or read_i2c(address, 0x8140, 6) != identity):
                return None
            geometry = read_i2c(address, 0x8048, 4)
            if len(geometry) != 4 or sorted(struct.unpack('<HH', geometry)) != [480, 800]:
                return None
        except OSError:
            return None
        touch = True
    if touch:
        return 'hyperpixel-4-touch'
    try:
        return 'waveshare-144' if st7735_id(read_spi) else None
    except OSError:
        return None


class Header:
    """Slow open-drain transfers on a dedicated CM4 header. High always releases the wire."""

    def __init__(self, request, gpio):
        self.request = request
        self.gpio = gpio
        self.spi_ready = False

    def drive(self, pin, high):
        self.request.set_value(pin, self.gpio.line.Value.ACTIVE if high else self.gpio.line.Value.INACTIVE)

    def high(self, pin):
        return self.request.get_value(pin) == self.gpio.line.Value.ACTIVE

    def clock(self, high):
        self.drive(11, high)
        if high:
            deadline = time.monotonic() + 0.005
            while not self.high(11):
                if time.monotonic() >= deadline:
                    raise OSError(errno.ETIMEDOUT, 'Panel clock is held low')
        time.sleep(0.00001)

    def start(self):
        self.drive(10, True)
        self.clock(True)
        if not self.high(10):
            raise OSError(errno.EBUSY, 'Panel data line is held low')
        self.drive(10, False)
        self.clock(False)

    def stop(self):
        self.clock(False)
        self.drive(10, False)
        self.clock(True)
        self.drive(10, True)

    def pulse(self, high):
        self.drive(10, high)
        self.clock(True)
        bit = int(self.high(10))
        self.clock(False)
        return bit

    def write_byte(self, value):
        for shift in reversed(range(8)):
            self.pulse(bool(value & (1 << shift)))
        return self.pulse(True) == 0

    def read_i2c(self, address, register, length):
        try:
            self.start()
            if not self.write_byte(address << 1):
                raise OSError(errno.ENXIO, 'No response at this touch address')
            if not all(self.write_byte(b) for b in register.to_bytes(2, 'big')):
                raise OSError(errno.EIO, 'Touch controller refused register selector')
            self.start()
            if not self.write_byte((address << 1) | 1):
                raise OSError(errno.EIO, 'Touch controller refused to send data')
            result = bytearray()
            for index in range(length):
                value = 0
                for _ in range(8):
                    value = (value << 1) | self.pulse(True)
                result.append(value)
                self.pulse(index + 1 == length)
            return bytes(result)
        finally:
            self.stop()

    def spi_byte(self, value):
        result = 0
        for shift in reversed(range(8)):
            self.drive(10, bool(value & (1 << shift)))
            # The ST7735S presents its reply while SCLK is low. By the time
            # clock(True) returns, the panel has already released SDA.
            result = (result << 1) | int(self.high(10))
            self.clock(True)
            self.clock(False)
        return result

    def read_spi(self, command, length):
        if not self.spi_ready:
            self.spi_setup()
        self.clock(False)
        try:
            self.drive(8, False)
            self.spi_byte(command)
            return bytes(self.spi_byte(0xFF) for _ in range(length))
        finally:
            self.drive(8, True)
            self.drive(10, True)

    def spi_setup(self):
        line = self.gpio.line
        # Reset/IRQ is never driven. An undriven Waveshare needs time to exit reset.
        self.request.reconfigure_lines({
            25: self.gpio.LineSettings(direction=line.Direction.OUTPUT, drive=line.Drive.OPEN_DRAIN,
                                       output_value=line.Value.INACTIVE),
            27: self.gpio.LineSettings(direction=line.Direction.INPUT, bias=line.Bias.PULL_UP),
        })
        time.sleep(0.15)
        self.spi_ready = True


def select_chip(candidates, label, identity=Path.resolve):
    matches = {}
    for path in candidates:
        if label(path) in ('pinctrl-bcm2711', 'pinctrl-bcm2835'):
            matches.setdefault(identity(path), path)
    return next(iter(matches.values())) if len(matches) == 1 else None


def header_chip(gpiod):
    def label(path):
        with gpiod.Chip(str(path)) as candidate:
            return candidate.get_info().label

    return select_chip(sorted(Path('/dev').glob('gpiochip[0-9]*')), label,
                       lambda path: path.stat().st_rdev)


def neutral_probe():
    import gpiod
    from gpiod.line import Bias, Direction, Drive, Value

    chip_path = header_chip(gpiod)
    if chip_path is None:
        return None
    with gpiod.Chip(str(chip_path)) as chip:
        if any(chip.get_line_info(pin).used for pin in PINS):
            return None
        # GPIO ownership alone does not notice every alternate function. Verify mux and
        # save pulls before, and again after taking the exclusive request.
        def input_biases():
            state = subprocess.run(['/usr/bin/pinctrl', 'get', ','.join(map(str, PINS))],
                                   capture_output=True, text=True, check=True, timeout=2)
            found = {}
            for raw in state.stdout.splitlines():
                match = re.fullmatch(r'\s*(\d+):\s+ip\s+(pu|pd|pn)\s*', raw.split('|')[0])
                if not match:
                    return {}
                found[int(match[1])] = {'pu': Bias.PULL_UP, 'pd': Bias.PULL_DOWN, 'pn': Bias.DISABLED}[match[2]]
            return found if set(found) == set(PINS) else {}

        biases = input_biases()
        if not biases:
            return None
        with chip.request_lines(consumer='cmprovision-panel-id',
                                config={pin: gpiod.LineSettings(direction=Direction.AS_IS) for pin in PINS}) as request:
            if input_biases() != biases:
                return None
            restore = {pin: gpiod.LineSettings(direction=Direction.INPUT, bias=bias) for pin, bias in biases.items()}
            released = gpiod.LineSettings(direction=Direction.OUTPUT, drive=Drive.OPEN_DRAIN,
                                          bias=Bias.PULL_UP, output_value=Value.ACTIVE)
            try:
                request.reconfigure_lines({pin: released for pin in (8, 10, 11)})
                time.sleep(0.005)
                bus = Header(request, gpiod)
                if not all(bus.high(pin) for pin in (8, 10, 11)):
                    return None
                profile = identify(bus.read_i2c, bus.read_spi)
                if profile and target_available(profile, lambda pin: chip.get_line_info(pin).used):
                    return profile
                return None
            finally:
                request.reconfigure_lines(restore)
