> [English](serial-console.md) · [Русский](ru/serial-console.md)

# Connect to a USB serial console

From an SSH session on the station, use a local FTDI or PL2303 USB adapter to reach a switch or a board. Choose a cable with the voltage levels and pinout required by the target. A Catalyst RJ45 console takes RS-232. A CM4 GPIO UART uses 3.3 V logic. Power off the carrier before changing an OTG jumper.

## Select the physical port

```sh
cmprovision-console list
```

Copy a complete `by-id/…` or `by-path/…` identifier from the output. The list shows the USB interface and the kernel driver. Use the physical path for adapters with duplicate or missing serial numbers. Run the list again after moving a cable. On multiport adapters, select the interface and port attached to the intended target.

## Listen for the speed

Replace the example identifier with one from your station. Detection needs administrator privileges to verify terminal ownership.

```sh
sudo cmprovision-console detect --port 'by-id/usb-EXAMPLE-if00-port0' --profile cisco --target switch01
```

The Cisco profile selects 8N2 and the default Linux profile selects 8N1. You can also supply a frame such as `7e1`. FTDI supports 7 or 8 data bits. The detector checks the settings applied by the driver, including speed, frame and disabled flow control. It confirms the baud rate from recognizable boot messages, login prompts or switch prompts in two fresh receive windows. Each window lasts up to 1.5 seconds, with a 12-second limit for the command. [Linux FTDI driver](https://github.com/torvalds/linux/blob/master/drivers/usb/serial/ftdi_sio.c)

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
