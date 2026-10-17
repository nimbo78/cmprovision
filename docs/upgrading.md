# Upgrade and restore

[Back to the README](../README.md) · [Operating the provisioner](operations.md)

## Install over the existing package

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

Without the old package in the snapshot, `cmprovision-restore` refuses to restore it over a different installed version. The helpers are installed with the package; their source is [`tools/backup.sh`](../tools/backup.sh) and [`tools/restore.sh`](../tools/restore.sh).

If you deliberately accept upgrading without an automatic rollback snapshot, the escape hatch is:

```sh
sudo CMPROVISION_NO_SNAPSHOT=1 apt install ./cmprovision4_*_all.deb
```

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

**Settings → Service status** shows both workers and their logs. A module receives its script immediately; its switch and port appear a few seconds later. Notifications are grouped into one message per channel every 10 seconds.

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
