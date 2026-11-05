# Experimental provisioner image

This image builder is under development and is not a released or hardware-accepted image.
It targets Raspberry Pi OS Lite Trixie arm64 and runs on a separate disposable Debian 13
build system. Never build on a station which is provisioning modules.

The builder pins pi-gen to a specific commit, verifies the supplied `cmprovision4` package
against an independently supplied SHA-256, and writes the image and checksums under its empty
build directory. It uses the configured Debian and Raspberry Pi package repositories: their
contents are not yet pinned to a dated snapshot, so the build is not fully reproducible.

```sh
sudo bash image/build.sh /path/to/empty-build-directory /path/to/cmprovision4.deb EXPECTED_SHA256
```

To include the optional Raspberry Pi OS `fpms-lite` package, append its package path and
independently verified SHA-256:

```sh
sudo bash image/build.sh /path/to/empty-build-directory /path/to/cmprovision4.deb CMP_SHA256 \
    /path/to/fpms-lite.deb FPMS_SHA256
```

pi-gen's documented build dependencies are required, including arm64 QEMU/binfmt on an x86
builder. Build paths must not contain spaces. The source tree and package must already be present
on the builder. The build directory is retained for investigation; the script does not write
to a physical disk or remove an existing build directory.

Run the image safety tests on a disposable Debian host:

```sh
sudo python3 -m unittest discover -s tests/image -p 'test_*.py'
sudo env CMP_IMAGE_LARAVEL_SOURCE=/path/to/test-copy-with-vendor \
    python3 tests/image/test_real_firstboot.py
```

The second test makes two temporary application copies and uses the real Laravel key generator
and `ssh-keygen`, with SSH keys confined to temporary directories.

The image setup currently:

- Locks the build-time root password and does not set a shared initial user password.
- Removes the image-build application key; a first-boot service generates and verifies a fresh
  Laravel key before nginx and the cmprovision queue workers start. Subsequent boots keep it.
- Relies on pi-gen to reset the machine ID and generates unique SSH host keys before SSH can start.
- Masks cmprovision DHCP/TFTP and USB boot services until an operator configures the station.
- Installs Intel Wi-Fi firmware for supported adapters such as AX200; Pi OS Lite already includes
  firmware for the onboard Broadcom Wi-Fi and supported MediaTek adapters.
- Includes and enables `lldpd`, so other stations can recognise this provisioner on a switch port.
- Optionally installs `fpms-lite`. Its display service stays off until FPMS is enabled in Settings;
  the image setting watcher stops it again when FPMS is disabled. Enable SPI separately for a
  compatible display and restart the station before using it; SPI is not enabled by this image.
- Audits the root filesystem before export for unlocked build-time accounts, shared keys,
  missing first-boot dependencies and provisioning services that are not masked.

## First hardware test on an isolated stand

This is a manual acceptance procedure for the experimental image, not a completed setup wizard.
The image has no preconfigured stand network, web user or provisioning project. It must not be
connected to a production provisioning VLAN while performing the test.

1. Write the image to the intended device. At first boot, use the Raspberry Pi OS setup dialog
   on the attached monitor and keyboard to create a local user. Alternatively, configure a user
   and a management connection with Raspberry Pi Imager when writing the image. There is no
   shared initial password. Verify that the operator can log in and that the application and
   SSH host keys remain unchanged after a reboot.
2. Set up a management connection separately from the isolated provisioning network. Log in on
   the device and create the first web user:

   ```sh
   sudo -u www-data /var/lib/cmprovision/artisan auth:create-user
   ```

   Open the web UI using the management address and verify the login. DHCP/TFTP and USB boot
   remain masked at this point.
3. With the current branch's network controller installed, open **Settings → Network**. For a
   **new, isolated primary stand**, connect the intended Ethernet or VLAN profile, then choose
   **Configure station role → Primary**. Enable USB boot if needed and confirm that the segment
   has no other DHCP server. Apply the change and confirm it through the independent management
   connection within two minutes. For a station sharing another primary's stand, choose
   **Secondary** instead: it uses `172.20.0.2/16` and keeps DHCP/TFTP and USB boot masked.
   See [network operations](../docs/operations.md#addresses-routes-and-the-provisioning-network).

   The accepted `2.24.0~dev2` image predates this form. Until a rebuilt image includes it, upgrade
   the package first or use the following console alternative. For untagged `eth0`, with no other DHCP server on
   that segment, create the stand profile from the local console:

   ```sh
   sudo nmcli connection add type ethernet ifname eth0 con-name cmprovision-stand \
       ipv4.method manual ipv4.addresses 172.20.0.1/16 ipv4.never-default yes \
       ipv6.method link-local ipv6.addr-gen-mode eui64 connection.autoconnect yes
   sudo nmcli connection up cmprovision-stand
   ip -4 address show dev eth0
   ```

   This example takes over `eth0`. Do not use it if that port carries the current management
   connection, another station's stand network, or a network with its own DHCP server. For a
   different interface, tagged VLAN or secondary station, configure and verify the intended
   network separately before enabling services; `172.20.0.1` is the primary station's address.
   When using the console alternative, bind DHCP/TFTP to the verified interface and enable services:

   ```sh
   printf 'eth0\n' | sudo tee /etc/default/cmprovision-stand-interface >/dev/null
   sudo systemctl unmask cmprovision-dnsmasq.service cmprovision-rpiboot.service
   sudo systemctl enable --now cmprovision-dnsmasq.service cmprovision-rpiboot.service
   systemctl status cmprovision-dnsmasq.service cmprovision-rpiboot.service
   ```

4. Confirm DHCP/TFTP traffic remains on the intended segment. Select the intended Utility OS
   in Settings; the hardware test described below explicitly selected 64-bit version 2026.10.2.
   Upload a sensor OS image, create a test project and only then connect a disposable sensor.
   Record its complete provisioning cycle.

The image can also be written to a station by another cmprovision server over network boot.
The writing server uses its **active project** for newly booting devices, so isolate the test or,
for a fresh image with no resumable writes, use a pre-install check which refuses every serial
except the intended station. A resumed write skips pre-install scripts, so such a check is not a
general substitute for isolation. Verify the image before post-install scripts resize its partitions
or make other intentional changes. Leave EEPROM flashing out of the project unless it is explicitly
part of the test. Raspberry Pi OS can also schedule EEPROM updates itself: this image retains the OS
default for `rpi-eeprom-update.service`. If the test must preserve the current bootloader, mask that
service on the written system before its first boot and record that the hardware test used this change.
After the test, a station whose bootloader falls back to network boot can still receive whatever
project is active on that shared stand if its own system stops booting. Isolate it from the stand
or arrange an explicit safeguard before relying on unattended recovery.

For a headless test, prepare a **station-specific copy** with Raspberry Pi OS's first-user
configuration and an SSH public key, and enable SSH on first boot. Keep the generic image free
of user credentials. cmprovision serves project images to modules over HTTP: do not put reusable
Wi-Fi passwords in a project image. Instead, connect over the isolated stand network and transfer
the Wi-Fi profile over authenticated SSH after first boot. Set the correct Wi-Fi country for the
site and make the provisioning Ethernet connection `never-default` before relying on Wi-Fi for
management. The first-boot OS check reports the OS state; it does not test the web login or FPMS.

A station-specific derivative of the Intel-enabled image has been network-flashed to a CM4,
verified, booted and rebooted. The image's own resize expanded eMMC, and Intel AX200 firmware
loaded on first boot. That test added one-time user/SSH configuration and masked automatic EEPROM
updates before boot. The resulting station also provisioned a CM4 through a tagged VLAN and an
untagged sensor port: network boot, split-image writing, SHA-256 verification, partition resizing
and the first-boot OS check completed in 7 minutes 19 seconds. The first run omitted the existing
stand's Firefox workaround and WLAN Pi OS 26.10 reported `systemd: degraded`. A repeat with that
post-install script completed in 7 minutes 2 seconds: verification and the OS check passed, and
systemd reported `running` with no failed units.
The test used temporary administrator-configured addresses and interface binding; it does not
validate automatic promotion of a secondary station to a primary one through the web UI.
A clean installation with Raspberry Pi Imager and the remaining checks below are still outstanding.
The LLDP-enabled image was subsequently network-flashed and booted on a CM4. The original generic
image bytes passed SHA-256 verification before serial-restricted post-install scripts configured
one-time SSH access and masked EEPROM auto-updates. The first-boot check reported `running`, no
failed units, enabled LLDP and an automatically expanded root filesystem. Application data and
network/display settings were restored from a verified off-device backup. After a further reboot,
the neighbouring provisioner recognised it as another station, and FPMS reported a hardware
heartbeat and `running`; this is not physical screen/button acceptance. The SSH host key and
machine ID remained stable; restoring application data intentionally restored its matching old
APP_KEY. A sensor flash was then repeated from this exact rebuilt artifact over isolated VLAN 15:
network boot, split-image writing, SHA-256 verification, resizing and the OS check passed in
7 minutes 7 seconds, with systemd `running` and no failed units. The test used the existing
Firefox workaround and temporary administrator-configured networking as described above.

Before calling an image ready, verify its actual first-boot user setup, SSH host-key regeneration,
two independent application keys, networking, explicit stand activation, web login, HDMI, optional
FPMS, updates, a power cycle and a real sensor flash. Check that no build-host secrets are present.
An image is not a backup of an existing provisioner and must not include its credentials or projects.
