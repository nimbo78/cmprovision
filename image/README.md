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
- Installs the application package; the separate optional `fpms-lite` package is not yet bundled.
- Audits the root filesystem before export for unlocked build-time accounts, shared keys,
  missing first-boot dependencies and provisioning services that are not masked.

Before calling an image ready, verify its actual first-boot user setup, SSH host-key regeneration,
two independent application keys, networking, explicit stand activation, web login, HDMI, optional
FPMS, updates, a power cycle and a real sensor flash. Check that no build-host secrets are present.
An image is not a backup of an existing provisioner and must not include its credentials or projects.
