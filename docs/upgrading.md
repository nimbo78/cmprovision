# Upgrade and restore

[Back to the README](../README.md) · [Operating the provisioner](operations.md)

## Install over the existing package

Version 2.6.4 adds Identify after the first-boot OS check, until the module's next reboot. It needs no database migration. Already provisioned sensors keep their existing first-boot script: upgrading the server alone does not add polling to them. Provision them again with the updated server to get the new behaviour. See [first-boot OS check](operations.md#first-boot-os-check).

The 2.6.3 migration adds a per-run destination-disk snapshot. Resume marks from older versions have no such snapshot and are cleared once during the upgrade: an interrupted old run writes from the beginning at its next start. New runs keep their normal split-image resume behavior. The re-provision project option starts disabled. Upgrading from 2.6.3 development builds that already applied this migration does not clear the marks again.

> [!IMPORTANT]
> Never run `apt remove` before upgrading: package removal deletes the database and storage.

```sh
sudo apt install ./cmprovision4_*_all.deb
```

Reload browser tabs that were open during the upgrade.

## Backups and rollback

Upgrading from a version older than 2.5 (1.x or 2.0–2.4) automatically takes a snapshot under `/var/backups/cmprovision/<timestamp>-<old-version>/`, including the old package. If all three snapshot attempts fail, the upgrade stops and leaves the old version installed. Try again with no modules on the stand.

To restore that version and its data:

```sh
sudo cmprovision-restore "/var/backups/cmprovision/<snapshot>"
```

For other upgrades, take the snapshot yourself before installing. Install `dpkg-repack` so the backup includes the installed package:

```sh
sudo apt install dpkg-repack
sudo cmprovision-backup
```

Replace `<snapshot>` with the actual snapshot directory. By default, backups omit uploaded images and packages. Use `sudo cmprovision-backup --with-uploads` to include them.

Snapshots take 100–150 MB each, so only the newest five stay: after a snapshot in `/var/backups/cmprovision` the older ones are removed. `--keep N` (or `CMPROVISION_BACKUP_KEEP=N` in the environment) sets another number, `--keep all` keeps every snapshot. `sudo cmprovision-backup --prune` applies the limit without making a snapshot, for example after an upgrade that left a long list. Only directories named `<date>-<time>-<version>` are ever removed; a destination you give by hand is trimmed only by `--prune`.

Without the old package in the snapshot, `cmprovision-restore` refuses to restore it over a different installed version. The helpers are installed with the package; their source is [`tools/backup.sh`](../tools/backup.sh) and [`tools/restore.sh`](../tools/restore.sh).

If you deliberately accept upgrading without an automatic rollback snapshot, the escape hatch is:

```sh
sudo CMPROVISION_NO_SNAPSHOT=1 apt install ./cmprovision4_*_all.deb
```

## PHP security updates

CVE-2026-109618 and CVE-2026-109619 affect PHP itself. Use the fixed patch release
for each supported branch:

| Branch | Minimum version |
| --- | --- |
| PHP 8.3 | 8.3.36 |
| PHP 8.4 | 8.4.27 |
| PHP 8.5 | 8.5.12 |

See the [PHP security announcement](https://www.php.net/archive/2026.php) and
the advisories for [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w)
and [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp).
The package requires fixed versions of the real CLI and FPM packages; the generic
`php-cli` and `php-fpm` metapackage versions cannot enforce these patch levels.
Install or upgrade with `apt`, which resolves these dependencies, after refreshing
the package index. Both CLI and FPM must be patched.

On Debian 13 / Raspberry Pi OS Trixie, update PHP 8.4 with no provisioning in progress:

```sh
sudo apt update
sudo apt install --only-upgrade php8.4-cli php8.4-fpm
sudo systemctl restart php8.4-fpm cmprovision-queue cmprovision-queue-images
php -v
php-fpm8.4 -v
```

APT also upgrades the installed extensions tied to the PHP common package.
Restarting the queue workers replaces their long-running PHP processes.
Check **Settings → Status** and reload the interface afterwards.

Composer accepts the fixed branches. Its `config.platform.php` value of `8.3.36`
is a dependency-resolution target. A pre-install check validates the actual CLI
before Composer installs dependencies. You can also run the check separately:

```sh
composer check-platform-reqs --lock --no-dev
```

This checks CLI; confirm FPM's version separately. Rebuild existing Docker bench
images to pick up the update. The image build checks the installed PHP against
the lock file. Upload Progress and the image-upload size limits remain unchanged;
disabling Upload Progress is not required once these fixes are installed.

## PHP and queue workers

From 2.5, the web interface has its own PHP-FPM pool, intended for batches of 40–48 modules starting together. The pool is configured in `/etc/php/<version>/fpm/pool.d/cmprovision.conf` and uses `/run/php/cmprovision-fpm.sock`.

After a system upgrade installs a new PHP version, recreate the pool for it:

```sh
sudo dpkg-reconfigure cmprovision4
```

Two workers run alongside the web interface:

| Service | Work |
| --- | --- |
| `cmprovision-queue` | Switch-port lookup and notifications |
| `cmprovision-queue-images` | Image checksums and image processing; also port lookup when the first worker is busy |

**Settings → Status** shows both workers and their logs. A module receives its script immediately; its switch and port appear a few seconds later. Notifications are grouped into one message per channel every 10 seconds.

SQLite uses WAL mode. Run manual `artisan` and `sqlite3` commands as `www-data`, for example:

```sh
sudo -u www-data /var/lib/cmprovision/artisan about
```

## Switch settings from 2.3 and 2.4

Version 2.4 moves switches into their own list, using the 2.3 switch settings as the first entry. The 2.3 settings remain available for rollback.

- Restoring a 2.3 snapshot restores its database. The next upgrade recreates the switch list from those settings.
- Installing the 2.3 package over 2.4 without restoring a snapshot leaves the 2.4 switch list in place.

Check **Settings → Ethernet switches** after either path.

## Other installation changes

The package's post-installation step adjusts existing servers:

- nginx gets `send_timeout 6h` for image downloads.
- PHP's `upload_tmp_dir` moves to disk next to the images.
- `.env` uses `APP_ENV=production` and `APP_DEBUG=false`.
- Firmware channels move from `stable` / `beta` to `default` / `latest`. Previously downloaded firmware remains available to projects using it.
