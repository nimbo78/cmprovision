# Provision a CM4 over USB

[Back to the README](../README.md) · [Local console](local-console.md) · [WLAN Pi M4+ hardware](hardware/wlanpi-m4-plus.md)

USB boot lets you start provisioning a Compute Module 4 even when its installed
system or EEPROM boot order prevents network boot. You do not need to log in to
the installed system or erase its boot partition first. The CM4 loads the utility
OS into RAM through `rpiboot`, then writes the active project's image to its storage.

**Ethernet is still required.** USB carries the boot files; the utility OS obtains
its DHCP lease, provisioning script and image through the target's Ethernet port.
This workflow does not expose the target as a USB drive and does not use Raspberry
Pi Imager or the installed system's USB Ethernet gadget. Utility OS 2026.10.1 also
provides a [USB diagnostic fallback](module-diagnostics.md#automatic-usb-fallback)
when its Ethernet report cannot be obtained; this does not replace Ethernet for
provisioning scripts or images.

These instructions cover CM4 targets. For two WLAN Pi M4+ units, use the
[carrier-board guide](hardware/wlanpi-m4-plus.md) to identify their connectors and BOOT/OTG controls.

## What you need

- A working cmprovision server and access to its web interface.
- A CM4 carrier with a USB device/programming port and access to `nRPIBOOT`,
  sometimes labelled `BOOT` or `EMMC-DISABLE`.
- A short USB data cable matching the carrier's programming port.
- A powered USB hub with enough power for the target and its peripherals.
- Ethernet connections from server and target to the isolated provisioning switch.

Raspberry Pi recommends a powered hub and short cables for
[rpiboot hosts](https://github.com/raspberrypi/usbboot#recommended-rpiboot-host-configuration).
Match power requirements and jumper positions to the carrier's own documentation.

```text
Provisioner USB-A ── powered USB hub ── USB data cable ── target programming port

Provisioner eth0 ── isolated switch ── target Ethernet
                                      PoE disabled for this USB-powered setup
```

The provisioner is the USB **host**; the target is the USB **device**. If both
units are M4+, change BOOT/OTG only on the target and leave the server in its working host configuration.

## Prepare the project

Writing an image replaces the target's system and data. Select the intended
target storage before enabling USB boot.

1. Wait until no module is provisioning or starting. In **Projects → Add project**,
   choose an image from **Images** whose checksum preparation has finished.
2. Set **Destination storage device**. A CM4 with eMMC normally uses `/dev/mmcblk0`;
   check the target's hardware before using a different path.
3. Enable **Verify that image was written correctly**.
4. **EEPROM firmware update to apply** can stay at `-none-`. USB boot does not
   require changing the target's EEPROM boot order.
5. **Check the OS after the first boot** can remain enabled, but it is skipped
   for USB boots. First-boot scripts and package installation are skipped with it;
   ordinary pre-install and post-install scripts still run.
6. **Re-provision from the web UI** is optional. Enable it if the written image
   should permit the later [SSH re-provision workflow](operations.md#re-provision-an-installed-sensor).
7. Save and choose **Set active**. The active project applies to the whole stand:
   arrange that other modules do not start while this project is selected.

## Start through USB

1. In **Settings → Utility OS**, choose the OS to serve. Since 2.7.0 the package
   offers both the new 64-bit OS and legacy. The selected row says **modules get
   this one**. Change it only while no target is booting.
2. In **Settings → Service status**, check **rpiboot (USB file server)** and open
   its **View log**. Waiting for a device before it is connected is normal.
3. Fully power off the target: unplug USB and remove or disable its other power
   sources. For the diagram above, disable PoE on its switch port but keep the
   Ethernet port enabled for data. The server's **Restart** control only cycles
   PoE; it does not leave it disabled.
4. With the target unpowered, select USB **Device** routing on its carrier and
   assert `nRPIBOOT`/`BOOT` as the carrier requires. These are separate controls:
   Device routing alone does not force a CM4 into ROM USB boot. On M4+ use the
   [board-specific table](hardware/wlanpi-m4-plus.md#boot-and-usb-role-controls).
5. Connect the powered hub to the provisioner's USB-A port and its own power
   supply. Connect the target's Ethernet port to the provisioning switch.
6. Connect a hub data port to the target's USB programming port. This powers the
   target in the shown setup. Watch the rpiboot log for discovery and file transfer.
   The installed cmprovision service already runs rpiboot; a second manual
   rpiboot process is unnecessary.
7. Open **CMs** and the target's serial number. Check the project, image and
   **Utility OS**. Follow the write and verification stages to **Done**.

If OS check was enabled, the card should say **skipped: the module booted over USB**.
The target remains in the utility OS after completion. It does not automatically
reboot into the written image: forced USB boot would otherwise bring it back
through the USB boot path. [LED signals](leds.md) and **Identify** help locate it.

## Boot the written system

1. After **Done**, unplug the target's USB cable and remove any remaining target
   power. Disabling PoE alone does not power off a USB-powered target.
2. Release `nRPIBOOT`/`BOOT` and restore the carrier's normal USB Host routing.
3. Remove the USB connection to the provisioner. If this was a temporary project,
   restore the previous active project.
4. Re-enable the target's normal power, such as PoE. It can now boot the written
   system according to its EEPROM boot order. That order must permit storage boot.

This ordinary boot does not turn a completed USB run's **OS check: skipped** into
**passed**. To validate first-boot scripts and packages, perform a separate
[network provisioning run with OS check](operations.md#first-boot-os-check).

## Troubleshooting

| What you see | What to check |
| --- | --- |
| rpiboot waits while the target boots its installed OS | The target is not entering ROM USB boot; check BOOT/nRPIBOOT |
| No USB discovery | Device routing, a data-capable cable, the programming port and target power |
| Repeated disconnects before transfer finishes | Power supply, hub capacity and cable |
| `Failed to open second stage bootloader` | The selected utility OS directory must contain a valid `bootfiles.bin`; see [Utility OS](utility-os.md) |
| Transfer stops after `start4.elf` / `fixup4.dat`, before the kernel | Check that the packaged rpiboot service serves `etc/boot/usb`; its `config.txt` and `boot.img` provide the CM4 USB boot ramdisk |
| Files transfer but no new run appears | Target Ethernet, DHCP and connectivity to the provisioner (`172.20.0.1` by default) |
| A card asks to confirm the current project | Follow the [re-provision project confirmation](operations.md#re-provision-an-installed-sensor), then restart the waiting target |
| Verification fails | Inspect the module log before repeating; USB file transfer alone does not prove a successful image write |
| No USB keyboard on an M4+ target | Device mode disconnects its USB-A ports; use Host routing for the separate [local-console workflow](local-console.md) |

For a continuous log, run this on the provisioner over your normal management connection:

```sh
sudo journalctl -u cmprovision-rpiboot -f
```
