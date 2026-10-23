# Operating the provisioner

[Back to the README](../README.md) · [Upgrade and restore](upgrading.md) · [LED signals](leds.md) · [USB provisioning](usb-provisioning.md) · [Local console](local-console.md)

Detailed behaviour of the project options and stand controls. Start with the [installation guide](../README.md#quick-start) if this is a new server.

For a CM4 that cannot enter normal network boot, use [USB provisioning](usb-provisioning.md).
For direct diagnosis on a target's screen and keyboard, use its [tty2 console](local-console.md).
The [WLAN Pi M4+ guide](hardware/wlanpi-m4-plus.md) covers both units' connectors and USB roles.

- [How a module is named](#how-a-module-is-named)
- [The Settings page](#the-settings-page)
- [First-boot OS check](#first-boot-os-check)
- [Images from the internet](#images-from-the-internet)
- [Image download and write recovery](#image-download-and-write-recovery)
- [Re-provision an installed sensor](#re-provision-an-installed-sensor)
- [Built-in Wi-Fi as a cold spare](#built-in-wi-fi-as-a-cold-spare)
- [Wi-Fi networks](#wi-fi-networks)
- [Switches and PoE restart](#switches-and-poe-restart)
- [Network access](#network-access)

## How a module is named

A module is known by its **MAC address** (the one of its Ethernet port, which belongs to the module and stays with it on
any carrier board) and, once the system written to it has started, by its **host name**. Every page, the HDMI display
and the messages to chats show the host name when there is one and the MAC address when there is not; the MAC is shown
beside the host name. The **serial number** of the board is the small print: the Serial column of the CMs list, a row on
the module card, `S/N` on the HDMI device card, `serial` in the JSON of a webhook. The label placeholders `$mac`,
`$serial` and `$hostname` all stay. The address of a module card, `/cms/<serial>`, is the same as before.

## The Settings page

Settings has four tabs, each at its own address (`/settings?tab=network`), so a link or a bookmark opens the part you mean:

| Tab | Holds |
| --- | --- |
| **Status** | The services of the server (dnsmasq, rpiboot, the USB diagnostics receiver, both queue workers, the cold spare, the web interface) with a running or stopped chip and **View log** for each, and the utility OS modules get now. |
| **Network** | Wi-Fi networks, Ethernet switches, DHCP static addresses. |
| **Provisioning** | Utility OS, Re-provision (the provisioner key and the saved sensor accounts), the HDMI display. |
| **Notifications** | Notification channels and the current batch. |

On every tab except Status a strip at the top shows the same services in one line (a word beside each colour, "N of 7 running") and the **Logs** menu with the journal of each. It refreshes by itself every 30 seconds, so a stopped queue worker is seen whichever tab you are on. Each section has one line of what it is, its actions at the right, and a longer explanation behind **How it works**. Only the open tab is read: Wi-Fi and switches are asked about on Network only.

Links from before the tabs (`/settings#wifi`, `#reprovision-key`, `#utility-os`, `#hdmi-display`) still work: the page takes them to the tab that holds the section.

## First-boot OS check

A project with **Check the OS after the first boot** does not stop when the image is written. The module reboots into its new system right on the stand, where a one-shot service checks it:

1. After the post-install scripts the utility OS mounts the written root partition (the image's second partition, ext4) and adds the service `cmprovision-firstboot`, with the project's first-boot scripts and packages.
1. The module reboots. Its bootloader must try eMMC before the network (`BOOT_ORDER`, e.g. `0xf21`); otherwise the module comes back to network boot and the run fails with "the new system did not start".
1. The new system gets its address from the stand's DHCP and waits for its boot to finish (at most 5 minutes). Then it installs the project's packages with one `apt-get` and runs the first-boot scripts, 2 minutes each; a non-zero exit fails the check.
1. It reports its host name, the systemd state and the failed units. The service removes its unit, enablement link, scripts and packages; its log stays in `/var/log/cmprovision-firstboot.log`, readable by root alone: it keeps the text of the first-boot scripts.
1. Only now is the run done. The label can print `$hostname` (label moment "After the OS check"), and the notifications go out. Without a report in 15 minutes the run fails.
1. The module keeps running and shows the result on its LEDs: ACT and PWR fade in and out together when the check passed, the error pattern when it failed. From 2.6.4, it also asks the server about **Identify** every 5 seconds. Identify uses the existing 30-second fast pattern; **Stop**, expiry or a failed request restores the check's result. While blinking, it polls every 2 seconds.

Identify after the OS check lasts **until the next reboot**. Only the LED helper is retained in a private temporary directory under `/run`; no permanent service or SSH access is added, and packages/scripts cannot run again on reboot. The module card and dashboard offer Identify only while heartbeats are recent (within 2 minutes). Older first-boot scripts do not poll: upgrading the server alone cannot add this to an already running system; provision it again with the updated server.

- **First-boot scripts** are a script type of their own (Scripts → first boot): they run as root in the new system, with `$SERVER` set.
- **Packages** (Scripts → Packages) are `.deb` files uploaded to the server. The stand has no internet, so apt downloads nothing: whatever a package depends on must be in the image or be uploaded too, and a missing dependency fails the check. Recommended packages are not installed. The list shows each package's architecture: it must be the image's (`arm64` for a 64-bit image, `armhf` for a 32-bit one) or `all`.
- Anyone who reaches the provisioner's web server can download the files under `/uploads`, the images included: keep secrets out of packages.
- The new system needs systemd and curl. A module booted over USB (rpiboot) is not checked: a reboot would bring it back to USB boot.

## Re-provision an installed sensor

Use **CMs → module → Re-provision…** when a sensor has booted its installed OS and needs a new image. The first implementation supports eMMC/SD, an MBR partition table and an ext4 root on partition 2. SSH must be running; the bootloader must fall back to network boot when the disk is no longer bootable. A module still in the utility OS can be restarted through PoE instead.

1. Select the active project and open the module card. The module needs a current DHCP lease on the stand network and must not be writing an image or running its first-boot check.
2. Choose **Check access**. The provisioner tries its restricted SSH key first, then enabled accounts from **Settings → Provisioning → Re-provision (sensor accounts)**, in their listed order. If none provides access, enter the sensor login and password manually; the account needs sudo access. If a saved or manually entered account requires a password change, enter and repeat a new password. The server verifies the new login before continuing.
3. Review the matched serial, address, disk, CID and next project. Choose **Wipe and re-provision** and confirm. This erases the first MiB of that disk and requests a reboot; it is not a full secure erase.
4. The card waits for the module to return to network boot, then its normal provisioning run starts from the beginning. If SSH disconnects without a confirmed result, the command is not repeated automatically. Wait for boot or perform a fresh access check after 30 seconds if the installed system is still running.

To enable key access for future runs, turn on **Re-provision from the web UI** in the project editor. After the image's verification and post-install scripts, the provisioner adds its public key and `/usr/local/sbin/cmprovision-agent` to the written root. The key uses a forced command and cannot provide an ordinary shell or forwarding. The option defaults to off and does not change the image's SSH login policy: files can be installed while root key login is disabled. Installation failures are nonfatal and appear as `agent skipped` in the post-install log.

**Settings → Provisioning → Re-provision (provisioner key)** shows the public key. Its private part stays in `storage/app/ssh/id_ed25519`; backups and `restore --data-only` preserve it. Protect those backups as server credentials. Manual key installation requires the matching agent and a restricted `authorized_keys` entry, not a plain unrestricted key.

An access check lasts two minutes and is tied to the operator's web session. Passwords are kept encrypted on the server for that interval and are cleared when the dialog is cancelled or the command is dispatched. The SSH host key is remembered for the operation, so changing devices between its steps is refused; the initial connection relies on the isolated stand network.

If the active project or its configuration changes, the previous confirmation no longer authorizes that project. A module returning after the erase waits instead of receiving an unexpected configuration. Review the new active project, use **Confirm current project for next boot** on its card, then restart it through PoE if it is waiting in the utility OS. Existing records with no stored disk path are accepted only when the sensor's root disk can be determined and matched to its recorded identity; no disk path is guessed.

### Saved sensor accounts

**Settings → Provisioning → Re-provision (sensor accounts)** lets you add, edit, enable, disable, delete and reorder sensor logins. The initial list enables `wlanpi / wlanpi` for [WLAN Pi](https://userguide.wlanpi.com/wlan-pi-os/faq/default-password). The `pi / raspberry` profile starts disabled for legacy Raspberry Pi OS images; [current images require a user configured at installation](https://www.raspberrypi.com/news/raspberry-pi-bullseye-update-april-2022/).

Changes require your web account password. Saved passwords are encrypted with the provisioner's APP_KEY; they never return to the browser. An empty password when editing keeps the stored one. Backups preserve the list together with its matching APP_KEY. Changing one sensor's expired password does not replace a shared saved password.

Keep up to 10 profiles, with at most 3 enabled for automatic checks. Enabled profiles run in order, identical credentials are tried once, and the first usable login with a matching device and sudo access wins. Automatic attempts run only on the first **Check access** of an operation, at most once per minute per module; manual entry remains available during that interval. A changed SSH host key, network failure or mismatched hardware stops the sequence. Finding a password does not erase anything: the normal identity review and separate wipe confirmation still apply.

## Images from the internet

**Add from the internet** on the Images page makes the provisioner fetch an image itself instead of you uploading it from your computer. There are three sources:

- **GitHub release.** Type `owner/name` (for example `WLAN-Pi/pi-gen`); the releases and their images are listed, pre-releases on request. The repositories you used stay in a list of their own.
- **Raspberry Pi OS.** The images of the official Raspberry Pi list (64 and 32 bit, Lite and Full, legacy).
- **Any link.** An https address of a `.gz`, `.xz`, `.bz2` or `.zip` file, and optionally its checksum (64 hex digits, or the address of a `.sha256` / `SHA256SUMS` file).

How the fetch goes:

- The image appears in the list with a progress bar, the speed and the time left. A broken connection is tried again after 5, 15, 30, 60 and then every 120 seconds, and the fetch goes on from the byte it has, also after a restart or a kill of the queue worker; the row says why it waits. (A server that cannot resume a download can only deliver a file within one slice of about a minute; the window warns about it, and a longer fetch from such a server fails with that reason.) Twelve failures in a row (about a quarter of an hour without the connection), or twelve slices of about a minute without a byte, fail the image; it says why, and **Retry** goes on from what is on the disk. **Delete** cancels a fetch and removes what it left.
- The fetch runs in slices of about a minute on the queue of the image checksums, so the checksums and pieces copies of other images are not held up by a download that lasts for hours.
- The image cannot be chosen in a project, and a module does not write it, until it is fetched **and checked**. Uploaded images are ready as soon as they are stored, as before.
- What is checked: the downloaded file against the checksum the source published (the GitHub digest, a `.sha256` file next to the file, the checksum list of the release, or the checksum you gave), and for Raspberry Pi OS also the unpacked image against the checksum of the list. A file that is not the published one, or that does not unpack, is deleted and the image says so; Retry then fetches it again. An image without any published checksum is taken only when you tick **Add without a checksum**; the page then says "no published checksum".
- A `.zip` is repacked into an `.img.gz` on the server (a module can only stream gz, xz and bz2): the image is read from the archive straight into gzip, so no unpacked copy lies on the disk; the archive is deleted when the new file is whole and checked. The archive must hold exactly one `.img` file. On a CM4 this takes minutes for a 6 GB image; the Images page shows the share done.
- Only https addresses of public servers are accepted. Addresses that point into a private or local network (including the stand's 172.20.0.0/16), numeric hosts and addresses with a user name or password are refused, on every redirect too.
- GitHub allows 60 requests an hour without a token: the lists are kept for five minutes, and when the limit is reached the window says when to come back.
- Fetching needs a 64-bit PHP; on a 32-bit system the function is off.

The disk of the provisioner is small (29 GB on the stand's M4+), and the Images page shows what is free. Before a fetch starts the server checks that the file fits with 2 GiB to spare, counting what the other fetches still have to write, a `.zip` with room for its repacked file; if it does not, the message says what is missing. **Delete unused images** removes the images that no project takes (and no module is writing), their pieces copies and the leftovers of failed fetches, after a confirmation that names the number and the size.

## Image download and write recovery

A broken connection no longer fails the write. The module downloads, decompresses and writes the image in one stream; when the connection breaks (a switch hiccup, an nginx restart, a re-plugged cable), curl starts again from the byte it got to while the decompressor and `dd` keep running. Server errors (5xx) and lost connections are retried every 5 seconds; after 10 minutes without a byte the write fails, and an answer like 404 (the image was deleted) fails it at once.

The partition table (the first MiB of the image) is cleared before the write and written last. A module that loses power mid-write, a PoE restart included, finds no partition table on its eMMC and boots from the network again instead of a half-written image.

A project with **Split the image into 512 MB pieces** goes further: a module restarted during the write, or after a failed write, continues from its last verified piece and downloads only the rest.

- The server keeps a gzip copy of the image in pieces next to the original, made in the background after the project is saved (about 5 minutes for a 6 GB image on a CM4); the Images page shows its state. Until it is ready the project writes the original, and a restart starts over.
- A piece counts once the module has written it with `fsync` and the decompressor has checked its checksum; the run goes on from the first piece that does not count. Turn on **Verify that image was written correctly** too: it checks the whole image, including the part written before the restart.
- The module card shows where the next start continues; **Start over** makes it write from the beginning instead.
- A module whose storage was written in between (flashed elsewhere, another SD card) does not continue onto it: the image is written from the beginning, at the latest on the next start.
- For an `.xz` or `.bz2` image the copy is gzip, so the download grows.

## Built-in Wi-Fi as a cold spare

A provisioner with a better Wi-Fi card (an M.2 card, a USB adapter) keeps its built-in Wi-Fi off the network: while another device carries the default route, the built-in Wi-Fi stays disconnected. When there has been no other default route for 30 seconds (the card was removed, its driver failed, it cannot connect), the built-in Wi-Fi connects on its own, and once the card has carried the default route again for a minute, it leaves. Only the device is disconnected, never a NetworkManager profile: after a reboot both radios connect, and the built-in Wi-Fi leaves a minute later.

- The provisioner's address changes with the radio that carries the traffic; `<hostname>.local` (avahi) follows it.
- Every switch goes to the journal: `journalctl -t cmprovision-uplink`, or Settings → Status → uplink cold spare.
- `SPARE=no` in `/etc/default/cmprovision-uplink` keeps the built-in Wi-Fi connected all the time.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.

## Wi-Fi networks

Settings → Network → Wi-Fi networks lists the networks the provisioner connects to, the preferred first, and the Wi-Fi profiles it leaves to other tools (greyed). Each network is one NetworkManager profile that serves both radios: the card carries the traffic, the built-in Wi-Fi stands by as the cold spare.

- **Add network** takes the name (SSID; **Find networks** lists the networks in reach), the security (WPA2-Personal, WPA2/WPA3 mixed or WPA3-Personal), the password, and whether the network is hidden. Every change asks for your account password first.
- The password is stored on the server and never shown again; left empty when editing, it keeps the stored one. A new password of the network in use takes effect at its next connection, so it can be entered before the router changes.
- **Check now** tries a network on the idle built-in Wi-Fi while the card keeps working: connected, wrong password, not found, no address. A network only the card can reach (6 GHz, say) shows as not found and can be saved all the same.
- The order (↑ ↓) decides which network the provisioner joins when it connects; it does not leave a working network for a higher one. The network in use cannot be deleted. **Changes log** shows every change (`journalctl -t cmprovision-wifi`).
- A provisioner set up with Raspberry Pi Imager keeps its Wi-Fi in a netplan profile, shown greyed: add the same network here, check it, then remove the old profile with `sudo nmcli connection delete <name>`.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS the section says it is not available.

## Switches and PoE restart

Settings → Network → Ethernet switches lists the switches of the stand; **Add switch** takes a switch's address and SNMP read access (a community for v2c, a user for v3). With them a module gets its switch and port as its board, `sw01 Gi0/3` (the switch's sysName before the first dot, then the port's description or name), and the Ports page shows each switch's front panel port by port. A port that leads to another switch of the list is a link, never a module's port: it shows that switch's own MAC (its base MAC, the MAC of its VLAN interface, or the MAC of its address in the provisioner's ARP cache). The same switch cannot be added twice under two addresses. Two switches with the same sysName give boards that look the same: Settings and the Ports page warn about it, rename one on the switch.

Verified switch models use the physical positions and printed port numbers of their front panel. On a phone, scroll the panel sideways to keep that order. Other models show **Automatic port order**. Supported panels and manufacturer references: [Switch front panels](switch-front-panels.md).

Write access is optional, set for each switch, and only used to restart modules over PoE: a write community for v2c, or a user of its own for v3. **Check write access** writes one PoE port's current value back, so it changes nothing. With write access, the module card, the Ports page and the dashboard get a **Restart** button: the switch turns the port's PoE off for 5 seconds and on again. It is offered only on ports with one of the provisioner's modules (or a Raspberry Pi still booting) and nothing else, and not while the module writes its bootloader EEPROM. A port that does not come back on is listed on the dashboard, the Ports page and the module card with **Power on** until it does.

## Network access

Connect the provisioner's `eth0` only to the isolated provisioning switch. Use Wi-Fi for the web interface and the upstream network.

The modules' `/scriptexecute` endpoint has no login: it accepts only clients on `172.20.0.0/16` and the server itself. Other networks receive HTTP 403. Keep nginx connected directly to the clients; do not put a reverse proxy in front of this endpoint or configure nginx `set_real_ip_from` / `real_ip_header`, which would make the client address depend on a forwarded header.

Files under `/uploads`, including OS images and packages, can be downloaded by anyone who can reach the provisioner's web server. Keep secrets out of these files and restrict access to the server.

## Unrecognized devices

On Ports, **Unrecognized** / **UNR** means that a MAC has not been matched to a
module. A new module is added automatically when its utility OS requests
the provisioning script; no manual registration is needed. The initial booting
window is 180 seconds for recognized Raspberry Pi MACs with a known link age.
The later unrecognized state does not prove a hardware fault. Follow the
[discovery troubleshooting guide](unrecognized-devices.md) to check the switch,
DHCP, boot-file delivery, HTTP and console evidence on wlanpi-da2.

## Module diagnostics

Open the module card's **Module diagnostics** section for storage, power and temperature, RAM availability, Ethernet and boot information. It shows measurements, kernel evidence and suggested checks, and can collect a fresh snapshot while the target waits in the utility OS. See [Module diagnostics](module-diagnostics.md) for interpretation and limitations.
