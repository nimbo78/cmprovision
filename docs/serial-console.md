> [English](serial-console.md) · [Русский](ru/serial-console.md)

# Connect to a USB serial console

From an SSH session on the station, use a local FTDI or PL2303 USB adapter to reach a switch or a board. Choose a cable with the voltage levels and pinout required by the target. A Catalyst RJ45 console takes RS-232. A CM4 GPIO UART uses 3.3 V logic. Power off the carrier before changing an OTG jumper.

## Select the physical port

```sh
cmprovision-console list
```

Copy a complete `by-id/…` or `by-path/…` identifier from the output. The list shows the USB interface and the kernel driver. Use the physical path for adapters with duplicate or missing serial numbers. Run the list again if you move the adapter to another USB socket. On multiport adapters, select the interface and port attached to the intended target.

## Move between devices in one SSH session

Run this from your operator account (usually in the `dialout` group). Use the USB identifier from `list`.

```sh
cmprovision-console connect --port 'by-id/usb-EXAMPLE-if00-port0'
```

By default, `connect` uses `sudo` to check five baud rates with **8N1, 8N2, 7E1, 8E1, 7O1 and 8O1**, then opens `picocom` as your user. One Auto scan takes up to 40 seconds. It skips formats rejected by the driver and discards samples with reported parity or framing errors. If several formats receive recognizable text twice at the same speed, it lists them and uses the first in the order above. For example, `Readable on RX: 8N1, 8N2. Using 8N1.` describes what the adapter could read. Both settings can read the same bytes, especially with gaps between characters. Use an explicit frame when the peer requires particular transmit settings. [Linux terminal modes](https://man7.org/linux/man-pages/man3/termios.3.html)

Move the target end of the cable to the next device. Readable output keeps the session open. Sustained garbled output triggers a fresh check of speed and framing and reopens the terminal in the same window. A pause leaves the existing connection alone. This mode is for text consoles.

Press **Ctrl+]** to request a fresh check in the connected session. During the check the wrapper waits for recognizable output. A silent device can stay at this step until it prints again. **Ctrl+C** cancels the check. In the connected terminal, **Ctrl+A**, then **Ctrl+X** exits. If you unplug the USB adapter itself, run `list` and select it again.

## Listen for the speed

Replace the example identifier with one from your station. Detection needs administrator privileges to verify terminal ownership.

```sh
sudo cmprovision-console detect --port 'by-id/usb-EXAMPLE-if00-port0' --profile cisco --target switch01
```

For a single scan, `detect --profile auto` also checks framing. Plain `detect` retains its 8N1 default. The existing `cisco` preset selects 8N2. Cisco documents the Catalyst 2960-CX factory console as 9600 8N1. You can also specify a frame directly, such as `7e1`. A fixed-profile scan takes up to 12 seconds and checks for recognizable output in two independent windows. [Cisco installation guide](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst2960cx_3650cx/hardware/installation/guide/b_2960cx-3560cx_hig.pdf)

FTDI supports 7 or 8 data bits. The detector reads back the speed, frame and flow control applied by the driver. Each receive window for a fixed profile lasts up to 1.5 seconds. [Linux FTDI driver](https://github.com/torvalds/linux/blob/master/drivers/usb/serial/ftdi_sio.c)

| Result | What to do |
|---|---|
| `detected` | Two separate windows confirmed one speed. Open the printed `picocom` command from your own account. |
| `silent` | The detector read no bytes during its sampling windows. A short burst can fall in the interval discarded after a speed change. If the speed is known, open a manual session. |
| `inconclusive` | Noise, a single burst or multiple possible speeds prevented confirmation. Wait for repeated output or choose the speed manually. |
| `busy` | Another reader, service or terminal lock owns the line. Finish the existing session before retrying. |
| `unavailable` | Check the adapter, permissions and the current identifier. The message gives the reason when available. |

The optional `--target` label records the last confirmed speed and time for that piece of equipment, USB connection and profile. Use a different label when moving the target end of the cable. A saved speed is tried first and must be confirmed again. The station discards the console text it receives.

## Open a manual session

Use an account with access to the adapter, usually through the `dialout` group. For a Catalyst with a known speed of 9600 baud, the printed command looks like this.

```sh
picocom --baud 9600 --databits 8 --parity n --stopbits 2 --flow n /dev/serial/by-id/usb-EXAMPLE-if00-port0
```

Press **Ctrl+A**, then **Ctrl+X** to leave picocom. The station image includes picocom. On a package-only station, install the Debian `picocom` package if the command is missing. [Picocom manual](https://manpages.debian.org/trixie/picocom/picocom.1.en.html)

Detection opens the selected device for reading. Opening or closing a full serial cable can change DTR/RTS, and the Linux terminal can echo incoming bytes before raw mode takes effect. If the target must receive zero electrical input, use a correctly levelled receive-only connection with only the station RX and common ground connected. For a console on a remote terminal server, configure the speed on its physical serial line. [Linux terminal modes](https://man7.org/linux/man-pages/man3/termios.3.html)
