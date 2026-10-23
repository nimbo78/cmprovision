# Unrecognized devices on the stand

[Back to the README](../README.md) · [Module diagnostics](module-diagnostics.md) · [USB provisioning](usb-provisioning.md)

**Unrecognized** means that the switch reports a MAC address on a live port, but
cmprovision has not matched it to a module. The port tile says **UNR**.
Older versions called this **foreign** and displayed **MAC**; neither term was a
hardware diagnosis. A device with a link but no learned MAC is shown as **LNK**
and shares the unrecognized filter.

No manual registration is required. A new module is added automatically when its
temporary utility OS requests the provisioning script with its serial and MAC.
A switch's MAC table alone does not supply that serial. A DHCP lease alone is
also not registration, and a module can be added even if provisioning is then
refused, for example because there is no active project or storage is missing.

A recognized Raspberry Pi MAC on a link up for at most 180 seconds is initially
shown as **booting**. If it has not been matched after that window, it becomes
**unrecognized**. An unknown link age or an unrecognized MAC prefix can skip that
initial window. This timeout changes the display; it does not establish where
boot stopped. Unrecognized ports are not completed modules and do not keep the
batch's in-progress counter running indefinitely.

## Investigate a new M4+ on wlanpi-da2

Leave the target connected in its current state and note its **switch/port, MAC
and connection time**. Start with observations on the provisioner, preserving the
original boot attempt. A reboot or USB recovery boot is a separate step after
collecting those observations.

| Last observation | What it establishes | Next check |
| --- | --- | --- |
| PoE delivery, no Ethernet link | The switch reports power delivery; no data link is established. | Power, cable, connector and switch port. PoE watts do not certify stable module power. |
| Link and learned MAC | The switch has learned Ethernet traffic; its MAC table can retain an earlier device's address. | Compare a fresh switch snapshot with DHCP and packet timestamps. |
| DHCP request / lease | The device is attempting address configuration / obtained a lease. An installed OS can do this too. | DHCP boot options and TFTP requests distinguish an attempted network boot from ordinary OS networking. |
| Requests for boot files | The device started a network boot. | Requested filenames, missing-file errors and transfer progress. File delivery does not prove Linux started. |
| Linux reached DHCP but no script request | A second DHCP exchange can belong to Linux, but logs alone may not identify it conclusively. | Target console and connectivity to the provisioner's HTTP service. |
| HTTP request to `/scriptexecute` | The target reached the application. | Reported serial/MAC, HTTP result, application log and module card. HTTP 200 can contain a refusal script. |
| Installed WLAN Pi OS is reachable | The target may have booted its existing installation instead of the utility OS. | Read its hardware identity and boot configuration using an approved login; use the separate re-provision or USB recovery workflow as appropriate. |
| No useful network evidence | The network path did not identify the stopping point. | HDMI/tty2 or USB recovery/serial diagnostics, with the limits below. |

The first pass can be performed remotely over SSH to wlanpi-da2. The operator only
needs to keep the board connected and identify the port; physical jumper, cable,
power and screen checks may still be needed later. A switch port/MAC and hostname
are clues, not sufficient proof of a module's hardware serial.

## Read-only checks on the provisioner

From the management computer, connect with `ssh da2`. Run the following Linux
commands **on the provisioner**. Replace the example MAC with the one shown for
the affected port, and use the actual connection time for the log window.

```sh
mac='e4:5f:01:62:9f:d4'
systemctl is-active cmprovision-dnsmasq cmprovision-rpiboot nginx
sudo awk -v mac="$mac" 'tolower($2) == mac {print}' /var/lib/cmprovision/etc/dnsmasq.leases
ip -4 neigh show dev eth0
sudo journalctl -u cmprovision-dnsmasq --since '15 minutes ago' --no-pager -n 300
sudo journalctl -u cmprovision-rpiboot --since '15 minutes ago' --no-pager -n 100
```

A dnsmasq lease lists expiry, MAC, IPv4 address, hostname and client ID. Check
expiry and correlate it with the current port; an old lease or neighbour entry
is not evidence that the target is reachable now. If dnsmasq messages are absent
from the unit journal, also check its syslog identifiers:

```sh
sudo journalctl -t dnsmasq -t dnsmasq-dhcp -t dnsmasq-tftp --since '15 minutes ago' --no-pager -n 300
```

For the IPv4 address obtained above, inspect recent HTTP requests. This example
uses the default nginx access log and redacts run tokens before displaying them.
A missing line can also mean that the log is disabled, rotated or outside the
selected window; it does not by itself identify the failed stage.

```sh
ip='172.20.108.22'
sudo tail -n 2000 /var/log/nginx/access.log | grep -F -- "$ip " | sed -E 's/(diagnostics_token|identify_run)=[^&[:space:]]+/\1=REDACTED/g' | tail -n 20
sudo tail -n 100 /var/lib/cmprovision/storage/logs/laravel.log
```

If the logs do not distinguish the stages, a short packet capture during a
controlled new boot can show DHCP, TFTP and HTTP traffic. `tcpdump` is optional
and may need installation on the provisioner. Restrict captures to the incident
and stop them after a bounded interval. Retain relevant timestamps, filenames,
HTTP codes and module errors for comparison; review logs before sharing them.

## Console and USB fallback

The [local console](local-console.md) can show whether Linux booted and whether
`eth0`, its carrier and a DHCP address exist. A [USB recovery boot](usb-provisioning.md)
can bypass a boot-order problem. USB provisioning still needs Ethernet for its script and image; diagnostic
reports can instead use the automatic USB fallback in utility OS 2026.10.1.

A manual USB serial report is possible when the loaded USB image provides a
serial gadget, as with Raspberry Pi's mass-storage gadget. It is a separate
workflow from the packaged cmprovision USB boot image. A missing eMMC block
device does not itself prevent a RAM-based Linux diagnostic report.

The [automatic USB report fallback](module-diagnostics.md#automatic-usb-fallback)
uses Ethernet first, then USB after 90 seconds without the expected network
report. It requires utility OS 2026.10.1, a Device-mode USB data connection and
the receiver service on the provisioner. Open **Diagnostics** even if the board
has no provisioning record yet; a USB snapshot does not start a flash or mark
the board done. An unrecognized port links to a recent matching-MAC USB report
when possible.

If Linux never starts or USB cannot enumerate, the target cannot send its own
report. Host-side USB/rpiboot events can still narrow the stopping point, while
earlier kernel failures may require HDMI or hardware UART.
