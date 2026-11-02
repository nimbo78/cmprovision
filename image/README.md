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
3. For a **new, isolated primary stand** using untagged `eth0`, with no other DHCP server on
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
4. Bind DHCP/TFTP to the verified stand interface, then enable the services explicitly:

   ```sh
   printf 'eth0\n' | sudo tee /etc/default/cmprovision-stand-interface >/dev/null
   sudo systemctl unmask cmprovision-dnsmasq.service cmprovision-rpiboot.service
   sudo systemctl enable --now cmprovision-dnsmasq.service cmprovision-rpiboot.service
   systemctl status cmprovision-dnsmasq.service cmprovision-rpiboot.service
   ```

   Confirm DHCP/TFTP traffic remains on the intended segment. Upload a sensor OS image, create
   a test project and only then connect a disposable sensor. Record its complete provisioning cycle.

Before calling an image ready, verify its actual first-boot user setup, SSH host-key regeneration,
two independent application keys, networking, explicit stand activation, web login, HDMI, optional
FPMS, updates, a power cycle and a real sensor flash. Check that no build-host secrets are present.
An image is not a backup of an existing provisioner and must not include its credentials or projects.
