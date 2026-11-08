> **Language / Язык:** [English](deployment-options.md) | [Русский](ru/deployment-options.md)

# Deployment Options: Station Image and VLAN

Version 2.24.0 introduces UI-based network management and an experimental Raspberry Pi OS Lite image. An updated image build with Intel Wi‑Fi has completed network flashing, initial boot, and reboot on CM4; a station deployed from this image also flashed a test sensor over a tagged VLAN. Project-driven SSH and Wi-Fi provisioning, data restoration, and cold PoE startup have been verified on CM4. All five pages of the new FPMS and KEY2 navigation were tested on physical hardware. Classic/Dark themes, other buttons, and image configuration via Raspberry Pi Imager remain open for further testing. FPMS development is focused on Raspberry Pi OS Lite; a dedicated package for WLAN Pi OS is not planned. This front-panel design decision does not affect existing cmprovision compatibility with WLAN Pi OS.

## Pre-Built Station Image

For 2.26.0, use the matching package and experimental Lite image from the [release assets](https://github.com/nimbo78/cmprovision/releases/tag/v2.26.0). The refreshed image includes the station-role form and project-driven setup. Its publication does not replace the physical first-boot and display checks below; the application package is stable while the ready-made station image remains experimental.

The `.deb` package installs onto an existing, running OS. The pre-built image is a full disk image for Raspberry Pi Imager containing Raspberry Pi OS Lite arm64, cmprovision, and optionally `fpms-lite`. It can be flashed onto eMMC or microSD of a supported carrier board, booted, and initialized.

Planned architecture for Raspberry Pi OS Lite releases:

1. Pinned versions of OS, packages, utility OS, and build scripts; published version metadata, component manifests, and SHA-256 hashes.
2. Pre-installed cmprovision and optional fpms-lite packages, with the front-panel display activated only upon operator request. SPI must also be enabled for the screen.
3. First-boot initialization: station hostname, user credentials, Wi-Fi/Ethernet management interface, time zone, and stand network interface. Settings must never be cloned from an existing provisioner without an explicit backup restoration.
4. Unique `machine-id`, SSH host keys, and application encryption keys per station. Images, projects, Wi-Fi credentials, API tokens, and sensor deployment tags are excluded from the public image.
5. Stand DHCP and TFTP services remain disabled until the proper interface is selected and configuration is confirmed, preventing accidental DHCP rogue offers on the office LAN.
6. Validation test suite: flashing, first cold boot on supported CM4 hardware, web access, HDMI and FPMS displays, reboot, update, backup restore, and flashing a real test sensor. User data and other storage drives remain untouched by the image builder.

Product direction: maintaining a single image with optional FPMS is far more sustainable than building separate images for Lite and WLAN Pi OS. An already running Raspberry Pi OS can always install cmprovision via `.deb` without re-imaging the disk.

The experimental image builder and manual first-boot procedure are documented in [image/README.md](../image/README.md). Initial setup is not yet wrapped in a guided wizard, and upstream repository packages are not yet pinned to snapshots. A re-test flashing a sensor from the latest image (including LLDP) completed in 7:07: network boot, chunked flashing, SHA-256 verification, partition resize, and OS check all passed; systemd reported status `running` with zero failed units. The repeated test project also incorporated a workaround for `wlanpi-firefox-setup.service` (present on standard test stands but omitted in the first run).

## Configuring Station Images via cmprovision Projects

Starting with 2.24.0, a pre-built station image can be deployed directly from another cmprovision instance. Projects include an initial setup configuration block: hostname, Linux user account, password and/or public SSH keys, SSH daemon enablement, Wi-Fi SSID/security/passphrase, and regulatory country code. This setup mechanism applies specifically to supported station images rather than arbitrary OS images.

The shared image retains its original SHA-256 checksum. Configuration is applied only after write verification succeeds; secrets are never injected into the shared image file, script outputs, or flashing logs. Provisioning data is transferred over an ephemeral SSH channel with strict server key, serial number, storage CID, and run ID validation. The initial PXE/HTTP boot process continues to require an isolated stand network. On first boot, DHCP and TFTP remain inactive until the operator assigns the interface and station role.
An updated image with setup schema 1 is required (the earlier dev2 build does not support this schema). Software validation, application to an arm64 target under QEMU, and real hardware testing of SSH/Wi-Fi configuration with cold CM4 reboot have all passed. For full details, see [Station Setup from Project](station-project-setup.md).

## VLAN: Two Network Architectures

VLANs isolate provisioning traffic on managed switches. Target sensor ports are typically configured as **access / untagged** in the designated VLAN: sensors receive untagged Ethernet frames and do not need 802.1Q awareness.

### Option A: Provisioner Port Also Untagged

```text
Provisioner eth0 ── access VLAN 42 ── switch ── access VLAN 42 ── sensors
       Wi-Fi ───────────────────────── management network / internet
```

The switch tags all ingress frames from the provisioner port into VLAN 42, while the provisioner sees regular untagged `eth0`. This works seamlessly with the standard isolated stand topology without adding VLAN configuration inside cmprovision. VLAN 42 is merely an example; verify switch and DHCP configurations prior to connection.

### Option B: 802.1Q Trunk to Provisioner

```text
Provisioner eth0.42 ── tagged VLAN 42 on trunk ── switch ── access VLAN 42 ── sensors
                    └── additional VLANs on the same physical link if needed
```

Here, frames between provisioner and switch carry 802.1Q tags, requiring a VLAN sub-interface on top of physical Ethernet on the provisioner. The network management UI creates VLAN profiles and safely reassigns the provisioning network with interactive confirmation or automatic rollback on timeout. Sensor ports remain untagged. Tagged VLAN connectivity and rollback mechanisms were verified on d62 and Cisco 2960-CX; live CM4 flashing over VLAN 15 was also validated. For this test run, primary station IP assignment and DHCP/TFTP bindings were temporarily applied via administrative commands. The **Configure station role** form configures initial networking and switches between primary, secondary, and disabled roles with automatic rollback for addresses and services. It was tested against isolated NetworkManager and systemd, as well as on d62 retaining secondary status across cold reboot. For details, see [Network Operations](operations.md#additional-tagged-vlans).

The stand topology uses network `172.20.0.0/16`, provisioner IP `172.20.0.1`, and a DHCP pool starting at `172.20.0.11`; these addresses are referenced throughout boot files and application logic. Setting a VLAN ID does not alter this subnet. Arbitrary subnets, multiple concurrent flashing networks, and automated switch port provisioning are separate feature areas.

Each stand network segment must have exactly one active DHCP/TFTP provisioner. A secondary station on the same segment can monitor sensor status, but running duplicate DHCP/TFTP servers will disrupt operations. Modify network topologies on live stands only after active flashing completes, ensuring a fallback management path is available.

### Recommendations

- For an isolated stand switch with Wi-Fi management, the current untagged setup is sufficient; a pre-built image is not required.
- On a shared managed network with a dedicated access port for the provisioner, configuring VLANs solely on the switch is often easiest.
- To carry multiple VLANs over a single cable, configure a switch trunk port and corresponding VLAN profiles on the provisioner (supported in 2.24.0).
- For rapid deployment of identical stations, use the pre-built image with secure first-boot configuration.
