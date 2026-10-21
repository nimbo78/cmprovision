# Storage diagnostics

cmprovision collects a read-only snapshot from the target's temporary utility OS at
startup, after a failure, and at completion when the target stays in that OS.
Open **CMs → serial number → Storage diagnostics** for the findings, device facts,
relevant kernel messages and MMC bus state. **Download report** saves a text copy.
A failure diagnosis is also retained in the module history and appears in the dashboard log.

The snapshot does not format, reset, repair or write the target storage. It reads
sysfs and kernel messages; it does not perform a full storage read/write test.
A diagnostic upload has a short timeout, and its failure does not stop provisioning.

## What the findings mean

| Finding | Meaning and next checks |
| --- | --- |
| MMC storage did not initialize | The relevant MMC host reports `Card stuck being busy!` and the configured block device is missing. Fully power off, check seating and power, and compare with another module or card. |
| Configured storage was not detected | The configured path is not a block device. Check the project path; a CM4 Lite requires a microSD card in its carrier, while a CM4 with eMMC uses on-module storage. |
| Storage I/O errors | The kernel reported an error for the target device or its MMC host. Inspect the evidence and compare power, connections and another module/card. |
| Storage reports read-only | The block device reports read-only; check write protection and the storage state. |
| Undervoltage | The firmware reported undervoltage now or earlier in this boot. Check the supply, cable and powered hub. These flags do not measure the eMMC supply directly. |
| eMMC wear warning | The card reports a near-limit lifetime or reserved-block estimate. Retain the report and plan a replacement. |
| Storage detected | The device is present and the collected snapshot contains no recognized warnings. This does not certify its full capacity or write reliability. |

An error during early initialization does not prove that eMMC has failed. For
example, in a cross-check a suspect CM4 may fail on two Mcuzone carriers while
another CM4 exposes its eMMC on both. That localizes the problem to the original
module much more convincingly than a single kernel message.

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

The target must boot Linux and reach cmprovision over Ethernet, including when
[booted over USB](usb-provisioning.md). USB device-descriptor failures, lack of
power, and failures before Linux or DHCP cannot produce a target snapshot. Missing
telemetry alone is not evidence of failed storage; inspect the provisioner's
rpiboot log, cable, power and boot controls in that case.

The collector is part of the provisioning script served by cmprovision, so an
already running older script needs a new boot to gain this feature. The snapshot
is also left on the target at `/tmp/cmprovision-storage-diagnostics.txt` for a
[local-console check](local-console.md). It contains selected device/kernel facts,
not environment variables, project script output or credentials. Reports are
bounded to 32 KiB.
