# Provision another cmprovision station

The development branch can configure a new station while writing the generic cmprovision Lite image.
The project supplies Linux access and optional management Wi-Fi; the shared image stays unchanged.
This feature requires a rebuilt image with **station setup schema 1**, the 64-bit utility OS, and
eMMC/SD with an ext4 root on partition 2. The previously tested `2.24.0~dev2` image predates this support.
It is not a customization tool for arbitrary Raspberry Pi OS or WLAN Pi OS images.

## Prepare the project

1. Upload a compatible station image and wait until its checksums are ready.
2. Create a project, select that image and turn on **Verify that image was written correctly**.
   Select **Check the OS after the first boot** to reboot and check the new station automatically.
   Splitting the image is optional. Keep EEPROM changes disabled unless the maintenance operation needs them.
3. Enable **Set up a cmprovision station**. Enter its Linux administrator name and host name.
   The default `cmprovision-{serial}` gives each station a different name; `{serial}` expands to its full serial.
4. Set an initial password, add public SSH keys, or use both. Turn on **Enable SSH** for remote access.
   With password login disabled, at least one public key and SSH are required; the administrator then uses
   passwordless sudo. With a password, the image's normal sudo policy remains in place.
5. Optionally configure management Wi-Fi: SSID, WPA2/WPA3 security, password, country and hidden-network flag.
   When editing, an empty password field retains the saved value. A changed SSID requires its own password.
   No saved password or Linux password hash is returned to the form.
6. Save, confirming your web-account password. Activate the project only after checking which devices can
   enter network boot. For an existing station, first make and verify an off-device backup; see
   [image acceptance and station reimaging](../image/README.md#first-hardware-test-on-an-isolated-stand).
7. Start the intended device in the 64-bit utility OS. The run verifies the image before **Configuring the
   station**, then continues the project's remaining post-install steps and optional OS check.

The new station keeps DHCP/TFTP and USB boot masked. Connect through the configured management path,
create a **separate web account** with `sudo -u www-data /var/lib/cmprovision/artisan auth:create-user`,
then use [Configure station role](operations.md#addresses-routes-and-the-provisioning-network).
The Linux password does not create or change a web account. Without Wi-Fi setup, the image keeps its
normal Ethernet management behavior; with Wi-Fi, a DHCP profile for the CM4's provisioning port `eth0`
excludes its default routes and DNS, leaving management to Wi-Fi.
Wi-Fi setup enables the radio once after NetworkManager starts. Later boots retain the operator's radio setting.

## Delivery and failures

Project settings are encrypted with the provisioner's application key. Linux passwords are hashed before
storage. Each run keeps an encrypted settings snapshot, so editing a project during a write does not change
the destination's credentials. Successful delivery clears that snapshot; expired runs are removed by the
queue and before preparing further runs. Backups containing the database and application key remain sensitive.

After verification, the utility OS mounts the written system and starts its SSH server temporarily on
TCP 2224. Its host key and restricted authorized key exist only in a temporary RAM filesystem. The server
pins the announced SSH host key and checks the serial, storage CID and run identifier before sending settings
through SSH stdin. Credentials never go into the shared image download, generated shell script or HTTP response.
The temporary listener is stopped after setup; its authorization is not installed for normal SSH access.

The initial bootstrap still relies on the **isolated provisioning network**, like the image download itself.
SSH protects settings from passive capture; the unauthenticated PXE/HTTP bootstrap does not protect against
an attacker actively replacing that bootstrap. A changed host key or identity is refused rather than accepted again.

An identical request can be retried if its success response was lost. A partially applied setup accepts only
the same settings snapshot. If power fails before setup finishes, the image's first-boot coordinator refuses
to start application services with an incomplete configuration; repeat the image write. A failure is shown in
the post-install phase. Do not place credentials in a custom script to work around it.

## Validation status

The implementation is tested with encrypted database records, Livewire forms, a real loopback OpenSSH server,
the NetworkManager keyfile parser and account configuration on a disposable copy of the real arm64 image under QEMU.
A complete CM4 hardware run passed image verification, automatic management Wi-Fi association, password and public-key
SSH login, real sudo elevation and a cold PoE boot. Existing station data and settings were restored from a verified
off-device backup. The physical panel rendered all five pages and KEY2 worked. Remaining controls,
Classic/Dark appearance and Raspberry Pi Imager customization remain pending acceptance.
