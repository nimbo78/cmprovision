# Module diagnostics

cmprovision collects a read-only snapshot from the target's temporary utility OS at
startup, after a failure, and at completion when the target stays in that OS.
Open **CMs → serial number → Module diagnostics** for a summary of storage, power
and temperature, memory, Ethernet, and boot/system information. Expand **Device
facts and kernel output** for evidence. **Download report** saves a text copy;
failure findings from registered modules are also retained in module History and the dashboard log.

## What is collected

| Area | Readings and interpretation |
| --- | --- |
| Storage | Configured block device, MMC controller and bus, CID, card firmware, eMMC lifetime/pre-EOL estimates, read-only state and relevant I/O errors. |
| Power and temperature | Firmware undervoltage, frequency-capping, throttling and soft-temperature-limit flags, distinguishing current conditions from events since boot. A named CPU/SoC thermal sensor and its lowest passive/hot/critical trip point, when exposed by the kernel. |
| Memory | Total and available RAM from `/proc/meminfo`; a warning below 5% available and evidence of kernel out-of-memory events. This describes resource pressure, not RAM-chip health. |
| Ethernet | `eth0` presence, carrier, operational state, speed, duplex, cumulative error/drop counters, IPv4 addresses/routes and relevant kernel messages. Half duplex, missing carrier/interface and transmit timeouts have suggested checks. |
| Boot and system | Kernel, architecture, model, utility OS version, uptime, device-tree boot mode and running bootloader Git version. EEPROM dates come from the existing EEPROM report, separately from the running bootloader, which may have been supplied over USB. |

A 100 Mbps link or a non-zero cumulative Ethernet counter does not by itself
produce a fault diagnosis. Compare counters over time and inspect switch settings
before blaming a cable. Missing or unsupported readings say **not reported**;
no recognized warning is not a pass for an unmeasured component.

The snapshot does not format, reset, repair or write target storage, change network
settings, run a RAM test, or stress the CPU/network. It reads selected sysfs/procfs
values, firmware flags and kernel messages. The firmware query and upload are
bounded; a failed diagnostic upload does not stop provisioning.

## Findings and next checks

| Finding | Meaning and next checks |
| --- | --- |
| MMC storage did not initialize | The relevant MMC host reports `Card stuck being busy!` and the configured block device is missing. Fully power off, check seating and power, and compare with another module or card. |
| Configured storage was not detected | Check the project storage path. A CM4 Lite needs a microSD card; a CM4 with eMMC uses its on-module storage. |
| Storage I/O errors / read-only | Inspect the evidence for the selected device. Check power, write protection, connections and another module/card. |
| eMMC wear warning | The card reports a near-limit lifetime or reserved-block estimate. Retain the report and plan a replacement. |
| Undervoltage | Check the supply, cable and powered hub. These firmware flags do not measure the eMMC supply directly; `0x0` cannot exclude every power problem. |
| Thermal limit / performance limited | Check cooling and power. The sensor threshold comes from this kernel's thermal trip points. Frequency-capping/throttling flags alone do not identify the cause. Historical flags do not mean the condition persists now. |
| Low available RAM / out-of-memory event | Inspect scripts and workload memory use. This does not prove defective RAM. The 5% threshold is an operational warning. |
| Ethernet missing / link down / half duplex / timeout | Check the cable, port, negotiation and expected interface. A missing interface may mean a missing driver in that utility OS. Readings describe collection time; the connection may have recovered before upload. |

An early initialization error does not prove that eMMC has failed. For example,
a suspect CM4 failing on two Mcuzone carriers while another CM4 exposes eMMC on
both localizes the problem much more convincingly than one kernel message.

The firmware flag meanings follow the [Raspberry Pi documentation](https://github.com/raspberrypi/documentation/blob/master/documentation/asciidoc/computers/os/graphics-utilities.adoc#get_throttled).
Boot modes follow the [bootloader documentation](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html#BOOT_ORDER);
thermal readings follow the [Linux thermal sysfs interface](https://cdn.kernel.org/doc/html/latest/driver-api/thermal/sysfs-api.html).

## Collect another snapshot

After a completed or failed run, leave the target powered, running the utility OS,
and connected to the stand Ethernet. **Collect again** asks it for a fresh snapshot
on its next heartbeat. The button is unavailable during provisioning, after it has
booted the installed OS, or when it is no longer reporting. An active Identify
request takes priority; the diagnostic request waits for it to finish.

If no report arrives within 90 seconds, the card says so and allows a retry when
the target is online. Old reports cannot replace the diagnostics of a new run.
A new run clears the previous snapshot; its failure diagnosis remains in History.

## When no report is available

For a new board shown as **Unrecognized / UNR** on Ports, use the
[discovery troubleshooting guide](unrecognized-devices.md) before changing its boot setup.

The target must boot Linux. Ethernet delivery works with either network or
[USB boot](usb-provisioning.md); USB report delivery additionally needs utility OS
**2026.10.1** and a USB data connection in Device mode. The legacy utility OS and
older already-running scripts/images do not gain the USB agent from a server-only
upgrade. Missing telemetry alone is not evidence of failed hardware.

A target stuck before Linux or USB enumeration cannot send its own report. Inspect
rpiboot/USB events on the provisioner and, where available, HDMI or hardware UART.
The USB receiver's journal describes transport failures; it does not replace a
complete provisioner hardware diagnosis.

The collector is part of the provisioning script served by cmprovision, so an
already running older script needs a new boot to gain new readings. The snapshot
is also left on the target at `/tmp/cmprovision-module-diagnostics.txt` for a
[local-console check](local-console.md). It contains selected device/kernel facts
and the stand IPv4 configuration, not environment variables, project script
output or EEPROM configuration credentials. Review it before sharing publicly.
Reports are bounded to 32 KiB. Available sensors depend on the utility OS and
hardware. The Ethernet collector is server-generated; the USB agent and its drivers are included in utility OS 2026.10.1.

## Automatic USB fallback

The policy is **Ethernet first, USB only when the expected Ethernet report cannot
be obtained**. Booting through USB does not itself select USB report delivery.
The operator must provide a USB Device-mode data connection; the fallback does
not move jumpers, change USB routing, restart a target or interrupt a flash.

1. The utility OS starts a dedicated CDC ACM diagnostic agent before waiting for
   `eth0`, carrier or DHCP. It exposes a serial interface named **cmprovision
   diagnostics**, without a shell/login or mass-storage function.
2. A receiver on the provisioner exchanges small identity/status messages. It
   requests the full snapshot only after **90 seconds** without the expected
   Ethernet delivery. Device polling and transfer add a small delay.
3. Before any provisioning script is obtained, USB can collect a discovery
   snapshot with the last recorded network stage. If a script's diagnostic upload
   failed, USB retrieves the original cached snapshot. A timed-out manual network
   request can instead collect a fresh snapshot through USB while still in the
   utility OS.
4. The receiver checks the USB descriptor serial, challenge, boot ID, snapshot ID,
   bounded size and SHA256. The application checks the current run token and
   rechecks delivery before saving. If Ethernet has already delivered that report,
   no USB copy is requested; if it arrives during transfer, the late USB copy is
   ignored. Duplicate events and obsolete run tokens do not replace current data.

### Where to see reports

Open **Diagnostics** in the navigation bar. It lists USB reports and lets you
inspect or download a snapshot. Reports identify their source as **USB** or
**Ethernet**; USB snapshots show their approximate collection time derived from
boot uptime, separately from server receipt time. A module card links to its USB
report, and an unrecognized port links to a recent report with a matching Ethernet
MAC when one is available. These links are observations, not proof that an old
report describes the device currently in that port.

A USB report can arrive **before a module has ever contacted cmprovision over
Ethernet**. It is kept separately by serial and boot/snapshot ID, without creating
a provisioning run or a successful batch result. For a report with a matching
current run token, only the diagnostic snapshot/request fields are updated;
provisioning progress, phase and result are unchanged. Failure findings are also
retained in that module's History. USB report history is bounded to 14 days and
2,000 snapshots; the page lists the latest 50.

### Provisioner service

The package installs **cmprovision-usb-diagnostics**. Settings → Service status
shows it and opens its journal. For a command-line check on the provisioner:

```sh
systemctl status cmprovision-usb-diagnostics --no-pager
sudo journalctl -u cmprovision-usb-diagnostics --since '15 minutes ago' --no-pager
```

The receiver runs as `www-data`, with `dialout` membership confined to that service
and device access restricted to CDC ACM. It only opens devices advertising the
dedicated cmprovision product, so an ordinary USB console or Raspberry Pi
mass-storage gadget is ignored. It sends no shell commands, performs no disk
operations, and passes bounded JSON to the application over a local stdin channel.
The normal authenticated web interface provides access to saved reports.

The service orders itself after `modprobe@cdc_acm.service`, so the device group is
available when its access rule is installed, including before the first hotplug.
This follows the [systemd DeviceAllow guidance](https://www.freedesktop.org/software/systemd/man/latest/systemd.resource-control.html#DeviceAllow=).
The transport uses the [Linux USB serial gadget](https://docs.kernel.org/usb/gadget_serial.html).

A service restart or reconnect does not repeat an already saved snapshot. If no
compatible USB device is present, the receiver waits and Ethernet provisioning
continues normally. Both paths use the same read-only collector. The USB agent
belongs to the temporary utility OS and disappears when the installed OS boots.

### Development checks

On Linux with BusyBox, `python3 -m unittest discover -s tests/tools -p test_usb_diagnostics.py -v`
exercises the real shell agent and receiver through a pseudo-terminal, including
failed Ethernet delivery, framing/identity checks and original snapshot retention.
With the application's development dependencies installed, it also runs the local
Artisan ingestion against a separate temporary SQLite database. The PHP
`UsbDiagnosticsTest` covers run ownership, concurrent Ethernet delivery and the web
page. Utility OS checks additionally validate the built kernel/image and boot it in
QEMU; physical USB enumeration still needs a connected CM4 and a stand acceptance check.

## Trust model

The per-run token binds a snapshot to one provisioning run so that a late report cannot overwrite a newer run. It is not a credential: the module sends it as a query parameter, so it can appear in the provisioner's web server log, and the USB agent announces it to the USB host. Any host on the stand network that learns it can replace that run's snapshot, which is the same trust the stand already places in its network (`/scriptexecute` is open to the stand and the server itself only). A device on the provisioner's USB port can likewise create reports under any serial or MAC; they show only as a USB report on the card and port panel and never change the module's provisioning state.
