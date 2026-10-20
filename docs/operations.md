# Operating the provisioner

[Back to the README](../README.md) · [Upgrade and restore](upgrading.md) · [LED signals](leds.md) · [USB provisioning](usb-provisioning.md) · [Local console](local-console.md)

Detailed behaviour of the project options and stand controls. Start with the [installation guide](../README.md#quick-start) if this is a new server.

For a CM4 that cannot enter normal network boot, use [USB provisioning](usb-provisioning.md).
For direct diagnosis on a target's screen and keyboard, use its [tty2 console](local-console.md).
The [WLAN Pi M4+ guide](hardware/wlanpi-m4-plus.md) covers both units' connectors and USB roles.

- [First-boot OS check](#first-boot-os-check)
- [Image download and write recovery](#image-download-and-write-recovery)
- [Re-provision an installed sensor](#re-provision-an-installed-sensor)
- [Built-in Wi-Fi as a cold spare](#built-in-wi-fi-as-a-cold-spare)
- [Wi-Fi networks](#wi-fi-networks)
- [Switches and PoE restart](#switches-and-poe-restart)
- [Network access](#network-access)

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
2. Choose **Check access**. The provisioner tries its restricted SSH key first, then enabled accounts from **Settings → Re-provision accounts**, in their listed order. If none provides access, enter the sensor login and password manually; the account needs sudo access. If a saved or manually entered account requires a password change, enter and repeat a new password. The server verifies the new login before continuing.
3. Review the matched serial, address, disk, CID and next project. Choose **Wipe and re-provision** and confirm. This erases the first MiB of that disk and requests a reboot; it is not a full secure erase.
4. The card waits for the module to return to network boot, then its normal provisioning run starts from the beginning. If SSH disconnects without a confirmed result, the command is not repeated automatically. Wait for boot or perform a fresh access check after 30 seconds if the installed system is still running.

To enable key access for future runs, turn on **Re-provision from the web UI** in the project editor. After the image's verification and post-install scripts, the provisioner adds its public key and `/usr/local/sbin/cmprovision-agent` to the written root. The key uses a forced command and cannot provide an ordinary shell or forwarding. The option defaults to off and does not change the image's SSH login policy: files can be installed while root key login is disabled. Installation failures are nonfatal and appear as `agent skipped` in the post-install log.

**Settings → Re-provision key** shows the public key. Its private part stays in `storage/app/ssh/id_ed25519`; backups and `restore --data-only` preserve it. Protect those backups as server credentials. Manual key installation requires the matching agent and a restricted `authorized_keys` entry, not a plain unrestricted key.

An access check lasts two minutes and is tied to the operator's web session. Passwords are kept encrypted on the server for that interval and are cleared when the dialog is cancelled or the command is dispatched. The SSH host key is remembered for the operation, so changing devices between its steps is refused; the initial connection relies on the isolated stand network.

If the active project or its configuration changes, the previous confirmation no longer authorizes that project. A module returning after the erase waits instead of receiving an unexpected configuration. Review the new active project, use **Confirm current project for next boot** on its card, then restart it through PoE if it is waiting in the utility OS. Existing records with no stored disk path are accepted only when the sensor's root disk can be determined and matched to its recorded identity; no disk path is guessed.

### Saved sensor accounts

**Settings → Re-provision accounts** lets you add, edit, enable, disable, delete and reorder sensor logins. The initial list enables `wlanpi / wlanpi` for [WLAN Pi](https://userguide.wlanpi.com/wlan-pi-os/faq/default-password). The `pi / raspberry` profile starts disabled for legacy Raspberry Pi OS images; [current images require a user configured at installation](https://www.raspberrypi.com/news/raspberry-pi-bullseye-update-april-2022/).

Changes require your web account password. Saved passwords are encrypted with the provisioner's APP_KEY; they never return to the browser. An empty password when editing keeps the stored one. Backups preserve the list together with its matching APP_KEY. Changing one sensor's expired password does not replace a shared saved password.

Keep up to 10 profiles, with at most 3 enabled for automatic checks. Enabled profiles run in order, identical credentials are tried once, and the first usable login with a matching device and sudo access wins. Automatic attempts run only on the first **Check access** of an operation, at most once per minute per module; manual entry remains available during that interval. A changed SSH host key, network failure or mismatched hardware stops the sequence. Finding a password does not erase anything: the normal identity review and separate wipe confirmation still apply.

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
- Every switch goes to the journal: `journalctl -t cmprovision-uplink`, or Settings → Service status → uplink cold spare.
- `SPARE=no` in `/etc/default/cmprovision-uplink` keeps the built-in Wi-Fi connected all the time.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.

## Wi-Fi networks

Settings → Wi-Fi networks lists the networks the provisioner connects to, the preferred first, and the Wi-Fi profiles it leaves to other tools (greyed). Each network is one NetworkManager profile that serves both radios: the card carries the traffic, the built-in Wi-Fi stands by as the cold spare.

- **Add network** takes the name (SSID; **Find networks** lists the networks in reach), the security (WPA2-Personal, WPA2/WPA3 mixed or WPA3-Personal), the password, and whether the network is hidden. Every change asks for your account password first.
- The password is stored on the server and never shown again; left empty when editing, it keeps the stored one. A new password of the network in use takes effect at its next connection, so it can be entered before the router changes.
- **Check now** tries a network on the idle built-in Wi-Fi while the card keeps working: connected, wrong password, not found, no address. A network only the card can reach (6 GHz, say) shows as not found and can be saved all the same.
- The order (↑ ↓) decides which network the provisioner joins when it connects; it does not leave a working network for a higher one. The network in use cannot be deleted. **Changes log** shows every change (`journalctl -t cmprovision-wifi`).
- A provisioner set up with Raspberry Pi Imager keeps its Wi-Fi in a netplan profile, shown greyed: add the same network here, check it, then remove the old profile with `sudo nmcli connection delete <name>`.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS the section says it is not available.

## Switches and PoE restart

Settings → Ethernet switches lists the switches of the stand; **Add switch** takes a switch's address and SNMP read access (a community for v2c, a user for v3). With them a module gets its switch and port as its board, `sw01 Gi0/3` (the switch's sysName before the first dot, then the port's description or name), and the Ports page shows each switch's front panel port by port. A port that leads to another switch of the list is a link, never a module's port: it shows that switch's own MAC (its base MAC, the MAC of its VLAN interface, or the MAC of its address in the provisioner's ARP cache). The same switch cannot be added twice under two addresses. Two switches with the same sysName give boards that look the same: Settings and the Ports page warn about it, rename one on the switch.

Write access is optional, set for each switch, and only used to restart modules over PoE: a write community for v2c, or a user of its own for v3. **Check write access** writes one PoE port's current value back, so it changes nothing. With write access, the module card, the Ports page and the dashboard get a **Restart** button: the switch turns the port's PoE off for 5 seconds and on again. It is offered only on ports with one of the provisioner's modules (or a Raspberry Pi still booting) and nothing else, and not while the module writes its bootloader EEPROM. A port that does not come back on is listed on the dashboard, the Ports page and the module card with **Power on** until it does.

## Network access

Connect the provisioner's `eth0` only to the isolated provisioning switch. Use Wi-Fi for the web interface and the upstream network.

The modules' `/scriptexecute` endpoint has no login: it accepts only clients on `172.20.0.0/16` and the server itself. Other networks receive HTTP 403. Keep nginx connected directly to the clients; do not put a reverse proxy in front of this endpoint or configure nginx `set_real_ip_from` / `real_ip_header`, which would make the client address depend on a forwarded header.

Files under `/uploads`, including OS images and packages, can be downloaded by anyone who can reach the provisioner's web server. Keep secrets out of these files and restrict access to the server.
