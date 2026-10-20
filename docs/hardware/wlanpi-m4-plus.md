# WLAN Pi M4+ as provisioner and target

[Back to the README](../../README.md) · [USB provisioning](../usb-provisioning.md) · [Local console](../local-console.md)

Two WLAN Pi M4+ units can have different roles on the same stand:

| Role | What it runs | What you connect |
| --- | --- | --- |
| Provisioner | Its installed OS and cmprovision | Management network, isolated Ethernet stand, USB Host port when using rpiboot |
| Target / sensor | The temporary utility OS, then the image written to its storage | Stand Ethernet; USB programming cable or an HDMI monitor and keyboard |

Keep the provisioner in its working host configuration. Change boot controls,
replace storage contents and connect the diagnostic keyboard only on the target.
Check which CM4 is installed: M4+ assemblies can have different RAM and storage
configurations. A CM4 with eMMC uses that eMMC; the carrier's microSD slot does not
make it a CM4 Lite.

## Find the programming connector

The [board illustrations in WLAN Pi discussion #101](https://github.com/orgs/WLAN-Pi/discussions/101)
show both a standard purple Mcuzone CM4 WiFi7 PoE board and a green WLAN Pi variant.
Their layouts differ even where the family name is similar. Match the printed
labels and fitted controls to your board.

- On the labelled purple board, use USB-C **power and Program**, on the HDMI side.
  The opposite USB-C is **AUX power (opt)** and is for power.
- The green WLAN Pi board also has its data-capable USB-C on the HDMI side.
- BOOT/OTG contacts are beside the fan connector and GPIO area. The front-panel
  A/B/C buttons are not the ROM boot control.

## Boot and USB role controls

**BOOT/nRPIBOOT and OTG do different jobs.** BOOT forces CM4 ROM USB boot; OTG
chooses whether the CM4's USB connection goes to its host ports or programming port.
Set these while the target is completely unpowered.

| Board layout shown in #101 | USB provisioning | Normal boot and USB keyboard |
| --- | --- | --- |
| Purple `CM4_WiFi7 Rev1.0`, no DEV/HOST switch | Close **BOOT** and the separate **OTG** pair; its label says `OTG Short=Device` | Open **BOOT** and **OTG** |
| Green `CM4_WIFI7_2409`, with DEV/HOST switch | Close **BOOT**, set **OTG → DEV** | Open **BOOT**, set **OTG → HOST** |

Identify each contact pair by its own label. On the board without a switch,
moving a single jumper from OTG to BOOT does not enable both functions: USB
provisioning needs both. Do not treat other GPIO contacts as BOOT.

The M4+ assembly's [hardware description](https://www.badgerwifi.co.uk/store/p/wlanpi-m4-plus)
also explains that Device mode disables USB-A. This is why the local-keyboard and
USB-provisioning workflows below use different setups. If your controls differ
from the documented layouts, follow the documentation for your carrier revision.

## Setup for USB provisioning

```text
Provisioner M4+ USB-A ── powered hub ── data cable ── target M4+ programming USB-C

Provisioner M4+ RJ45 ── isolated switch ── target M4+ RJ45
                                            PoE disabled; powered through hub
```

Use the target's BOOT/Device settings from the table, then follow
[Provision a CM4 over USB](../usb-provisioning.md). The target still needs Ethernet
for DHCP, its script and image. Use the switch's controls to disable PoE only on
the target port; the provisioner must remain powered.

When finished, unplug USB before releasing BOOT and restoring Host mode. A PoE
restart cannot turn off a target that is still powered through USB.

## Setup for tty2

```text
Provisioner M4+ RJ45 ── isolated switch ── target M4+ RJ45 / PoE
                                            ├─ HDMI ── monitor
                                            └─ USB-A ── wired keyboard
```

Release BOOT and restore Host mode as above. Remove the USB connection between
the two units, then follow [Use the target's local console](../local-console.md)
with a network boot. The built-in M4+ LCD is not the utility OS's tty2 display.

The USB-gadget configuration discussed in #101 belongs to the installed WLAN Pi
OS. This provisioning workflow uses the CM4 ROM and cmprovision's utility OS;
it does not require editing the installed image's `/boot/config.txt` or installing
a Windows RNDIS driver.
