"""Read a supported ST7735 controller before claiming its display and buttons.

The Waveshare wiring uses a bidirectional SDA on GPIO10. Linux SPI_3WIRE
releases that wire during the receive transfer; it does not change the LCD's
four-line D/C protocol. ID bytes identify the controller, not panel dimensions.
"""
import array
from contextlib import contextmanager
import ctypes
import fcntl
import os
from pathlib import Path
import subprocess
import time

SPI_DEVICE = Path('/dev/spidev0.0')
DPI_STATUS = Path('/proc/device-tree/soc/dpi@7e208000/status')


class _Transfer(ctypes.Structure):
    _fields_ = [('tx_buf', ctypes.c_uint64), ('rx_buf', ctypes.c_uint64),
                ('length', ctypes.c_uint32), ('speed_hz', ctypes.c_uint32),
                ('delay_usecs', ctypes.c_uint16), ('bits_per_word', ctypes.c_uint8),
                ('cs_change', ctypes.c_uint8), ('tx_nbits', ctypes.c_uint8),
                ('rx_nbits', ctypes.c_uint8), ('word_delay_usecs', ctypes.c_uint8),
                ('pad', ctypes.c_uint8)]


if __package__:
    from fpms.integrations.display_st7735_id import identify
else:
    from display_st7735_id import identify


def _read(fd, command, count):
    tx = ctypes.create_string_buffer(bytes([command]))
    rx = ctypes.create_string_buffer(count)
    transfers = (_Transfer * 2)()
    transfers[0].tx_buf = ctypes.addressof(tx)
    transfers[0].length = 1
    transfers[0].speed_hz = 100000
    transfers[0].bits_per_word = 8
    transfers[0].delay_usecs = 5
    transfers[1].rx_buf = ctypes.addressof(rx)
    transfers[1].length = count
    transfers[1].speed_hz = 100000
    transfers[1].bits_per_word = 8
    # One message keeps CS asserted through the direction change.
    fcntl.ioctl(fd, 0x40406B00, bytes(transfers))
    return bytes(rx)


@contextmanager
def _spi_reader():
    fd = os.open(SPI_DEVICE, os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC)
    old_mode = None
    try:
        mode = array.array('I', [0])
        fcntl.ioctl(fd, 0x80046B05, mode, True)
        old_mode = mode[0]
        if old_mode not in (0, 0x10):
            raise ValueError('SPI belongs to a different mode')
        fcntl.ioctl(fd, 0x40046B05, array.array('I', [old_mode | 0x10]))
        yield lambda command, count: _read(fd, command, count)
    finally:
        try:
            if old_mode is not None:
                fcntl.ioctl(fd, 0x40046B05, array.array('I', [old_mode]))
        finally:
            os.close(fd)


def _dpi_configured():
    try:
        return DPI_STATUS.read_bytes().rstrip(b'\0') in (b'okay', b'ok')
    except FileNotFoundError:
        return False
    except OSError:
        return True


def _read_biases(directions):
    # The kernel forgets bias flags when a request is released. Read the actual
    # Raspberry Pi pin configuration; 'get' performs no configuration changes.
    reply = subprocess.run(['/usr/bin/pinctrl', 'get', ','.join(map(str, directions))],
                           check=True, capture_output=True, text=True, timeout=2)
    lines = reply.stdout.splitlines()
    if len(lines) != len(directions):
        raise ValueError('Incomplete pin configuration')
    biases = {}
    for line in lines:
        head, separator, _ = line.partition('|')
        fields = head.split()
        if not separator or len(fields) not in (3, 4):
            raise ValueError('Unknown pin configuration')
        pin = int(fields[0].removesuffix(':'))
        if (fields[0] != f'{pin}:' or pin not in directions or pin in biases
                or fields[1] != directions[pin] or fields[-1] not in ('pu', 'pd', 'pn')):
            raise ValueError('Pin configuration changed or cannot be restored')
        biases[pin] = fields[-1]
    return biases


def probe_st7735():
    """Return a profile/ID on a positive response, otherwise leave controls idle."""
    if not SPI_DEVICE.exists() or _dpi_configured():
        return None
    try:
        import gpiod
        from gpiod.line import Bias, Direction, Drive, Value
        if __package__:
            from ..gpiochip import get_gpiochip
            chip_path = get_gpiochip()
        else:
            from display_boot_probe import header_chip
            chip_path = header_chip(gpiod)
            if chip_path is None:
                return None
        with gpiod.Chip(chip_path) as chip:
            before = {pin: chip.get_line_info(pin) for pin in (25, 27)}
        if any(info.used or info.direction not in (Direction.INPUT, Direction.OUTPUT)
               or (info.direction is Direction.OUTPUT and info.drive is not Drive.PUSH_PULL)
               or info.bias not in (Bias.PULL_UP, Bias.PULL_DOWN, Bias.DISABLED, Bias.UNKNOWN)
               for info in before.values()):
            return None
        biases = {pin: info.bias for pin, info in before.items()}
        if Bias.UNKNOWN in biases.values():
            pull = _read_biases({pin: 'ip' if info.direction is Direction.INPUT else 'op'
                                 for pin, info in before.items()})
            known = {'pu': Bias.PULL_UP, 'pd': Bias.PULL_DOWN, 'pn': Bias.DISABLED}
            biases = {pin: known[pull[pin]] if bias is Bias.UNKNOWN else bias
                      for pin, bias in biases.items()}
        with _spi_reader() as read_register:
            # AS_IS permits reading an existing output without changing its level.
            # Keep the original polarity so saved values remain logical values.
            with gpiod.request_lines(chip_path, consumer='fpms-display-probe', config={
                pin: gpiod.LineSettings(direction=Direction.AS_IS, active_low=info.active_low)
                for pin, info in before.items()
            }) as lines:
                levels = dict(zip(before, lines.get_values(list(before))))
                restore = {pin: gpiod.LineSettings(direction=info.direction, bias=biases[pin],
                           drive=info.drive, active_low=info.active_low, output_value=levels[pin])
                           for pin, info in before.items()}
                try:
                    lines.reconfigure_lines({
                        25: gpiod.LineSettings(direction=Direction.OUTPUT, output_value=Value.INACTIVE),
                        # Shared with other panels' IRQ: only a weak pull-up during the probe.
                        27: gpiod.LineSettings(direction=Direction.INPUT, bias=Bias.PULL_UP),
                    })
                    time.sleep(0.15)
                    identity = identify(read_register)
                finally:
                    lines.reconfigure_lines(restore)
        return {'profile': 'waveshare-1.44', 'id': identity} if identity else None
    except (ImportError, OSError, ValueError, subprocess.SubprocessError):
        # Missing/occupied hardware must not start the legacy GPIO/button driver.
        return None
