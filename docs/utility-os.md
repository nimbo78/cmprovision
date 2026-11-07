> **Language / Язык:** [English](utility-os.md) | [Русский](ru/utility-os.md)

# Utility OS

The target module boots into a minimal micro-OS loaded either over the network (TFTP via dnsmasq) or over USB (`rpiboot`). Starting with 2.7.0, two options are packaged:

| Directory | Description |
|---|---|
| `scriptexecute64/` | Primary modern OS: Buildroot LTS, 64-bit Raspberry Pi kernel, modern BusyBox, curl, e2fsprogs, flashrom. Built from `utility-os/` |
| `scriptexecute/` | Legacy OS: prebuilt 32-bit binaries from raspberrypi/scriptexecutor (2022). Scheduled for removal in a future release |

The active operating system is controlled by the symlink `/var/lib/cmprovision/etc/boot` pointing to either `../scriptexecute` or `../scriptexecute64`: both dnsmasq `tftp-root` and `rpiboot -d /var/lib/cmprovision/etc/boot/usb` follow this link. The toggle is available in Settings → Utility OS. It updates the symlink immediately without requiring service restarts and logs operator actions in the Laravel audit log. Switch the OS only when no modules are actively booting: a module fetching files during the switch could download mismatched components.

Manual switch from the CLI:

    sudo -u www-data ln -sfn ../scriptexecute /var/lib/cmprovision/etc/boot      # legacy 32-bit
    sudo -u www-data ln -sfn ../scriptexecute64 /var/lib/cmprovision/etc/boot    # modern 64-bit

The symlink is created and validated by `tools/boot-link` (`/usr/lib/cmprovision/boot-link`), called by `postinst`, `prerm` (when rolling back below 2.7), and `cmprovision-restore`, always as `www-data`. Rolling back below 2.7 automatically reverts the boot link to the legacy OS.

Both directories include `bootfiles.bin` from usbboot. Starting with usbboot 20261002, `rpiboot` does not embed `bootcode4.bin` internally; since September 28, 2026, usbboot encapsulates it inside `bootfiles.bin` (`2711/bootcode4.bin` alongside required helpers). When `rpiboot` locates `bootfiles.bin` inside its `-d` directory, it extracts the second-stage bootloader directly from it and serves remaining files from the directory.

For USB booting, each variant contains a dedicated `usb/` subdirectory: its `config.txt` sets `boot_ramdisk=1`, and `boot.img` contains a FAT32 filesystem packaging the exact same kernel, initramfs, DTB, overlays, and configuration used for network booting. On test stands, streaming individual files over USB stalled after `start4.elf` and `fixup4.dat`; a consolidated `boot.img` resolved boot reliability. This approach is [recommended by Raspberry Pi for CM4](https://github.com/raspberrypi/usbboot#booting-linux). Network boot files remain in the root of the OS directory. The `tools/utility-os/usb_image.py` script builds the USB image without root privileges or loop mounts; `--check` extracts its contents and verifies parity with source files. Both network and USB files are validated by `MANIFEST`, ensuring corrupted USB images prevent the OS from being selected.

The `MANIFEST` in each directory defines the OS release (version, kernel, architecture, build timestamp) and enumerates file SHA-256 checksums. Settings displays these details, and automated test suites verify them against disk contents. Any directory with missing files, altered sizes, or checksum mismatches is excluded from the boot switcher.

## Usage Workflows

The temporary micro-OS boots via network or USB, and its local console provides direct troubleshooting access on the target module:

- [USB Provisioning for CM4](usb-provisioning.md): Project preparation, wiring, flashing with verification, eMMC boot restoration, and OS check constraints.
- [Local Console tty2](local-console.md): Target monitor and keyboard access, login credentials, and diagnostic commands without SSH network dependencies.
- [WLAN Pi M4+](hardware/wlanpi-m4-plus.md): Provisioner and target on matching hardware, Mcuzone carrier revisions, BOOT/OTG toggles, and power delivery.

## Architecture

- `utility-os/` — BR2_EXTERNAL tree `CMPROVISION`: `configs/cmprovision_defconfig`, `board/cmprovision/` (kernel and BusyBox config fragments, init `S99scriptexec`, `post-build.sh`, `post-image.sh`, `config.txt` and `cmdline.txt` templates), `package/` (firmware bootloaders, `raspberrypi/utils`, `bootfiles.bin`), `legacy-commands.txt`.
- `utility-os/versions.env` — Single source of truth for component versions and SHA-256 hashes.
- XZ images are decompressed with `xz-utils`: it emits explicit format error messages where BusyBox xz can fail silently. BusyBox includes awk mathematical extensions, and util-linux provides `ipcmk` to retain legacy utility compatibility.
- lldpd (since 2026.10.2): Target module advertises its identity to managed switches over LLDP. Buildroot `S60lldpd` launches the daemon on `eth0` (`/etc/default/lldpd`, `-I eth0`), `S61lldpname` assigns hostname `cm-<eth0 MAC without colons>` and system description `cmprovision utility OS <version> (Linux <kernel> <arch>)`, setting `lldp status tx-only`: the OS strictly transmits and ignores neighbor frames. Only the core LLDP protocol is built (CDP, FDP, EDP, SONMP, LLDP-MED, DOT1, DOT3, and custom TLVs are disabled). Script execution runs in the background; failures preserve fallback hostnames without blocking boot. The Ports web view identifies this OS on switch ports (`StandMap::utilityOs()`): a module booted into the utility OS but waiting for script execution is clearly marked as "Stand utility OS running, script not requested" rather than an unknown device. Automated tests: `UtilityOsLldpTest`; QEMU smoke test: `lldp` stage in `tools/utility-os/smoke/check.sh`.
- Flashrom patch in `patches/flashrom/`: returns the message `identical` when EEPROM flashing is skipped. The provisioning script uses this to distinguish matching EEPROM images from performed writes. Both scenarios are validated in QEMU using real flashrom against a file-backed emulated SPI chip (`dummy`), requiring no host hardware access.
- Scripts: `tools/utility-os/build.sh` (build), `test.sh` (automated validation), `update.sh` (version bump), `manifest.sh` (manifest generation).
- GitHub Actions CI: `.github/workflows/utility-os.yml` runs identical builds and tests.

The init system maintains backward compatibility with legacy formats, appending `uos=<version>` to start parameters. The server identifies targets by serial number, so parameter syntax is strictly preserved (`UtilityOsInitTest`).

## Building

Host requirement: Debian or Ubuntu x86_64 (WSL test stand or `ubuntu-24.04` runner). Builds run under a non-root user account, outside of OneDrive, and never on the provisioner. Required dependencies: `build-essential bc cpio rsync unzip file wget git python3 perl patch xz-utils bzip2`; for verification: `qemu-system-arm`.

    UOS_WORK=$HOME/uos-work UOS_CACHE=$HOME/uos-cache sh tools/utility-os/build.sh

The script verifies host dependencies, downloads the Buildroot release from `versions.env` with SHA-256 verification, and compiles the system. The built OS tree is placed in `scriptexecute64/`, with full source packages and licenses exported to `$UOS_WORK/output/legal-info`. Initial compilation takes approximately one hour; subsequent runs leverage download caches and ccache in `$UOS_CACHE`.
When build inputs change, the working output directory is rebuilt from scratch: otherwise `linux-custom` can retain stale kernel sources, and rootfs may retain obsolete binaries or disabled components. Cache tracking monitors the entire `utility-os/` tree (package recipes, patches, config fragments, rootfs overlays) and build, hashing, manifest, and licensing scripts; generated `__pycache__` artifacts are excluded. Download and compiler caches are preserved across clean builds, keeping unchanged runs fast and incremental.

## Automated Verification

    sh tools/utility-os/test.sh

- Kernel: required configuration options are compiled in;
- Composition: all utilities listed in `legacy-commands.txt` are present, loadable kernel modules are absent, network login services are removed;
- QEMU (`qemu-system-aarch64 -M virt`) boots the exact `kernel8.img` and `scriptexecute.img`, init queries a mock server following an initial 502 response, returning startup parameters, utility versions, and CLI messages;
- Size constraints and `MANIFEST` validation;
- USB images for both operating systems: kernel, initramfs, and settings inside FAT match the corresponding standalone files.

CLI messages are recorded in `tests/fixtures/utility-os/messages.txt`. PHPUnit (`UtilityOsMessagesTest`) validates them against regex patterns used by the server to explain failures. Pi-specific hardware features (LEDs, SPI/flashrom, `vcgencmd`, hardware jumpers, eMMC) are validated on real hardware.

## Version Updates

    sh tools/utility-os/update.sh --check     # 0: up to date, 10: updates available, 1: query failed
    sh tools/utility-os/update.sh             # updates versions.env and displays diff

Component update policy: Buildroot tracks the active LTS branch, kernel follows the latest `stable_*` tag, firmware uses the latest release, `raspberrypi/utils` tracks latest commit, and usbboot tracks the latest tag. Migration to subsequent Buildroot LTS releases is performed manually. Under [Buildroot LTS policy](https://buildroot.org/lts.html), starting in 2025 LTS releases occur in February of odd years (2025.02, 2027.02). The February 2026 release is not an LTS release.
Checksums are strictly enforced: downloads with mismatched SHA-256 abort the build immediately. Partial downloads leave pinned versions unchanged.

Scheduled CI runs execute updates monthly. If updates exist, CI compiles, tests, and opens a PR against `main` containing version diffs and binary sizes. (Requires GitHub Actions permissions in fork settings: Settings → Actions → General). PRs opened by `GITHUB_TOKEN` require manual workflow approval before test execution. See [GitHub documentation](https://docs.github.com/en/actions/concepts/security/github_token). Scheduled runs are automatically paused by GitHub after 60 days of repository inactivity; use "Enable workflow" to resume.

## Releasing a New OS Version

1. Run `update.sh` (or merge CI PR), `build.sh`, and `test.sh`.
2. Commit `utility-os/versions.env`, `scriptexecute64/`, and `tests/fixtures/utility-os/messages.txt`; run full PHPUnit test suite.
3. Package `.deb`, deploy to provisioner, test on hardware via the UI switch: flashing with verification, EEPROM, LEDs, OS check, PoE restart resumption, and boot timings.
4. Release cmprovision. Concurrently publish GitHub Release `utility-os-<version>` with the `legal-info` archive attached.

## Source Code and Licensing

The utility OS packages GPL-licensed software (Linux kernel, BusyBox, etc.). Complete source code and license texts are generated via `make legal-info` (`$UOS_WORK/output/legal-info`). Archives are published with each GitHub Release `utility-os-<version>` and preserved in CI artifacts.
Releases:
- [Sources and licenses for 2026.10.2](https://github.com/nimbo78/cmprovision/releases/tag/utility-os-2026.10.2)
- [Sources and licenses for 2026.10.1](https://github.com/nimbo78/cmprovision/releases/tag/utility-os-2026.10.1)
- [Archive for 2026.10](https://github.com/nimbo78/cmprovision/releases/tag/utility-os-2026.10)

The `build.sh` script enriches standard licensing output with `tools/utility-os/legal_info.py`: bundling the upstream Buildroot archive, repository recipes, and matching Bootlin SDK source archives, patches, and licenses. Components are resolved against `summary.csv` inside the verified SDK, and their checksums are verified against the SDK's Buildroot commit. The builder writes `source-complete.json`, required by CI to archive sources. Checksums for individual kernel and Raspberry Pi utils licenses are validated against previously pinned source archives, ensuring updated GPL texts are verified without weakening source integrity checks.

## Troubleshooting

- Target module fails to boot with modern OS: Settings → Utility OS → select legacy OS, reboot module. Inspect the target console (display, tty1) and server logs to determine which step halted.
- Settings warns dnsmasq is not following symlink: verify `etc/dnsmasq.conf` contains `tftp-root=/var/lib/cmprovision/etc/boot`, then run `systemctl restart cmprovision-dnsmasq`.
- USB booting does not start: verify `bootfiles.bin` is present in the directory targeted by `etc/boot`. Without it, `rpiboot` aborts with "Failed to open second stage bootloader" (systemd restarts it automatically).
- Build fails on checksum mismatch: verify download connection and upstream release. Altered files on existing tags require manual investigation; `update.sh` recalculates hashes when bumping versions, never to mask modified upstream artifacts.
- QEMU test fails before script execution: inspect module console output in `$UOS_WORK/qemu.log` and mock server capture in `$UOS_WORK/smoke/`.

## USB Diagnostics (2026.10.1)

The OS launches a background CDC ACM diagnostic agent before waiting for eth0/DHCP. Ethernet is the primary reporting link; if no report is received within 90 seconds, a USB-connected provisioner can capture a hardware diagnostic snapshot via `cmprovision-usb-diagnostics`. The snapshot tool shares logic with server scripts; the agent exposes neither a login shell nor a block storage device. Newly detected board snapshots are isolated from future flashing runs. For full details, see [Module Diagnostics](module-diagnostics.md#automatic-usb-fallback).

To limit parallel build jobs: `UOS_JOBS=4 sh tools/utility-os/build.sh`.
