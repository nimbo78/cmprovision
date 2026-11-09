> **Language / Язык:** [English](deploy-to-provisioner.md) | [Русский](ru/deploy-to-provisioner.md)

# Deployment to the Provisioner

How to deploy updated code to the live provisioner `wlanpi-da2` (styles, logo, new
features, bug fixes) — before or instead of pushing to GitHub. This is a manual workflow
for validating on real hardware: we only push to the remote repository after the package
has built and installed successfully on the provisioner.

For published stable releases, stations with the updater installed can use
[Settings → Updates](updates.md) instead. It verifies signatures, requires an idle stand and a recovery
snapshot, and automatically restores the previous application if health checks fail. Use this manual
workflow for initial installation, development builds and recovery over SSH.

The `cmprovision4` package is `Architecture: all` (pure PHP plus a precompiled
ARM binary `ledpattern`, included as-is), and `vendor/` is installed from
`composer.lock`, so it can be built anywhere on Debian 13. **Do not build on the
provisioner itself:** building consumes its CPU and I/O, slowing down active module
flashing significantly (on October 22–23, two modules took 42 and 48 minutes instead of
the usual 6–7 because a build was running). The workflow is: build the `.deb` in WSL
(`tools/build-deb-wsl.sh`, ~1.5 minutes) → upload the package to da2 → install when
the stand is idle → `postinst` runs migrations and restarts PHP-FPM.

Cross-references: provisioner networking — [provisioner-network.md](provisioner-network.md);
details on the `.deb` build process and `vendor/` handling — root `CLAUDE.md`.

## Where to Run Commands

Builds are triggered from **Git Bash** (installed with Git for Windows): `tools/build-deb-wsl.sh`
is an `sh` script, and the package itself builds inside WSL. Everything else — `scp` and
`ssh da2 '…'` — works identically in both Git Bash and **PowerShell** (built-in Windows
PowerShell 5.1): both read the same `C:\Users\igors\.ssh\config`. Heavy lifting happens on
the provisioner within single quotes, leaving the local shell unaffected; Windows PowerShell 5.1
drops nested double quotes inside `ssh da2 '…'`, so remote commands are written without them.
In VS Code, any shell can be opened using the dropdown arrow next to the `+` button in the terminal panel.

Source code archives are no longer streamed to da2 (previously piped via `git archive | ssh`,
which PowerShell 5.1 corrupted): `.deb` files are transferred cleanly via `scp` and verified with SHA-256.

## One-Time Setup: SSH Alias `da2`

The provisioner's address, user, and host key alias are defined in `C:\Users\igors\.ssh\config` —
read by both SSH clients (Git Bash and Windows):

```
Host da2
    HostName 192.168.68.237
    User claude
    HostKeyAlias 192.168.25.30
```

- `HostName` — Provisioner IP address over Wi-Fi. Assigned via DHCP and has changed before
  (`192.168.25.30` → `192.168.68.237`): if `ssh da2` fails to connect, determine the new
  IP address and update this line.
- `User claude` — Key-based authentication (`~/.ssh/id_ed25519`) with passwordless sudo.
  For the `wlanpi` user, sudo prompts for a password, requiring `ssh -t da2 '…'`.
- `HostKeyAlias` — Preserves host key identity in `known_hosts` across address changes.

Verification (from any shell):

```sh
ssh da2 'hostname; dpkg-query -W cmprovision4'
```

Expected output: `wlanpi-da2` and the currently installed package version.

Packages on the provisioner reside in `~/cmprovision/build/` (previous versions remain there for rollback).

## One-Time Setup: WSL for Building

The WSL distribution (`cmprov-trixie`, Debian 13) requires `dpkg-dev`, `debhelper`,
`fakeroot`, `composer`, and PHP 8.4 **no lower than 8.4.27**: `composer.json` blocks
building on unpatched PHP (`pre-install-cmd: composer check-platform-reqs`). If the build
aborts on this check, run `apt-get install --only-upgrade 'php8.4*'` inside WSL. An
alternate distribution can be specified with `CMP_WSL_DISTRO`.

## 1. Build the `.deb` in WSL

**Commit your changes first:** the build archives a commit or tag, not uncommitted working tree edits (`git archive`).
A draft commit works fine for testing — you can amend it later (`git commit --amend`) or undo it (`git reset`).
From Git Bash at the repository root:

```sh
tools/build-deb-wsl.sh HEAD /tmp/cmprovision-pkg      # or a tag: tools/build-deb-wsl.sh v2.18.0 …
```

The script pipes `git archive` into WSL, where `tools/build-deb.sh` (`dpkg-buildpackage -b -us -uc`,
invoking `composer install --optimize-autoloader --no-dev` from `Makefile`) builds the package. The compiled
`.deb` is returned to the specified directory alongside `SHA256SUMS`. The version is taken from the top entry
of `debian/changelog`.

## 2. Upload to the Provisioner

```sh
scp /tmp/cmprovision-pkg/cmprovision4_<version>_all.deb /tmp/cmprovision-pkg/SHA256SUMS da2:cmprovision/build/
ssh da2 'cd ~/cmprovision/build && sha256sum -c --ignore-missing SHA256SUMS'
```

The second command must output `OK`, confirming integrity (Wi-Fi transfers have dropped packets before).
Install only when the stand is quiet: avoid updating while a module is writing an image, booting, or undergoing OS check.

## 3. Install

```sh
ssh da2 'sudo dpkg -i ~/cmprovision/build/cmprovision4_<version>_all.deb'
```

Before unpacking, `preinst` creates an automatic backup snapshot (starting with 2.17.0, stored in
`/var/backups/cmprovision`, keeping the last five snapshots; see [upgrading.md](upgrading.md)).
`postinst` runs `php artisan migrate --force`, fixes file permissions, regenerates the FPM pool
configuration, and restarts PHP-FPM; queue services are restarted by `dh_installsystemd`.
If dpkg warns about missing dependencies (unlikely on an already working system), run:
`ssh da2 'sudo apt-get -f install'`.

### The Identical Version Trap

debhelper stamps all package files with mtime from the changelog entry date. Installing the
**exact same** version a second time causes PHP opcache to ignore modified files, and dpkg may
not consider it an upgrade (skipping FPM restarts). Two solutions:

- **Bump the version for each build** (cleanest): for testing, tweak the top version in
  `debian/changelog` (e.g. `(2.6.0)` → `(2.6.0+test1)`) and commit — `git archive` only
  packages committed files. For an actual release, add a proper new entry at the top.
- **Or restart services manually** after reinstalling the same version:

```sh
ssh da2 'sudo systemctl restart php8.4-fpm cmprovision-queue cmprovision-queue-images'
```

## 4. Verification

- Open the provisioner web interface and check **Settings → Status**: both queue workers
  and dnsmasq should be green.
- Check package version: `ssh da2 'dpkg-query -W cmprovision4'`.
- Inspect recent logs (last 50 lines):
  `ssh da2 'sudo journalctl -u php8.4-fpm -u cmprovision-queue -n 50 --no-pager'`.

## 5. Rollback

List available packages, newest first:

```sh
ssh da2 'ls -t ~/cmprovision/build/*.deb'
```

Roll back to a previous version (substitute package name from the list):

```sh
ssh da2 'sudo apt-get install --reinstall --allow-downgrades ~/cmprovision/build/cmprovision4_2.6.0_all.deb'
```

This rolls back **code**: database migrations executed by the newer version remain in place.
Rolling back **data** (database, firmware images, `.env`) is performed separately using
`cmprovision-restore` from a snapshot; see `CLAUDE.md`.

## Post-Installation Disk Cleanup

Since packages are built in WSL, da2 maintains neither source trees nor build artifacts:
only uploaded `.deb` files (~55–60 MB each) remain. The provisioner's eMMC is only 29 GB,
hosting OS images (1–2 GB each) and backup snapshots. Prune old `.deb` files, keeping the
last 2–3 for rollback:

```sh
ssh da2 'ls -t ~/cmprovision/build/*.deb | tail -n +4 | xargs -r rm -f; df -h / | tail -1'
```

## 6. Once Verified — Push

Installing on the provisioner intentionally precedes pushing to git: the package is first
proven on real hardware. After successful verification:

```sh
git push origin <branch>
```

Follow standard procedure for release tags.
