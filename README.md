<picture>
  <source media="(prefers-color-scheme: dark)" srcset="docs/brand/cmprovision-horizontal-white.svg">
  <img src="docs/brand/cmprovision-horizontal.svg" alt="cmprovision" height="48">
</picture>

# Raspberry Pi Compute Module Provisioning System #

Web application to mass program compute modules; designed to run on a Raspberry Pi 4. Compute Module 3, 3+ and 4 are supported out-of-the-box.
Pi 4 boards can also be provisioned, but that requires that network boot is enabled in EEPROM settings first.

![screenshot](https://user-images.githubusercontent.com/1123701/209007803-7cc38d14-7f07-4910-8108-87323d25ac20.png)

This is a maintained fork of [raspberrypi/cmprovision](https://github.com/raspberrypi/cmprovision) (last upstream release: v1.6.3, 2023).
It fixes the open upstream issues around EEPROM firmware downloads, silent provisioning failures and current Raspberry Pi OS releases; see `debian/changelog` for the full list and the [Upgrading](#upgrading) section before installing over an existing server.

## Documentation ##

Full documentation is available on the Product Information Portal: [Using the Compute Module Provisioner.pdf](https://pip.raspberrypi.com/categories/685-whitepapers-app-notes/documents/RP-003468-WP/Using-the-Compute-Module-Provisioner.pdf)

## Provisioning Compute Module 4 Devices ##

This process requires a Raspberry Pi 4 which will be used solely to mass program compute modules. Make sure eth0 connects to an Ethernet switch that only has the compute modules you wish to program connected.

**WARNING:** Do NOT connect eth0 to your office/public network, since it may also provision other Raspberry Pi devices. Instead, use the wireless LAN interface to access the web interface, and your local network.

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
    sudo /var/lib/cmprovision/artisan auth:create-user  
    ```

You can now access the web interface with a web browser on the wireless LAN IP address.

## Provisioning Compute Module 3 and 3+ Devices ##

This process requires a Raspberry Pi 4 which will be used solely to mass program compute modules.

**WARNING:** Do NOT connect eth0 to your office/public network, since it may also provision other Raspberry Pi devices. Instead, use the wireless LAN interface to access the web interface, and your local network.

When provisioning Compute Module 3 and 3+ modules, Ethernet is not used: instead the images are transferred over USB. Use a good quality USB A to micro USB cable between the Pi 4 acting as server and the `USB slave` port of each CMIO board containing the compute modules to be provisioned. If you wish to provision more than 4 compute modules at a time, a USB hub can be used. Connect power supplies to the `power in` micro USB port of the CMIO boards, and make sure the J4 `USB slave boot enable` jumper is set to `en`.


1. Start with a fresh 64-bit Raspberry Pi OS (Lite) installation.  For simplicity, use [Raspberry Pi Imager](https://www.raspberrypi.com/software/) and the advanced settings menu (Ctrl-Shift-X) to set the password, hostname and wireless LAN configuration.  
    
    **NOTE:** If you intend to write images larger than 2 GB, you MUST install the **64-bit** edition of Raspberry Pi OS (Lite), which is available in Raspberry Pi Imager under the category "Raspberry Pi OS (other)" -> "Raspberry Pi OS Lite (64-bit)".

1. Run `sudo apt update` to make sure the package information on your Pi is up to date, and it is able to install dependencies.

1. Install the ready-made .deb package from https://github.com/raspberrypi/cmprovision/releases/:  

    ```
    sudo apt install ./cmprovision4_*_all.deb  
    ```

1. Set a web application username and password with:  

    ```
    sudo /var/lib/cmprovision/artisan auth:create-user  
    ```

You can now access the web interface with a web browser on the wireless LAN IP address.

### Provisioning from an alternate OS ###

During provisioning of CM3 and CM3+ devices, a small utility operating system, `scriptexecute`, is USB booted on each compute module. This configures the compute modules as  USB network adapters, and expects to be able to reach the provisioning server on predictable IPv6 link-local addresses that can be calculated based on the MAC address that each compute module chooses for its USB network interface. On Raspberry Pi OS, this is configured automatically by putting `slaac hwaddr` in `/etc/dhcpcd.conf`, however if your OS does not use `dhcpcd` as its network manager, then you will need to set this up manually. Examples of alternate network managers include `systemd-networkd` and `Network Manager`. How you set this up depends on the exact network manager used by your Linux distribution, so we cannot advise on how to do this.

On Raspberry Pi OS Bookworm or later (NetworkManager) the USB Ethernet gadget interface of each compute module appears as `usb0`, `usb1`, ... on the server and needs an EUI-64 link-local address. A profile like the following should give it one (untested by the fork maintainers, who provision CM4 over Ethernet):

```
sudo nmcli connection add type ethernet con-name cmprovision-usb ifname 'usb*'     ipv4.method disabled ipv6.method link-local ipv6.addr-gen-mode eui64 connection.autoconnect yes
```

## Provisioning Raspberry Pi 4 boards ##

It is also possible to use the system to provision regular Raspberry Pi 4 boards, instead of CM4 modules.
However that requires that network boot is enabled first in the EEPROM settings of each Pi 4.

You can create a SD card that enables that with [Imager](https://www.raspberrypi.com/software/), by going to: "Choose OS" -> "Misc utility images" -> "Bootloader" -> "Network boot"

## Switches: port lookup, the Ports page and PoE restart ##

Settings → Ethernet switches lists the switches of the stand; **Add switch** takes a switch's address and SNMP read access (a community for v2c, a user for v3). With them a module gets its switch and port as its board, `sw01 Gi0/3` (the switch's sysName before the first dot, then the port's description or name), and the Ports page shows each switch's front panel port by port. A port that leads to another switch of the list is a link, never a module's port: it shows that switch's own MAC (its base MAC, the MAC of its VLAN interface, or the MAC of its address in the provisioner's ARP cache). The same switch cannot be added twice under two addresses. Two switches with the same sysName give boards that look the same: Settings and the Ports page warn about it, rename one on the switch.

Write access is optional, set for each switch, and only used to restart modules over PoE: a write community for v2c, or a user of its own for v3. **Check write access** writes one PoE port's current value back, so it changes nothing. With write access, the module card, the Ports page and the dashboard get a **Restart** button: the switch turns the port's PoE off for 5 seconds and on again. It is offered only on ports with one of the provisioner's modules (or a Raspberry Pi still booting) and nothing else, and not while the module writes its bootloader EEPROM. A port that does not come back on is listed on the dashboard, the Ports page and the module card with **Power on** until it does.

## Upgrading ##

Install the new package over the old one with `sudo apt install ./cmprovision4_*.deb`; never `apt remove` it first, the package's removal step deletes the database and storage.

Upgrading from a version older than 2.4 (1.x, or 2.0 to 2.3) takes a snapshot by itself first: `/var/backups/cmprovision/<timestamp>-<old version>/`, with the old package inside, and `sudo cmprovision-restore <that directory>` goes back to the old version with its data. If no snapshot can be taken (three tries), the upgrade stops and the old version stays installed: try again while no module is on the stand, or upgrade without a way back with `sudo CMPROVISION_NO_SNAPSHOT=1 apt install ./cmprovision4_*.deb`. Reload browser tabs that were open during the upgrade.

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
