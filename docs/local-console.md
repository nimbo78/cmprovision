# Use the target's local console

[Back to the README](../README.md) · [USB provisioning](usb-provisioning.md) · [WLAN Pi M4+ hardware](hardware/wlanpi-m4-plus.md)

The utility OS has a local shell on **tty2**. Use it to diagnose the target when
it cannot obtain an address, download its script or report progress to the server.
The provisioning script continues on **tty1** while you use tty2.

This is a console on the **module being provisioned**. A keyboard connected to
the server, an SSH session to the provisioner or the browser's developer console
does not open the target's tty2. The utility OS does not start an SSH or telnet
login service. Its local root account has an empty password, so physical access
to this console gives full control of the temporary system.

## Connect the screen and keyboard

You need a target carrier with an accessible HDMI output, a monitor and a wired
USB keyboard. On WLAN Pi M4+, use its HDMI connector; the small built-in LCD is
not configured as a Linux console in this utility OS.

```text
Provisioner eth0 ── isolated switch ── target Ethernet / normal target power
                                       ├─ HDMI ── monitor
                                       └─ USB host port ── keyboard
```

1. Power off the target before arranging its carrier controls and cables.
2. Release any forced USB boot setting, such as BOOT/nRPIBOOT, and select normal
   USB **Host** routing. Connect the keyboard to the target's host port and the
   monitor to the target's HDMI output. Select that HDMI input on the monitor.
3. Connect the target to the provisioning network and power it normally. For a
   WLAN Pi M4+, [identify its BOOT/OTG controls here](hardware/wlanpi-m4-plus.md#boot-and-usb-role-controls).
4. Boot the target into the utility OS over the network. If it already boots an
   installed system, use the normal [Re-provision… workflow](operations.md#re-provision-an-installed-sensor)
   when you intend to replace that system. An ordinary restart of a bootable
   eMMC does not necessarily enter the utility OS.

On M4+ this is a separate setup from USB provisioning: USB Device mode disables
its USB-A ports. Use network boot and Host mode for the keyboard workflow above.

## Open tty2

1. When the utility OS starts, press **Alt+F2** on the target's keyboard.
   **Ctrl+Alt+F2** also works for console switching; keyboards that assign media
   actions to function keys may require **Fn** too.
2. At the login prompt enter `root`. If prompted for a password, press Enter
   without typing one. These are the utility OS credentials, not the login of
   the image being written.
3. Run `tty`. It should report `/dev/tty2`.
4. Press **Alt+F1** to see provisioning output, then **Alt+F2** to return to your
   shell. Switching consoles does not pause the write or verification.

If a long-running diagnostic occupies your tty2 shell, Ctrl+C stops that diagnostic.
Avoid rebooting, changing storage or stopping the provisioning processes while a
write, EEPROM update or verification is running.

## Check which system is running

Run these on the target's tty2:

```sh
tty
uname -r
uname -m
cat /etc/cmprovision-uos
tr -d '\000' </proc/device-tree/serial-number
echo
cat /proc/mounts
```

For Utility OS 2026.10 expect `/dev/tty2`, kernel `6.18.50-cmprovision`, architecture
`aarch64` and version marker `2026.10`. Later utility OS releases will report their
own versions. Legacy uses a 32-bit kernel and has no `/etc/cmprovision-uos` marker;
the missing marker there is not itself a failure.

The utility OS's root filesystem is temporary and runs in RAM. The provisioning
script may separately mount the target's storage during post-install work. After
an OS-check reboot, the console belongs to the **written system** instead, with
that system's login policy and tools; the temporary version marker is no longer expected.

## Diagnose a stalled start

These commands inspect state without changing the disk:

| Question | Command |
| --- | --- |
| Does Ethernet have a link? | `cat /sys/class/net/eth0/carrier` — `1` means link, `0` means no link |
| Did the target obtain an IPv4 address? | `ip -4 addr show eth0` |
| What routes are present? | `ip route` |
| Can it reach the default stand address? | `ping -c 3 172.20.0.1` |
| Does the kernel see the storage? | `cat /proc/partitions` |
| What did the kernel report? | `dmesg` |
| What is running? | `ps` |

A target on the default stand gets an address in `172.20.0.0/16`. If there is no
carrier, check the cable and switch port first. If the link is present but there
is no address, inspect DHCP/TFTP under **Settings → Service status** on the
provisioner. If the target has an address but no script, compare tty1's messages
with the server logs.

Opening tty2 does not report a provisioning success. Let the normal write and
verification finish; if OS check is enabled for a network boot, wait for
**OS check: passed** and **Done** in the module card.

## If there is no console

| Symptom | Check |
| --- | --- |
| No picture | Target HDMI port, monitor input, cable and target power |
| Picture but no keyboard | Target USB Host routing and the keyboard's connection to the target |
| Small M4+ LCD shows nothing | Use HDMI; the utility OS does not run the WLAN Pi front-panel application |
| The login or hostname looks like the installed image | Confirm network boot into the utility OS using tty1, `uname` and the version marker |
| SSH to the target fails | A network shell is not part of the utility OS; use its local tty2 |

A USB-to-UART connection is a different console and does not by itself test tty2.
