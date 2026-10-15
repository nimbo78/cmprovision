<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/brand/cmprovision-horizontal-white.svg">
  <img src="docs/brand/cmprovision-horizontal.svg" alt="cmprovision" height="48">
</picture>

# Raspberry Pi Compute Module Provisioning System #

Web application to mass program compute modules; designed to run on a Raspberry Pi 4. Compute Module 4 is supported out-of-the-box; Compute Module 3 and 3+ are not supported since 2.5.1 (for them use v1.6.11 of this fork on Raspberry Pi OS with dhcpcd, or upstream raspberrypi/cmprovision).
Pi 4 boards can also be provisioned, but that requires that network boot is enabled in EEPROM settings first.

![screenshot](https://user-images.githubusercontent.com/1123701/209007803-7cc38d14-7f07-4910-8108-87323d25ac20.png)

This is a maintained fork of [raspberrypi/cmprovision](https://github.com/raspberrypi/cmprovision) (last upstream release: v1.6.3, 2023).
It fixes the open upstream issues around EEPROM firmware downloads, silent provisioning failures and current Raspberry Pi OS releases; see `debian/changelog` for the full list and the [Upgrading](#upgrading) section before installing over an existing server.

## Documentation ##

Full documentation is available on the Product Information Portal: [Using the Compute Module Provisioner.pdf](https://pip.raspberrypi.com/categories/685-whitepapers-app-notes/documents/RP-003468-WP/Using-the-Compute-Module-Provisioner.pdf)

## Provisioning Compute Module 4 Devices ##

This process requires a Raspberry Pi 4 which will be used solely to mass program compute modules. Make sure eth0 connects to an Ethernet switch that only has the compute modules you wish to program connected.

**WARNING:** Do NOT connect eth0 to your office/public network, since it may also provision other Raspberry Pi devices. Instead, use the wireless LAN interface to access the web interface, and your local network.

The modules' endpoint `/scriptexecute` takes no login, so it answers only the stand network 172.20.0.0/16 and the server itself: a request from any other network, the office included, gets 403. Keep nginx talking to the clients directly: a reverse proxy in front of it, or nginx's realip module (`set_real_ip_from`, `real_ip_header`), would make the client address come from a header.

1. Start with a fresh 64-bit Raspberry Pi OS (Lite) installation. For simplicity, use [Raspberry Pi Imager](https://www.raspberrypi.com/software/) and the advanced settings menu (Ctrl-Shift-X) to set the password, hostname and wireless LAN configuration.  
    
    **NOTE:** If you intend to write images larger than 2 GB, you MUST install the **64-bit** edition of Raspberry Pi OS (Lite), which is available in Raspberry Pi Imager under the category "Raspberry Pi OS (other)" -> "Raspberry Pi OS Lite (64-bit)".

1. Configure eth0 to have a static IP of 172.20.0.1 inside a /16 subnet (netmask 255.255.0.0). Do not set a default gateway.

    On Raspberry Pi OS **Trixie** the network is managed by NetworkManager:

    ```
    sudo nmcli connection add type ethernet ifname eth0 con-name cmprovision         ipv4.method manual ipv4.addresses 172.20.0.1/16 ipv4.never-default yes         ipv6.method link-local ipv6.addr-gen-mode eui64 connection.autoconnect yes
    sudo nmcli connection up cmprovision
    ```

1. Run `sudo apt update` to make sure the package information on your Pi is up to date, and it is able to install dependencies.

1. Install the ready-made .deb package from https://github.com/raspberrypi/cmprovision/releases/:  

    ```
    sudo apt install ./cmprovision4_*_all.deb  
    ```

1. Set a web application username and password with:  

    ```
    sudo -u www-data /var/lib/cmprovision/artisan auth:create-user  
    ```

You can now access the web interface with a web browser on the wireless LAN IP address.

## Provisioning Raspberry Pi 4 boards ##

It is also possible to use the system to provision regular Raspberry Pi 4 boards, instead of CM4 modules.
However that requires that network boot is enabled first in the EEPROM settings of each Pi 4.

You can create a SD card that enables that with [Imager](https://www.raspberrypi.com/software/), by going to: "Choose OS" -> "Misc utility images" -> "Bootloader" -> "Network boot"

## The OS check after the first boot ##

A project with **Check the OS after the first boot** does not stop when the image is written. The module reboots into its new system right on the stand, where a one-shot service checks it:

1. After the post-install scripts the utility OS mounts the written root partition (the image's second partition, ext4) and adds the service `cmprovision-firstboot`, with the project's first-boot scripts and packages.
1. The module reboots. Its bootloader must try eMMC before the network (`BOOT_ORDER`, e.g. `0xf21`); otherwise the module comes back to network boot and the run fails with "the new system did not start".
1. The new system gets its address from the stand's DHCP and waits for its boot to finish (at most 5 minutes). Then it installs the project's packages with one `apt-get` and runs the first-boot scripts, 2 minutes each; a non-zero exit fails the check.
1. It reports its host name, the systemd state and the failed units. The service then removes itself; its log stays in `/var/log/cmprovision-firstboot.log`, readable by root alone: it keeps the text of the first-boot scripts.
1. Only now is the run done. The label can print `$hostname` (label moment "After the OS check"), and the notifications go out. Without a report in 15 minutes the run fails.
1. The module keeps running and shows the result on its LEDs: ACT and PWR fade in and out together when the check passed, the error pattern when it failed.

- **First-boot scripts** are a script type of their own (Scripts → first boot): they run as root in the new system, with `$SERVER` set.
- **Packages** (Scripts → Packages) are `.deb` files uploaded to the server. The stand has no internet, so apt downloads nothing: whatever a package depends on must be in the image or be uploaded too, and a missing dependency fails the check. Recommended packages are not installed. The list shows each package's architecture: it must be the image's (`arm64` for a 64-bit image, `armhf` for a 32-bit one) or `all`.
- Anyone who reaches the provisioner's web server can download the files under `/uploads`, the images included: keep secrets out of packages.
- The new system needs systemd and curl. A module booted over USB (rpiboot) is not checked: a reboot would bring it back to USB boot.

## The built-in Wi-Fi as a cold spare ##

A provisioner with a better Wi-Fi card (an M.2 card, a USB adapter) keeps its built-in Wi-Fi off the network: while another device carries the default route, the built-in Wi-Fi stays disconnected. When there has been no other default route for 30 seconds (the card was removed, its driver failed, it cannot connect), the built-in Wi-Fi connects on its own, and once the card has carried the default route again for a minute, it leaves. Only the device is disconnected, never a NetworkManager profile: after a reboot both radios connect, and the built-in Wi-Fi leaves a minute later.

- The provisioner's address changes with the radio that carries the traffic; `<hostname>.local` (avahi) follows it.
- Every switch goes to the journal: `journalctl -t cmprovision-uplink`, or Settings → Service status → uplink cold spare.
- `SPARE=no` in `/etc/default/cmprovision-uplink` keeps the built-in Wi-Fi connected all the time.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.

## The provisioner's Wi-Fi networks ##

Settings → Wi-Fi networks lists the networks the provisioner connects to, the preferred first, and the Wi-Fi profiles it leaves to other tools (greyed). Each network is one NetworkManager profile that serves both radios: the card carries the traffic, the built-in Wi-Fi stands by as the cold spare.

- **Add network** takes the name (SSID; **Find networks** lists the networks in reach), the security (WPA2-Personal, WPA2/WPA3 mixed or WPA3-Personal), the password, and whether the network is hidden. Every change asks for your account password first.
- The password is stored on the server and never shown again; left empty when editing, it keeps the stored one. A new password of the network in use takes effect at its next connection, so it can be entered before the router changes.
- **Check now** tries a network on the idle built-in Wi-Fi while the card keeps working: connected, wrong password, not found, no address. A network only the card can reach (6 GHz, say) shows as not found and can be saved all the same.
- The order (↑ ↓) decides which network the provisioner joins when it connects; it does not leave a working network for a higher one. The network in use cannot be deleted. **Changes log** shows every change (`journalctl -t cmprovision-wifi`).
- A provisioner set up with Raspberry Pi Imager keeps its Wi-Fi in a netplan profile, shown greyed: add the same network here, check it, then remove the old profile with `sudo nmcli connection delete <name>`.
- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS the section says it is not available.

## Switches: port lookup, the Ports page and PoE restart ##

Settings → Ethernet switches lists the switches of the stand; **Add switch** takes a switch's address and SNMP read access (a community for v2c, a user for v3). With them a module gets its switch and port as its board, `sw01 Gi0/3` (the switch's sysName before the first dot, then the port's description or name), and the Ports page shows each switch's front panel port by port. A port that leads to another switch of the list is a link, never a module's port: it shows that switch's own MAC (its base MAC, the MAC of its VLAN interface, or the MAC of its address in the provisioner's ARP cache). The same switch cannot be added twice under two addresses. Two switches with the same sysName give boards that look the same: Settings and the Ports page warn about it, rename one on the switch.

Write access is optional, set for each switch, and only used to restart modules over PoE: a write community for v2c, or a user of its own for v3. **Check write access** writes one PoE port's current value back, so it changes nothing. With write access, the module card, the Ports page and the dashboard get a **Restart** button: the switch turns the port's PoE off for 5 seconds and on again. It is offered only on ports with one of the provisioner's modules (or a Raspberry Pi still booting) and nothing else, and not while the module writes its bootloader EEPROM. A port that does not come back on is listed on the dashboard, the Ports page and the module card with **Power on** until it does.

## Upgrading ##

Install the new package over the old one with `sudo apt install ./cmprovision4_*.deb`; never `apt remove` it first, the package's removal step deletes the database and storage.

Upgrading from a version older than 2.5 (1.x, or 2.0 to 2.4) takes a snapshot by itself first: `/var/backups/cmprovision/<timestamp>-<old version>/`, with the old package inside, and `sudo cmprovision-restore <that directory>` goes back to the old version with its data. If no snapshot can be taken (three tries), the upgrade stops and the old version stays installed: try again while no module is on the stand, or upgrade without a way back with `sudo CMPROVISION_NO_SNAPSHOT=1 apt install ./cmprovision4_*.deb`. Reload browser tabs that were open during the upgrade.

2.5 is built for 40-48 modules starting at once. The web interface runs in a php-fpm pool of its own (`/etc/php/<version>/fpm/pool.d/cmprovision.conf`, socket `/run/php/cmprovision-fpm.sock`); after a new PHP version comes with a system upgrade, run `sudo dpkg-reconfigure cmprovision4` to give it the pool. Two queue workers run next to it: `cmprovision-queue` finds the modules' switch ports and sends the notifications, `cmprovision-queue-images` computes image checksums (and finds ports when the first one is busy); Settings → Service status shows both with their logs. A module gets its script at once and its board a few seconds later; notifications go out in packs, one message per channel every 10 seconds. The database runs in WAL mode: run `artisan` and `sqlite3` by hand as the web user, `sudo -u www-data /var/lib/cmprovision/artisan …`.

2.4 keeps the switches in a list of their own and makes the 2.3 switch settings its first entry; the 2.3 settings stay for going back. Going back with `cmprovision-restore` puts back the 2.3 database, so the next upgrade makes the list again from the 2.3 settings; after installing the 2.3 package over 2.4 without a restore, the list stays as 2.4 left it. Check Settings → Ethernet switches after either.

For other upgrades take the snapshot yourself (the commands come with the package; in a checkout they are `tools/backup.sh` and `tools/restore.sh`). Only with `dpkg-repack` installed does it hold the installed package; without one, `cmprovision-restore` refuses it while another version is installed:

```
sudo apt install dpkg-repack       # once
sudo cmprovision-backup            # /var/backups/cmprovision/<timestamp>-<version>/
sudo cmprovision-restore /var/backups/cmprovision/<snapshot>
```

The package's post-installation step adjusts existing servers: `send_timeout 6h` for image downloads in the nginx site, `upload_tmp_dir` on disk next to the images, `APP_ENV=production` / `APP_DEBUG=false` in `.env`. The firmware store moves from the `stable`/`beta` channel names to `default`/`latest`; images downloaded earlier stay available to the projects that use them.

## API ##

Create a token on the profile page (permissions: create, read, update, delete) and send it as `Authorization: Bearer <token>`.

* `GET /api/cms`, `/api/projects`, `/api/projects/{id}`, `/api/projects/{id}/cms`, `/api/images`, `/api/firmware`, `/api/scripts`, `/api/labels`
* `POST /api/images` (multipart field `image`), `DELETE /api/images/{id}`
* `PATCH /api/projects/{id}` with any project fields and `scripts` (list of script ids); changing the active project rebuilds the EEPROM image the modules download
* `POST /api/scripts`, `GET|PATCH|DELETE /api/scripts/{id}`

## Development ##

This PHP web application uses the Laravel framework.
Make sure you familarize yourself with the fine documentation: https://laravel.com/docs/13.x/.

In particular note:
* run `composer install` to install the dependencies living in the `vendor` directory.
* the styles are built with the standalone Tailwind CLI 3.4 (no Node needed): `tailwindcss -c tailwind.config.js -i resources/css/app.css -o public/css/app.css --minify` after `php artisan view:cache`, then commit `public/css/app.css`.
* tests: `php artisan test` (phpunit.xml runs them on an in-memory SQLite database) and `npm run test:js` (Node 20 or newer).
* requirements: Debian 13 / Raspberry Pi OS Trixie or newer, PHP 8.3 or newer. Version 1.6.11 is the last one for Debian 11 and 12.
* `docker/` holds a test bench (Debian 13 with PHP 8.4) laid out like the installed package, see `docker/README.md`.
* build the package with `dpkg-buildpackage -b -us -uc` from a clean checkout without a `vendor/` directory (the build installs production dependencies itself).

## Licence ##

The main code of the Raspberry Pi compute module provisioning system is made available under the terms of the BSD 3-clause ("new") license.
Look in the `vendor` directory (after running `composer --install`) to consult the open source licenses of the various dependencies like the Laravel framework used.
