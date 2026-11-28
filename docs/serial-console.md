> [English](serial-console.md) · [Русский](ru/serial-console.md)

# Connect to a USB serial console

Use a local FTDI or PL2303 USB adapter to reach a switch or a board from the web interface or an SSH session on the station. Choose a cable with the voltage levels and pinout required by the target. A Catalyst RJ45 console takes RS-232. A CM4 GPIO UART uses 3.3 V logic. Power off the carrier before changing an OTG jumper.

## Use the browser terminal

1. Plug the USB adapter into the station. An operator with console access will see **Console** beside **Ports** on the next inventory refresh. A busy adapter stays visible. Open **Console** and confirm your web password.
2. Choose a cable by USB port, label or last observed hostname. **Connect** listens for one second and opens the keyboard even on a quiet line. Auto starts with the last settings that produced a device prompt for that cable, or 9600 8N1 on first use. If you move the cable to another device, **Re-detect** listens for repeated output. You can also choose a known baud rate and format in **Port details**.
3. Type into the terminal. Only one session can own the port. Disconnect, leave the tab or sign out to release it. If the browser loses contact, automatic sending pauses after two seconds and the station releases the port after twelve. Use HTTPS on the management network when entering device credentials.

### Wake a quiet device

For a dedicated console cable, enable **Port details → Send Enter on connect**, save, then press **Connect**. The station sends one carriage return after a quiet second or a recognized Cisco or Huawei login invitation. Other input, including a visible question, is shown in the terminal. If the device stays silent, the keyboard is ready. A silent console may already contain an unfinished command that Enter would submit.

The setting starts off. A cable with a unique USB serial number keeps its setting when reconnected. For cables without a serial number, or with a serial number the station has seen on two adapters at once, enable it again after reconnecting the adapter or restarting the console service. After restoring the database on a fresh station, enable it there as well. **Identify**, **Re-detect**, and inventory checks only listen.

### Send a configuration

At a device prompt, choose **Paste config from file** or paste multiple lines into the terminal. Review the UTF-8 text and original line numbers. The limit is 1 MiB per file and 8 KiB per command. The session sends one command at a time and checks its echo before sending Enter.

| Mode | During transfer |
| --- | --- |
| **Automatic** (default) | Press **Start sending** once for the whole file. The station answers Yes, accepts displayed filenames and Cisco `[confirm]`, and advances paged output. Progress shows the source line and how many replies it sent. |
| **Step** | Send each subsequent command explicitly. Use this for an unfamiliar device transcript. |
| **Smart** | Advance after an expected prompt. A CLI error stops on its source line for correction. At a confirmation or filename prompt, the operator chooses the response. |

Cisco IOS, Huawei S5731 (VRP), S5735-S-V2 and S5735I-S8U4XN-V2 (YunShan OS) have console profiles. On Huawei, run **Read version** (`display version`) at the authenticated prompt before using Automatic or Smart. Supply CLI commands with explicit view changes. For example, a Huawei file can end with `return` and `save`; Automatic handles the save and overwrite questions. A repeated question, missing value, login prompt or rejected command pauses the transfer at that line. After a disconnect, check the last confirmed file line on the device before resuming. Terminal output and uploaded configurations live only in the current session.

### Long output on a serial line

On a Catalyst 2960-CX running IOS 15.2(7)E14, `show terminal` reports the current screen length. From the same EXEC prompt, `terminal length 0` lets `show version` finish without a `--More--` pause. Restore the value you read before disconnecting. On the lab switch it was 24, so the restore command was `terminal length 24`. Closing a browser tab can leave the physical console logged in. [Cisco IOS terminal length](https://www.cisco.com/c/en/us/td/docs/ios/fundamentals/command/reference/cf_book/cf_s5.html)

Huawei documents `screen-length 0 temporary` for continuous output in user view and `mmi-mode enable` for machine-to-machine operation. Automatic answers prompts from the selected file transfer and works with the device's normal console settings. [Huawei S5700 Command Reference, sections 2.5.17 and 2.5.20](https://www.scribd.com/document/960183015/01-02-Basic-Configurations-Commands)

### Grant access

The first web administrator receives the console permission. An upgrade uses the recorded first administrator, or the only existing account if there is one. To grant or revoke access for another operator, run the following on the station with their account email.

```sh
cd /var/lib/cmprovision
sudo -u www-data php artisan auth:serial-console engineer@example.com --grant
sudo -u www-data php artisan auth:serial-console engineer@example.com --revoke
```

Running the command with the email alone shows the current permission. The terminal assets are bundled locally with the station package. [xterm.js](https://xtermjs.org/)

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

On Huawei S5731 (VRP) and S5735-S-V2 (YunShan OS), Auto can use a user-view prompt such as `<rack-east-01>`, a distinctive interface-view prompt, or an OS banner. Both platforms share the same prompt conventions. Use `display version` to read the model and software. With `detect --profile huawei`, the detector scans the supported speeds using 8N1. If the line is silent and has no saved speed, the suggested manual command uses the Huawei default of 9600 baud. [Huawei console settings](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_ce/en/content/ce/19_edesk_dc_console_login_failure/edesk_dc_console_login_failure_edesk002.html)

## Open a manual session

Use an account with access to the adapter, usually through the `dialout` group. For a Catalyst with a known speed of 9600 baud, the printed command looks like this.

```sh
picocom --baud 9600 --databits 8 --parity n --stopbits 2 --flow n /dev/serial/by-id/usb-EXAMPLE-if00-port0
```

Press **Ctrl+A**, then **Ctrl+X** to leave picocom. The station image includes picocom. On a package-only station, install the Debian `picocom` package if the command is missing. [Picocom manual](https://manpages.debian.org/trixie/picocom/picocom.1.en.html)

Detection opens the selected device for reading. Opening or closing a full serial cable can change DTR/RTS, and the Linux terminal can echo incoming bytes before raw mode takes effect. If the target must receive zero electrical input, use a correctly levelled receive-only connection with only the station RX and common ground connected. For a console on a remote terminal server, configure the speed on its physical serial line. [Linux terminal modes](https://man7.org/linux/man-pages/man3/termios.3.html)
