# Module diagnostics

cmprovision collects a read-only snapshot from the target's temporary utility OS at
startup, after a failure, and at completion when the target stays in that OS.
Open **CMs → serial number → Module diagnostics** for a summary of storage, power
and temperature, memory, Ethernet, and boot/system information. Expand **Device
facts and kernel output** for evidence. **Download report** saves a text copy;
failure findings are also retained in module History and the dashboard log.

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

The target must boot Linux and reach cmprovision over Ethernet, including when
[booted over USB](usb-provisioning.md). USB device-descriptor failures, lack of
power, and failures before Linux or DHCP cannot produce a target snapshot. Missing
telemetry alone is not evidence of failed hardware. Inspect the provisioner's
rpiboot log, USB cable, power and boot controls in that case. This section does not
yet collect host-side rpiboot/USB logs or diagnose the provisioner itself.

The collector is part of the provisioning script served by cmprovision, so an
already running older script needs a new boot to gain new readings. The snapshot
is also left on the target at `/tmp/cmprovision-module-diagnostics.txt` for a
[local-console check](local-console.md). It contains selected device/kernel facts
and the stand IPv4 configuration, not environment variables, project script
output or EEPROM configuration credentials. Review it before sharing publicly.
Reports are bounded to 32 KiB. Available sensors depend on the utility OS and
hardware; no utility OS image rebuild is needed for the server-generated collector.

## Planned USB fallback

USB report delivery is not implemented yet. Its intended policy is **Ethernet
first, USB only when the Ethernet report cannot be obtained**, regardless of how
the utility OS was booted.

- A successful Ethernet report needs no duplicate USB collection.
- If the expected Ethernet report does not arrive within a bounded wait, or a
  requested network collection times out, try the USB diagnostic channel when
  connected. Otherwise offer the operator a USB diagnostic/recovery path.
- Use the same collector and report format for both channels. Show the source
  (`Ethernet` or `USB`) and collection time, and keep reports tied to the correct
  module and boot/run. A late report must not overwrite a newer run's snapshot.
- Switching the report channel must not itself restart a module or interrupt
  provisioning. A USB recovery boot is a separate operator action.

The collector will need to be built into the utility OS and run before its wait
for Ethernet/DHCP, so an unavailable network cannot prevent local collection.
Transferring that snapshot over USB is the fallback. This requires an updated
utility OS and a USB receiver on the provisioner; the current server-generated
collector alone cannot provide it. Missing Ethernet telemetry is a reason to
try another channel, not proof of defective hardware.
