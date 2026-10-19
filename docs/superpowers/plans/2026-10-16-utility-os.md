# Our own utility OS (2.7.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modules boot a utility OS we build ourselves (buildroot LTS 2025.02, arm64 kernel from raspberrypi/linux `stable_20260911`), kept current by one command and a monthly CI pull request. The package ships it next to the legacy OS with a switch in Settings, and `rpiboot` gets its second stage again (`bootfiles.bin`).

**Architecture:**
- `utility-os/` is a BR2_EXTERNAL tree named `CMPROVISION`; every version and hash lives in `utility-os/versions.env`. `tools/utility-os/build.sh` builds it on Debian/Ubuntu x86_64 (the WSL bench, GitHub's `ubuntu-24.04`) into `scriptexecute64/`. `test.sh` checks the result: the kernel config, the legacy command set, and a QEMU boot of the same kernel and initramfs that records the tools' messages. `update.sh` raises the pins.
- The package ships `scriptexecute/` (the legacy OS, now with `bootfiles.bin` and a `MANIFEST`) and `scriptexecute64/`. The www-data-owned link `etc/boot` picks the one modules get, and dnsmasq's `tftp-root` and `rpiboot -d` follow it. `tools/boot-link`, run as www-data by `postinst`, `prerm` and `cmprovision-restore`, makes and repairs the link. The Settings section `UtilityOsSettings` (service `App\Services\UtilityOs`) replaces it atomically.
- The new OS's init sends the legacy start parameters in the legacy formats plus `uos=<version>`. The server keeps that in `cms.utility_os`, shows it on the module card and names it in the start log line.

**Tech Stack:** buildroot 2025.02.x (BR2_EXTERNAL, Bootlin aarch64 glibc toolchain), Linux 6.18 (raspberrypi/linux), BusyBox 1.37, QEMU (`qemu-system-aarch64 -M virt`), POSIX sh, Python 3 (stdlib only), GitHub Actions; Laravel 13 (Laravel 8 layout), Livewire 3, PHPUnit 12 on PHP 8.4 (WSL bench), debhelper 13.

**Spec:** docs/superpowers/specs/2026-10-16-utility-os-design.md

## Conventions

```bash
SP=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad
REPO="/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
```

- PHP tests run on the WSL bench `cmprov-trixie` (PHP 8.4): `bash "$SP/wsl-bench/tests-ok.sh" '<filter>'` syncs the working tree into `/root/dev` and runs phpunit with that filter. Its last lines are `phpunit exit N` and phpunit's summary. The whole suite: `bash "$SP/wsl-bench/suite-ok.sh"` ends with `php rc=0 js rc=0`.
- The bench sleeps without an open session. Keep it awake (Task 0, step 1).
- Commands inside the bench go as a script on stdin: `cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s` … `EOF`. Never put `$` in `wsl.exe` arguments: it expands early.
- The buildroot work happens in the bench's own filesystem, never in OneDrive: sources in `/root/uos/src` (a copy of the working tree), buildroot and its output in `/root/uos/work`, downloads and ccache in `/root/uos/cache`. The helpers `$SP/wsl-bench/32-uos-in.sh` (the tree in), `33-uos-build.sh` and `35-uos-test.sh` (build and checks as systemd units, logs in `/root/uos`) and `34-uos-out.sh` (the built files out) come with Task 7.
- Edits go through the Edit tool. Patch scripts, if any, are written with Write and run with `python -I`. One heredoc per command.
- Commit messages go to `$SP/commit-270-tN.txt` and in with `git commit -q -F`. Each ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Release, merge, push, the GitHub settings and live checks on hardware happen only on the user's word (Task 13).

## Global Constraints

**The contract with the server (the module script does not change)**
- `cmdline.txt` of the new OS is the legacy one with `&uos=<version>` at the end of the URL:
  `readjumper script=http://172.20.0.1/scriptexecute?serial={serial}&model={model}&storagesize={storagesize}&mac={mac}&inversejumper={jumper}&memorysize={memorysize}&temp={temp}&cid={cid}&csd={csd}&bootmode={bootmode}&uos=<version>`
- Placeholders get the legacy init's values in the legacy formats (`etc/init.d/S99scriptexec` of `scriptexecute/scriptexecute.img`):
  - `{serial}`: the `Serial` line of `/proc/cpuinfo` after `: `; if absent, `/proc/device-tree/serial-number` without its NUL.
  - `{model}`: `/proc/device-tree/model` without its NUL, spaces → `+`.
  - `{storagesize}`: `/sys/block/mmcblk0/size`.
  - `{cid}`, `{csd}`: `/sys/block/mmcblk0/device/cid` and `/csd`.
  - `{bootmode}`: the 4th byte of `/proc/device-tree/chosen/bootloader/boot-mode`, decimal.
  - `{memorysize}`: `MemTotal` of `/proc/meminfo`, kB.
  - `{temp}`: `vcgencmd measure_temp` without `temp=` (`48.3'C`); without an answer, `/sys/class/thermal/thermal_zone0/temp` in the same form.
  - `{mac}`: `/sys/class/net/eth0/address`.
  - `{jumper}`: only with `readjumper`, the levels of GPIO 5, 13, 21 in a row (`111`) from `pinctrl`.
  - A missing source gives an empty value.
- The server takes `uos` only as `[0-9A-Za-z.-]{1,32}`; anything else is no value.
- The OS version is `YYYY.MM` (`2026.10`), and a second version in a month is `YYYY.MM.N` (`2026.10.1`).

**The new OS**
- Kernel: arm64 `bcm2711_defconfig` plus `utility-os/board/cmprovision/linux.fragment`. `CONFIG_MODULES` stays on; the modules are built and removed from the image in `post-build.sh`. Everything the OS needs is `=y`: `test.sh` (Task 8) lists them, and the fragment (Task 7) adds the five the defconfig lacks. `LOCALVERSION="-cmprovision"`, image `Image.gz` → `kernel8.img`.
- TFTP directory `scriptexecute64/`: `kernel8.img`, `scriptexecute.img` (cpio, xz), `start4.elf`, `fixup4.dat`, `bcm2711-rpi-cm4.dtb`, `bcm2711-rpi-4-b.dtb`, `bcm2711-rpi-400.dtb`, `overlays/spi-gpio40-45.dtbo`, `overlays/dwc2.dtbo`, `bootfiles.bin`, `config.txt`, `cmdline.txt`, `MANIFEST`.
- `rpiboot` 20261002 and later carries no `bootcode4.bin` of its own, and usbboot keeps it only inside `firmware/bootfiles.bin` (tar member `2711/bootcode4.bin` with the files it needs). With `bootfiles.bin` in its `-d` directory, rpiboot takes the second stage from it and everything else from the directory. Both OS directories carry `bootfiles.bin` of usbboot tag `20261002-115811` (1556480 bytes, SHA256 `177cf37ad7f4a1ff2de44ae18b14d67d02cd32c6c44042f3f845278c58e46e89`). This replaces the spec's «bootcode4.bin in both directories»: same aim, the form usbboot ships now.
- `config.txt` is the legacy one plus `arm_64bit=1` and `initramfs scriptexecute.img followkernel`.
- The command set is a superset of `utility-os/legacy-commands.txt`: the legacy OS's commands minus those its header leaves out with their reasons (rpi-userland's camera, multimedia and VideoCore test tools; the old `dtoverlay` hooks; sysfsutils' test programs; `e2scrub`).
- Console as in the legacy OS: progress on tty1, a getty on tty2 (root, no password). No network login service: no telnetd, no sshd, and no init script that starts one.
- Size: `kernel8.img` + `scriptexecute.img` ≤ 32 MiB.
- Sources: buildroot from its release tarball (SHA256 as in its `.sign`). The kernel and `raspberrypi/utils` come as GitHub's archives of a commit, each with its SHA256 pinned; buildroot's git download is never shallow, which for raspberrypi/linux would mean its 5.2 GB history. The boot files and `bootfiles.bin` come as single files by tag. Every source has its hash line (`BR2_DOWNLOAD_FORCE_CHECK_HASHES`).

**The package**
- The link `/var/lib/cmprovision/etc/boot` → `../scriptexecute` or `../scriptexecute64` (relative). Only www-data makes, repairs or changes it (`runuser -u www-data` in `postinst`, `prerm`, `restore.sh`; the web app runs as www-data anyway).
- The shipped `etc/dnsmasq.conf` (a conffile) keeps `tftp-root=/var/lib/cmprovision/scriptexecute`; `boot-link fix` moves the live file's line to the link.
- `postinst` must not fail: every new command there ends in `|| echo …` or `|| true`.
- No new sudo rule, no new Debian dependency of the package (QEMU and the build tools are build-host only).

**Texts (English UI), verbatim**
- Settings section title «Utility OS»; table header «Utility OS», «Kernel», «Built»; the current row «modules get this one»; the button «Serve this one»; its confirmation «Serve the <version> utility OS to modules? Switch when no module is booting: a module that is booting now may get the files of both.»
- Notices: «Modules that boot from now on get <label>.», «Could not switch: <reason>»; no current one: «The link <path> points at no utility OS of the package: modules get nothing. Choose one.»; dnsmasq elsewhere: «dnsmasq serves <tftp-root> (tftp-root in etc/dnsmasq.conf), so this switch does not change what modules get over the network. Set tftp-root=<link> there and restart dnsmasq.»
- `<label>` is the version and the bits: «2026.10 (64-bit)», «legacy (32-bit)».
- Service status row «utility OS: <label>», or «utility OS: none».
- Module card row «Utility OS» with «2026.10» or «legacy».
- Log sentence added to the start and resume lines: « Utility OS: 2026.10.» / « Utility OS: legacy.»
- Laravel log on a switch: `Utility OS: modules now get <label> (<dir>, before: <dir or none>) by <name>`.

## Review Focus

1. A snapshot of 2.6 or older restored with its own `.deb` (`cmprovision-restore` downgrades the package first): the 2.6 package has no `boot-link`, so restore must skip it and keep the snapshot's own `tftp-root`. Pinned in Task 1 (`test_restore_sets_the_link_up_like_postinst` checks the `-x` guard).
2. A `MANIFEST` that is missing or damaged (a half-copied directory) must make that OS unavailable, not a choice that serves broken files. Pinned in Task 3 (`test_a_directory_without_a_good_manifest_is_no_os`).
3. A `dnsmasq.conf` that is missing or unreadable must not break the Settings page. Pinned in Task 3 (`test_tftp_root_that_follows_the_link`, the null case) and Task 4 (no warning on null).
4. `update.sh` without network, or with GitHub answering garbage, must fail loudly (exit 1) and never write a half-updated `versions.env`. CI must not open a PR then. Pinned in Task 9 (`test_check_fails_without_an_answer`, `test_a_failed_download_leaves_versions_env_alone`, `test_a_buildroot_that_does_not_match_its_signature_file_is_refused`).
5. A module whose kernel has no `Serial` line in `/proc/cpuinfo`, or whose `vcgencmd` cannot reach the firmware, must still start with the right serial and a temperature from sysfs. Pinned in Task 6 (`test_serial_and_temperature_have_fallbacks`).


## Decided while writing this plan (against the spec, or where it was silent)

The research of 2026-10-16 (raspberrypi/linux at `stable_20260911`, raspberrypi/utils, usbboot, firmware, buildroot 2025.02.18 sources, GitHub docs) changed these. Each one goes into the ledger as a ruling when its task runs.

1. **`bootfiles.bin`, not `bootcode4.bin`, in both directories.** usbboot removed the standalone `firmware/2711/bootcode4.bin` on 2026-09-28 and keeps it only inside `firmware/bootfiles.bin` (with `memsys*.bin`, `bootmain`, …). rpiboot uses `bootfiles.bin` when it is in its `-d` directory. The spec's aim stands: rpiboot finds the CM4's second stage in both OS directories.
2. **The rpiboot unit gets `Restart=on-failure`.** rpiboot exits on a file it cannot serve, which is what a CM4 meets now on da2, and nothing restarted it.
3. **The kernel and `raspberrypi/utils` come as GitHub archives of a commit.** The spec said a shallow git clone, but buildroot's git download is never shallow (5.2 GB for raspberrypi/linux). The kernel builds in `output/build/linux-custom`. The hashes are pinned all the same.
4. **`legal-info` gets the kernel's GPL-2.0 text under another name.** buildroot 2025.02 hashes the pre-6.17 text.
5. **Our packages are `cmp-rpi-boot-files`, `cmp-rpi-utils` and `cmp-usbboot-bootfiles`.** The `cmp-` prefix keeps them apart from buildroot's own names.
6. **`raspberrypi/utils` is pinned at `b57c48e`**, its head on 2026-10-16. The spec's `7d64443` was the head on the day it was written.
7. **More commands are left out of the legacy list, each with its reason in the file:**
   - `dtoverlay-pre` and `dtoverlay-post`: hooks of the old userland;
   - `dlist_test`, `get_device`, `get_driver`, `get_module`: sysfsutils 2.1.1 installs only `systool`;
   - `e2scrub` and `e2scrub_all`: they need bash and LVM.
8. **`S50crond` goes.** buildroot installs it with BusyBox's `crond` applet, and the legacy OS started no daemon of that kind.
9. **A PR made with `GITHUB_TOKEN` does start a run of its own, but only after approval.** The spec said it starts none. The docs say this.

---

### Task 0: The bench, and serving through the link

No repository changes. This proves the switch idea on the bench before anything relies on it: dnsmasq must serve the link's current target without a restart.

- [x] **Step 1: Keep the bench awake for the session**

Run (Bash, `run_in_background: true`): `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 43200`

- [x] **Step 2: Space, cores and host packages**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
set -e
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
df -h /root | tail -1; nproc; free -g | sed -n 2p
export DEBIAN_FRONTEND=noninteractive
printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d
apt-get update -qq
apt-get install -y -qq --no-install-recommends build-essential bc cpio rsync unzip file wget git python3 perl patch xz-utils bzip2 gzip ca-certificates qemu-system-arm curl dnsmasq-base > /root/uos-host-packages.log 2>&1 || tail -5 /root/uos-host-packages.log
rm -f /usr/sbin/policy-rc.d
for c in make gcc g++ bc cpio rsync unzip file wget git python3 perl patch xz bzip2 qemu-system-aarch64 curl dnsmasq; do command -v $c >/dev/null || echo "MISSING $c"; done
echo host-ready
EOF
```
Expected: at least 40G available on `/root` (buildroot output about 20 GB plus caches), several cores, no `MISSING` line, `host-ready`. With less space, stop and tell the user. The build cannot run there.

- [x] **Step 3: dnsmasq serves the link's target, and switching needs no restart**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
set -e
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
D=/srv/uos-tftp-check
rm -rf $D && mkdir -p $D/a $D/b && echo A > $D/a/hello && echo B > $D/b/hello && chmod -R a+rX $D
ln -s a $D/boot
/usr/sbin/dnsmasq --keep-in-foreground --port=0 --enable-tftp --tftp-root=$D/boot --listen-address=127.0.0.1 --bind-interfaces --pid-file=$D/pid --log-facility=$D/log &
sleep 1
printf 'first: '; curl -s tftp://127.0.0.1/hello
ln -sfn b $D/boot
printf 'after the switch: '; curl -s tftp://127.0.0.1/hello
kill $(cat $D/pid); rm -rf $D
EOF
```
Expected: `first: A`, then `after the switch: B`. If the second line is still `A`, dnsmasq resolved its root at start. Record a ledger ruling then: Task 3's `UtilityOs::switchTo()` must restart dnsmasq after replacing the link, through the existing `config('cmprovision.dnsmasq_restart')` (the sudo rule allows it). Add a test to Task 3 that the command runs.

- [x] **Step 4: Ledger**

Write the free space, the core count and the result of step 3 into the ledger.

---

### Task 1: `boot-link`, the package around the link, 2.7.0~dev1

**Files:**
- Create: `tools/boot-link`
- Create: `tests/Unit/BootLinkTest.php`
- Modify: `tests/Unit/PackagingTest.php` (four tests at the end)
- Modify: `debian/rules` (`override_dh_install`), `debian/postinst` (after the TFTP port warning), `debian/prerm` (before `#DEBHELPER#`), `debian/postrm` (purge), `debian/cmprovision-rpiboot.service`, `tools/restore.sh` (after the ownership block), `debian/changelog` (new top entry)

**Interfaces:**
- Consumes: —
- Produces: `/usr/lib/cmprovision/boot-link fix <app>` and `boot-link legacy <app>`. Exit 0 on success, 1 when `<app>/etc/boot` is a real directory, 2 on a wrong call. After `fix`, `<app>/etc/boot` is a relative link to `../scriptexecute` or `../scriptexecute64`, and `tftp-root=<app>/scriptexecute` in `<app>/etc/dnsmasq.conf` reads `tftp-root=<app>/etc/boot`.

- [x] **Step 1: The dev version first**

Add at the top of `debian/changelog`:
```
cmprovision4 (2.7.0~dev1) unstable; urgency=medium

  * Work in progress: our own utility OS, built with buildroot, next to
    the legacy one with a switch in Settings
    (docs/superpowers/specs/2026-10-16-utility-os-design.md).

 -- nimbo78 <igor.sin.78@gmail.com>  Sat, 17 Oct 2026 10:00:00 +0300
```
Commit: write `$SP/commit-270-t0.txt`:
```
2.7.0~dev1: our own utility OS begins

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add debian/changelog && git commit -q -F "$SP/commit-270-t0.txt"`

- [x] **Step 2: Write the failing test of the helper**

Create `tests/Unit/BootLinkTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** tools/boot-link (2.7.0): the link etc/boot that dnsmasq's tftp-root and rpiboot -d follow to the utility OS modules
    get. postinst, prerm and cmprovision-restore run it as the web user; here it runs under sh in an application
    directory of its own */
class BootLinkTest extends TestCase
{
    protected string $app;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v sh 2>/dev/null')) === '')
            $this->markTestSkipped('needs sh');
        $this->app = sys_get_temp_dir().'/boot-link-'.bin2hex(random_bytes(4));
        mkdir($this->app.'/etc', 0755, true);
        mkdir($this->app.'/scriptexecute');
        mkdir($this->app.'/scriptexecute64');
        file_put_contents($this->conf(), "port=0\nenable-tftp\ntftp-root={$this->app}/scriptexecute\n\nlog-dhcp\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->app));
        parent::tearDown();
    }

    protected function conf(): string
    {
        return $this->app.'/etc/dnsmasq.conf';
    }

    protected function link(): string
    {
        return $this->app.'/etc/boot';
    }

    /** runs the helper; its exit code */
    protected function helper(string ...$args): int
    {
        exec('sh '.escapeshellarg(dirname(__DIR__, 2).'/tools/boot-link').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $code);
        return $code;
    }

    public function test_fix_points_a_new_link_at_the_legacy_os_and_moves_tftp_root_to_it()
    {
        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()), 'relative: it works wherever the directory is mounted');
        $this->assertSame("port=0\nenable-tftp\ntftp-root={$this->app}/etc/boot\n\nlog-dhcp\n", file_get_contents($this->conf()),
            'only that line changes');
    }

    public function test_fix_twice_changes_nothing()
    {
        $this->helper('fix', $this->app);
        $conf = file_get_contents($this->conf());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
        $this->assertSame($conf, file_get_contents($this->conf()));
    }

    public function test_fix_keeps_the_new_os_once_chosen()
    {
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute64', readlink($this->link()), 'an upgrade keeps the operator\'s choice');
    }

    public function test_fix_points_a_link_to_a_missing_os_back_at_the_legacy_one()
    {
        // a snapshot of a later version restored here, or a directory gone with a downgrade
        rmdir($this->app.'/scriptexecute64');
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_fix_points_a_foreign_link_back_at_the_legacy_one()
    {
        symlink('/etc', $this->link());

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_fix_leaves_a_tftp_root_set_by_hand_alone()
    {
        file_put_contents($this->conf(), "enable-tftp\ntftp-root=/srv/tftp\n");

        $this->assertSame(0, $this->helper('fix', $this->app));

        $this->assertSame("enable-tftp\ntftp-root=/srv/tftp\n", file_get_contents($this->conf()));
    }

    public function test_legacy_points_the_link_back()
    {
        symlink('../scriptexecute64', $this->link());

        $this->assertSame(0, $this->helper('legacy', $this->app));

        $this->assertSame('../scriptexecute', readlink($this->link()));
    }

    public function test_a_directory_in_the_way_is_refused()
    {
        mkdir($this->link());

        $this->assertSame(1, $this->helper('fix', $this->app));

        $this->assertTrue(is_dir($this->link()) && !is_link($this->link()));
        $this->assertFileDoesNotExist($this->link().'/scriptexecute', 'ln never puts the link inside it');
    }

    public function test_a_wrong_call_is_refused()
    {
        $this->assertSame(2, $this->helper('switch', $this->app));
        $this->assertSame(2, $this->helper('fix', $this->app.'/nowhere'));
        $this->assertSame(2, $this->helper('fix'));
        $this->assertFalse(is_link($this->link()));
    }
}
```

- [x] **Step 3: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'BootLinkTest'`
Expected: `phpunit exit 1`, the tests fail (sh cannot open `tools/boot-link`).

- [x] **Step 4: Write the helper**

Create `tools/boot-link`:
```sh
#!/bin/sh
# cmprovision (2.7.0): the utility OS modules get. <app>/etc/boot points at scriptexecute/ (the legacy OS) or
# scriptexecute64/ (ours, see docs/utility-os.md); dnsmasq's tftp-root and rpiboot -d follow it, so a switch needs no
# restart.
#   boot-link fix <app>      make the link if there is none and point it back at the legacy OS if it points anywhere but
#                            an OS of the package; turn tftp-root=<app>/scriptexecute in <app>/etc/dnsmasq.conf into
#                            tftp-root=<app>/etc/boot (the shipped conffile keeps the old line: a changed one would stop
#                            an upgrade at dpkg's question)
#   boot-link legacy <app>   point the link at the legacy OS (going back below 2.7, whose package has no scriptexecute64/)
# postinst, prerm and cmprovision-restore run it as the web user (runuser -u www-data): etc/ is its directory, and root
# reading or writing there would follow whatever link the web user planted.
set -eu

usage() { echo "usage: boot-link fix|legacy <application directory>" >&2; exit 2; }
[ $# -eq 2 ] || usage
CMD=$1
APP=$2
[ -d "$APP/etc" ] || usage
LINK=$APP/etc/boot

if [ -d "$LINK" ] && [ ! -L "$LINK" ]; then
    echo "boot-link: $LINK is a directory, not the link to the utility OS: move it away" >&2
    exit 1
fi

# -n: replace the link itself, never follow it into the directory it points at
point() { ln -sfn "../$1" "$LINK"; }

case "$CMD" in
    fix)
        target=$(readlink "$LINK" 2>/dev/null || true)
        case "$target" in
            ../scriptexecute|../scriptexecute64) [ -d "$APP/${target#../}" ] || point scriptexecute ;;
            *) point scriptexecute ;;
        esac
        CONF=$APP/etc/dnsmasq.conf
        if [ -f "$CONF" ] && grep -qxF "tftp-root=$APP/scriptexecute" "$CONF"; then
            sed -i "s#^tftp-root=$APP/scriptexecute\$#tftp-root=$LINK#" "$CONF"
        fi
        ;;
    legacy)
        point scriptexecute
        ;;
    *)
        usage
        ;;
esac
```

- [x] **Step 5: Run the helper's test again**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'BootLinkTest'`
Expected: `phpunit exit 0`, `OK (9 tests, …)`.

- [x] **Step 6: Write the failing packaging tests**

Append to `tests/Unit/PackagingTest.php`, before the closing `}` of the class:
```php
    public function test_the_utility_os_link_is_set_up_as_the_web_user_before_dnsmasq_starts()
    {
        // etc/ is the web user's directory: root reading and writing there would follow a symlink it planted
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('runuser -u www-data -- /usr/lib/cmprovision/boot-link fix /var/lib/cmprovision ||', $postinst);
        $this->assertLessThan(strpos($postinst, 'boot-link fix'), strpos($postinst, 'fix_ownership ||'), 'etc/ is the web user\'s first');
        $this->assertLessThan(strpos($postinst, 'deb-systemd-invoke restart cmprovision-dnsmasq'), strpos($postinst, 'boot-link fix'),
            'an upgrade restarts dnsmasq with the new tftp-root');
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'boot-link fix'));
        $this->assertStringContainsString("\tinstall -D -m 755 tools/boot-link debian/cmprovision4/usr/lib/cmprovision/boot-link\n",
            $this->file('debian/rules'));
    }

    public function test_dnsmasq_and_rpiboot_follow_the_link_and_the_shipped_conffile_stays()
    {
        // the application edits dnsmasq.conf (dhcp-host=): a changed shipped copy would stop an upgrade at dpkg's question,
        // so postinst moves tftp-root in the live file instead
        $this->assertStringContainsString("tftp-root=/var/lib/cmprovision/scriptexecute\n", $this->file('etc/dnsmasq.conf'));
        $rpiboot = $this->file('debian/cmprovision-rpiboot.service');
        $this->assertStringContainsString("ExecStart=/usr/bin/rpiboot -l -d /var/lib/cmprovision/etc/boot\n", $rpiboot);
        // rpiboot exits on a file it cannot serve (since 20261002 a CM4 asks for one the old directory lacked)
        $this->assertStringContainsString("Restart=on-failure\nRestartSec=2\n", $rpiboot);
    }

    public function test_going_below_2_7_points_the_link_at_the_legacy_os_and_purge_removes_it()
    {
        // the older package has no scriptexecute64/, and tftp-root stays on the link
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.7.0~"', $prerm);
        $this->assertStringContainsString('runuser -u www-data -- /usr/lib/cmprovision/boot-link legacy /var/lib/cmprovision || true', $prerm);
        $this->assertLessThan(strpos($prerm, '#DEBHELPER#'), strpos($prerm, 'boot-link legacy'));
        $this->assertStringContainsString('rm -f /var/lib/cmprovision/etc/boot', $this->file('debian/postrm'));
    }

    public function test_restore_sets_the_link_up_like_postinst()
    {
        // a snapshot of 2.6 or older names scriptexecute/ in tftp-root, and --data-only puts no link back; a snapshot's older
        // package (restore reinstalls it first) has no boot-link, and its own tftp-root is right for it
        $restore = $this->file('tools/restore.sh');
        $this->assertStringContainsString("if [ -x /usr/lib/cmprovision/boot-link ]; then\n    runuser -u www-data -- /usr/lib/cmprovision/boot-link fix \"\$APP\"", $restore);
        $this->assertLessThan(strpos($restore, 'boot-link fix'), strpos($restore, 'chown -R -h www-data:www-data'), 'after the owners are set');
        $this->assertLessThan(strpos($restore, "systemctl restart 'php*-fpm' nginx cmprovision-dnsmasq"), strpos($restore, 'boot-link fix'),
            'before dnsmasq starts again');
    }
```

- [x] **Step 7: Run them to see them fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'PackagingTest'`
Expected: `phpunit exit 1`, the four new tests fail and the old ones pass.

- [x] **Step 8: Wire the helper into the package**

In `debian/rules`, `override_dh_install`, after the `wifi-networks` line:
```make
	install -D -m 755 tools/boot-link debian/cmprovision4/usr/lib/cmprovision/boot-link
```

In `debian/postinst`, after the block that ends with `echo "         (for tftpd-hpa: systemctl disable --now tftpd-hpa), then: systemctl restart cmprovision-dnsmasq"` and its `fi`:
```sh

    # The utility OS modules get (2.7.0): etc/boot points at scriptexecute/ (the legacy OS) or scriptexecute64/, and
    # dnsmasq's tftp-root and rpiboot -d follow it. boot-link makes it (the legacy OS on an upgrade) and moves tftp-root
    # of the live dnsmasq.conf to it. As the web user, like artisan: etc/ is its directory, and root reading and writing
    # there would follow a symlink it planted (sed -i would leave it a copy of any file)
    runuser -u www-data -- /usr/lib/cmprovision/boot-link fix /var/lib/cmprovision || echo "Could not set up the utility OS link in /var/lib/cmprovision/etc"
```

In `debian/prerm`, before `#DEBHELPER#`:
```sh
# The utility OS link (2.7.0): going back below 2.7 takes scriptexecute64/ away while tftp-root stays on the link, so the
# link points at the legacy OS from now on (as the web user, like postinst: etc/ is its directory)
if [ "$1" = "upgrade" ] && [ -n "$2" ] && dpkg --compare-versions "$2" lt "2.7.0~"; then
    runuser -u www-data -- /usr/lib/cmprovision/boot-link legacy /var/lib/cmprovision || true
fi

```

In `debian/postrm`, inside `if [ "$1" = "purge" ]; then`, after the `rmdir /run/cmprovision …` line:
```sh
    # the utility OS link postinst made (2.7.0)
    rm -f /var/lib/cmprovision/etc/boot
```

In `debian/cmprovision-rpiboot.service`, the `[Service]` section becomes (rpiboot exits when a device asks for a file it cannot serve, and nothing started it again):
```
[Service]
Type=simple
ExecStart=/usr/bin/rpiboot -l -d /var/lib/cmprovision/etc/boot
Restart=on-failure
RestartSec=2
```

In `tools/restore.sh`, after the `for f in "$APP/.env" "$APP"/database/database.sqlite*; do … done` loop:
```sh
# The utility OS link (2.7.0) as postinst sets it up: a snapshot of 2.6 or older names scriptexecute/ in tftp-root, and
# --data-only puts no link back. A snapshot's older package (reinstalled above) has no boot-link: its tftp-root is its own
if [ -x /usr/lib/cmprovision/boot-link ]; then
    runuser -u www-data -- /usr/lib/cmprovision/boot-link fix "$APP" || echo "could not set up the utility OS link $APP/etc/boot"
fi
```

- [x] **Step 9: Run the packaging and helper tests**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'PackagingTest|BootLinkTest'`
Expected: `phpunit exit 0`.

- [x] **Step 10: Shell syntax**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
cd /root/dev && for f in tools/boot-link debian/postinst debian/prerm debian/postrm tools/restore.sh; do dash -n "$f" && echo "ok $f"; done
EOF
```
Expected: five `ok` lines.

- [x] **Step 11: Commit**

Write `$SP/commit-270-t1.txt`:
```
The utility OS link etc/boot, which dnsmasq and rpiboot follow

tools/boot-link (as the web user from postinst, prerm and restore)
makes the link to the legacy OS, repairs one that points anywhere else,
and moves tftp-root of the live dnsmasq.conf to it; going below 2.7
points it back at the legacy OS, purge removes it. rpiboot follows the
link too and is started again when it exits.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add tools/boot-link tests/Unit/BootLinkTest.php tests/Unit/PackagingTest.php debian/rules debian/postinst debian/prerm debian/postrm debian/cmprovision-rpiboot.service tools/restore.sh && git commit -q -F "$SP/commit-270-t1.txt"`

---

### Task 2: The legacy OS gets `bootfiles.bin` and a `MANIFEST`

This is the fix rpiboot needs now. On da2, `rpiboot 20261005` finds no BCM2711 second stage in `scriptexecute/`: it prints «Failed to open second stage bootloader (bootcode4.bin)» and exits, so CM4 USB boot is broken. The same `MANIFEST` writer serves the new OS later (Task 7).

**Files:**
- Create: `scriptexecute/bootfiles.bin` (binary, from usbboot tag `20261002-115811`)
- Create: `tools/utility-os/manifest.sh`
- Create: `scriptexecute/MANIFEST` (written by `manifest.sh`)
- Create: `tests/Unit/UtilityOsFilesTest.php`
- Modify: `.gitattributes` (`MANIFEST` stays LF, `bootfiles.bin` binary)

**Interfaces:**
- Consumes: —
- Produces: `sh tools/utility-os/manifest.sh <dir> key=value…` writes `<dir>/MANIFEST`: a `#` comment line, the `key=value` arguments in their order, then `file=<sha256> <size> <path>` for every file under `<dir>` except `MANIFEST`, sorted by path (`LC_ALL=C`), paths relative to `<dir>` with `/`. The legacy keys: `version=legacy`, `bits=32`, `kernel=Linux 5.4`, `built=2022-11`, `source=raspberrypi/scriptexecutor (buildroot 2019.11.1)`.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/UtilityOsFilesTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The utility OS directories of the package (2.7.0): each MANIFEST lists exactly its files with their SHA256 and sizes,
    and each directory carries usbboot's bootfiles.bin, which rpiboot (20261002 and later) takes the CM4's second stage from */
class UtilityOsFilesTest extends TestCase
{
    const BOOTFILES_SHA256 = '177cf37ad7f4a1ff2de44ae18b14d67d02cd32c6c44042f3f845278c58e46e89';

    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function dirs(): array
    {
        return [['scriptexecute']];
    }

    /** the file= lines of a MANIFEST: path => [sha256, size] */
    protected static function listed(string $manifest): array
    {
        preg_match_all('/^file=([0-9a-f]{64}) (\d+) (.+)$/m', $manifest, $m, PREG_SET_ORDER);
        $files = [];
        foreach ($m as [, $sha256, $size, $path])
            $files[$path] = [$sha256, (int) $size];
        return $files;
    }

    /** the files under a directory except MANIFEST: path => [sha256, size] */
    protected static function present(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file)
        {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($path !== 'MANIFEST')
                $files[$path] = [hash_file('sha256', $file->getPathname()), $file->getSize()];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    #[DataProvider('dirs')]
    public function test_the_manifest_lists_exactly_the_files_with_their_hashes(string $dir)
    {
        $listed = self::listed(file_get_contents(self::root()."/$dir/MANIFEST"));

        $this->assertSame(self::present(self::root()."/$dir"), $listed);
    }

    /** the member names of a tar archive (headers of 512 bytes, each member padded to 512; usbboot's is GNU format) */
    protected static function tarNames(string $file): array
    {
        $data = file_get_contents($file);
        $names = [];
        for ($at = 0; $at + 512 <= strlen($data); )
        {
            $header = substr($data, $at, 512);
            $name = rtrim(substr($header, 0, 100), "\0");
            if ($name === '')
                break;
            // only POSIX ustar keeps a name prefix there; GNU tar keeps times in the same bytes
            $prefix = substr($header, 257, 6) === "ustar\0" ? rtrim(substr($header, 345, 155), "\0") : '';
            $names[] = ($prefix !== '' ? $prefix.'/' : '').$name;
            $at += 512 + (int) ceil(octdec(trim(substr($header, 124, 12), " \0")) / 512) * 512;
        }
        return $names;
    }

    #[DataProvider('dirs')]
    public function test_rpiboot_finds_the_second_stage(string $dir)
    {
        $this->assertContains('2711/bootcode4.bin', self::tarNames(self::root()."/$dir/bootfiles.bin"), 'the CM4\'s second stage is in it');
    }

    public function test_the_legacy_directory_has_bootfiles_bin_of_the_pinned_usbboot()
    {
        // usbboot tag 20261002-115811; ours follows UOS_BOOTFILES_SHA256 of versions.env (update.sh moves it)
        $this->assertSame(self::BOOTFILES_SHA256, hash_file('sha256', self::root().'/scriptexecute/bootfiles.bin'));
    }

    public function test_the_legacy_manifest_says_what_it_is()
    {
        $manifest = file_get_contents(self::root().'/scriptexecute/MANIFEST');
        foreach (["version=legacy\n", "bits=32\n", "kernel=Linux 5.4\n", "built=2022-11\n"] as $line)
            $this->assertStringContainsString($line, $manifest);
    }

    public function test_manifest_sh_lists_every_file_sorted_with_its_hash_and_size()
    {
        if (trim((string) shell_exec('command -v sh sha256sum 2>/dev/null | wc -l')) !== '2')
            $this->markTestSkipped('needs sh and sha256sum');
        $dir = sys_get_temp_dir().'/uos-manifest-'.bin2hex(random_bytes(4));
        mkdir($dir.'/overlays', 0755, true);
        file_put_contents($dir.'/kernel8.img', 'kernel');
        file_put_contents($dir.'/overlays/dwc2.dtbo', 'overlay');
        file_put_contents($dir.'/MANIFEST', 'old');
        try
        {
            exec('sh '.escapeshellarg(self::root().'/tools/utility-os/manifest.sh').' '.escapeshellarg($dir)
                .' version=2026.10 bits=64 '.escapeshellarg('kernel=Linux 6.18.50 (raspberrypi/linux stable_20260911)').' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $this->assertSame("# cmprovision utility OS: what it is and its files (tools/utility-os/manifest.sh)\n"
                ."version=2026.10\nbits=64\nkernel=Linux 6.18.50 (raspberrypi/linux stable_20260911)\n"
                .'file='.hash('sha256', 'kernel')." 6 kernel8.img\n"
                .'file='.hash('sha256', 'overlay')." 7 overlays/dwc2.dtbo\n", file_get_contents($dir.'/MANIFEST'));
        }
        finally
        {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsFilesTest'`
Expected: `phpunit exit 1`, no `scriptexecute/MANIFEST`, no `bootfiles.bin`, no `manifest.sh`.

- [x] **Step 3: The MANIFEST writer**

Create `tools/utility-os/manifest.sh`:
```sh
#!/bin/sh
# Write <dir>/MANIFEST (2.7.0): what the utility OS in <dir> is (the key=value arguments, in their order) and every file in
# it with its SHA256 and size. Settings → Utility OS shows it, and tests/Unit/UtilityOsFilesTest.php checks it.
#   tools/utility-os/manifest.sh <dir> key=value...
set -eu
[ $# -ge 1 ] && [ -d "$1" ] || { echo "usage: manifest.sh <dir> key=value..." >&2; exit 2; }
DIR=$1
shift
{
    echo "# cmprovision utility OS: what it is and its files (tools/utility-os/manifest.sh)"
    for kv in "$@"; do
        printf '%s\n' "$kv"
    done
    (cd "$DIR" && find . -type f ! -path ./MANIFEST ! -path ./MANIFEST.new | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r f; do
        printf 'file=%s %s %s\n' "$(sha256sum "$f" | cut -d' ' -f1)" "$(wc -c < "$f" | tr -d ' ')" "$f"
    done)
} > "$DIR/MANIFEST.new"
mv "$DIR/MANIFEST.new" "$DIR/MANIFEST"
```

- [x] **Step 4: `bootfiles.bin` for the legacy directory, and its MANIFEST**

Add to `.gitattributes`, after `*.bin binary`:
```
# what a utility OS directory holds (tools/utility-os/manifest.sh): LF everywhere, the hashes are of the committed bytes
MANIFEST text eol=lf
```

Run:
```bash
cd "$REPO"
curl -fsSL -o scriptexecute/bootfiles.bin https://raw.githubusercontent.com/raspberrypi/usbboot/20261002-115811/firmware/bootfiles.bin
echo "177cf37ad7f4a1ff2de44ae18b14d67d02cd32c6c44042f3f845278c58e46e89  scriptexecute/bootfiles.bin" | sha256sum -c -
sh tools/utility-os/manifest.sh scriptexecute version=legacy bits=32 "kernel=Linux 5.4" built=2022-11 "source=raspberrypi/scriptexecutor (buildroot 2019.11.1)"
head -7 scriptexecute/MANIFEST; grep -c '^file=' scriptexecute/MANIFEST
```
Expected: `scriptexecute/bootfiles.bin: OK`. The MANIFEST head is the comment, the five keys and the first `file=` line (`bcm2710-rpi-cm3.dtb`). `16` files: the 15 legacy files plus `bootfiles.bin`.

(Git Bash on Windows has `sha256sum`, `find`, `sort` and `wc`; `manifest.sh` gives the same bytes there as on Linux because `.gitattributes` keeps every file of the directory LF or binary.)

- [x] **Step 5: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsFilesTest|PackagingTest'`
Expected: `phpunit exit 0`.

- [x] **Step 6: Commit**

Write `$SP/commit-270-t2.txt`:
```
rpiboot boots a CM4 again: bootfiles.bin in the legacy OS directory

rpiboot 20261002 carries no bootcode4.bin of its own, and usbboot now
ships it only inside firmware/bootfiles.bin (2711/bootcode4.bin with the
files it needs); with bootfiles.bin in its -d directory rpiboot takes
the second stage from it. Without it, the first CM4 made rpiboot exit
with "Failed to open second stage bootloader". A MANIFEST says what the
OS is and lists its files with their SHA256.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add .gitattributes scriptexecute/bootfiles.bin scriptexecute/MANIFEST tools/utility-os/manifest.sh tests/Unit/UtilityOsFilesTest.php && git update-index --chmod=+x tools/utility-os/manifest.sh && git commit -q -F "$SP/commit-270-t2.txt"`

---

### Task 3: `App\Services\UtilityOs` — the systems, the current one, the switch

**Files:**
- Create: `app/Services/UtilityOs.php`
- Create: `tests/Support/UtilityOsTree.php`
- Create: `tests/Feature/UtilityOsTest.php`
- Modify: `config/cmprovision.php` (two keys at the end)

**Interfaces:**
- Consumes: the `MANIFEST` format of Task 2 (`key=value` lines, `file=<sha256> <size> <path>` lines, `#` comments).
- Produces:
  - `UtilityOs::DIRS = ['scriptexecute64', 'scriptexecute']`;
  - `systems(): array` — `dir => ['dir' => string, 'version' => string, 'bits' => ?string, 'kernel' => ?string, 'built' => ?string, …, 'files' => [path => ['sha256' => string, 'size' => int]]]`, only for directories with a good `MANIFEST`, in `DIRS` order;
  - `manifest(string $dir): ?array` — the same array for one directory, or null;
  - `current(): ?string` — the directory the link points at, or null;
  - `link(): string`;
  - `switchTo(string $dir): void` — throws `InvalidArgumentException` for a directory that is no OS of the package, `RuntimeException` when the link cannot be replaced;
  - `tftpRoot(): ?string`;
  - `tftpRootFollowsLink(): ?bool`;
  - `static label(?array $system): string` — «2026.10 (64-bit)», «legacy (32-bit)», «none».
  - Config: `cmprovision.boot_root` (default `base_path()`), `cmprovision.boot_link` (default `base_path('etc/boot')`).
  - Test trait `Tests\Support\UtilityOsTree`: `makeTree(): void`, `$this->tree` (the root), `osDir(string $dir, string $manifestLines)`.

- [x] **Step 1: Write the test tree and the failing test**

Create `tests/Support/UtilityOsTree.php`:
```php
<?php

namespace Tests\Support;

/** A throwaway application directory with both utility OSes, their MANIFESTs, the link etc/boot to the legacy one and a
    dnsmasq.conf that follows the link; config points App\Services\UtilityOs at it */
trait UtilityOsTree
{
    protected string $tree;

    protected function makeTree(): void
    {
        $this->tree = sys_get_temp_dir().'/uos-tree-'.bin2hex(random_bytes(4));
        mkdir($this->tree.'/etc', 0755, true);
        $this->osDir('scriptexecute', "version=legacy\nbits=32\nkernel=Linux 5.4\nbuilt=2022-11\n");
        $this->osDir('scriptexecute64', "version=2026.10\nbits=64\nkernel=Linux 6.18.50 (raspberrypi/linux stable_20260911)\nbuilt=2026-10-17\n");
        symlink('../scriptexecute', $this->tree.'/etc/boot');
        file_put_contents($this->tree.'/etc/dnsmasq.conf', "port=0\nenable-tftp\ntftp-root={$this->tree}/etc/boot\n");
        config(['cmprovision.boot_root' => $this->tree, 'cmprovision.boot_link' => $this->tree.'/etc/boot',
                'cmprovision.dnsmasq_conf' => $this->tree.'/etc/dnsmasq.conf']);
        $this->beforeApplicationDestroyed(fn () => exec('rm -rf '.escapeshellarg($this->tree)));
    }

    protected function osDir(string $dir, string $manifestLines): void
    {
        @mkdir($this->tree.'/'.$dir);
        file_put_contents($this->tree.'/'.$dir.'/kernel8.img', 'kernel of '.$dir);
        file_put_contents($this->tree.'/'.$dir.'/MANIFEST', "# cmprovision utility OS\n".$manifestLines
            .'file='.hash('sha256', 'kernel of '.$dir).' '.strlen('kernel of '.$dir)." kernel8.img\n");
    }
}
```

Create `tests/Feature/UtilityOsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Services\UtilityOs;
use Tests\Support\UtilityOsTree;
use Tests\TestCase;

/** App\Services\UtilityOs (2.7.0): the utility OSes of the package, the one the link etc/boot gives modules, the switch */
class UtilityOsTest extends TestCase
{
    use UtilityOsTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTree();
    }

    public function test_both_systems_with_what_their_manifests_say()
    {
        $systems = app(UtilityOs::class)->systems();

        $this->assertSame(['scriptexecute64', 'scriptexecute'], array_keys($systems), 'ours first');
        $this->assertSame('2026.10', $systems['scriptexecute64']['version']);
        $this->assertSame('64', $systems['scriptexecute64']['bits']);
        $this->assertSame('Linux 5.4', $systems['scriptexecute']['kernel']);
        $this->assertSame(['sha256' => hash('sha256', 'kernel of scriptexecute'), 'size' => 23],
            $systems['scriptexecute']['files']['kernel8.img']);
        $this->assertSame('2026.10 (64-bit)', UtilityOs::label($systems['scriptexecute64']));
        $this->assertSame('legacy (32-bit)', UtilityOs::label($systems['scriptexecute']));
        $this->assertSame('none', UtilityOs::label(null));
    }

    public function test_the_current_one_is_where_the_link_points()
    {
        $os = app(UtilityOs::class);
        $this->assertSame('scriptexecute', $os->current());

        unlink($this->tree.'/etc/boot');
        $this->assertNull($os->current(), 'no link');

        symlink('/etc', $this->tree.'/etc/boot');
        $this->assertNull($os->current(), 'a link elsewhere is no OS of the package');
    }

    public function test_switching_replaces_the_link_and_leaves_nothing_behind()
    {
        $os = app(UtilityOs::class);

        $os->switchTo('scriptexecute64');

        $this->assertSame('../scriptexecute64', readlink($this->tree.'/etc/boot'));
        $this->assertSame('scriptexecute64', $os->current());
        $this->assertSame([], glob($this->tree.'/etc/.boot-*'), 'the new link was renamed over the old one');
    }

    public function test_only_an_os_of_the_package_can_be_served()
    {
        $os = app(UtilityOs::class);
        foreach (['../../etc', '/etc', 'storage', ''] as $dir)
        {
            try
            {
                $os->switchTo($dir);
                $this->fail("switched to '$dir'");
            }
            catch (\InvalidArgumentException $e)
            {
                $this->assertSame('../scriptexecute', readlink($this->tree.'/etc/boot'), "'$dir' left the link alone");
            }
        }
    }

    public function test_a_directory_without_a_good_manifest_is_no_os()
    {
        // a half-copied directory must not become a choice that serves broken files
        file_put_contents($this->tree.'/scriptexecute64/MANIFEST', "# cut short\nbits=64\n");
        $os = app(UtilityOs::class);

        $this->assertSame(['scriptexecute'], array_keys($os->systems()));
        $this->expectException(\InvalidArgumentException::class);
        $os->switchTo('scriptexecute64');
    }

    public function test_tftp_root_that_follows_the_link()
    {
        $os = app(UtilityOs::class);
        $this->assertTrue($os->tftpRootFollowsLink());
        $this->assertSame($this->tree.'/etc/boot', $os->tftpRoot());

        file_put_contents($this->tree.'/etc/dnsmasq.conf', "enable-tftp\ntftp-root={$this->tree}/scriptexecute\n");
        $this->assertFalse($os->tftpRootFollowsLink());
        $this->assertSame($this->tree.'/scriptexecute', $os->tftpRoot());

        unlink($this->tree.'/etc/dnsmasq.conf');
        $this->assertNull($os->tftpRootFollowsLink(), 'unknown, not a warning');
        $this->assertNull($os->tftpRoot());
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsTest'`
Expected: `phpunit exit 2` or `1`: `Class "App\Services\UtilityOs" not found`.

- [x] **Step 3: The configuration**

In `config/cmprovision.php`, before the closing `];`:
```php
    /*
    |--------------------------------------------------------------------------
    | Utility OS (2.7.0)
    |--------------------------------------------------------------------------
    |
    | The utility OSes of the package (scriptexecute/, scriptexecute64/) live in boot_root; boot_link is the link
    | dnsmasq's tftp-root and rpiboot -d follow to the one modules get (tools/boot-link sets it up).
    |
    */

    'boot_root' => env('CMPROVISION_BOOT_ROOT', base_path()),

    'boot_link' => env('CMPROVISION_BOOT_LINK', base_path('etc/boot')),
```

- [x] **Step 4: The service**

Create `app/Services/UtilityOs.php`:
```php
<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/* The utility OS modules boot to be provisioned (2.7.0). The package ships two in the application directory, each with a
   MANIFEST (tools/utility-os/manifest.sh): scriptexecute64/ (ours, buildroot, docs/utility-os.md) and scriptexecute/ (the
   legacy one from raspberrypi/scriptexecutor). The link etc/boot picks the one modules get: dnsmasq's tftp-root and
   rpiboot -d follow it on every file, so a switch is replacing the link and takes effect at once. */
class UtilityOs
{
    /** the directories an OS of the package lives in, in the order the page lists them */
    const DIRS = ['scriptexecute64', 'scriptexecute'];

    public function link(): string
    {
        return config('cmprovision.boot_link');
    }

    protected function root(): string
    {
        return rtrim(config('cmprovision.boot_root'), '/');
    }

    /** Every OS of the package with a good MANIFEST: dir => what it says */
    public function systems(): array
    {
        $systems = [];
        foreach (self::DIRS as $dir)
            if (($manifest = $this->manifest($dir)) !== null)
                $systems[$dir] = $manifest;
        return $systems;
    }

    /** The MANIFEST of one directory: its key=value lines, the file= lines as files; null when it is no OS of the package */
    public function manifest(string $dir): ?array
    {
        if (!in_array($dir, self::DIRS, true))
            return null;
        $text = @file_get_contents($this->root().'/'.$dir.'/MANIFEST');
        if ($text === false)
            return null;
        $values = ['dir' => $dir, 'files' => []];
        foreach (preg_split('/\r?\n/', $text) as $line)
        {
            if ($line === '' || $line[0] === '#' || !str_contains($line, '='))
                continue;
            [$key, $value] = explode('=', $line, 2);
            if ($key === 'file')
            {
                if (preg_match('/^([0-9a-f]{64}) (\d+) (.+)$/', $value, $m))
                    $values['files'][$m[3]] = ['sha256' => $m[1], 'size' => (int) $m[2]];
            }
            elseif (!in_array($key, ['dir', 'files'], true))
                $values[$key] = $value;
        }
        // a MANIFEST cut short (a half-copied directory) says neither what it is nor which files it has
        return isset($values['version']) && $values['files'] ? $values : null;
    }

    /** The directory modules get now: where the link points, when that is an OS of the package */
    public function current(): ?string
    {
        $target = @readlink($this->link());
        if ($target === false)
            return null;
        foreach (self::DIRS as $dir)
            if ($target === '../'.$dir)
                return $dir;
        return null;
    }

    /** Serve the OS in $dir to modules from now on: a new link next to the old one, renamed over it, so there is never a
        moment without one */
    public function switchTo(string $dir): void
    {
        if ($this->manifest($dir) === null)
            throw new InvalidArgumentException(($dir === '' ? 'nothing' : $dir).' is not a utility OS of the package');
        $link = $this->link();
        $new = dirname($link).'/.boot-'.bin2hex(random_bytes(4));
        if (!@symlink('../'.$dir, $new))
            throw new RuntimeException('cannot create '.$new);
        if (!@rename($new, $link))
        {
            @unlink($new);
            throw new RuntimeException('cannot replace '.$link);
        }
    }

    /** Where dnsmasq serves files from (tftp-root of its configuration), or null when the configuration cannot be read */
    public function tftpRoot(): ?string
    {
        $conf = @file_get_contents(config('cmprovision.dnsmasq_conf'));
        if ($conf === false || !preg_match('/^tftp-root=(.*)$/m', $conf, $m))
            return null;
        return rtrim(trim($m[1]), '/');
    }

    /** Does dnsmasq follow the link? null: its configuration cannot be read, so the page does not warn */
    public function tftpRootFollowsLink(): ?bool
    {
        if (@file_get_contents(config('cmprovision.dnsmasq_conf')) === false)
            return null;
        return $this->tftpRoot() === $this->link();
    }

    /** "2026.10 (64-bit)", "legacy (32-bit)": an OS in one line */
    public static function label(?array $system): string
    {
        if (!$system)
            return 'none';
        return $system['version'].(isset($system['bits']) ? ' ('.$system['bits'].'-bit)' : '');
    }
}
```

- [x] **Step 5: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsTest'`
Expected: `phpunit exit 0`, `OK (6 tests, …)`.

If Task 0 step 3 showed that dnsmasq does not follow a switched link, add to `switchTo()` after the rename: `exec(config('cmprovision.dnsmasq_restart'), $out, $rc);`, plus a test that sets `cmprovision.dnsmasq_restart` to `touch <tree>/restarted` and asserts the file. Ledger the ruling.

- [x] **Step 6: Commit**

Write `$SP/commit-270-t3.txt`:
```
UtilityOs: the utility OSes of the package and the switch

The systems come from their MANIFESTs, the current one from the link
etc/boot; switching renames a new link over the old one and accepts
only an OS of the package with a good MANIFEST.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add app/Services/UtilityOs.php config/cmprovision.php tests/Support/UtilityOsTree.php tests/Feature/UtilityOsTest.php && git commit -q -F "$SP/commit-270-t3.txt"`

---

### Task 4: Settings → Utility OS, and its Service status row

**Files:**
- Create: `app/Http/Livewire/UtilityOsSettings.php`
- Create: `resources/views/livewire/utility-os-settings.blade.php`
- Create: `tests/Feature/UtilityOsSettingsTest.php`
- Modify: `resources/views/livewire/settings.blade.php` (a section before «Service status», a row in its table)
- Modify: `app/Http/Livewire/Settings.php` (`$utilityOs` in `render()`)

**Interfaces:**
- Consumes: `App\Services\UtilityOs` (Task 3): `systems()`, `current()`, `manifest()`, `switchTo()`, `link()`, `tftpRoot()`, `tftpRootFollowsLink()`, `label()`; `Tests\Support\UtilityOsTree`.
- Produces: Livewire component `utility-os-settings` with the action `serve(string $dir)`; `Settings::$utilityOs` (string label).

- [x] **Step 1: Write the failing test**

Create `tests/Feature/UtilityOsSettingsTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use App\Http\Livewire\UtilityOsSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\UtilityOsTree;
use Tests\TestCase;

/** Settings → Utility OS (2.7.0): both OSes of the package, the one modules get, the switch and who made it */
class UtilityOsSettingsTest extends TestCase
{
    use RefreshDatabase, UtilityOsTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTree();
        $this->actingAs(User::factory()->create(['name' => 'igor']));
    }

    public function test_the_section_lists_both_and_marks_the_one_modules_get()
    {
        Livewire::test(UtilityOsSettings::class)
            ->assertSeeInOrder(['2026.10', '64-bit', 'Linux 6.18.50', '2026-10-17', 'Serve this one',
                                'legacy', '32-bit', 'Linux 5.4', '2022-11', 'modules get this one'])
            ->assertDontSee('dnsmasq serves');
    }

    public function test_serving_the_other_one_switches_the_link_and_logs_who_did_it()
    {
        Log::shouldReceive('info')->once()
            ->with('Utility OS: modules now get 2026.10 (64-bit) (scriptexecute64, before: scriptexecute) by igor');

        Livewire::test(UtilityOsSettings::class)
            ->call('serve', 'scriptexecute64')
            ->assertSee('Modules that boot from now on get 2026.10 (64-bit).')
            ->assertSeeInOrder(['2026.10', 'modules get this one', 'legacy', 'Serve this one']);

        $this->assertSame('../scriptexecute64', readlink($this->tree.'/etc/boot'));
    }

    public function test_a_directory_outside_the_package_is_refused()
    {
        Livewire::test(UtilityOsSettings::class)
            ->call('serve', '../../etc')
            ->assertSee('Could not switch: ../../etc is not a utility OS of the package');

        $this->assertSame('../scriptexecute', readlink($this->tree.'/etc/boot'));
    }

    public function test_the_section_warns_when_dnsmasq_does_not_follow_the_link()
    {
        file_put_contents($this->tree.'/etc/dnsmasq.conf', "enable-tftp\ntftp-root={$this->tree}/scriptexecute\n");

        Livewire::test(UtilityOsSettings::class)
            ->assertSee("dnsmasq serves {$this->tree}/scriptexecute (tftp-root in etc/dnsmasq.conf), so this switch does not change what modules get over the network.")
            ->assertSee("Set tftp-root={$this->tree}/etc/boot there and restart dnsmasq.");
    }

    public function test_no_warning_when_dnsmasq_conf_cannot_be_read_and_none_served_is_said()
    {
        unlink($this->tree.'/etc/dnsmasq.conf');
        unlink($this->tree.'/etc/boot');

        Livewire::test(UtilityOsSettings::class)
            ->assertDontSee('dnsmasq serves')
            ->assertSee("The link {$this->tree}/etc/boot points at no utility OS of the package: modules get nothing. Choose one.");
    }

    public function test_the_settings_page_has_the_section_and_the_service_status_row()
    {
        $this->get(route('settings'))->assertOk()
            ->assertSee('Utility OS')
            ->assertSeeLivewire('utility-os-settings');
        Livewire::test(Settings::class)->assertSee('utility OS: legacy (32-bit)');
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsSettingsTest'`
Expected: `phpunit exit 1` or `2`: `Class "App\Http\Livewire\UtilityOsSettings" not found`.

- [x] **Step 3: The component and its view**

Create `app/Http/Livewire/UtilityOsSettings.php`:
```php
<?php

namespace App\Http\Livewire;

use App\Services\UtilityOs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/* Settings page section (2.7.0): the utility OSes of the package and the one modules get. Serving the other one replaces
   the link etc/boot, which dnsmasq and rpiboot follow (App\Services\UtilityOs) */
class UtilityOsSettings extends Component
{
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $os = app(UtilityOs::class);
        return view('livewire.utility-os-settings', [
            'systems' => $os->systems(),
            'current' => $os->current(),
            'link' => $os->link(),
            'follows' => $os->tftpRootFollowsLink(),
            'tftpRoot' => $os->tftpRoot(),
        ]);
    }

    public function serve(string $dir)
    {
        $os = app(UtilityOs::class);
        $before = $os->current();
        try
        {
            $os->switchTo($dir);
        }
        catch (\Throwable $e)
        {
            $this->notice = 'Could not switch: '.$e->getMessage();
            $this->noticeIsError = true;
            return;
        }
        $label = UtilityOs::label($os->manifest($dir));
        Log::info('Utility OS: modules now get '.$label.' ('.$dir.', before: '.($before ?? 'none').') by '.(Auth::user()->name ?? 'unknown'));
        $this->notice = 'Modules that boot from now on get '.$label.'.';
        $this->noticeIsError = false;
    }
}
```

Create `resources/views/livewire/utility-os-settings.blade.php`:
```blade
{{-- Settings → Utility OS (2.7.0): the OSes of the package, the one modules get, the switch --}}
<div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md">
    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @if ($follows === false)
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded mb-4" role="status">
            dnsmasq serves {{ $tftpRoot }} (tftp-root in etc/dnsmasq.conf), so this switch does not change what modules get over the network.
            Set tftp-root={{ $link }} there and restart dnsmasq.
        </div>
    @endif

    <div class="cmp-card-scroll">
        <table class="cmp-table">
            <thead><tr><th>Utility OS</th><th>Kernel</th><th>Built</th><th></th></tr></thead>
            <tbody>
            @foreach ($systems as $dir => $s)
                <tr wire:key="uos-{{ $dir }}">
                    <td class="font-semibold whitespace-nowrap">{{ $s['version'] }} <span class="font-normal text-fg-muted">{{ $s['bits'] ?? '?' }}-bit</span></td>
                    <td>{{ $s['kernel'] ?? '' }}</td>
                    <td class="whitespace-nowrap">{{ $s['built'] ?? '' }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if ($current === $dir)
                            <span class="text-pos font-semibold">modules get this one</span>
                        @else
                            <button type="button" wire:click="serve('{{ $dir }}')"
                                    wire:confirm="Serve the {{ $s['version'] }} utility OS to modules? Switch when no module is booting: a module that is booting now may get the files of both."
                                    class="cmp-btn cmp-btn-secondary cmp-btn-small">Serve this one</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($current === null)
        <p class="mt-3 text-sm text-neg">The link {{ $link }} points at no utility OS of the package: modules get nothing. Choose one.</p>
    @endif
</div>
```

- [x] **Step 4: The section on the Settings page and the status row**

In `resources/views/livewire/settings.blade.php`, insert before the line `        <div class="md:col-span-1">` that opens the «Service status» heading block:
```blade
        <div class="md:col-span-1" id="utility-os">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Utility OS</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    The system a module boots from the network or over USB to be provisioned. The package has two: ours,
                    built from current versions (64-bit), and the legacy one from 2022 (32-bit). A switch takes effect for
                    the next file a module asks for.
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <livewire:utility-os-settings />
        </div>

```

In the same file, in the «Service status» table, before the `<tr>` of «web interface», add:
```blade
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($utilityOs !== 'none') class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> utility OS: {{ $utilityOs }}</span>
                            </li>
                        </ul>
                    </td>
                    <td></td>
                </tr>
```

In `app/Http/Livewire/Settings.php`, add `$utilityOs` to the first `public` line (after `$uplinkSpareRunning`), and in `render()` after `$this->uplinkSpareRunning = …`:
```php
        $os = app(\App\Services\UtilityOs::class);
        $current = $os->current();
        $this->utilityOs = \App\Services\UtilityOs::label($current ? $os->manifest($current) : null);
```

- [x] **Step 5: Run the tests**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsSettingsTest|ServiceStatusTest|StaticIpSettingsTest|TokenGuardTest|PageLayoutTest|OverlayLayerTest'`
Expected: `phpunit exit 0`.

- [x] **Step 6: Commit**

Write `$SP/commit-270-t4.txt`:
```
Settings: the utility OS modules get, and the switch

Both OSes of the package with version, kernel and build date; Serve this
one replaces the link after a confirmation that warns about modules
booting now; the switch goes to the Laravel log with the operator's
name. A warning when dnsmasq does not serve through the link, and a
Service status row.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add app/Http/Livewire/UtilityOsSettings.php resources/views/livewire/utility-os-settings.blade.php tests/Feature/UtilityOsSettingsTest.php resources/views/livewire/settings.blade.php app/Http/Livewire/Settings.php && git commit -q -F "$SP/commit-270-t4.txt"`

---

### Task 5: The module's utility OS: `cms.utility_os`, the card, the log line

**Files:**
- Create: `database/migrations/2026_10_17_100000_add_utility_os_to_cms.php`
- Create: `tests/Feature/UtilityOsModuleTest.php`
- Modify: `app/Models/Cm.php` (`$fillable`, a method)
- Modify: `app/Http/Controllers/ScriptExecuteController.php` (`moduleFields()`, the start and resume log lines, a helper)
- Modify: `resources/views/livewire/cm-card.blade.php` (a row after «Temperature»)
- Modify: `tests/Feature/ImageResumeStartTest.php:148` (the resume line gains the sentence)

**Interfaces:**
- Consumes: the `uos=` start parameter of the new OS (Task 6's `cmdline.txt`).
- Produces: column `cms.utility_os` (string 32, nullable); `Cm::utilityOsLabel(): string` («2026.10» or «legacy»); `ScriptExecuteController::utilityOsOf(Request): ?string` (protected static).

- [x] **Step 1: Write the failing test**

Create `tests/Feature/UtilityOsModuleTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The utility OS a module started with (2.7.0): ours sends uos=<version>, the legacy one nothing; the card and the
    start log line name it */
class UtilityOsModuleTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=Raspberry+Pi+Compute+Module+4+Rev+1.1&storagesize=61071360&mac=e4:5f:01:62:9f:d3&bootmode=2';

    protected function setUp(): void
    {
        parent::setUp();
        $project = Project::create(['name' => 'no image', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
    }

    protected function startLine(): string
    {
        return (string) Cmlog::where('cm', self::SERIAL)->where('msg', 'like', 'Provisioning started%')->latest('id')->value('msg');
    }

    public function test_ours_names_its_version()
    {
        $this->get(self::START.'&uos=2026.10')->assertOk();

        $this->assertSame('2026.10', Cm::where('serial', self::SERIAL)->value('utility_os'));
        $this->assertStringStartsWith('Provisioning started. Utility OS: 2026.10.', $this->startLine());
    }

    public function test_the_legacy_one_sends_nothing()
    {
        $this->get(self::START)->assertOk();

        $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'));
        $this->assertStringStartsWith('Provisioning started. Utility OS: legacy.', $this->startLine());
    }

    public function test_a_start_with_the_legacy_one_forgets_the_last_version()
    {
        $this->get(self::START.'&uos=2026.10')->assertOk();
        $this->get(self::START)->assertOk();

        $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'));
    }

    public function test_only_a_plain_version_is_taken()
    {
        foreach (['<b>1</b>', str_repeat('9', 33), '2026.10 rc', ''] as $bad)
        {
            $this->get(self::START.'&uos='.rawurlencode($bad))->assertOk();
            $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'), "'$bad'");
        }
    }

    public function test_the_card_names_it()
    {
        $this->actingAs(User::factory()->create());
        $this->get(self::START.'&uos=2026.10')->assertOk();
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->assertSeeInOrder(['Utility OS', '2026.10']);

        $this->get(self::START)->assertOk();
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->assertSeeInOrder(['Utility OS', 'legacy']);
    }
}
```
(`CmCard::mount($serial)` takes the serial; the project without an image makes the start end with «No image to write.».)

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsModuleTest'`
Expected: `phpunit exit 1`: no column `utility_os`, and the start line has no «Utility OS».

- [x] **Step 3: The column**

Create `database/migrations/2026_10_17_100000_add_utility_os_to_cms.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.7.0: the utility OS of the module's last start, from the uos= parameter of ours (empty: the legacy one) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->string('utility_os', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn('utility_os');
        });
    }
};
```

- [x] **Step 4: The model**

In `app/Models/Cm.php`, add `'utility_os'` to `$fillable` (on the line with `'durable_bytes', 'durable_image_id', 'durable_at', 'progress_base',`), and add the method next to `canIdentify()`:
```php
    /** The utility OS of the last start, as the card and the log name it: the version of ours, or "legacy" (2.7.0) */
    public function utilityOsLabel(): string
    {
        return $this->utility_os ?: 'legacy';
    }
```

- [x] **Step 5: The start, the resume and the parameter**

In `app/Http/Controllers/ScriptExecuteController.php`:

In `moduleFields()`, add to the returned array after `'temp1' => $req->query('temp'),`:
```php
            'utility_os' => self::utilityOsOf($req),
```

After `moduleFields()`, add:
```php
    /* The utility OS the module booted (2.7.0): ours sends uos=<version> (utility-os/board/cmprovision/cmdline.txt), the
       legacy one nothing */
    protected static function utilityOsOf(Request $req): ?string
    {
        $uos = (string) $req->query('uos');
        return preg_match('/^[0-9A-Za-z.-]{1,32}$/', $uos) ? $uos : null;
    }
```

In `startProvisoning()`, the line `$msg = "Provisioning started.";` becomes:
```php
        $msg = "Provisioning started. Utility OS: ".$this->cm->utilityOsLabel().".";
```

In `resumeProvisioning()`, the log call becomes:
```php
        $this->logInfo('Provisioning resumed: the module restarted during the write; continuing from '
            .ImagePieces::gb($from).' of '.ImagePieces::gb($total).'.'
            .' Utility OS: '.$this->cm->utilityOsLabel().'.'
            .($this->cm->os_check === 'skipped' ? ' OS check skipped: the module booted over USB.' : ''));
```

In `tests/Feature/ImageResumeStartTest.php:148`, the expected line becomes `'Provisioning resumed: the module restarted during the write; continuing from 0.0 GB of 0.0 GB. Utility OS: legacy.'`.

- [x] **Step 6: The card**

In `resources/views/livewire/cm-card.blade.php`, after the `<tr>` of «Temperature» (line 142):
```blade
                <tr><td class="text-fg-muted">Utility OS</td><td>{{ $cm->utilityOsLabel() }}</td></tr>
```

- [x] **Step 7: Run the tests**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsModuleTest|ImageResumeStartTest|ModuleCardTest|ResolveBoardsTest|ProvisioningLogTest|ScriptExecuteRobustnessTest|StandNetworkTest|UpgradeMigrationsTest'`
Expected: `phpunit exit 0`.

- [x] **Step 8: Commit**

Write `$SP/commit-270-t5.txt`:
```
The module's utility OS: uos= in cms.utility_os, on the card, in the log

Ours sends its version, the legacy one nothing; only a plain version
is taken. The start and resume lines say which OS the run had.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add database/migrations/2026_10_17_100000_add_utility_os_to_cms.php tests/Feature/UtilityOsModuleTest.php app/Models/Cm.php app/Http/Controllers/ScriptExecuteController.php resources/views/livewire/cm-card.blade.php tests/Feature/ImageResumeStartTest.php && git commit -q -F "$SP/commit-270-t5.txt"`

---

### Task 6: The contract of the new OS: its init, `cmdline.txt` and `config.txt`

The init is written and tested before any build: under the bench's `busybox sh` (BusyBox 1.37, the version of the new OS) with stubs for the hardware and the network.

**Files:**
- Create: `utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec` (mode 755 in git: `git update-index --chmod=+x`)
- Create: `utility-os/board/cmprovision/cmdline.txt`
- Create: `utility-os/board/cmprovision/config.txt`
- Create: `tests/Feature/UtilityOsInitTest.php`

**Interfaces:**
- Consumes: —
- Produces:
  - `S99scriptexec start` — exits 0 when stopped by a non-200, non-5xx answer or a missing `script=`; otherwise it ends after the script with «cmprovision: the script ended with exit code N: nothing more to do»;
  - environment `UOS_PROC`, `UOS_SYS`, `UOS_TMP` (defaults `/proc`, `/sys`, `/tmp`);
  - `cmdline.txt` and `config.txt` templates with `@UOS_VERSION@` (only in `cmdline.txt`), which `post-image.sh` (Task 7) fills in.

- [x] **Step 1: Write the failing test**

Create `tests/Feature/UtilityOsInitTest.php`:
```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The init of our utility OS (utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec) under BusyBox sh:
 * the same start parameters in the same formats as the legacy OS's init, plus uos=. The kernel's files come from a tree in
 * the test's directory (UOS_PROC, UOS_SYS, UOS_TMP), the hardware and the network from stubs. And its two templates.
 */
class UtilityOsInitTest extends TestCase
{
    const APPLETS = ['sh', 'cat', 'tr', 'sed', 'awk', 'od', 'head', 'tail'];
    const VERSION = '2026.10';

    protected $dir, $busybox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->busybox = trim((string) shell_exec('command -v busybox 2>/dev/null'));
        $applets = $this->busybox ? explode("\n", (string) shell_exec(escapeshellarg($this->busybox).' --list')) : [];
        foreach (self::APPLETS as $applet)
            if (!in_array($applet, $applets, true))
                $this->markTestSkipped('needs busybox with '.$applet);
        $this->dir = sys_get_temp_dir().'/cmprovision-uos-init-'.uniqid();
        foreach (['bin', 'tmp', 'responses'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        foreach (self::APPLETS as $applet)
            symlink($this->busybox, $this->dir.'/bin/'.$applet);
        // the module's world: every call noted; curl answers from responses/<n>.code and <n>.body
        foreach (['udhcpc', 'ip', 'getty', 'mount'] as $name)
            $this->stub($name, 'echo "'.$name.' $*" >> "$T/calls"');
        $this->stub('sleep', 'echo "sleep $*" >> "$T/calls"');
        $this->stub('vcgencmd', '[ -f "$T/vcgencmd" ] && cat "$T/vcgencmd" || exit 1');
        // pinctrl lev 5 prints the level and a newline (pinctrl.c, raspberrypi/utils)
        $this->stub('pinctrl', '[ "$1" = lev ] && [ -f "$T/gpio/$2" ] || exit 1'."\n".'cat "$T/gpio/$2"; echo');
        $this->stub('curl', implode("\n", [
            'n=$(( $(cat "$T/curl.count" 2>/dev/null || echo 0) + 1 )); echo $n > "$T/curl.count"',
            'for a in "$@"; do printf "%s\n" "$a"; done >> "$T/curl.args"; echo --- >> "$T/curl.args"',
            'out=; while [ $# -gt 0 ]; do [ "$1" = -o ] && out=$2; shift; done',
            'code=$(cat "$T/responses/$n.code" 2>/dev/null || echo 000)',
            '[ "$code" = 000 ] && { printf 000; exit 7; }',
            'cat "$T/responses/$n.body" > "$out"; printf "%s" "$code"',
        ]));
        $this->world();
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\nT=".escapeshellarg($this->dir)."\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    protected static function path(string $file): string
    {
        return dirname(__DIR__, 2).'/utility-os/board/cmprovision/'.$file;
    }

    /** the kernel command line the firmware hands over: its own words, then cmdline.txt of the new OS */
    protected function cmdline(bool $readjumper = true): string
    {
        $line = str_replace('@UOS_VERSION@', self::VERSION, trim(file_get_contents(self::path('cmdline.txt'))));
        return 'coherent_pool=1M 8250.nr_uarts=0 '.($readjumper ? $line : str_replace('readjumper ', '', $line));
    }

    /** A CM4 4 GB with eMMC, booted from the network, jumpers 1 0 1; $over replaces (or with null removes) files */
    protected function world(array $over = []): void
    {
        $files = array_merge([
            'proc/cmdline' => $this->cmdline()."\n",
            'proc/cpuinfo' => "processor\t: 0\nBogoMIPS\t: 108.00\n\nRevision\t: d03140\nSerial\t\t: 10000000e611110c\nModel\t\t: Raspberry Pi Compute Module 4 Rev 1.1\n",
            'proc/meminfo' => "MemTotal:        3884356 kB\nMemFree:         3700000 kB\n",
            'proc/device-tree/model' => "Raspberry Pi Compute Module 4 Rev 1.1\0",
            'proc/device-tree/serial-number' => "10000000e611110c\0",
            'proc/device-tree/chosen/bootloader/boot-mode' => "\0\0\0\2",
            'sys/class/net/eth0/address' => "e4:5f:01:62:9f:d3\n",
            'sys/class/net/eth0/carrier' => "1\n",
            'sys/block/mmcblk0/size' => "61071360\n",
            'sys/block/mmcblk0/device/cid' => "1501004a3a3333475012345678abcd00\n",
            'sys/block/mmcblk0/device/csd' => "d02700328f5903ffffffffef8a404000\n",
            'sys/class/thermal/thermal_zone0/temp' => "47900\n",
            'sys/class/leds/ACT/trigger' => "none [mmc0] timer\n",
            'sys/class/leds/ACT/brightness' => "255\n",
            'gpio/5' => '1', 'gpio/13' => '0', 'gpio/21' => '1',
            'vcgencmd' => "temp=48.3'C\n",
            'responses/1.code' => '200',
            'responses/1.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\nexit 3\n",
        ], $over);
        foreach ($files as $path => $content)
        {
            $file = $this->dir.'/'.$path;
            if ($content === null)
            {
                @unlink($file);
                continue;
            }
            @mkdir(dirname($file), 0755, true);
            file_put_contents($file, $content);
        }
    }

    /** runs the init as rcS does ("start"); its console */
    protected function boot(): string
    {
        $env = ['PATH' => $this->dir.'/bin:'.getenv('PATH'), 'UOS_PROC' => $this->dir.'/proc', 'UOS_SYS' => $this->dir.'/sys',
                'UOS_TMP' => $this->dir.'/tmp'];
        $prefix = '';
        foreach ($env as $name => $value)
            $prefix .= $name.'='.escapeshellarg($value).' ';
        exec($prefix.'timeout 20 '.escapeshellarg($this->busybox).' sh '
            .escapeshellarg(self::path('rootfs-overlay/etc/init.d/S99scriptexec')).' start 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        return implode("\n", $out);
    }

    /** the curl calls: each a list of its arguments */
    protected function curlCalls(): array
    {
        $calls = [];
        foreach (explode("---\n", (string) @file_get_contents($this->dir.'/curl.args')) as $call)
            if ($call !== '')
                $calls[] = explode("\n", rtrim($call, "\n"));
        return $calls;
    }

    protected function startUrl(int $call = 0): string
    {
        return end($this->curlCalls()[$call]);
    }

    public function test_the_start_has_the_legacy_parameters_in_the_legacy_formats()
    {
        $this->boot();

        $this->assertSame('http://172.20.0.1/scriptexecute?serial=10000000e611110c&model=Raspberry+Pi+Compute+Module+4+Rev+1.1'
            .'&storagesize=61071360&mac=e4:5f:01:62:9f:d3&inversejumper=101&memorysize=3884356&temp=48.3\'C'
            .'&cid=1501004a3a3333475012345678abcd00&csd=d02700328f5903ffffffffef8a404000&bootmode=2&uos=2026.10', $this->startUrl());
        $this->assertSame(['-g', '--retry', '10', '--retry-connrefused', '-o', $this->dir.'/tmp/script', '-w', '%{http_code}'],
            array_slice($this->curlCalls()[0], 0, 8));
    }

    public function test_without_readjumper_the_jumpers_stay_unread()
    {
        $this->world(['proc/cmdline' => $this->cmdline(false)."\n"]);

        $this->boot();

        $this->assertStringContainsString('&inversejumper=&memorysize=', $this->startUrl());
    }

    public function test_serial_and_temperature_have_fallbacks()
    {
        // a kernel without the Serial line, a firmware vcgencmd cannot reach
        $this->world(['proc/cpuinfo' => "processor\t: 0\n", 'vcgencmd' => null]);

        $this->boot();

        $this->assertStringContainsString('?serial=10000000e611110c&', $this->startUrl());
        $this->assertStringContainsString("&temp=47.9'C&", $this->startUrl());
    }

    public function test_a_missing_source_leaves_its_value_empty()
    {
        $this->world(['sys/block/mmcblk0/size' => null, 'sys/block/mmcblk0/device/cid' => null, 'sys/block/mmcblk0/device/csd' => null,
                      'proc/device-tree/chosen/bootloader/boot-mode' => null, 'vcgencmd' => null,
                      'sys/class/thermal/thermal_zone0/temp' => null]);

        $this->boot();

        $url = $this->startUrl();
        foreach (['storagesize', 'temp', 'cid', 'csd', 'bootmode'] as $name)
            $this->assertStringContainsString($name.'=', $url);
        $this->assertStringContainsString('&storagesize=&mac=', $url);
        $this->assertStringContainsString('&temp=&cid=&csd=&bootmode=&uos=2026.10', $url);
        $this->assertStringNotContainsString('{', $url, 'every placeholder filled in');
    }

    public function test_a_server_error_is_tried_again_and_its_page_never_runs()
    {
        // 502: php-fpm restarting under a package upgrade; the legacy init ran nginx's error page as the script
        $this->world(['responses/1.code' => '502', 'responses/1.body' => 'echo RAN-502 > '.escapeshellarg($this->dir.'/ran-502')."\n",
                      'responses/2.code' => '200', 'responses/2.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileDoesNotExist($this->dir.'/ran-502');
        $this->assertFileExists($this->dir.'/ran');
        $this->assertCount(2, $this->curlCalls());
        $this->assertStringContainsString('no answer from the server (HTTP 502): trying again in 10 seconds', $console);
        $this->assertStringContainsString('sleep 10', file_get_contents($this->dir.'/calls'));
    }

    public function test_no_connection_is_tried_again()
    {
        $this->world(['responses/1.code' => null, 'responses/1.body' => null,
                      'responses/2.code' => '200', 'responses/2.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileExists($this->dir.'/ran');
        $this->assertStringContainsString('(HTTP 000)', $console);
    }

    public function test_another_answer_stops_the_module_without_running_it()
    {
        $this->world(['responses/1.code' => '404', 'responses/1.body' => 'echo RAN > '.escapeshellarg($this->dir.'/ran')."\n"]);

        $console = $this->boot();

        $this->assertFileDoesNotExist($this->dir.'/ran');
        $this->assertCount(1, $this->curlCalls());
        $this->assertStringContainsString('the server answered HTTP 404: stopping', $console);
    }

    public function test_a_script_that_returns_is_not_run_again()
    {
        $console = $this->boot();

        $this->assertFileExists($this->dir.'/ran');
        $this->assertCount(1, $this->curlCalls());
        $this->assertStringContainsString('the script ended with exit code 3: nothing more to do', $console);
    }

    public function test_act_goes_dark_and_a_shell_waits_on_tty2()
    {
        $console = $this->boot();

        $this->assertSame("none\n", file_get_contents($this->dir.'/sys/class/leds/ACT/trigger'));
        $this->assertSame("0\n", file_get_contents($this->dir.'/sys/class/leds/ACT/brightness'));
        $calls = file_get_contents($this->dir.'/calls');
        $this->assertStringContainsString('getty -L tty2 0 vt100', $calls);
        $this->assertStringContainsString('udhcpc -i eth0', $calls);
        $this->assertStringContainsString('Note: shell is available on tty2 for debugging purposes', $console);
    }

    public function test_cmdline_txt_is_the_legacy_one_with_uos_at_the_end()
    {
        $legacy = trim(file_get_contents(dirname(__DIR__, 2).'/scriptexecute/cmdline.txt'));

        $this->assertSame($legacy.'&uos=@UOS_VERSION@', trim(file_get_contents(self::path('cmdline.txt'))));
    }

    public function test_config_txt_is_the_legacy_one_with_the_64_bit_kernel_said_outright()
    {
        $lines = fn (string $file) => array_values(array_filter(array_map('trim', file($file)), fn ($l) => $l !== '' && $l[0] !== '#'));
        $legacy = $lines(dirname(__DIR__, 2).'/scriptexecute/config.txt');
        $ours = $lines(self::path('config.txt'));

        $expected = array_merge(['arm_64bit=1'], str_replace('initramfs scriptexecute.img', 'initramfs scriptexecute.img followkernel', $legacy));
        $this->assertSame($expected, $ours);
    }
}
```
- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsInitTest'`
Expected: `phpunit exit 1`. The template files and the init are missing (`file_get_contents(...cmdline.txt): Failed to open stream`).

- [x] **Step 3: The templates**

Create `utility-os/board/cmprovision/cmdline.txt` (one line, then a newline):
```
readjumper script=http://172.20.0.1/scriptexecute?serial={serial}&model={model}&storagesize={storagesize}&mac={mac}&inversejumper={jumper}&memorysize={memorysize}&temp={temp}&cid={cid}&csd={csd}&bootmode={bootmode}&uos=@UOS_VERSION@
```

Create `utility-os/board/cmprovision/config.txt`:
```
# cmprovision utility OS (utility-os/): the legacy config.txt, with the 64-bit kernel said outright
arm_64bit=1

# Set the pull ups for jumper reading
gpio=5,13,21=pu

initramfs scriptexecute.img followkernel

[pi4]
dtoverlay=dwc2
# For flashrom
dtparam=audio=off
dtoverlay=spi-gpio40-45
```

- [x] **Step 4: The init**

Create `utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec`:
```sh
#!/bin/sh
# cmprovision utility OS (utility-os/, docs/utility-os.md): fill the module's facts into the script address of the kernel
# command line, get the provisioning script from the server and run it. The parameters and their formats are the legacy
# OS's (etc/init.d/S99scriptexec of scriptexecute/scriptexecute.img): the server knows a module by its serial number.
# No set -e: a fact that cannot be read leaves its value empty, it does not stop the module.
# UOS_PROC, UOS_SYS and UOS_TMP move /proc, /sys and /tmp (tests/Feature/UtilityOsInitTest.php).

case "$1" in
    start) ;;
    *) exit 0 ;;
esac

PROC=${UOS_PROC:-/proc}
SYS=${UOS_SYS:-/sys}
TMP=${UOS_TMP:-/tmp}

say() { echo "cmprovision: $*"; }
# a file's text without NUL bytes (device tree strings end with one); nothing when there is no such file
text() { [ -r "$1" ] && tr -d '\000' < "$1"; }
# every {name} in URL becomes the value: awk's index() takes both literally, whatever the value holds
fill() {
    URL=$(printf '%s' "$URL" | awk -v name="{$1}" -v value="$2" '{
        out = ""
        while ((i = index($0, name)) > 0) { out = out substr($0, 1, i - 1) value; $0 = substr($0, i + length(name)) }
        printf "%s", out $0 }')
}
# the level of a GPIO, 0 or 1 ("pinctrl lev 5" prints "1"): pinctrl reads the register with its own numbers, while the
# sysfs numbers of the legacy init are shifted since Linux 6.6 (the controller starts at 512)
level() { pinctrl lev "$1" 2>/dev/null | tr -d ' \n'; }

URL=
READJUMPER=
set -f   # the address has ? in it: no file name expansion
for p in $(cat "$PROC/cmdline"); do
    case "$p" in
        script=*) URL=${p#script=} ;;
        readjumper) READJUMPER=1 ;;
    esac
done
set +f

# ACT dark until the script takes it: led0 up to Linux 6.0, ACT since 6.1
for led in "$SYS/class/leds/led0" "$SYS/class/leds/ACT"; do
    [ -e "$led/trigger" ] || continue
    echo none > "$led/trigger"
    echo 0 > "$led/brightness"
done

mount -t debugfs none "$SYS/kernel/debug" 2>/dev/null
echo "Note: shell is available on tty2 for debugging purposes"
getty -L tty2 0 vt100 &

say "waiting for eth0 to appear"
while [ ! -e "$SYS/class/net/eth0" ]; do sleep 1; done
ip link set eth0 up
say "waiting for the network link"
while [ "$(text "$SYS/class/net/eth0/carrier")" != 1 ]; do sleep 1; done
say "obtaining a DHCP lease"
udhcpc -i eth0   # it goes to the background with the lease and renews it

case "$URL" in
    http://*) ;;
    *) say "no script= address on the kernel command line: nothing to do"; exit 0 ;;
esac

SERIAL=$(sed -n 's/^Serial[[:space:]]*: *//p' "$PROC/cpuinfo" | head -n 1)
[ -n "$SERIAL" ] || SERIAL=$(text "$PROC/device-tree/serial-number")
TEMP=$(vcgencmd measure_temp 2>/dev/null | sed -n 's/^temp=//p')
if [ -z "$TEMP" ] && [ -r "$SYS/class/thermal/thermal_zone0/temp" ]; then
    TEMP=$(awk '{ printf "%.1f'"'"'C", $1 / 1000 }' "$SYS/class/thermal/thermal_zone0/temp")
fi
BOOTMODE=
if [ -r "$PROC/device-tree/chosen/bootloader/boot-mode" ]; then
    BOOTMODE=$(od -An -tu1 -j3 -N1 "$PROC/device-tree/chosen/bootloader/boot-mode" | tr -d ' \n')
fi
JUMPER=
if [ -n "$READJUMPER" ]; then
    for gpio in 5 13 21; do JUMPER=$JUMPER$(level $gpio); done
fi

fill serial "$SERIAL"
fill model "$(text "$PROC/device-tree/model" | tr ' ' '+')"
fill temp "$TEMP"
fill storagesize "$(text "$SYS/block/mmcblk0/size")"
fill cid "$(text "$SYS/block/mmcblk0/device/cid")"
fill csd "$(text "$SYS/block/mmcblk0/device/csd")"
fill bootmode "$BOOTMODE"
fill memorysize "$(awk '/^MemTotal:/ { print $2 }' "$PROC/meminfo")"
fill mac "$(text "$SYS/class/net/eth0/address")"
fill jumper "$JUMPER"

# 200 is the script (the server answers its own refusals with a script too); no connection or 5xx (php-fpm restarting
# under a package upgrade) is tried again; any other answer stops the module, since running it would do no good
while :; do
    say "downloading the script from $URL"
    CODE=$(curl -g --retry 10 --retry-connrefused -o "$TMP/script" -w '%{http_code}' "$URL")
    case "$CODE" in
        200) break ;;
        000|5??) say "no answer from the server (HTTP $CODE): trying again in 10 seconds" ;;
        *) say "the server answered HTTP $CODE: stopping"; exit 0 ;;
    esac
    sleep 10
done
say "executing the script"
sh "$TMP/script"
# the script ends in finish and waits for the operator; one that returns is not run again (a second run would write again)
say "the script ended with exit code $?: nothing more to do"
```
Run: `git update-index --add --chmod=+x utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec` after `git add`, in step 7.

- [x] **Step 5: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsInitTest'`
Expected: `phpunit exit 0`, `OK (11 tests, …)`.

- [x] **Step 6: Syntax under dash too**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
dash -n /root/dev/utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec && echo dash-ok
busybox sh -n /root/dev/utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec && echo busybox-ok
EOF
```
Expected: `dash-ok`, `busybox-ok`.

- [x] **Step 7: Commit**

Write `$SP/commit-270-t6.txt`:
```
The new utility OS's init and boot files: the legacy contract plus uos=

The init fills in the legacy start parameters in the legacy formats
(jumpers through pinctrl), adds uos=, runs a 200 answer, tries 5xx and
no connection again, stops on any other answer and never runs a
returned script twice. config.txt is the legacy one with arm_64bit=1
and followkernel.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run:
```bash
git add utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec utility-os/board/cmprovision/cmdline.txt utility-os/board/cmprovision/config.txt tests/Feature/UtilityOsInitTest.php
git update-index --chmod=+x utility-os/board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec
git commit -q -F "$SP/commit-270-t6.txt"
```

---

### Task 7: The buildroot tree, the pins, `build.sh`, and the first build on the bench

**Files:**
- Create: `utility-os/external.desc`, `utility-os/external.mk`, `utility-os/Config.in`
- Create: `utility-os/versions.env`
- Create: `utility-os/configs/cmprovision_defconfig`
- Create: `utility-os/board/cmprovision/linux.fragment`, `busybox.fragment`, `post-build.sh`, `post-image.sh`
- Create: `utility-os/package/cmp-rpi-boot-files/{Config.in,cmp-rpi-boot-files.mk}`
- Create: `utility-os/package/cmp-rpi-utils/{Config.in,cmp-rpi-utils.mk,CMakeLists.txt}`
- Create: `utility-os/package/cmp-usbboot-bootfiles/{Config.in,cmp-usbboot-bootfiles.mk}`
- Create: `utility-os/legacy-commands.txt` (written by `tools/utility-os/legacy_commands.py`)
- Create: `tools/utility-os/legacy_commands.py`, `tools/utility-os/hashes.sh`, `tools/utility-os/build.sh`
- Create: `tests/Unit/UtilityOsTreeTest.php`
- Create (bench helpers, not in the repository): `$SP/wsl-bench/32-uos-in.sh`, `33-uos-build.sh`, `34-uos-out.sh`, `35-uos-test.sh`

**Interfaces:**
- Consumes: Task 6's `rootfs-overlay/`, `config.txt`, `cmdline.txt`; Task 2's `tools/utility-os/manifest.sh`.
- Produces:
  - `utility-os/versions.env`: `KEY=value` lines (no spaces, no quotes; `#` comments), keys `UOS_VERSION`, `UOS_BUILDROOT_VERSION`, `UOS_BUILDROOT_SHA256`, `UOS_LINUX_TAG`, `UOS_LINUX_COMMIT`, `UOS_LINUX_VERSION`, `UOS_LINUX_SHA256`, `UOS_FIRMWARE_TAG`, `UOS_START4_SHA256`, `UOS_FIXUP4_SHA256`, `UOS_FIRMWARE_LICENCE_SHA256`, `UOS_RPI_UTILS_COMMIT`, `UOS_RPI_UTILS_SHA256`, `UOS_USBBOOT_TAG`, `UOS_BOOTFILES_SHA256`, `UOS_USBBOOT_LICENSE_SHA256`. Sourced by sh and included by make (`external.mk`).
  - `tools/utility-os/hashes.sh <versions.env> <dir>`: writes `<dir>/linux/linux.hash`, `<dir>/cmp-rpi-boot-files/cmp-rpi-boot-files.hash`, `<dir>/cmp-rpi-utils/cmp-rpi-utils.hash`, `<dir>/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.hash`. Exit 1 and «hashes.sh: <KEY> has no SHA256 in versions.env» when one is empty.
  - `tools/utility-os/build.sh` (env `UOS_WORK`, `UOS_CACHE`): buildroot output in `$UOS_WORK/output` (kernel build directory `build/linux-custom`), TFTP directory copied to `<repo>/scriptexecute64/`, `$UOS_WORK/output/legal-info/`. Last line `build.sh: done: …`.
  - Bench: `32-uos-in.sh` (tree tar on stdin → `/root/uos/src`), `33-uos-build.sh` (unit `uos-build`, log `/root/uos/build.log` ending in `build.sh exit N`), `34-uos-out.sh` (tar of `scriptexecute64` and the fixture on stdout), `35-uos-test.sh` (unit `uos-test`, log `/root/uos/test.log` ending in `test.sh exit N`).

Facts this task rests on (research of 2026-10-16, buildroot 2025.02.18 sources):
- buildroot's git download is never shallow (the whole raspberrypi/linux history, 5.2 GB). The kernel therefore comes as GitHub's archive of the tag's commit, as buildroot's own Raspberry Pi defconfigs do it (`BR2_LINUX_KERNEL_CUSTOM_TARBALL`), and its build directory is `linux-custom`. This replaces the spec's «git неглубоким клоном». Same aim, a pinned SHA256. The same goes for `raspberrypi/utils`.
- With `BR2_DOWNLOAD_FORCE_CHECK_HASHES`, a source without a line in an existing hash file stops the download. A package that has no hash file anywhere is taken unchecked. That is why `hashes.sh` always writes all four files.
- `make legal-info` checks license files against buildroot's own `linux/linux.hash`, which has the pre-6.17 `LICENSES/preferred/GPL-2.0`. This kernel has the newer text, so `external.mk` copies the text to `LICENSES/preferred/GPL-2.0.cmprovision`, and the defconfig names that copy.
- buildroot's BusyBox config already has 253 of the 255 legacy applets: `tc` is off and `sysctl` is `CONFIG_BB_SYSCTL=y`. BusyBox's package installs `S50crond` when `CONFIG_CROND=y`. The legacy OS started no cron daemon, so `post-build.sh` removes that script.
- `BR2_LINUX_KERNEL_INTREE_DTSO_NAMES` builds `overlays/<name>.dtbo` and puts it flat into `images/`. The kernel's own `make all` builds every overlay into the build directory as well, and `post-image.sh` falls back to that.

- [x] **Step 1: Write the failing test of the tree**

Create `tests/Unit/UtilityOsTreeTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The buildroot tree of our utility OS (utility-os/, 2.7.0): the pins, the configuration and the scripts that run before
    the build. tools/utility-os/test.sh checks the build itself. */
class UtilityOsTreeTest extends TestCase
{
    protected static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function file(string $path): string
    {
        return file_get_contents(self::root().'/'.$path);
    }

    /** versions.env as key => value */
    protected function versions(): array
    {
        preg_match_all('/^(UOS_[A-Z0-9_]+)=(.*)$/m', $this->file('utility-os/versions.env'), $m);
        return array_combine($m[1], $m[2]);
    }

    public function test_versions_env_pins_every_source_in_a_form_sh_and_make_both_read()
    {
        $v = $this->versions();
        $this->assertMatchesRegularExpression('/^\d{4}\.\d{2}(\.\d+)?$/', $v['UOS_VERSION']);
        $this->assertMatchesRegularExpression('/^2025\.02(\.\d+)?$/', $v['UOS_BUILDROOT_VERSION'], 'the LTS branch the spec chose');
        $this->assertMatchesRegularExpression('/^stable_\d{8}$/', $v['UOS_LINUX_TAG']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $v['UOS_LINUX_VERSION']);
        $this->assertMatchesRegularExpression('/^1\.\d{8}$/', $v['UOS_FIRMWARE_TAG']);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}$/', $v['UOS_USBBOOT_TAG']);
        foreach (['UOS_LINUX_COMMIT', 'UOS_RPI_UTILS_COMMIT'] as $key)
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $v[$key], $key);
        foreach (['UOS_BUILDROOT_SHA256', 'UOS_LINUX_SHA256', 'UOS_START4_SHA256', 'UOS_FIXUP4_SHA256', 'UOS_FIRMWARE_LICENCE_SHA256',
                  'UOS_RPI_UTILS_SHA256', 'UOS_BOOTFILES_SHA256', 'UOS_USBBOOT_LICENSE_SHA256'] as $key)
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $v[$key], $key);
        // make includes the file (external.mk): a quote or a space would end up in the value
        $this->assertDoesNotMatchRegularExpression('/^[^#].*["\' ]/m', $this->file('utility-os/versions.env'));
    }

    public function test_the_external_tree_reads_the_pins_before_its_packages()
    {
        $this->assertStringContainsString("name: CMPROVISION\n", $this->file('utility-os/external.desc'));
        $mk = $this->file('utility-os/external.mk');
        $this->assertLessThan(strpos($mk, 'package/*/*.mk'), strpos($mk, 'include $(BR2_EXTERNAL_CMPROVISION_PATH)/versions.env'));
        $this->assertStringContainsString('LINUX_POST_EXTRACT_HOOKS += CMPROVISION_LINUX_GPL_TEXT', $mk);
        foreach (['cmp-rpi-boot-files', 'cmp-rpi-utils', 'cmp-usbboot-bootfiles'] as $pkg)
            $this->assertStringContainsString("source \"\$BR2_EXTERNAL_CMPROVISION_PATH/package/$pkg/Config.in\"", $this->file('utility-os/Config.in'));
    }

    public function test_the_defconfig_is_the_os_the_spec_describes()
    {
        $defconfig = $this->file('utility-os/configs/cmprovision_defconfig');
        foreach (['BR2_aarch64=y', 'BR2_cortex_a72=y', 'BR2_TOOLCHAIN_EXTERNAL_BOOTLIN_AARCH64_GLIBC_STABLE=y',
                  'BR2_DOWNLOAD_FORCE_CHECK_HASHES=y', 'BR2_LINUX_KERNEL_CUSTOM_TARBALL=y', 'BR2_LINUX_KERNEL_DEFCONFIG="bcm2711"',
                  'BR2_LINUX_KERNEL_CONFIG_FRAGMENT_FILES="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/linux.fragment"',
                  'BR2_LINUX_KERNEL_IMAGEGZ=y', 'BR2_LINUX_KERNEL_INTREE_DTS_NAME="broadcom/bcm2711-rpi-cm4 broadcom/bcm2711-rpi-4-b broadcom/bcm2711-rpi-400"',
                  'BR2_LINUX_KERNEL_INTREE_DTSO_NAMES="overlays/spi-gpio40-45 overlays/dwc2"',
                  'BR2_LINUX_KERNEL_LICENSE_FILES="COPYING LICENSES/preferred/GPL-2.0.cmprovision LICENSES/exceptions/Linux-syscall-note"',
                  'BR2_TARGET_GENERIC_HOSTNAME="scriptexecute"', 'BR2_TARGET_GENERIC_GETTY_PORT="tty1"', 'BR2_SYSTEM_DHCP=""',
                  'BR2_PACKAGE_LIBCURL_TLS_NONE=y', 'BR2_PACKAGE_UTIL_LINUX_BINARIES=y', 'BR2_PACKAGE_E2FSPROGS_RESIZE2FS=y',
                  'BR2_PACKAGE_FLASHROM=y', 'BR2_PACKAGE_CMP_RPI_UTILS=y', 'BR2_PACKAGE_CMP_RPI_BOOT_FILES=y',
                  'BR2_PACKAGE_CMP_USBBOOT_BOOTFILES=y', 'BR2_TARGET_ROOTFS_CPIO_XZ=y', '# BR2_TARGET_ROOTFS_TAR is not set'] as $line)
            $this->assertStringContainsString($line."\n", $defconfig, $line);
        $this->assertStringNotContainsString('BR2_TARGET_GENERIC_ROOT_PASSWD="', $defconfig, 'root on tty2 without a password, as the spec says');
    }

    public function test_the_kernel_fragment_adds_what_bcm2711_defconfig_lacks_and_keeps_modules()
    {
        $fragment = $this->file('utility-os/board/cmprovision/linux.fragment');
        foreach (['CONFIG_LOCALVERSION="-cmprovision"', 'CONFIG_SPI_BCM2835=y', 'CONFIG_SPI_SPIDEV=y', 'CONFIG_RASPBERRYPI_GPIOMEM=y',
                  'CONFIG_VIRTIO_MMIO=y', 'CONFIG_VIRTIO_NET=y'] as $line)
            $this->assertStringContainsString($line."\n", $fragment, $line);
        $this->assertDoesNotMatchRegularExpression('/^(# )?CONFIG_MODULES[ =]/m', $fragment, 'without modules every =m of the defconfig would be built in');
    }

    public function test_busybox_has_every_legacy_applet_and_no_network_login()
    {
        $fragment = $this->file('utility-os/board/cmprovision/busybox.fragment');
        $this->assertStringContainsString("CONFIG_TC=y\n", $fragment);
        $this->assertStringContainsString("# CONFIG_TELNETD is not set\n", $fragment);
        $this->assertStringContainsString('rm -f "$TARGET/etc/init.d/S50crond"', $this->file('utility-os/board/cmprovision/post-build.sh'));
        $this->assertStringContainsString('rm -rf "$TARGET/lib/modules"', $this->file('utility-os/board/cmprovision/post-build.sh'));
    }

    public function test_the_legacy_command_list_is_made_from_the_legacy_image()
    {
        $python = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($python === '')
            $this->markTestSkipped('needs python3');
        exec(escapeshellarg($python).' -I '.escapeshellarg(self::root().'/tools/utility-os/legacy_commands.py').' '
            .escapeshellarg(self::root().'/scriptexecute/scriptexecute.img'), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(implode("\n", $out)."\n", $this->file('utility-os/legacy-commands.txt'), 'run legacy_commands.py again');
        $commands = array_values(array_filter($out, fn ($l) => $l !== '' && $l[0] !== '#'));
        $this->assertCount(340, $commands);
        foreach (['busybox', 'flashrom', 'resize2fs', 'vcgencmd', 'curl', 'parted', 'mmc', 'bsdtar', '[['] as $cmd)
            $this->assertContains($cmd, $commands);
        foreach (['raspistill', 'tvservice', 'dtoverlay-pre', 'get_module', 'e2scrub'] as $cmd)
            $this->assertNotContains($cmd, $commands);
    }

    public function test_hashes_sh_writes_every_hash_file_buildroot_checks()
    {
        $dir = sys_get_temp_dir().'/uos-hashes-'.bin2hex(random_bytes(4));
        try
        {
            exec('sh '.escapeshellarg(self::root().'/tools/utility-os/hashes.sh').' '.escapeshellarg(self::root().'/utility-os/versions.env')
                .' '.escapeshellarg($dir).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
            $v = $this->versions();
            $this->assertSame("sha256  {$v['UOS_LINUX_SHA256']}  linux-{$v['UOS_LINUX_COMMIT']}.tar.gz\n", file_get_contents("$dir/linux/linux.hash"));
            $this->assertSame("sha256  {$v['UOS_START4_SHA256']}  start4.elf\nsha256  {$v['UOS_FIXUP4_SHA256']}  fixup4.dat\n"
                ."sha256  {$v['UOS_FIRMWARE_LICENCE_SHA256']}  LICENCE.broadcom\n", file_get_contents("$dir/cmp-rpi-boot-files/cmp-rpi-boot-files.hash"));
            $this->assertSame("sha256  {$v['UOS_RPI_UTILS_SHA256']}  cmp-rpi-utils-{$v['UOS_RPI_UTILS_COMMIT']}.tar.gz\n",
                file_get_contents("$dir/cmp-rpi-utils/cmp-rpi-utils.hash"));
            $this->assertSame("sha256  {$v['UOS_BOOTFILES_SHA256']}  bootfiles.bin\nsha256  {$v['UOS_USBBOOT_LICENSE_SHA256']}  LICENSE\n",
                file_get_contents("$dir/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.hash"));

            // a source without its hash would go unchecked: hashes.sh refuses
            file_put_contents("$dir.env", str_replace("UOS_START4_SHA256={$v['UOS_START4_SHA256']}", 'UOS_START4_SHA256=', $this->file('utility-os/versions.env')));
            exec('sh '.escapeshellarg(self::root().'/tools/utility-os/hashes.sh').' '.escapeshellarg("$dir.env").' '.escapeshellarg("$dir-2").' 2>&1', $out2, $code2);
            $this->assertSame(1, $code2);
            $this->assertStringContainsString('hashes.sh: UOS_START4_SHA256 has no SHA256 in versions.env', implode("\n", $out2));
        }
        finally
        {
            exec('rm -rf '.escapeshellarg($dir).' '.escapeshellarg("$dir-2").' '.escapeshellarg("$dir.env"));
        }
    }

    public function test_post_image_lays_out_the_tftp_directory()
    {
        $images = sys_get_temp_dir().'/uos-images-'.bin2hex(random_bytes(4));
        $build = $images.'-build';
        try
        {
            mkdir("$images/rpi-boot", 0755, true);
            mkdir("$build/linux-custom/arch/arm64/boot/dts/overlays", 0755, true);
            foreach (['Image.gz', 'rootfs.cpio.xz', 'bcm2711-rpi-cm4.dtb', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb', 'spi-gpio40-45.dtbo',
                      'bootfiles.bin', 'rpi-boot/start4.elf', 'rpi-boot/fixup4.dat'] as $f)
                file_put_contents("$images/$f", "content of $f");
            // dwc2.dtbo only in the kernel's build directory: the fallback
            file_put_contents("$build/linux-custom/arch/arm64/boot/dts/overlays/dwc2.dtbo", 'content of dwc2.dtbo');
            exec('BR2_EXTERNAL_CMPROVISION_PATH='.escapeshellarg(self::root().'/utility-os').' BUILD_DIR='.escapeshellarg($build)
                .' sh '.escapeshellarg(self::root().'/utility-os/board/cmprovision/post-image.sh').' '.escapeshellarg($images).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));

            $out64 = "$images/scriptexecute64";
            $names = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out64, \FilesystemIterator::SKIP_DOTS)) as $f)
                $names[] = substr($f->getPathname(), strlen($out64) + 1);
            sort($names);
            $this->assertSame(['MANIFEST', 'bcm2711-rpi-4-b.dtb', 'bcm2711-rpi-400.dtb', 'bcm2711-rpi-cm4.dtb', 'bootfiles.bin', 'cmdline.txt',
                               'config.txt', 'fixup4.dat', 'kernel8.img', 'overlays/dwc2.dtbo', 'overlays/spi-gpio40-45.dtbo',
                               'scriptexecute.img', 'start4.elf'], $names);
            $v = $this->versions();
            $this->assertSame('content of Image.gz', file_get_contents("$out64/kernel8.img"));
            $this->assertSame('content of rootfs.cpio.xz', file_get_contents("$out64/scriptexecute.img"));
            $this->assertStringEndsWith("&uos={$v['UOS_VERSION']}\n", file_get_contents("$out64/cmdline.txt"));
            $manifest = file_get_contents("$out64/MANIFEST");
            foreach (["version={$v['UOS_VERSION']}\n", "bits=64\n", "kernel=Linux {$v['UOS_LINUX_VERSION']} (raspberrypi/linux {$v['UOS_LINUX_TAG']})\n",
                      "usbboot={$v['UOS_USBBOOT_TAG']}\n", ' overlays/dwc2.dtbo'] as $needle)
                $this->assertStringContainsString($needle, $manifest);
        }
        finally
        {
            exec('rm -rf '.escapeshellarg($images).' '.escapeshellarg($build));
        }
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsTreeTest'`
Expected: `phpunit exit 1` or `2`: `utility-os/versions.env` is missing.

- [x] **Step 3: The pins and the external tree**

Create `utility-os/versions.env`:
```
# The pinned versions of the cmprovision utility OS (docs/utility-os.md). tools/utility-os/update.sh rewrites this file;
# tools/utility-os/build.sh, hashes.sh and utility-os/external.mk read it: KEY=value, no spaces, no quotes.
UOS_VERSION=2026.10
UOS_BUILDROOT_VERSION=2025.02.18
UOS_BUILDROOT_SHA256=0620c699b3a1da41cc854bc8584692b913e57e284d3328121c674a43b8707ab1
UOS_LINUX_TAG=stable_20260911
UOS_LINUX_COMMIT=cff533aec2fa601846766b32ff57204e0a61bed7
UOS_LINUX_VERSION=6.18.50
UOS_LINUX_SHA256=6c58de91ade97d7cd9176e408f05c9d134cda64dba95a87b7af976bf26c0c0bd
UOS_FIRMWARE_TAG=1.20260915
UOS_START4_SHA256=560ead37044d849325755d3684ac1f74654b3ce6450604a42df6dd832cda9ede
UOS_FIXUP4_SHA256=d181d5055daebac22513d6904fa9ce21d1e99b852cc391af095ade6f0b45c400
UOS_FIRMWARE_LICENCE_SHA256=c7283ff51f863d93a275c66e3b4cb08021a5dd4d8c1e7acc47d872fbe52d3d6b
UOS_RPI_UTILS_COMMIT=b57c48e97f7f43d95d86334798650cfda1ebc8a1
UOS_RPI_UTILS_SHA256=7d50ea36e71f565129c35f15af4a77367389e4b11ce6e0eaea758975e1ce6a42
UOS_USBBOOT_TAG=20261002-115811
UOS_BOOTFILES_SHA256=177cf37ad7f4a1ff2de44ae18b14d67d02cd32c6c44042f3f845278c58e46e89
UOS_USBBOOT_LICENSE_SHA256=b40930bbcf80744c86c46a12bc9da056641d722716c378f5659b9e555ef833e1
```
(`raspberrypi/utils` moved from the spec's `7d64443` to `b57c48e` on 2026-10-16. The tree pins today's head: ledger ruling. The SHA256 of the kernel and utils archives were taken from `https://github.com/raspberrypi/linux/archive/<commit>/linux-<commit>.tar.gz` (257266347 bytes) and `https://github.com/raspberrypi/utils/archive/<commit>/cmp-rpi-utils-<commit>.tar.gz` (273947 bytes) on 2026-10-16.)

Create `utility-os/external.desc`:
```
name: CMPROVISION
desc: cmprovision utility OS (the system a Compute Module boots to be provisioned)
```

Create `utility-os/Config.in`:
```
source "$BR2_EXTERNAL_CMPROVISION_PATH/package/cmp-rpi-boot-files/Config.in"
source "$BR2_EXTERNAL_CMPROVISION_PATH/package/cmp-rpi-utils/Config.in"
source "$BR2_EXTERNAL_CMPROVISION_PATH/package/cmp-usbboot-bootfiles/Config.in"
```

Create `utility-os/external.mk`:
```make
# cmprovision utility OS (docs/utility-os.md): the pins (UOS_*) for the packages of this tree, then the packages
include $(BR2_EXTERNAL_CMPROVISION_PATH)/versions.env
include $(sort $(wildcard $(BR2_EXTERNAL_CMPROVISION_PATH)/package/*/*.mk))

# legal-info checks the kernel's license files against buildroot's linux.hash, which has the GPL-2.0 text of Linux
# before 6.17; the same text under another name is checked against nothing (BR2_LINUX_KERNEL_LICENSE_FILES names it)
define CMPROVISION_LINUX_GPL_TEXT
	cp $(@D)/LICENSES/preferred/GPL-2.0 $(@D)/LICENSES/preferred/GPL-2.0.cmprovision
endef
LINUX_POST_EXTRACT_HOOKS += CMPROVISION_LINUX_GPL_TEXT
```

- [x] **Step 4: The packages**

Create `utility-os/package/cmp-rpi-boot-files/Config.in`:
```
config BR2_PACKAGE_CMP_RPI_BOOT_FILES
	bool "cmp-rpi-boot-files"
	help
	  start4.elf and fixup4.dat of a raspberrypi/firmware release
	  (UOS_FIRMWARE_TAG of utility-os/versions.env), for the TFTP
	  directory of the cmprovision utility OS.

	  https://github.com/raspberrypi/firmware
```

Create `utility-os/package/cmp-rpi-boot-files/cmp-rpi-boot-files.mk`:
```make
################################################################################
#
# cmp-rpi-boot-files: the Pi 4 boot files of a raspberrypi/firmware release, for scriptexecute64/
#
################################################################################

CMP_RPI_BOOT_FILES_VERSION = $(UOS_FIRMWARE_TAG)
CMP_RPI_BOOT_FILES_SITE = https://raw.githubusercontent.com/raspberrypi/firmware/$(CMP_RPI_BOOT_FILES_VERSION)/boot
CMP_RPI_BOOT_FILES_SOURCE = start4.elf
CMP_RPI_BOOT_FILES_EXTRA_DOWNLOADS = fixup4.dat LICENCE.broadcom
CMP_RPI_BOOT_FILES_LICENSE = Raspberry Pi firmware (redistributable binary)
CMP_RPI_BOOT_FILES_LICENSE_FILES = LICENCE.broadcom
CMP_RPI_BOOT_FILES_INSTALL_TARGET = NO
CMP_RPI_BOOT_FILES_INSTALL_IMAGES = YES

define CMP_RPI_BOOT_FILES_EXTRACT_CMDS
	cp $(CMP_RPI_BOOT_FILES_DL_DIR)/start4.elf $(CMP_RPI_BOOT_FILES_DL_DIR)/fixup4.dat \
		$(CMP_RPI_BOOT_FILES_DL_DIR)/LICENCE.broadcom $(@D)/
endef

define CMP_RPI_BOOT_FILES_INSTALL_IMAGES_CMDS
	$(INSTALL) -D -m 0644 $(@D)/start4.elf $(BINARIES_DIR)/rpi-boot/start4.elf
	$(INSTALL) -D -m 0644 $(@D)/fixup4.dat $(BINARIES_DIR)/rpi-boot/fixup4.dat
endef

$(eval $(generic-package))
```

Create `utility-os/package/cmp-usbboot-bootfiles/Config.in`:
```
config BR2_PACKAGE_CMP_USBBOOT_BOOTFILES
	bool "cmp-usbboot-bootfiles"
	help
	  bootfiles.bin of raspberrypi/usbboot (UOS_USBBOOT_TAG of
	  utility-os/versions.env): rpiboot takes the second stage of a
	  CM4 (2711/bootcode4.bin) from it.

	  https://github.com/raspberrypi/usbboot
```

Create `utility-os/package/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.mk`:
```make
################################################################################
#
# cmp-usbboot-bootfiles: bootfiles.bin of raspberrypi/usbboot, for rpiboot -d scriptexecute64/
#
################################################################################

CMP_USBBOOT_BOOTFILES_VERSION = $(UOS_USBBOOT_TAG)
CMP_USBBOOT_BOOTFILES_SITE = https://raw.githubusercontent.com/raspberrypi/usbboot/$(CMP_USBBOOT_BOOTFILES_VERSION)/firmware
CMP_USBBOOT_BOOTFILES_SOURCE = bootfiles.bin
CMP_USBBOOT_BOOTFILES_EXTRA_DOWNLOADS = https://raw.githubusercontent.com/raspberrypi/usbboot/$(CMP_USBBOOT_BOOTFILES_VERSION)/LICENSE
CMP_USBBOOT_BOOTFILES_LICENSE = Apache-2.0 (usbboot); Raspberry Pi bootloader (redistributable binary)
CMP_USBBOOT_BOOTFILES_LICENSE_FILES = LICENSE
CMP_USBBOOT_BOOTFILES_INSTALL_TARGET = NO
CMP_USBBOOT_BOOTFILES_INSTALL_IMAGES = YES

define CMP_USBBOOT_BOOTFILES_EXTRACT_CMDS
	cp $(CMP_USBBOOT_BOOTFILES_DL_DIR)/bootfiles.bin $(CMP_USBBOOT_BOOTFILES_DL_DIR)/LICENSE $(@D)/
endef

define CMP_USBBOOT_BOOTFILES_INSTALL_IMAGES_CMDS
	$(INSTALL) -D -m 0644 $(@D)/bootfiles.bin $(BINARIES_DIR)/bootfiles.bin
endef

$(eval $(generic-package))
```

Create `utility-os/package/cmp-rpi-utils/Config.in`:
```
config BR2_PACKAGE_CMP_RPI_UTILS
	bool "cmp-rpi-utils"
	select BR2_PACKAGE_DTC # libfdt, for dtmerge
	help
	  vcgencmd, vcmailbox, dtoverlay, dtparam, dtmerge and pinctrl of
	  raspberrypi/utils (UOS_RPI_UTILS_COMMIT of
	  utility-os/versions.env), for the cmprovision utility OS.

	  https://github.com/raspberrypi/utils
```

Create `utility-os/package/cmp-rpi-utils/cmp-rpi-utils.mk`:
```make
################################################################################
#
# cmp-rpi-utils: the tools of raspberrypi/utils the utility OS ships
#
################################################################################

CMP_RPI_UTILS_VERSION = $(UOS_RPI_UTILS_COMMIT)
CMP_RPI_UTILS_SITE = $(call github,raspberrypi,utils,$(CMP_RPI_UTILS_VERSION))
CMP_RPI_UTILS_LICENSE = BSD-3-Clause
CMP_RPI_UTILS_LICENSE_FILES = LICENCE
CMP_RPI_UTILS_DEPENDENCIES = dtc
CMP_RPI_UTILS_SUBDIR = cmprovision
CMP_RPI_UTILS_CONF_OPTS = -DBUILD_SHARED_LIBS=OFF

# the project's own top level builds every tool, and rpi-gpu-usage needs ncurses: ours builds the four the OS ships
define CMP_RPI_UTILS_TOP_LEVEL
	$(INSTALL) -D -m 0644 $(CMP_RPI_UTILS_PKGDIR)/CMakeLists.txt $(@D)/cmprovision/CMakeLists.txt
endef
CMP_RPI_UTILS_POST_EXTRACT_HOOKS += CMP_RPI_UTILS_TOP_LEVEL

$(eval $(cmake-package))
```

Create `utility-os/package/cmp-rpi-utils/CMakeLists.txt`:
```cmake
# cmprovision utility OS (utility-os/package/cmp-rpi-utils): the tools of raspberrypi/utils it ships; each directory is a
# project of its own
cmake_minimum_required(VERSION 3.10...3.27)
project(cmprovision-rpi-utils C)
add_subdirectory(../dtmerge dtmerge)
add_subdirectory(../pinctrl pinctrl)
add_subdirectory(../vcgencmd vcgencmd)
add_subdirectory(../vcmailbox vcmailbox)
```

- [x] **Step 5: The configuration, the fragments, the post scripts**

Create `utility-os/configs/cmprovision_defconfig`:
```
# cmprovision utility OS (docs/utility-os.md): what modules boot to be provisioned. tools/utility-os/build.sh sets
# BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION and BR2_GLOBAL_PATCH_DIR from utility-os/versions.env.
BR2_aarch64=y
BR2_cortex_a72=y
BR2_TOOLCHAIN_EXTERNAL=y
BR2_TOOLCHAIN_EXTERNAL_BOOTLIN=y
BR2_TOOLCHAIN_EXTERNAL_BOOTLIN_AARCH64_GLIBC_STABLE=y
BR2_CCACHE=y
BR2_DOWNLOAD_FORCE_CHECK_HASHES=y
BR2_TARGET_GENERIC_HOSTNAME="scriptexecute"
BR2_TARGET_GENERIC_ISSUE="cmprovision utility OS"
BR2_INIT_BUSYBOX=y
BR2_ROOTFS_DEVICE_CREATION_DYNAMIC_DEVTMPFS=y
BR2_TARGET_GENERIC_GETTY_PORT="tty1"
BR2_TARGET_GENERIC_GETTY_BAUDRATE_115200=y
BR2_SYSTEM_DHCP=""
BR2_ROOTFS_OVERLAY="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/rootfs-overlay"
BR2_ROOTFS_POST_BUILD_SCRIPT="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/post-build.sh"
BR2_ROOTFS_POST_IMAGE_SCRIPT="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/post-image.sh"
BR2_LINUX_KERNEL=y
BR2_LINUX_KERNEL_CUSTOM_TARBALL=y
BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION="https://github.com/raspberrypi/linux/archive/UOS_LINUX_COMMIT/linux-UOS_LINUX_COMMIT.tar.gz"
BR2_LINUX_KERNEL_DEFCONFIG="bcm2711"
BR2_LINUX_KERNEL_CONFIG_FRAGMENT_FILES="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/linux.fragment"
BR2_LINUX_KERNEL_LICENSE_FILES="COPYING LICENSES/preferred/GPL-2.0.cmprovision LICENSES/exceptions/Linux-syscall-note"
BR2_LINUX_KERNEL_IMAGEGZ=y
BR2_LINUX_KERNEL_DTS_SUPPORT=y
BR2_LINUX_KERNEL_INTREE_DTS_NAME="broadcom/bcm2711-rpi-cm4 broadcom/bcm2711-rpi-4-b broadcom/bcm2711-rpi-400"
BR2_LINUX_KERNEL_INTREE_DTSO_NAMES="overlays/spi-gpio40-45 overlays/dwc2"
BR2_LINUX_KERNEL_NEEDS_HOST_OPENSSL=y
BR2_PACKAGE_BUSYBOX_CONFIG_FRAGMENT_FILES="$(BR2_EXTERNAL_CMPROVISION_PATH)/board/cmprovision/busybox.fragment"
BR2_PACKAGE_E2FSPROGS=y
BR2_PACKAGE_E2FSPROGS_RESIZE2FS=y
BR2_PACKAGE_FLASHROM=y
BR2_PACKAGE_MMC_UTILS=y
BR2_PACKAGE_MMC_UTILS_ENABLE_DANGEROUS_COMMANDS=y
BR2_PACKAGE_PARTED=y
BR2_PACKAGE_UTIL_LINUX=y
BR2_PACKAGE_UTIL_LINUX_BINARIES=y
BR2_PACKAGE_LIBARCHIVE=y
BR2_PACKAGE_LIBARCHIVE_BSDTAR=y
BR2_PACKAGE_LIBSYSFS=y
BR2_PACKAGE_LIBCURL=y
BR2_PACKAGE_LIBCURL_CURL=y
BR2_PACKAGE_LIBCURL_VERBOSE=y
BR2_PACKAGE_LIBCURL_TLS_NONE=y
BR2_PACKAGE_DTC=y
BR2_PACKAGE_CMP_RPI_BOOT_FILES=y
BR2_PACKAGE_CMP_RPI_UTILS=y
BR2_PACKAGE_CMP_USBBOOT_BOOTFILES=y
BR2_TARGET_ROOTFS_CPIO=y
BR2_TARGET_ROOTFS_CPIO_XZ=y
# BR2_TARGET_ROOTFS_TAR is not set
```
(No `BR2_TARGET_GENERIC_ROOT_PASSWD`: empty means root logs in without a password, the getty on tty2 of the spec.)

Create `utility-os/board/cmprovision/linux.fragment`:
```
# cmprovision utility OS: what the arm64 bcm2711_defconfig of raspberrypi/linux lacks, or has as a module (the OS ships
# no modules, CONFIG_MODULES stays on: without it every =m of the defconfig would be built in). tools/utility-os/test.sh
# checks the whole list the OS needs.
CONFIG_LOCALVERSION="-cmprovision"
# flashrom writes the EEPROM through spidev on the SPI bus of overlay spi-gpio40-45
CONFIG_SPI_BCM2835=y
CONFIG_SPI_SPIDEV=y
# pinctrl reads the jumpers through /dev/gpiomem (it falls back to /dev/mem)
CONFIG_RASPBERRYPI_GPIOMEM=y
# the QEMU check of tools/utility-os/test.sh boots this very kernel: virtio-mmio and its network card
CONFIG_VIRTIO_MMIO=y
CONFIG_VIRTIO_NET=y
```

Create `utility-os/board/cmprovision/busybox.fragment`:
```
# cmprovision utility OS: BusyBox beyond buildroot's package/busybox/busybox.config, which has 253 of the 255 applets of
# the legacy OS (sysctl is CONFIG_BB_SYSCTL there): tc; the seamless decompression of tar; and nothing that logs in over
# the network
CONFIG_TC=y
CONFIG_FEATURE_SEAMLESS_GZ=y
CONFIG_FEATURE_SEAMLESS_XZ=y
CONFIG_FEATURE_SEAMLESS_BZ2=y
CONFIG_FEATURE_TAR_AUTODETECT=y
# CONFIG_TELNETD is not set
# CONFIG_HTTPD is not set
# CONFIG_FTPD is not set
# CONFIG_TFTPD is not set
```

Create `utility-os/board/cmprovision/post-build.sh`:
```sh
#!/bin/sh
# buildroot post-build of the cmprovision utility OS ($1: the target directory)
set -eu
TARGET=$1
. "$BR2_EXTERNAL_CMPROVISION_PATH/versions.env"
# no kernel modules: the OS needs only what linux.fragment builds in (depmod ran already and skips a missing directory)
rm -rf "$TARGET/lib/modules"
# the legacy OS started no cron daemon: the applet stays, the script buildroot adds for it goes
rm -f "$TARGET/etc/init.d/S50crond"
# which OS this is, for the console and the checks
printf '%s\n' "$UOS_VERSION" > "$TARGET/etc/cmprovision-uos"
printf 'cmprovision utility OS %s\n\n' "$UOS_VERSION" > "$TARGET/etc/issue"
```

Create `utility-os/board/cmprovision/post-image.sh`:
```sh
#!/bin/sh
# buildroot post-image of the cmprovision utility OS ($1: the images directory): the TFTP directory in $1/scriptexecute64,
# with its MANIFEST; tools/utility-os/build.sh copies it into the repository
set -eu
IMAGES=$1
EXT=$BR2_EXTERNAL_CMPROVISION_PATH
. "$EXT/versions.env"
OUT=$IMAGES/scriptexecute64
rm -rf "$OUT"
mkdir -p "$OUT/overlays"
cp "$IMAGES/Image.gz" "$OUT/kernel8.img"
cp "$IMAGES/rootfs.cpio.xz" "$OUT/scriptexecute.img"
for dtb in bcm2711-rpi-cm4 bcm2711-rpi-4-b bcm2711-rpi-400; do
    cp "$IMAGES/$dtb.dtb" "$OUT/"
done
for overlay in dwc2 spi-gpio40-45; do
    if [ -f "$IMAGES/$overlay.dtbo" ]; then
        cp "$IMAGES/$overlay.dtbo" "$OUT/overlays/"
    else
        # the kernel's own "make all" builds every overlay of the tree
        cp "$BUILD_DIR/linux-custom/arch/arm64/boot/dts/overlays/$overlay.dtbo" "$OUT/overlays/"
    fi
done
cp "$IMAGES/rpi-boot/start4.elf" "$IMAGES/rpi-boot/fixup4.dat" "$IMAGES/bootfiles.bin" "$OUT/"
cp "$EXT/board/cmprovision/config.txt" "$OUT/config.txt"
sed "s/@UOS_VERSION@/$UOS_VERSION/" "$EXT/board/cmprovision/cmdline.txt" > "$OUT/cmdline.txt"
sh "$EXT/../tools/utility-os/manifest.sh" "$OUT" "version=$UOS_VERSION" bits=64 \
    "kernel=Linux $UOS_LINUX_VERSION (raspberrypi/linux $UOS_LINUX_TAG)" "built=$(date -u +%Y-%m-%d)" \
    "buildroot=$UOS_BUILDROOT_VERSION" "firmware=$UOS_FIRMWARE_TAG" "rpi_utils=$UOS_RPI_UTILS_COMMIT" "usbboot=$UOS_USBBOOT_TAG"
echo "post-image: $OUT"
```

- [x] **Step 6: The legacy command list, the hash files, the build**

Create `tools/utility-os/legacy_commands.py`:
```python
"""The commands of the legacy utility OS (scriptexecute/scriptexecute.img) for utility-os/legacy-commands.txt: every
executable or link in bin, sbin, usr/bin and usr/sbin of its initramfs, sorted, minus those left out below with their
reason. tools/utility-os/test.sh checks that the new OS has every command of the list.
    python3 -I tools/utility-os/legacy_commands.py scriptexecute/scriptexecute.img > utility-os/legacy-commands.txt"""
import gzip
import lzma
import sys

LEFT_OUT = [
    ('camera, multimedia and VideoCore test tools of rpi-userland, which no provisioning script uses',
     lambda c: c.startswith('containers_') or c in ('mmal_vc_diag', 'raspistill', 'raspivid', 'raspividyuv', 'raspiyuv',
                                                     'tvservice', 'vchiq_test', 'vcsmem', 'vcfiled')),
    ("hooks of rpi-userland's dtoverlay; the dtoverlay of raspberrypi/utils does not call them",
     lambda c: c in ('dtoverlay-pre', 'dtoverlay-post')),
    ('test programs of sysfsutils 2.1.0; sysfsutils 2.1.1 (libsysfs of buildroot 2025.02) installs only systool',
     lambda c: c in ('dlist_test', 'get_device', 'get_driver', 'get_module')),
    ("e2fsprogs' e2scrub needs bash and LVM, and the utility OS has neither",
     lambda c: c in ('e2scrub', 'e2scrub_all')),
]

sys.stdout.reconfigure(newline='\n')   # LF on Windows too: the file is committed as it is written
data = open(sys.argv[1], 'rb').read()
if data[:2] == b'\x1f\x8b':
    data = gzip.decompress(data)
elif data[:6] == b'\xfd7zXZ\x00':
    data = lzma.decompress(data)

commands = set()
off = 0
while off < len(data) and data[off:off + 6] in (b'070701', b'070702'):
    fields = [int(data[off + 6 + i * 8:off + 14 + i * 8], 16) for i in range(13)]
    mode, size, namesize = fields[1], fields[6], fields[11]
    name = data[off + 110:off + 110 + namesize - 1].decode()
    off = (off + 110 + namesize + 3) & ~3
    off = (off + size + 3) & ~3
    if name == 'TRAILER!!!':
        break
    directory, _, command = name.rpartition('/')
    kind = mode & 0o170000
    if directory in ('bin', 'sbin', 'usr/bin', 'usr/sbin') and (kind == 0o120000 or (kind == 0o100000 and mode & 0o111)):
        commands.add(command)

print('# The commands of the legacy utility OS (scriptexecute/scriptexecute.img), made by tools/utility-os/legacy_commands.py:')
print('# the new OS has every one of them (tools/utility-os/test.sh). Left out, with the reason:')
kept = sorted(commands)
for reason, test in LEFT_OUT:
    out = [c for c in kept if test(c)]
    kept = [c for c in kept if not test(c)]
    print('#   %s: %s' % (' '.join(out), reason))
for command in kept:
    print(command)
```

Run: `cd "$REPO" && python -I tools/utility-os/legacy_commands.py scriptexecute/scriptexecute.img > utility-os/legacy-commands.txt && grep -vc '^#' utility-os/legacy-commands.txt`
Expected: `340`.

Create `tools/utility-os/hashes.sh`:
```sh
#!/bin/sh
# The buildroot hash files of the utility OS's own sources, from utility-os/versions.env (2.7.0): <dir>/<pkg>/<pkg>.hash.
# build.sh points BR2_GLOBAL_PATCH_DIR at <dir>. With BR2_DOWNLOAD_FORCE_CHECK_HASHES a source without its line stops
# the download, but a package with no hash file at all would be taken unchecked: all four are always written.
#   tools/utility-os/hashes.sh <versions.env> <dir>
set -eu
[ $# -eq 2 ] && [ -f "$1" ] || { echo "usage: hashes.sh <versions.env> <dir>" >&2; exit 2; }
. "$1"
DIR=$2
line() {
    [ -n "$1" ] || { echo "hashes.sh: $3 has no SHA256 in versions.env" >&2; exit 1; }
    printf 'sha256  %s  %s\n' "$1" "$2"
}
mkdir -p "$DIR/linux" "$DIR/cmp-rpi-boot-files" "$DIR/cmp-rpi-utils" "$DIR/cmp-usbboot-bootfiles"
line "${UOS_LINUX_SHA256:-}" "linux-$UOS_LINUX_COMMIT.tar.gz" UOS_LINUX_SHA256 > "$DIR/linux/linux.hash"
{
    line "${UOS_START4_SHA256:-}" start4.elf UOS_START4_SHA256
    line "${UOS_FIXUP4_SHA256:-}" fixup4.dat UOS_FIXUP4_SHA256
    line "${UOS_FIRMWARE_LICENCE_SHA256:-}" LICENCE.broadcom UOS_FIRMWARE_LICENCE_SHA256
} > "$DIR/cmp-rpi-boot-files/cmp-rpi-boot-files.hash"
line "${UOS_RPI_UTILS_SHA256:-}" "cmp-rpi-utils-$UOS_RPI_UTILS_COMMIT.tar.gz" UOS_RPI_UTILS_SHA256 > "$DIR/cmp-rpi-utils/cmp-rpi-utils.hash"
{
    line "${UOS_BOOTFILES_SHA256:-}" bootfiles.bin UOS_BOOTFILES_SHA256
    line "${UOS_USBBOOT_LICENSE_SHA256:-}" LICENSE UOS_USBBOOT_LICENSE_SHA256
} > "$DIR/cmp-usbboot-bootfiles/cmp-usbboot-bootfiles.hash"
```

Create `tools/utility-os/build.sh`:
```sh
#!/bin/sh
# Build the cmprovision utility OS with buildroot (2.7.0, docs/utility-os.md). utility-os/ is the BR2_EXTERNAL tree,
# utility-os/versions.env every version and hash. The TFTP directory lands in scriptexecute64/ of this repository, the
# sources and licences of everything in it in $UOS_WORK/output/legal-info.
#   UOS_WORK (default $HOME/uos-work): buildroot and its output; UOS_CACHE (default $HOME/uos-cache): downloads, ccache
# Debian or Ubuntu x86_64 (the Bootlin toolchain is built for it), never in OneDrive. Then tools/utility-os/test.sh.
set -eu
REPO=$(cd "$(dirname "$0")/../.." && pwd)
EXT=$REPO/utility-os
. "$EXT/versions.env"
WORK=${UOS_WORK:-$HOME/uos-work}
CACHE=${UOS_CACHE:-$HOME/uos-cache}

[ "$(uname -m)" = x86_64 ] || { echo "build.sh: needs an x86_64 host" >&2; exit 1; }
case "$PATH" in
    *' '*|*'	'*) echo "build.sh: PATH has spaces, buildroot refuses it (WSL: export PATH=/usr/sbin:/usr/bin:/sbin:/bin)" >&2; exit 1 ;;
esac
missing=
for c in which sed make gcc g++ patch perl python3 rsync cpio unzip bc wget git xz bzip2 gzip tar find xargs cmp sha256sum; do
    command -v "$c" >/dev/null 2>&1 || missing="$missing $c"
done
[ -x /usr/bin/file ] || missing="$missing file"
if [ -n "$missing" ]; then
    echo "build.sh: missing on this host:$missing" >&2
    echo "  apt-get install build-essential bc cpio rsync unzip file wget git python3 perl patch xz-utils bzip2" >&2
    exit 1
fi

mkdir -p "$WORK" "$CACHE/dl" "$CACHE/ccache"
BR=$WORK/buildroot-$UOS_BUILDROOT_VERSION
if [ ! -d "$BR" ]; then
    tarball=$CACHE/dl/buildroot-$UOS_BUILDROOT_VERSION.tar.gz
    if [ ! -f "$tarball" ]; then
        wget -q -O "$tarball.part" "https://buildroot.org/downloads/buildroot-$UOS_BUILDROOT_VERSION.tar.gz"
        mv "$tarball.part" "$tarball"
    fi
    if ! echo "$UOS_BUILDROOT_SHA256  $tarball" | sha256sum -c - >/dev/null 2>&1; then
        echo "build.sh: $tarball does not match UOS_BUILDROOT_SHA256" >&2
        rm -f "$tarball"
        exit 1
    fi
    tar xzf "$tarball" -C "$WORK"
fi

# the hashes of our sources, where buildroot looks for them (BR2_GLOBAL_PATCH_DIR)
sh "$REPO/tools/utility-os/hashes.sh" "$EXT/versions.env" "$WORK/hashes"

OUT=$WORK/output
export BR2_DL_DIR="$CACHE/dl" BR2_CCACHE_DIR="$CACHE/ccache"
make -C "$BR" O="$OUT" BR2_EXTERNAL="$EXT" cmprovision_defconfig > /dev/null
"$BR/utils/config" --file "$OUT/.config" \
    --set-str BR2_LINUX_KERNEL_CUSTOM_TARBALL_LOCATION "https://github.com/raspberrypi/linux/archive/$UOS_LINUX_COMMIT/linux-$UOS_LINUX_COMMIT.tar.gz" \
    --set-str BR2_GLOBAL_PATCH_DIR "$WORK/hashes"
make -C "$OUT" olddefconfig > /dev/null
# a changed linux.fragment or busybox.fragment takes effect only with a new configuration of its package
for pkg in linux-custom busybox; do
    if ls "$OUT"/build/$pkg*/.stamp_configured > /dev/null 2>&1; then
        make -C "$OUT" "${pkg%-custom}-reconfigure"
    fi
done
make -C "$OUT"
make -C "$OUT" legal-info > /dev/null

rsync -a --delete "$OUT/images/scriptexecute64/" "$REPO/scriptexecute64/"
echo "build.sh: done: $REPO/scriptexecute64 ($(du -sh "$REPO/scriptexecute64" | cut -f1)), legal-info in $OUT/legal-info"
```

Run: `cd "$REPO" && git add -N utility-os tools/utility-os && git update-index --chmod=+x tools/utility-os/build.sh tools/utility-os/hashes.sh tools/utility-os/legacy_commands.py utility-os/board/cmprovision/post-build.sh utility-os/board/cmprovision/post-image.sh`
(The `-N` keeps the new files listed by `git ls-files`, which the bench sync reads.)

- [x] **Step 7: Run the tree test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsTreeTest|UtilityOsInitTest|UtilityOsFilesTest'`
Expected: `phpunit exit 0`.

- [x] **Step 8: The bench helpers**

Create `$SP/wsl-bench/32-uos-in.sh`:
```sh
#!/bin/sh
# Bench only: the working tree (a tar on stdin) into /root/uos/src for a utility OS build; /root/uos/work and cache stay
set -e
mkdir -p /root/uos
rm -rf /root/uos/src.new && mkdir -p /root/uos/src.new && tar xf - -C /root/uos/src.new
rm -rf /root/uos/src && mv /root/uos/src.new /root/uos/src
echo "uos src: $(find /root/uos/src -type f | wc -l) files"
```

Create `$SP/wsl-bench/33-uos-build.sh`:
```sh
#!/bin/sh
# Bench only: tools/utility-os/build.sh of /root/uos/src in the background (systemd unit uos-build). Its log
# /root/uos/build.log ends in "build.sh exit N"; the PATH without the Windows paths WSL adds (buildroot refuses spaces)
set -e
systemctl reset-failed uos-build 2>/dev/null || true
systemd-run --unit=uos-build --collect --setenv=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    --setenv=HOME=/root --setenv=UOS_WORK=/root/uos/work --setenv=UOS_CACHE=/root/uos/cache \
    sh -c 'cd /root/uos/src && sh tools/utility-os/build.sh > /root/uos/build.log 2>&1; echo "build.sh exit $?" >> /root/uos/build.log'
echo "uos-build started"
```

Create `$SP/wsl-bench/34-uos-out.sh`:
```sh
#!/bin/sh
# Bench only: the built utility OS and the messages test.sh recorded, out of /root/uos/src as a tar on stdout
set -e
cd /root/uos/src
if [ -f tests/fixtures/utility-os/messages.txt ]; then
    tar cf - scriptexecute64 tests/fixtures/utility-os/messages.txt
else
    tar cf - scriptexecute64
fi
```

Create `$SP/wsl-bench/35-uos-test.sh`:
```sh
#!/bin/sh
# Bench only: tools/utility-os/test.sh of /root/uos/src in the background (systemd unit uos-test). Its log
# /root/uos/test.log ends in "test.sh exit N"
set -e
systemctl reset-failed uos-test 2>/dev/null || true
systemd-run --unit=uos-test --collect --setenv=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    --setenv=HOME=/root --setenv=UOS_WORK=/root/uos/work \
    sh -c 'cd /root/uos/src && sh tools/utility-os/test.sh > /root/uos/test.log 2>&1; echo "test.sh exit $?" >> /root/uos/test.log'
echo "uos-test started"
```

Put them on the bench:
```bash
for f in 32-uos-in.sh 33-uos-build.sh 34-uos-out.sh 35-uos-test.sh; do
    MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh "/root/bench/$f" < "$SP/wsl-bench/$f"
done
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- ls /root/bench/ | tr '\n' ' '
```
Expected: the listing has the four new scripts.

- [x] **Step 9: The first build**

Run:
```bash
cd "$REPO"
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/32-uos-in.sh
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/33-uos-build.sh
```
Expected: `uos src: … files`, then `uos-build started`.

Wait for the build. Use the Monitor tool with an until-loop that checks every 2 minutes: `until MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- tail -n 1 /root/uos/build.log | grep -q "^build.sh exit"; do sleep 120; done`. The first build takes 40–90 minutes on the bench's 16 cores (toolchain, host tools, the kernel with its modules, the packages, legal-info). Then:
```bash
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- tail -n 5 /root/uos/build.log
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- ls -la /root/uos/src/scriptexecute64 /root/uos/src/scriptexecute64/overlays
```
Expected: `build.sh: done: /root/uos/src/scriptexecute64 (…M), legal-info in /root/uos/work/output/legal-info` and `build.sh exit 0`. The listing has the 13 files of the spec's directory. `kernel8.img` is about 9–11 MB and `scriptexecute.img` 4–7 MB.

When the build stops, read the lines above `build.sh exit` and fix by kind; ledger each ruling:
- `ERROR: No hash found for …` or `… has wrong sha256 hash` — the source changed at its origin, or a line of `versions.env` is wrong. Download it by hand, check what it is, fix `versions.env`.
- The kernel stops on `overlays/…dtbo` — single overlay targets do not work in this tree. Remove `BR2_LINUX_KERNEL_INTREE_DTSO_NAMES` from the defconfig. `post-image.sh` then takes the overlays from the kernel's build directory, where `make all` built them.
- BusyBox's `tc` does not compile — drop `CONFIG_TC=y`, move `tc` into the left-out block of `legacy_commands.py` with the compiler's reason, and run the list again.
- `cmp-rpi-utils` stops in CMake — read `output/build/cmp-rpi-utils-*/cmprovision/` and the error. A subproject that needs more than libc and libfdt gets its dependency in `Config.in`, `select` and `_DEPENDENCIES`.
- `legal-info` fails on a license file hash — name the file in the error, and give it the same treatment as GPL-2.0 in `external.mk`.

After a fix: push the tree again (`32-uos-in.sh`) and run `33-uos-build.sh` again. It builds only what changed; a changed fragment reconfigures its package.

- [x] **Step 10: Commit the tree (the built files follow in Task 8, after their checks)**

Write `$SP/commit-270-t7.txt`:
```
The utility OS: buildroot tree, pins, build

utility-os/ is a BR2_EXTERNAL tree on buildroot 2025.02 LTS: the arm64
kernel of raspberrypi/linux stable_20260911 from bcm2711_defconfig plus
a fragment (modules built, none shipped), BusyBox with every applet of
the legacy OS, curl without TLS, e2fsprogs, flashrom, parted,
util-linux, mmc-utils, bsdtar and the tools of raspberrypi/utils.
versions.env holds every version and SHA256; hashes.sh turns them into
buildroot's hash files, build.sh builds scriptexecute64/.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run:
```bash
git add utility-os tools/utility-os/build.sh tools/utility-os/hashes.sh tools/utility-os/legacy_commands.py tests/Unit/UtilityOsTreeTest.php
git update-index --chmod=+x tools/utility-os/build.sh tools/utility-os/hashes.sh utility-os/board/cmprovision/post-build.sh utility-os/board/cmprovision/post-image.sh
git commit -q -F "$SP/commit-270-t7.txt"
```
Add any file a fix of step 9 changed.

---

### Task 8: The checks of the built OS, the QEMU boot, the messages, and the OS in the package

**Files:**
- Create: `tools/utility-os/test.sh`
- Create: `tools/utility-os/smoke/server.py`, `tools/utility-os/smoke/check.sh`, `tools/utility-os/smoke/verify.py`
- Create: `tests/Unit/UtilityOsMessagesTest.php`
- Create (from the bench build): `scriptexecute64/` (13 files), `tests/fixtures/utility-os/messages.txt`
- Modify: `debian/install` (a line), `tests/Unit/PackagingTest.php` (one assertion), `tests/Unit/UtilityOsFilesTest.php` (`dirs()` and one test)
- Modify, only if the messages test says so: `app/Http/Controllers/ScriptExecuteController.php` (`WRITE_FAILURE_HINTS`), `app/Support/FailureAdvice.php`

**Interfaces:**
- Consumes: Task 7's build (`$UOS_WORK/output`, `scriptexecute64/` in the source tree, `UOS_*` of `versions.env`), Task 6's init and `cmdline.txt`, `public/tools/ledpattern`.
- Produces: `sh tools/utility-os/test.sh` (one `ok`/`FAIL` line per check, exit 0 only when all pass; files in `$UOS_WORK/smoke`, QEMU's console in `$UOS_WORK/qemu.log`); the fixture `tests/fixtures/utility-os/messages.txt`. Its format: two `#` lines, then sections `[name]` followed by the text. The sections are `gz-cut`, `gz-garbage`, `xz-cut`, `xz-garbage`, `bz2-cut`, `bz2-garbage`, `dd-full`, `dd-records`, `sha256-mismatch`, `flashrom-strings`.

- [x] **Step 1: The test server, the check script and the verifier**

Create `tools/utility-os/smoke/server.py`:
```python
"""The provisioning server of the QEMU check (tools/utility-os/test.sh). The module's first /scriptexecute gets a 502 whose
page would leave a mark if the module ran it, the next ones the check script (check.sh with this server's address);
/samples/<name> serves compressed samples and ledpattern; POST /result keeps what the check found. Everything it sees
goes to the directory given first; test.sh stops it.
    server.py <out dir> <check.sh> <ledpattern>"""
import bz2
import gzip
import hashlib
import http.server
import lzma
import os
import random
import sys
import urllib.parse

OUT, CHECK, LEDPATTERN = sys.argv[1:4]


def samples():
    data = bytes(random.Random(2026).getrandbits(8) for _ in range(262144))
    files = {'garbage': b'<html><head><title>502 Bad Gateway</title></head><body>nginx</body></html>\n' * 8}
    with open(LEDPATTERN, 'rb') as f:
        files['ledpattern'] = f.read()
    packed = {'gz': gzip.compress(data), 'xz': lzma.compress(data, check=lzma.CHECK_CRC32), 'bz2': bz2.compress(data)}
    for kind, blob in packed.items():
        files['image.' + kind] = blob
        files['cut.' + kind] = blob[:len(blob) // 2]
    with open(os.path.join(OUT, 'image.sha256'), 'w') as f:
        f.write(hashlib.sha256(data).hexdigest() + '\n')
    return files


SAMPLES = samples()


class Handler(http.server.BaseHTTPRequestHandler):
    starts = 0

    def note(self, name, text):
        with open(os.path.join(OUT, name), 'a', encoding='utf-8') as f:
            f.write(text + '\n')

    def answer(self, code, body, kind='text/plain'):
        self.send_response(code)
        self.send_header('Content-Type', kind)
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        url = urllib.parse.urlsplit(self.path)
        self.note('requests', self.path)
        host = self.headers.get('Host', '')
        if url.path == '/scriptexecute':
            Handler.starts += 1
            if Handler.starts == 1:
                self.note('start', url.query)
                return self.answer(502, ('curl -s http://%s/ran-502\n' % host).encode())
            with open(CHECK, encoding='utf-8') as f:
                return self.answer(200, f.read().replace('__SERVER__', 'http://' + host).encode())
        if url.path == '/ran-502':
            self.note('ran-502', 'the module ran the page of a 502 as its script')
            return self.answer(200, b'')
        name = url.path[len('/samples/'):] if url.path.startswith('/samples/') else None
        if name in SAMPLES:
            return self.answer(200, SAMPLES[name], 'application/octet-stream')
        self.answer(404, b'not found\n')

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        if self.path == '/result':
            with open(os.path.join(OUT, 'result.txt'), 'wb') as f:
                f.write(body)
            return self.answer(200, b'')
        self.answer(404, b'')

    def log_message(self, fmt, *args):
        pass


httpd = http.server.HTTPServer(('127.0.0.1', 0), Handler)
with open(os.path.join(OUT, 'port'), 'w') as f:
    f.write(str(httpd.server_address[1]))
httpd.serve_forever()
```

Create `tools/utility-os/smoke/check.sh`:
```sh
#!/bin/sh
# Runs inside the utility OS under QEMU (tools/utility-os/test.sh; smoke/server.py fills in __SERVER__): what the new OS
# says, back to the test server, then power off. No set -e: every check reports, a failing one too.
S=__SERVER__
R=/tmp/result
cd /tmp || exit 1
: > "$R"
section() { printf '[%s]\n' "$1" >> "$R"; }
get() { curl -s -f -o "$2" "$S/samples/$1"; }

section uos
cat /etc/cmprovision-uos >> "$R"
section kernel
uname -r >> "$R"

# every tool starts: a missing library or a wrong binary shows as 126, 127, 132, 134 or 139, or in the first line
section versions
while read -r name args; do
    $name $args < /dev/null > /tmp/out 2>&1
    printf '%s rc=%s %s\n' "$name" "$?" "$(head -n 1 /tmp/out)" >> "$R"
done <<'EOF'
busybox
curl --version
e2fsck -V
mke2fs -V
resize2fs
tune2fs
parted --version
partprobe --version
sfdisk --version
blkdiscard --version
lsblk --version
flashrom --version
mmc --help
bsdtar --version
systool -h
pinctrl -h
vcgencmd version
vcmailbox
dtoverlay -h
dtmerge
hexdump --version
EOF

# the decompressors of the module's write (ScriptExecuteController::DECOMPRESSORS): a whole image, one cut short, garbage
get garbage /tmp/garbage
for kind in gz xz bz2; do
    case $kind in
        gz) dec="gzip -dc" ;;
        xz) dec="xz -dc" ;;
        bz2) dec="bunzip2 -dc" ;;
    esac
    get "image.$kind" /tmp/image
    get "cut.$kind" /tmp/cut
    section "$kind-whole"
    $dec < /tmp/image 2>/dev/null | sha256sum | cut -d' ' -f1 >> "$R"
    section "$kind-cut"
    $dec < /tmp/cut 2>&1 > /dev/null | head -n 5 >> "$R"
    section "$kind-garbage"
    $dec < /tmp/garbage 2>&1 > /dev/null | head -n 5 >> "$R"
done

# dd as the write uses it, and on a full storage
mkdir -p /tmp/full && mount -t tmpfs -o size=64k tmpfs /tmp/full
section dd-full
dd if=/dev/zero of=/tmp/full/x bs=4k count=64 2>&1 | head -n 5 >> "$R"
section dd-records
dd if=/dev/zero of=/dev/null bs=1M count=3 >> "$R" 2>&1
section dd-flags
dd if=/dev/zero of=/tmp/d bs=1M count=2 iflag=fullblock conv=fsync,notrunc 2>/dev/null
echo "rc=$?" >> "$R"

# the EEPROM flash: the image check, and the lines of flashrom the server looks for
echo hi > /tmp/pieeprom.bin
section sha256-mismatch
echo "0000000000000000000000000000000000000000000000000000000000000000  pieeprom.bin" | sha256sum -c >> "$R" 2>&1
section flashrom-strings
strings "$(command -v flashrom)" | grep -E 'identical|No EEPROM/flash device found' | sort -u >> "$R"

# small tools of the module's script
section od
printf '\000\000\000\003' > /tmp/bm
od -An -tu1 -j3 -N1 /tmp/bm | tr -d ' ' >> "$R"
section base64
printf 'aGVsbG8=' | base64 -d >> "$R"
echo >> "$R"
section zeros
head -c 1000 /dev/zero | tr -d '\000' | wc -c | tr -d ' ' >> "$R"
section awk
awk 'BEGIN { printf "%.0f\n", 2^40 + 1 }' >> "$R"
section cmp
cmp -s /tmp/d /tmp/d
echo "rc=$?" >> "$R"
section curl
curl -s -o /dev/null -w '%{http_code}' "$S/missing" >> "$R"
curl -s -f -o /dev/null "$S/missing"
echo " rc=$?" >> "$R"

# the 32-bit LED helper the module downloads runs on this 64-bit kernel
section ledpattern
get ledpattern /tmp/ledpattern && chmod +x /tmp/ledpattern
/tmp/ledpattern > /tmp/out 2>&1
printf 'rc=%s %s\n' "$?" "$(head -n 1 /tmp/out)" >> "$R"

curl -s --data-binary @"$R" "$S/result"
sync
poweroff -f
```

Create `tools/utility-os/smoke/verify.py`:
```python
"""What the QEMU run of tools/utility-os/test.sh left in its directory: one ok/FAIL line per check; the tools' messages
go to the fixture tests/Unit/UtilityOsMessagesTest.php reads. Exit 1 when a check failed.
    verify.py <smoke dir> <expected OS version> <fixture>"""
import os
import re
import sys

SMOKE, VERSION, FIXTURE = sys.argv[1:4]
MESSAGES = ['gz-cut', 'gz-garbage', 'xz-cut', 'xz-garbage', 'bz2-cut', 'bz2-garbage', 'dd-full', 'dd-records',
            'sha256-mismatch', 'flashrom-strings']
PARAMETERS = ['serial', 'model', 'storagesize', 'mac', 'inversejumper', 'memorysize', 'temp', 'cid', 'csd', 'bootmode', 'uos']
fails = 0


def check(ok, text):
    global fails
    print(('ok   ' if ok else 'FAIL ') + text)
    if not ok:
        fails += 1


def read(name):
    path = os.path.join(SMOKE, name)
    return open(path, encoding='utf-8', errors='replace').read() if os.path.exists(path) else None


start = (read('start') or '').strip()
check(start != '', 'qemu: the init asked the server for its script')
values = dict(pair.split('=', 1) if '=' in pair else (pair, '') for pair in start.split('&')) if start else {}
check([p.split('=', 1)[0] for p in start.split('&')] == PARAMETERS, 'start: the parameters of the legacy OS, then uos')
check(values.get('uos') == VERSION, 'start: uos=' + VERSION)
check(values.get('model') == 'linux,dummy-virt', 'start: the model from the device tree')
check(values.get('mac') == '52:54:00:12:34:56', 'start: the MAC of eth0')
check(values.get('memorysize', '').isdigit() and int(values['memorysize']) > 500000, 'start: MemTotal in kB')
check(all(values.get(k) == '' for k in ['serial', 'storagesize', 'inversejumper', 'temp', 'cid', 'csd', 'bootmode']),
      'start: what QEMU lacks is empty (serial, eMMC, jumpers, temperature, boot mode)')
check('{' not in start, 'start: every placeholder filled in')
check(read('ran-502') is None, 'start: the page of the 502 never ran')
check((read('requests') or '').count('/scriptexecute?') >= 2, 'start: the init asked again after the 502')

result = read('result.txt')
check(result is not None, 'qemu: the check script sent its result')
sections, name = {}, None
for line in (result or '').splitlines():
    m = re.fullmatch(r'\[([a-z0-9-]+)\]', line)
    if m:
        name = m.group(1)
        sections[name] = []
    elif name:
        sections[name].append(line)
text = {k: '\n'.join(v).strip() for k, v in sections.items()}

check(text.get('uos') == VERSION, 'os: /etc/cmprovision-uos')
check('-cmprovision' in text.get('kernel', ''), 'os: the kernel is ours (-cmprovision)')
tools = sections.get('versions', [])
check(len(tools) == 21, 'tools: 21 tools answered')
for line in tools:
    m = re.match(r'(\S+) rc=(\d+) ?(.*)', line)
    good = m and int(m.group(2)) not in (126, 127, 132, 134, 139) and 'error while loading shared libraries' not in line
    check(bool(good), 'tools: ' + line[:100])
whole = (read('image.sha256') or '').strip()
for kind in ['gz', 'xz', 'bz2']:
    check(text.get(kind + '-whole') == whole, kind + ': a whole image decompresses right')
check(text.get('od') == '3', 'od: the 4th byte as a number')
check(text.get('base64') == 'hello', 'base64 -d')
check(text.get('zeros') == '0', 'tr -d NUL | wc -c')
check(text.get('awk') == '1099511627777', 'awk: 64-bit sums')
check(text.get('cmp') == 'rc=0', 'cmp -s')
check(text.get('dd-flags') == 'rc=0', 'dd iflag=fullblock conv=fsync,notrunc')
check(text.get('curl') == '404 rc=22', 'curl -w %{http_code} and -f')
check(text.get('ledpattern', '').startswith('rc=') and 'usage: ledpattern' in text.get('ledpattern', ''),
      'ledpattern (32-bit) runs')
check(bool(re.search(r'^\d+\+\d+ records in$', text.get('dd-records', ''), re.M)), 'dd: N+M records in')
for name in MESSAGES:
    check(text.get(name, '') != '', 'messages: ' + name)

if all(text.get(name) for name in MESSAGES):
    with open(FIXTURE, 'w', encoding='utf-8', newline='\n') as f:
        f.write('# The messages of the tools of the utility OS %s, recorded under QEMU by tools/utility-os/test.sh;\n' % VERSION)
        f.write('# tests/Unit/UtilityOsMessagesTest.php checks that the server recognises them. Do not edit: run test.sh.\n')
        for name in MESSAGES:
            f.write('[%s]\n%s\n' % (name, text[name]))
    print('ok   messages written to ' + FIXTURE)
sys.exit(1 if fails else 0)
```

- [x] **Step 2: `test.sh`**

Create `tools/utility-os/test.sh`:
```sh
#!/bin/sh
# Check the built utility OS (2.7.0, docs/utility-os.md), after tools/utility-os/build.sh with the same UOS_WORK:
#   kernel    every option the OS needs is built in (=y); modules stay on (no =m of the defconfig built in)
#   contents  every command of utility-os/legacy-commands.txt and pinctrl, no kernel module, no network login service
#   qemu      qemu-system-aarch64 -M virt boots the same kernel8.img and scriptexecute.img; the init gets its script from
#             smoke/server.py after a 502, and the script sends back the start parameters, the versions of the tools and
#             their messages (smoke/verify.py writes tests/fixtures/utility-os/messages.txt)
#   size      kernel8.img + scriptexecute.img at most 32 MiB
#   manifest  scriptexecute64/MANIFEST lists exactly its files
# One ok/FAIL line per check; exit 0 only when all pass.
set -u
REPO=$(cd "$(dirname "$0")/../.." && pwd)
. "$REPO/utility-os/versions.env"
WORK=${UOS_WORK:-$HOME/uos-work}
OUT=$WORK/output
TFTP=$REPO/scriptexecute64
FAILS=0
ok() { echo "ok   $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }

# --- kernel
REQUIRED="CONFIG_MODULES CONFIG_SPI_BCM2835 CONFIG_SPI_SPIDEV CONFIG_RASPBERRYPI_GPIOMEM CONFIG_BCM_VCIO CONFIG_BCM2835_MBOX
CONFIG_RASPBERRYPI_FIRMWARE CONFIG_LEDS_GPIO CONFIG_LEDS_TRIGGER_TIMER CONFIG_LEDS_TRIGGER_HEARTBEAT CONFIG_LEDS_TRIGGER_DEFAULT_ON
CONFIG_GPIO_RASPBERRYPI_EXP CONFIG_MMC CONFIG_MMC_BLOCK CONFIG_MMC_SDHCI CONFIG_MMC_SDHCI_PLTFM CONFIG_MMC_SDHCI_IPROC
CONFIG_MMC_BCM2835 CONFIG_EXT4_FS CONFIG_VFAT_FS CONFIG_NLS_CODEPAGE_437 CONFIG_BCMGENET CONFIG_BROADCOM_PHY CONFIG_PACKET
CONFIG_UNIX CONFIG_INET CONFIG_FB CONFIG_FB_BCM2708 CONFIG_FRAMEBUFFER_CONSOLE CONFIG_VT CONFIG_VT_CONSOLE CONFIG_USB
CONFIG_USB_DWC2 CONFIG_USB_XHCI_HCD CONFIG_USB_XHCI_PCI CONFIG_PCIE_BRCMSTB CONFIG_USB_HID CONFIG_HID_GENERIC CONFIG_DEVTMPFS
CONFIG_BLK_DEV_INITRD CONFIG_RD_XZ CONFIG_COMPAT CONFIG_DEBUG_FS CONFIG_BCM2835_WDT CONFIG_BCM2711_THERMAL CONFIG_VIRTIO_MMIO
CONFIG_VIRTIO_NET CONFIG_ARM_PSCI_FW CONFIG_SERIAL_AMBA_PL011 CONFIG_SERIAL_AMBA_PL011_CONSOLE"
CONFIG=$OUT/build/linux-custom/.config
if [ -f "$CONFIG" ]; then
    missing=
    for opt in $REQUIRED; do
        grep -qx "$opt=y" "$CONFIG" || missing="$missing $opt"
    done
    if [ -z "$missing" ]; then ok "kernel: every option the OS needs is built in"; else fail "kernel: not built in:$missing"; fi
    if grep -qx 'CONFIG_LOCALVERSION="-cmprovision"' "$CONFIG"; then ok "kernel: LOCALVERSION -cmprovision"; else fail "kernel: LOCALVERSION"; fi
    if grep -qx 'CONFIG_IPV6=m' "$CONFIG"; then ok "kernel: the defconfig's modules stay modules"; else fail "kernel: CONFIG_IPV6 is no longer =m"; fi
else
    fail "kernel: no $CONFIG (run build.sh first)"
fi

# --- contents
TARGET=$OUT/target
set -f
missing=
for cmd in $(grep -v '^#' "$REPO/utility-os/legacy-commands.txt") pinctrl; do
    found=
    for d in bin sbin usr/bin usr/sbin; do
        if [ -e "$TARGET/$d/$cmd" ] || [ -L "$TARGET/$d/$cmd" ]; then found=1; fi
    done
    [ -n "$found" ] || missing="$missing $cmd"
done
set +f
if [ -z "$missing" ]; then ok "contents: every command of the legacy OS"; else fail "contents: missing:$missing"; fi
if [ -d "$TARGET/lib/modules" ]; then fail "contents: kernel modules in the image"; else ok "contents: no kernel modules"; fi
SCRIPTS=$(ls "$TARGET/etc/init.d" | tr '\n' ' ')
case " $SCRIPTS " in
    *telnet*|*ssh*|*dropbear*|*httpd*|*ftpd*|*inetd*|*crond*) fail "contents: a service the legacy OS did not start: $SCRIPTS" ;;
    *" S99scriptexec "*) ok "contents: init scripts $SCRIPTS" ;;
    *) fail "contents: no S99scriptexec among $SCRIPTS" ;;
esac
if grep -qx 'CONFIG_TELNETD=y' "$OUT"/build/busybox-*/.config; then fail "contents: BusyBox has telnetd"; else ok "contents: no telnetd"; fi

# --- size
size=$(( $(wc -c < "$TFTP/kernel8.img") + $(wc -c < "$TFTP/scriptexecute.img") ))
mib=$(( (size + 1048575) / 1048576 ))
before=$(git -C "$REPO" show HEAD:scriptexecute64/MANIFEST 2>/dev/null | awk '$0 ~ /^file=/ && ($3 == "kernel8.img" || $3 == "scriptexecute.img") { s += $2 } END { if (s) printf "%d", (s + 1048575) / 1048576 }')
if [ "$size" -le 33554432 ]; then ok "size: kernel8.img + scriptexecute.img = $mib MiB${before:+ (committed: $before MiB)}"; else fail "size: $mib MiB, over 32 MiB"; fi

# --- manifest
listed=$(sed -n 's/^file=\([0-9a-f]*\) \([0-9]*\) \(.*\)$/\3 \1 \2/p' "$TFTP/MANIFEST")
present=$(cd "$TFTP" && find . -type f ! -name MANIFEST | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r f; do
    printf '%s %s %s\n' "$f" "$(sha256sum "$f" | cut -d' ' -f1)" "$(wc -c < "$f" | tr -d ' ')"; done)
if [ "$listed" = "$present" ]; then ok "manifest: lists exactly the files"; else fail "manifest: differs from the files"; fi

# --- qemu
SMOKE=$WORK/smoke
rm -rf "$SMOKE" && mkdir -p "$SMOKE"
python3 "$REPO/tools/utility-os/smoke/server.py" "$SMOKE" "$REPO/tools/utility-os/smoke/check.sh" "$REPO/public/tools/ledpattern" \
    > "$SMOKE/server.log" 2>&1 &
SERVER=$!
for i in $(seq 50); do [ -s "$SMOKE/port" ] && break; sleep 0.2; done
PORT=$(cat "$SMOKE/port" 2>/dev/null)
APPEND="console=ttyAMA0 $(sed "s#http://172.20.0.1/#http://10.0.2.2:$PORT/#" "$TFTP/cmdline.txt")"
timeout 900 qemu-system-aarch64 -M virt -cpu cortex-a72 -smp 2 -m 1024 -nographic -no-reboot \
    -kernel "$TFTP/kernel8.img" -initrd "$TFTP/scriptexecute.img" -append "$APPEND" \
    -netdev user,id=n0 -device virtio-net-device,netdev=n0 > "$WORK/qemu.log" 2>&1
rc=$?
kill "$SERVER" 2>/dev/null
if [ "$rc" = 0 ]; then ok "qemu: the module powered off by itself"; else fail "qemu: exit $rc (124: no power-off in 15 minutes), see $WORK/qemu.log"; fi
mkdir -p "$REPO/tests/fixtures/utility-os"
python3 "$REPO/tools/utility-os/smoke/verify.py" "$SMOKE" "$UOS_VERSION" "$REPO/tests/fixtures/utility-os/messages.txt"
v=$?
[ "$v" = 0 ] || FAILS=$((FAILS + 1))

if [ "$FAILS" = 0 ]; then echo "test.sh: all checks passed"; exit 0; fi
echo "test.sh: $FAILS check(s) failed"
exit 1
```

- [x] **Step 3: Write the failing messages test**

Create `tests/Unit/UtilityOsMessagesTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Http\Controllers\ScriptExecuteController;
use App\Support\FailureAdvice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The server reads what the tools of the new utility OS print (2.7.0): tests/fixtures/utility-os/messages.txt holds their
    messages as tools/utility-os/test.sh recorded them under QEMU */
class UtilityOsMessagesTest extends TestCase
{
    /** section => its text */
    protected static function sections(): array
    {
        $sections = [];
        $name = null;
        foreach (file(dirname(__DIR__).'/fixtures/utility-os/messages.txt', FILE_IGNORE_NEW_LINES) as $line)
        {
            if (preg_match('/^\[([a-z0-9-]+)\]$/', $line, $m))
                $sections[$name = $m[1]] = '';
            elseif ($name !== null)
                $sections[$name] .= $line."\n";
        }
        return $sections;
    }

    public function test_every_message_was_recorded()
    {
        foreach (['gz-cut', 'gz-garbage', 'xz-cut', 'xz-garbage', 'bz2-cut', 'bz2-garbage', 'dd-full', 'dd-records',
                  'sha256-mismatch', 'flashrom-strings'] as $section)
            $this->assertNotSame('', trim(self::sections()[$section] ?? ''), $section);
    }

    public static function failedWrites(): array
    {
        return [
            'gzip on garbage' => ['gz-garbage', 'not a valid compressed image'],
            'xz on garbage' => ['xz-garbage', 'not a valid compressed image'],
            'bunzip2 on garbage' => ['bz2-garbage', 'not a valid compressed image'],
            'gzip cut short' => ['gz-cut', 'truncated or corrupted'],
            'xz cut short' => ['xz-cut', 'truncated or corrupted'],
            'bunzip2 cut short' => ['bz2-cut', 'truncated or corrupted'],
            'dd on a full storage' => ['dd-full', 'could not be written'],
        ];
    }

    #[DataProvider('failedWrites')]
    public function test_the_server_explains_a_failed_write_of_the_new_os(string $section, string $hint)
    {
        $this->assertStringContainsString($hint, (string) ScriptExecuteController::diagnoseWriteFailure(self::sections()[$section]),
            self::sections()[$section]);
    }

    public function test_the_write_counts_the_records_of_dd()
    {
        // write.blade.php: sed -n 's/^\([0-9]*\)+\([0-9]*\) records in$/\1 \2/p'
        $this->assertMatchesRegularExpression('/^[0-9]*\+[0-9]* records in$/m', self::sections()['dd-records']);
    }

    public function test_the_eeprom_flash_and_its_advice_read_the_new_os()
    {
        // the flash script greps flashrom's log for "identical" (ScriptExecuteController::startProvisoning); FailureAdvice
        // knows a missing chip and an image that differs from the project
        $flashrom = self::sections()['flashrom-strings'];
        $this->assertStringContainsString('identical', $flashrom);
        $advice = new \ReflectionMethod(FailureAdvice::class, 'eepromAdvice');
        $this->assertStringContainsString('flashrom found no SPI flash chip', $advice->invoke(null, $flashrom));
        $this->assertStringContainsString('does not match the active project', $advice->invoke(null, self::sections()['sha256-mismatch']));
    }
}
```

- [x] **Step 4: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsMessagesTest'`
Expected: `phpunit exit 1` or `2`: `file(…/messages.txt): Failed to open stream`.

- [x] **Step 5: Run the checks on the bench**

Push the tree again (the build in `/root/uos/work` stays) and run `test.sh`:
```bash
cd "$REPO"
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/32-uos-in.sh
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/33-uos-build.sh
```
Wait for the unit `uos-build` as in Task 7, step 9 (nothing changed, so only the images are made again: minutes). Then:
```bash
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/35-uos-test.sh
```
Wait the same way for the unit `uos-test`, then:
```bash
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- cat /root/uos/test.log
```
Expected: every line `ok`, the last one `test.sh: all checks passed`.

When a line says FAIL, fix by kind, and record a ledger ruling for each:
- `kernel: not built in: X` — add `CONFIG_X=y` to `linux.fragment`, rebuild (Task 7, step 9).
- `contents: missing: …` — enable the package option that ships the command in `cmprovision_defconfig`, or the BusyBox applet in `busybox.fragment`. If no buildroot 2025.02 package ships it, or it is a test program of a package (sysfsutils' `dlist_test`, `get_device`, `get_driver`) or a build tool (e2fsprogs' `compile_et`, `mk_cmds`), move it into the left-out block at the top of `legacy-commands.txt` with its reason. Rebuild.
- `qemu: exit 124` — read `/root/uos/work/qemu.log`. A kernel that does not boot under `virt` is Risk 3 of the spec: stop and tell the user.
- `start: …` or `tools: …` — the init (Task 6) or a package. Fix it there with a test first.
- `messages: …` empty — `check.sh` did not get there; see `result.txt` in `/root/uos/work/smoke`.

- [x] **Step 6: The built files into the repository**

Run:
```bash
cd "$REPO"
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/34-uos-out.sh | tar xf - -C "$REPO"
ls scriptexecute64 scriptexecute64/overlays; cat scriptexecute64/cmdline.txt; head -10 scriptexecute64/MANIFEST; head -6 tests/fixtures/utility-os/messages.txt
```
Expected: `bcm2711-rpi-4-b.dtb bcm2711-rpi-400.dtb bcm2711-rpi-cm4.dtb bootfiles.bin cmdline.txt config.txt fixup4.dat kernel8.img MANIFEST overlays scriptexecute.img start4.elf` and `dwc2.dtbo spi-gpio40-45.dtbo`. The cmdline ends in `&uos=2026.10`, the MANIFEST names `version=2026.10`, and the fixture starts with its two comment lines and `[gz-cut]`.

- [x] **Step 7: Run the messages test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsMessagesTest'`
Expected: `phpunit exit 0`.
If a case fails, the server does not recognise a message of the new OS. Add the message to the matching pattern of `ScriptExecuteController::WRITE_FAILURE_HINTS` (or `FailureAdvice::eepromAdvice()`), run `ImageWriteDiagnosticsTest|ModuleCardTest|UtilityOsMessagesTest`, and ledger it.

- [x] **Step 8: The OS in the package, and its files test**

In `debian/install`, after `scriptexecute var/lib/cmprovision`:
```
scriptexecute64 var/lib/cmprovision
```

In `tests/Unit/PackagingTest.php`, in `test_dnsmasq_and_rpiboot_follow_the_link_and_the_shipped_conffile_stays`, add:
```php
        $this->assertStringContainsString("scriptexecute var/lib/cmprovision\nscriptexecute64 var/lib/cmprovision\n", $this->file('debian/install'));
```

In `tests/Unit/UtilityOsFilesTest.php`, `dirs()` becomes `return [['scriptexecute'], ['scriptexecute64']];`, and add:
```php
    public function test_ours_says_the_versions_it_was_built_from()
    {
        preg_match_all('/^(UOS_[A-Z0-9_]+)=(.*)$/m', file_get_contents(self::root().'/utility-os/versions.env'), $m);
        $v = array_combine($m[1], $m[2]);
        $manifest = file_get_contents(self::root().'/scriptexecute64/MANIFEST');
        foreach (["version={$v['UOS_VERSION']}", 'bits=64', "kernel=Linux {$v['UOS_LINUX_VERSION']} (raspberrypi/linux {$v['UOS_LINUX_TAG']})",
                  "buildroot={$v['UOS_BUILDROOT_VERSION']}", "firmware={$v['UOS_FIRMWARE_TAG']}", "rpi_utils={$v['UOS_RPI_UTILS_COMMIT']}",
                  "usbboot={$v['UOS_USBBOOT_TAG']}"] as $line)
            $this->assertStringContainsString($line."\n", $manifest);
        $this->assertSame($v['UOS_BOOTFILES_SHA256'], hash_file('sha256', self::root().'/scriptexecute64/bootfiles.bin'));
        $this->assertStringEndsWith("&uos={$v['UOS_VERSION']}\n", file_get_contents(self::root().'/scriptexecute64/cmdline.txt'));
        $this->assertStringContainsString("# tests/Unit/UtilityOsMessagesTest.php", file_get_contents(self::root().'/tests/fixtures/utility-os/messages.txt'));
        $this->assertStringContainsString("utility OS {$v['UOS_VERSION']}, recorded", file_get_contents(self::root().'/tests/fixtures/utility-os/messages.txt'),
            'the messages are of this build');
    }
```

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsFilesTest|UtilityOsMessagesTest|UtilityOsTreeTest|PackagingTest|UtilityOsInitTest'`
Expected: `phpunit exit 0`.

- [x] **Step 9: Commit**

Write `$SP/commit-270-t8.txt`:
```
The utility OS 2026.10, checked under QEMU, in the package

test.sh checks the kernel options, the legacy command set, the size
and the MANIFEST, and boots the same kernel8.img and scriptexecute.img
under qemu-system-aarch64 -M virt: the init gets its script after a
502, and the script reports the start parameters, the tools and their
messages. The messages go to tests/fixtures/utility-os/messages.txt;
UtilityOsMessagesTest checks that the server recognises them.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run:
```bash
git add tools/utility-os/test.sh tools/utility-os/smoke scriptexecute64 tests/fixtures/utility-os/messages.txt tests/Unit/UtilityOsMessagesTest.php tests/Unit/UtilityOsFilesTest.php tests/Unit/PackagingTest.php debian/install
git update-index --chmod=+x tools/utility-os/test.sh tools/utility-os/smoke/check.sh
git commit -q -F "$SP/commit-270-t8.txt"
```
Add to `git add` any file changed in steps 5 or 7 (`linux.fragment`, `cmprovision_defconfig`, `busybox.fragment`, `legacy-commands.txt`, the controller, `FailureAdvice.php`).

---

### Task 9: `update.sh` — the newest versions, hashed, into `versions.env`

**Files:**
- Create: `tools/utility-os/update.sh`
- Create: `tests/Unit/UtilityOsUpdateTest.php`

**Interfaces:**
- Consumes: `utility-os/versions.env` (Task 7 keys).
- Produces: `sh tools/utility-os/update.sh [--check]`.
  - `--check`: one line per component (`buildroot: 2025.02.18 (newest)` or `kernel: stable_20260911 -> stable_20261009`), a `note:` line when a newer LTS of buildroot exists; exit 0 when nothing is new, 10 when something is, 1 when it could not find out.
  - Without `--check`: the same lines, then the downloads and hashes, then `versions.env` rewritten in one `mv` with `UOS_VERSION` raised. Exit 0, or 1 with the file untouched.
  - Environment: `UOS_VERSIONS_ENV` (the file, default `utility-os/versions.env`), `UOS_TODAY` (`YYYY-MM-DD`, default today in UTC).

- [x] **Step 1: Write the failing test**

Create `tests/Unit/UtilityOsUpdateTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** tools/utility-os/update.sh (2.7.0) with a fake git and a fake curl: what is newest, what moved, the hashes of what it
    downloads, the version of the OS, and versions.env never half written */
class UtilityOsUpdateTest extends TestCase
{
    const KERNEL_NEW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const UTILS_NEW = 'cccccccccccccccccccccccccccccccccccccccc';

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['sh', 'git', 'curl', 'sha256sum'] as $tool)
            if (trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '')
                $this->markTestSkipped('needs '.$tool);
        $this->dir = sys_get_temp_dir().'/uos-update-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0755, true);
        mkdir($this->dir.'/www', 0755, true);
        copy(dirname(__DIR__, 2).'/utility-os/versions.env', $this->dir.'/versions.env');
        // git ls-remote answers from <repo>.refs: --refs gives the tags without peeled lines, HEAD the head
        $this->stub('git', implode("\n", [
            '[ "$1" = ls-remote ] || exit 2',
            'case "$*" in',
            '    *gitlab.com/buildroot*) f=buildroot ;; *raspberrypi/linux*) f=linux ;; *raspberrypi/firmware*) f=firmware ;;',
            '    *raspberrypi/utils*) f=utils ;; *raspberrypi/usbboot*) f=usbboot ;; *) exit 2 ;;',
            'esac',
            '[ -f "$FAKE/$f.refs" ] || exit 128',
            'case "$*" in',
            '    *HEAD*) grep "	HEAD$" "$FAKE/$f.refs" ;;',
            '    *--refs*) grep "	refs/tags/" "$FAKE/$f.refs" | grep -v "\^{}$" ;;',
            '    *) grep "	refs/tags/" "$FAKE/$f.refs" ;;',
            'esac',
        ]));
        // curl -fsSL [--retry N] [-o file] URL serves www/<host and path>; a missing file is a 404 (exit 22)
        $this->stub('curl', implode("\n", [
            'out=; url=',
            'while [ $# -gt 0 ]; do case "$1" in -o) out=$2; shift ;; --retry) shift ;; -*) ;; *) url=$1 ;; esac; shift; done',
            'f="$FAKE/www/${url#https://}"',
            '[ -f "$f" ] || exit 22',
            'if [ -n "$out" ]; then cp "$f" "$out"; else cat "$f"; fi',
        ]));
        $this->world();
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    protected function versions(): array
    {
        preg_match_all('/^(UOS_[A-Z0-9_]+)=(.*)$/m', file_get_contents($this->dir.'/versions.env'), $m);
        return array_combine($m[1], $m[2]);
    }

    protected function serve(string $path, string $content): void
    {
        @mkdir(dirname($this->dir.'/www/'.$path), 0755, true);
        file_put_contents($this->dir.'/www/'.$path, $content);
    }

    /** the remote state: the pins of versions.env are the newest; $moved moves some forward */
    protected function world(array $moved = []): void
    {
        $v = $this->versions();
        $refs = [
            'buildroot' => ["1111\trefs/tags/2025.02-rc1", "2222\trefs/tags/{$v['UOS_BUILDROOT_VERSION']}", "3333\trefs/tags/2024.02.12"],
            'linux' => ["{$v['UOS_LINUX_COMMIT']}\trefs/tags/{$v['UOS_LINUX_TAG']}", "4444\trefs/tags/stable_20250101", "5555\trefs/tags/rpi-6.6.y-old"],
            'firmware' => ["6666\trefs/tags/{$v['UOS_FIRMWARE_TAG']}", "7777\trefs/tags/1.20250101", "8888\trefs/tags/stable"],
            'utils' => ["{$v['UOS_RPI_UTILS_COMMIT']}\tHEAD"],
            'usbboot' => ["9999\trefs/tags/{$v['UOS_USBBOOT_TAG']}", "aaaa\trefs/tags/windows-v1.1", "bbbb\trefs/tags/20250101-000000"],
        ];
        foreach ($moved as $repo => $lines)
            $refs[$repo] = array_merge($refs[$repo], $lines);
        foreach ($refs as $repo => $lines)
            file_put_contents($this->dir.'/'.$repo.'.refs', implode("\n", $lines)."\n");
    }

    /** runs update.sh; [exit code, output] */
    protected function update(string ...$args): array
    {
        $env = 'FAKE='.escapeshellarg($this->dir).' PATH='.escapeshellarg($this->dir.'/bin:'.getenv('PATH'))
            .' UOS_VERSIONS_ENV='.escapeshellarg($this->dir.'/versions.env').' UOS_TODAY=2026-10-20';
        exec($env.' sh '.escapeshellarg(dirname(__DIR__, 2).'/tools/utility-os/update.sh').' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function test_check_says_nothing_new_when_every_pin_is_the_newest()
    {
        [$code, $out] = $this->update('--check');

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('kernel: stable_20260911 (newest)', $out);
        $this->assertStringNotContainsString('->', $out);
    }

    public function test_check_names_what_moved_and_changes_nothing()
    {
        $before = file_get_contents($this->dir.'/versions.env');
        $this->world(['linux' => [self::KERNEL_NEW."\trefs/tags/stable_20261009"], 'utils' => []]);
        file_put_contents($this->dir.'/utils.refs', self::UTILS_NEW."\tHEAD\n");

        [$code, $out] = $this->update('--check');

        $this->assertSame(10, $code, $out);
        $this->assertStringContainsString('kernel: stable_20260911 -> stable_20261009', $out);
        $this->assertStringContainsString('utils: b57c48e97f7f43d95d86334798650cfda1ebc8a1 -> '.self::UTILS_NEW, $out);
        $this->assertStringContainsString('firmware: 1.20260915 (newest)', $out);
        $this->assertSame($before, file_get_contents($this->dir.'/versions.env'));
    }

    public function test_check_fails_without_an_answer()
    {
        unlink($this->dir.'/linux.refs');

        [$code, $out] = $this->update('--check');

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('update.sh: could not find out the newest kernel', $out);
    }

    public function test_buildroot_stays_in_its_lts_branch_and_names_the_next_one()
    {
        $this->world(['buildroot' => ["cccc\trefs/tags/2025.02.19", "dddd\trefs/tags/2026.02", "eeee\trefs/tags/2026.02.3", "ffff\trefs/tags/2025.02.19-rc1"]]);

        [$code, $out] = $this->update('--check');

        $this->assertSame(10, $code, $out);
        $this->assertStringContainsString('buildroot: 2025.02.18 -> 2025.02.19', $out);
        $this->assertStringContainsString('note: buildroot 2026.02 is a newer LTS', $out);
    }

    public function test_update_rewrites_the_pins_and_raises_the_version()
    {
        $this->world(['linux' => [self::KERNEL_NEW."\trefs/tags/stable_20261009"]]);
        $this->serve('raw.githubusercontent.com/raspberrypi/linux/'.self::KERNEL_NEW.'/Makefile',
            "# SPDX-License-Identifier: GPL-2.0\nVERSION = 6\nPATCHLEVEL = 18\nSUBLEVEL = 60\nEXTRAVERSION =\n");
        $this->serve('github.com/raspberrypi/linux/archive/'.self::KERNEL_NEW.'/linux-'.self::KERNEL_NEW.'.tar.gz', 'the new kernel');
        $before = $this->versions();
        $header = substr(file_get_contents($this->dir.'/versions.env'), 0, strpos(file_get_contents($this->dir.'/versions.env'), 'UOS_'));

        [$code, $out] = $this->update();

        $this->assertSame(0, $code, $out);
        $after = $this->versions();
        $this->assertSame('stable_20261009', $after['UOS_LINUX_TAG']);
        $this->assertSame(self::KERNEL_NEW, $after['UOS_LINUX_COMMIT']);
        $this->assertSame('6.18.60', $after['UOS_LINUX_VERSION']);
        $this->assertSame(hash('sha256', 'the new kernel'), $after['UOS_LINUX_SHA256']);
        $this->assertSame('2026.10.1', $after['UOS_VERSION'], 'a second version in October');
        foreach (['UOS_BUILDROOT_SHA256', 'UOS_START4_SHA256', 'UOS_RPI_UTILS_SHA256', 'UOS_BOOTFILES_SHA256'] as $key)
            $this->assertSame($before[$key], $after[$key], $key.' did not move');
        $this->assertStringStartsWith($header, file_get_contents($this->dir.'/versions.env'), 'the header comment stays');
        $this->assertStringContainsString('kernel: stable_20260911 -> stable_20261009', $out);
    }

    public static function months(): array
    {
        return [
            'the first version of a month' => ['2026.09', '2026.10'],
            'a second one' => ['2026.10', '2026.10.1'],
            'a third one' => ['2026.10.1', '2026.10.2'],
        ];
    }

    #[DataProvider('months')]
    public function test_the_version_of_the_os_follows_the_month(string $was, string $becomes)
    {
        file_put_contents($this->dir.'/versions.env', preg_replace('/^UOS_VERSION=.*$/m', 'UOS_VERSION='.$was, file_get_contents($this->dir.'/versions.env')));
        file_put_contents($this->dir.'/utils.refs', self::UTILS_NEW."\tHEAD\n");
        $this->serve('github.com/raspberrypi/utils/archive/'.self::UTILS_NEW.'/cmp-rpi-utils-'.self::UTILS_NEW.'.tar.gz', 'new utils');

        [$code, $out] = $this->update();

        $this->assertSame(0, $code, $out);
        $this->assertSame($becomes, $this->versions()['UOS_VERSION']);
        $this->assertSame(hash('sha256', 'new utils'), $this->versions()['UOS_RPI_UTILS_SHA256']);
    }

    public function test_a_failed_download_leaves_versions_env_alone()
    {
        // usbboot moved, but its bootfiles.bin cannot be downloaded
        $this->world(['usbboot' => ["cdcd\trefs/tags/20261101-120000"]]);
        $this->serve('raw.githubusercontent.com/raspberrypi/usbboot/20261101-120000/LICENSE', 'licence');
        $before = file_get_contents($this->dir.'/versions.env');

        [$code, $out] = $this->update();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('update.sh: cannot download https://raw.githubusercontent.com/raspberrypi/usbboot/20261101-120000/firmware/bootfiles.bin', $out);
        $this->assertSame($before, file_get_contents($this->dir.'/versions.env'));
        $this->assertFileDoesNotExist($this->dir.'/versions.env.new');
    }

    public function test_a_buildroot_that_does_not_match_its_signature_file_is_refused()
    {
        $this->world(['buildroot' => ["cccc\trefs/tags/2025.02.19"]]);
        $this->serve('buildroot.org/downloads/buildroot-2025.02.19.tar.gz', 'buildroot');
        $this->serve('buildroot.org/downloads/buildroot-2025.02.19.tar.gz.sign', 'SHA256: '.str_repeat('0', 64)."  buildroot-2025.02.19.tar.gz\n");
        $before = file_get_contents($this->dir.'/versions.env');

        [$code, $out] = $this->update();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('update.sh: buildroot-2025.02.19.tar.gz does not match its .sign', $out);
        $this->assertSame($before, file_get_contents($this->dir.'/versions.env'));
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsUpdateTest'`
Expected: `phpunit exit 1`: sh cannot open `tools/utility-os/update.sh`.

- [x] **Step 3: Write `update.sh`**

Create `tools/utility-os/update.sh`:
```sh
#!/bin/sh
# Raise the pins of the utility OS (utility-os/versions.env, docs/utility-os.md) to the newest versions:
#   buildroot  the newest point release of the LTS branch in versions.env; moving to the next LTS is a step by hand
#   kernel     the newest stable_* tag of raspberrypi/linux: its commit, its Linux version, GitHub's archive of it
#   firmware   the newest release tag of raspberrypi/firmware (1.YYYYMMDD): start4.elf, fixup4.dat, LICENCE.broadcom
#   utils      the head of raspberrypi/utils (it has no tags)
#   usbboot    the newest YYYYMMDD-hhmmss tag of raspberrypi/usbboot: firmware/bootfiles.bin, LICENSE
#   update.sh            download what moved, hash it, rewrite versions.env, raise UOS_VERSION
#   update.sh --check    only tell: exit 0 nothing new, 10 something new, 1 could not find out
# versions.env is rewritten in one mv, only when every download and hash succeeded.
# UOS_VERSIONS_ENV (the file) and UOS_TODAY (YYYY-MM-DD) are for tests/Unit/UtilityOsUpdateTest.php.
set -eu
REPO=$(cd "$(dirname "$0")/../.." && pwd)
ENV=${UOS_VERSIONS_ENV:-$REPO/utility-os/versions.env}
TODAY=${UOS_TODAY:-$(date -u +%Y-%m-%d)}
MODE=${1:-update}
. "$ENV"

fail() { echo "update.sh: $*" >&2; exit 1; }
BUILDROOT=https://gitlab.com/buildroot.org/buildroot.git
LINUX=https://github.com/raspberrypi/linux.git
FIRMWARE=https://github.com/raspberrypi/firmware.git
UTILS=https://github.com/raspberrypi/utils.git
USBBOOT=https://github.com/raspberrypi/usbboot.git

# the tag names of a repository (peeled lines left out)
tags() { git ls-remote --tags --refs "$1" "$2" | sed 's#.*refs/tags/##'; }
# the commit a tag names (an annotated tag's ^{} line wins)
commit_of() { git ls-remote --tags "$1" | awk -v t="refs/tags/$2" '$2 == t { c = $1 } $2 == t "^{}" { p = $1 } END { print p ? p : c }'; }
# the next version of the OS: YYYY.MM, or YYYY.MM.N for a second one in a month
next_version() {
    month=$(printf '%s' "$TODAY" | cut -c1-7 | tr - .)
    case "$1" in
        "$month") echo "$month.1" ;;
        "$month".*) echo "$month.$(( ${1##*.} + 1 ))" ;;
        *) echo "$month" ;;
    esac
}

branch=$(printf '%s' "$UOS_BUILDROOT_VERSION" | cut -d. -f1-2)
branch_re=$(printf '%s' "$branch" | sed 's/\./\\./g')
br=$(tags "$BUILDROOT" "refs/tags/$branch*" | grep -E "^$branch_re(\.[0-9]+)?\$" | sort -V | tail -n 1) || true
[ -n "$br" ] || fail "could not find out the newest buildroot $branch.x"
lts=$(tags "$BUILDROOT" 'refs/tags/20*' | grep -E '^[0-9]{4}\.02$' | sort -V | tail -n 1) || true
kernel=$(tags "$LINUX" 'refs/tags/stable_*' | grep -E '^stable_[0-9]{8}$' | sort | tail -n 1) || true
[ -n "$kernel" ] || fail "could not find out the newest kernel (stable_* of raspberrypi/linux)"
firmware=$(tags "$FIRMWARE" 'refs/tags/1.*' | grep -E '^1\.[0-9]{8}$' | sort | tail -n 1) || true
[ -n "$firmware" ] || fail "could not find out the newest firmware (raspberrypi/firmware)"
utils=$(git ls-remote "$UTILS" HEAD | awk '{ print $1; exit }') || true
[ -n "$utils" ] || fail "could not find out the head of raspberrypi/utils"
usbboot=$(tags "$USBBOOT" 'refs/tags/2*' | grep -E '^[0-9]{8}-[0-9]{6}$' | sort | tail -n 1) || true
[ -n "$usbboot" ] || fail "could not find out the newest usbboot"

moved=0
report() {
    if [ "$2" = "$3" ]; then echo "$1: $2 (newest)"; else echo "$1: $2 -> $3"; moved=1; fi
}
report buildroot "$UOS_BUILDROOT_VERSION" "$br"
report kernel "$UOS_LINUX_TAG" "$kernel"
report firmware "$UOS_FIRMWARE_TAG" "$firmware"
report utils "$UOS_RPI_UTILS_COMMIT" "$utils"
report usbboot "$UOS_USBBOOT_TAG" "$usbboot"
if [ -n "$lts" ] && [ "$lts" != "$branch" ] && [ "$(printf '%s\n%s\n' "$branch" "$lts" | sort -V | tail -n 1)" = "$lts" ]; then
    echo "note: buildroot $lts is a newer LTS; moving to it is a step by hand (docs/utility-os.md)"
fi

if [ "$MODE" = --check ]; then
    [ "$moved" = 1 ] && exit 10
    exit 0
fi
[ "$moved" = 1 ] || { echo "update.sh: nothing new"; exit 0; }

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
# the SHA256 of what a URL serves
fetch() {
    curl -fsSL --retry 3 -o "$TMP/file" "$1" || fail "cannot download $1"
    sha256sum "$TMP/file" | cut -d' ' -f1
}

V_BR=$UOS_BUILDROOT_VERSION; S_BR=$UOS_BUILDROOT_SHA256
if [ "$br" != "$UOS_BUILDROOT_VERSION" ]; then
    V_BR=$br
    S_BR=$(fetch "https://buildroot.org/downloads/buildroot-$br.tar.gz")
    sign=$(curl -fsSL --retry 3 "https://buildroot.org/downloads/buildroot-$br.tar.gz.sign") || fail "cannot download buildroot-$br.tar.gz.sign"
    printf '%s\n' "$sign" | grep -q "^SHA256: $S_BR  buildroot-$br.tar.gz\$" || fail "buildroot-$br.tar.gz does not match its .sign"
fi
T_LX=$UOS_LINUX_TAG; C_LX=$UOS_LINUX_COMMIT; V_LX=$UOS_LINUX_VERSION; S_LX=$UOS_LINUX_SHA256
if [ "$kernel" != "$UOS_LINUX_TAG" ]; then
    T_LX=$kernel
    C_LX=$(commit_of "$LINUX" "$kernel")
    [ -n "$C_LX" ] || fail "no commit for $kernel"
    V_LX=$(curl -fsSL --retry 3 "https://raw.githubusercontent.com/raspberrypi/linux/$C_LX/Makefile" \
        | awk '/^VERSION =/ { v = $3 } /^PATCHLEVEL =/ { p = $3 } /^SUBLEVEL =/ { s = $3 } END { if (v != "") print v "." p "." s }') || true
    [ -n "$V_LX" ] || fail "no Linux version in the Makefile of $C_LX"
    S_LX=$(fetch "https://github.com/raspberrypi/linux/archive/$C_LX/linux-$C_LX.tar.gz")
fi
T_FW=$UOS_FIRMWARE_TAG; S_START4=$UOS_START4_SHA256; S_FIXUP4=$UOS_FIXUP4_SHA256; S_FWL=$UOS_FIRMWARE_LICENCE_SHA256
if [ "$firmware" != "$UOS_FIRMWARE_TAG" ]; then
    T_FW=$firmware
    S_START4=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/start4.elf")
    S_FIXUP4=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/fixup4.dat")
    S_FWL=$(fetch "https://raw.githubusercontent.com/raspberrypi/firmware/$firmware/boot/LICENCE.broadcom")
fi
C_UT=$UOS_RPI_UTILS_COMMIT; S_UT=$UOS_RPI_UTILS_SHA256
if [ "$utils" != "$UOS_RPI_UTILS_COMMIT" ]; then
    C_UT=$utils
    S_UT=$(fetch "https://github.com/raspberrypi/utils/archive/$utils/cmp-rpi-utils-$utils.tar.gz")
fi
T_UB=$UOS_USBBOOT_TAG; S_BF=$UOS_BOOTFILES_SHA256; S_UBL=$UOS_USBBOOT_LICENSE_SHA256
if [ "$usbboot" != "$UOS_USBBOOT_TAG" ]; then
    T_UB=$usbboot
    S_BF=$(fetch "https://raw.githubusercontent.com/raspberrypi/usbboot/$usbboot/firmware/bootfiles.bin")
    S_UBL=$(fetch "https://raw.githubusercontent.com/raspberrypi/usbboot/$usbboot/LICENSE")
fi
V_OS=$(next_version "$UOS_VERSION")

{
    sed -n '/^#/p' "$ENV"
    cat <<EOF
UOS_VERSION=$V_OS
UOS_BUILDROOT_VERSION=$V_BR
UOS_BUILDROOT_SHA256=$S_BR
UOS_LINUX_TAG=$T_LX
UOS_LINUX_COMMIT=$C_LX
UOS_LINUX_VERSION=$V_LX
UOS_LINUX_SHA256=$S_LX
UOS_FIRMWARE_TAG=$T_FW
UOS_START4_SHA256=$S_START4
UOS_FIXUP4_SHA256=$S_FIXUP4
UOS_FIRMWARE_LICENCE_SHA256=$S_FWL
UOS_RPI_UTILS_COMMIT=$C_UT
UOS_RPI_UTILS_SHA256=$S_UT
UOS_USBBOOT_TAG=$T_UB
UOS_BOOTFILES_SHA256=$S_BF
UOS_USBBOOT_LICENSE_SHA256=$S_UBL
EOF
} > "$ENV.new"
mv "$ENV.new" "$ENV"
echo "update.sh: versions.env rewritten, the OS is now $V_OS: build.sh, then test.sh"
```
`fetch` runs in a command substitution: its `fail` prints the message and ends that subshell with 1, and `set -e` then ends the script at the assignment. So `versions.env.new` never appears.

- [x] **Step 4: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsUpdateTest'`
Expected: `phpunit exit 0`, `OK (10 tests, …)`.

- [x] **Step 5: Once against the real world, read-only**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
cd /root/dev && sh tools/utility-os/update.sh --check; echo "check exit $?"
EOF
```
Expected: five lines, each `(newest)` or a `->` to a newer version, then `check exit 0` or `check exit 10`. Write the output into the ledger. Do not run the update itself here: adopting new versions is the monthly PR's job.

- [x] **Step 6: Commit**

Write `$SP/commit-270-t9.txt`:
```
update.sh: the newest versions of the utility OS into versions.env

buildroot within its LTS branch, the newest stable_* kernel, firmware
and usbboot tags, the head of raspberrypi/utils; --check only tells
(exit 10 when something moved). What moved is downloaded and hashed,
a new buildroot checked against its .sign, and versions.env rewritten
in one mv with the OS version raised.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add tools/utility-os/update.sh tests/Unit/UtilityOsUpdateTest.php && git update-index --chmod=+x tools/utility-os/update.sh && git commit -q -F "$SP/commit-270-t9.txt"`

---

### Task 10: CI — on demand, on pull requests, and a monthly pull request with the newest versions

**Files:**
- Create: `.github/workflows/utility-os.yml`
- Create: `tests/Unit/UtilityOsWorkflowTest.php`

**Interfaces:**
- Consumes: `update.sh --check` exit codes 0/10/1, `update.sh`, `build.sh`, `test.sh`, the env `UOS_WORK`, `UOS_CACHE`.
- Produces: the workflow `utility-os`. Artifacts `utility-os-<run id>` (`scriptexecute64/`, the messages fixture) and `legal-info-<run id>`. The monthly PR `Utility OS <version>` from branch `utility-os/<version>` to `stage2`.

Facts (docs.github.com, 2026-10-16): the standard runner has 4 CPUs, 16 GB RAM and a 6-hour job limit. Scheduled runs use the workflow file of the default branch. Workflows in a fork run only after they are enabled in its Actions tab. A PR created with `GITHUB_TOKEN` starts its own `pull_request` run only after someone approves it. The cache is 10 GB per repository, and entries unused for 7 days go, so a monthly run usually starts cold.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/UtilityOsWorkflowTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .github/workflows/utility-os.yml (2.7.0): the triggers, the permissions, and what the monthly pull request carries */
class UtilityOsWorkflowTest extends TestCase
{
    protected function workflow(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/.github/workflows/utility-os.yml');
    }

    public function test_it_runs_on_demand_monthly_and_on_pull_requests_that_change_the_os()
    {
        $w = $this->workflow();
        foreach (["  workflow_dispatch:\n", "    - cron: '17 3 1 * *'\n", "      - 'utility-os/**'\n", "      - 'tools/utility-os/**'\n"] as $line)
            $this->assertStringContainsString($line, $w);
        $this->assertStringContainsString("permissions:\n  contents: write\n  pull-requests: write\n", $w, 'nothing else: listing any permission sets the rest to none');
        $this->assertStringContainsString('runs-on: ubuntu-24.04', $w, 'x86_64: the Bootlin toolchain is built for it');
        $this->assertStringContainsString('timeout-minutes: 300', $w);
    }

    public function test_the_monthly_run_builds_only_when_something_moved_and_proposes_it_to_stage2()
    {
        $w = $this->workflow();
        $this->assertStringContainsString('sh tools/utility-os/update.sh --check', $w);
        $this->assertStringContainsString("ref: \${{ github.event_name == 'schedule' && 'stage2' || github.ref }}", $w);
        foreach (['sh tools/utility-os/update.sh |', 'sh tools/utility-os/build.sh', 'sh tools/utility-os/test.sh'] as $step)
            $this->assertStringContainsString($step, $w);
        $this->assertStringContainsString('git add utility-os/versions.env scriptexecute64 tests/fixtures/utility-os/messages.txt', $w);
        $this->assertStringContainsString('gh pr create --base stage2', $w);
        $this->assertStringContainsString('GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $w);
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsWorkflowTest'`
Expected: `phpunit exit 1` or `2` (no workflow file).

- [x] **Step 3: The workflow**

Create `.github/workflows/utility-os.yml`:
```yaml
# The utility OS (docs/utility-os.md): built and checked on demand and on every pull request that changes it; once a month
# with the newest versions, which then come as a pull request to stage2 (adopted only after a live check on the bench).
name: utility-os

on:
  workflow_dispatch:
  schedule:
    - cron: '17 3 1 * *'
  pull_request:
    paths:
      - 'utility-os/**'
      - 'tools/utility-os/**'

permissions:
  contents: write
  pull-requests: write

jobs:
  build:
    runs-on: ubuntu-24.04
    timeout-minutes: 300
    env:
      UOS_WORK: ${{ runner.temp }}/uos-work
      UOS_CACHE: ${{ runner.temp }}/uos-cache
    steps:
      - uses: actions/checkout@v4
        with:
          ref: ${{ github.event_name == 'schedule' && 'stage2' || github.ref }}

      - name: Anything new
        id: check
        if: github.event_name == 'schedule'
        run: |
          set -o pipefail
          set +e
          sh tools/utility-os/update.sh --check | tee "$RUNNER_TEMP/check.txt"
          rc=$?
          set -e
          if [ "$rc" = 10 ]; then echo "new=yes" >> "$GITHUB_OUTPUT"; elif [ "$rc" = 0 ]; then echo "new=no" >> "$GITHUB_OUTPUT"; else exit 1; fi

      - name: Host packages and disk space
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        run: |
          sudo rm -rf /usr/share/dotnet /usr/local/lib/android /opt/ghc /opt/hostedtoolcache/CodeQL
          sudo apt-get update
          sudo apt-get install -y --no-install-recommends build-essential bc cpio rsync unzip file wget git python3 perl patch xz-utils bzip2 qemu-system-arm
          df -h /

      - uses: actions/cache@v4
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        with:
          path: ${{ runner.temp }}/uos-cache
          key: uos-${{ github.run_id }}
          restore-keys: uos-

      - name: The newest versions
        if: github.event_name == 'schedule' && steps.check.outputs.new == 'yes'
        run: sh tools/utility-os/update.sh | tee "$RUNNER_TEMP/update.txt"

      - name: Build
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        run: sh tools/utility-os/build.sh

      - name: Check
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        run: |
          set -o pipefail
          sh tools/utility-os/test.sh | tee "$RUNNER_TEMP/test.txt"

      - name: The built OS
        if: always() && (github.event_name != 'schedule' || steps.check.outputs.new == 'yes')
        uses: actions/upload-artifact@v4
        with:
          name: utility-os-${{ github.run_id }}
          path: |
            scriptexecute64
            tests/fixtures/utility-os/messages.txt
          retention-days: 30

      - name: legal-info archive
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        run: tar czf "$RUNNER_TEMP/legal-info.tar.gz" -C "$UOS_WORK/output" legal-info

      - name: The sources and licences
        if: github.event_name != 'schedule' || steps.check.outputs.new == 'yes'
        uses: actions/upload-artifact@v4
        with:
          name: legal-info-${{ github.run_id }}
          path: ${{ runner.temp }}/legal-info.tar.gz
          retention-days: 30

      - name: Pull request to stage2
        if: github.event_name == 'schedule' && steps.check.outputs.new == 'yes'
        env:
          GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
        run: |
          . utility-os/versions.env
          branch="utility-os/$UOS_VERSION"
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git switch -c "$branch"
          git add utility-os/versions.env scriptexecute64 tests/fixtures/utility-os/messages.txt
          git commit -m "Utility OS $UOS_VERSION: the newest versions"
          git push origin "$branch"
          {
            echo "The newest versions for the utility OS, built and checked by CI ($GITHUB_SERVER_URL/$GITHUB_REPOSITORY/actions/runs/$GITHUB_RUN_ID, artifacts there)."
            echo
            echo '```'
            cat "$RUNNER_TEMP/update.txt"
            echo '```'
            echo
            echo '```'
            cat "$RUNNER_TEMP/test.txt"
            echo '```'
            echo
            echo "Adopt it only after the live check on the provisioner (docs/utility-os.md, «Выпуск новой версии ОС»)."
          } > "$RUNNER_TEMP/pr.md"
          gh pr create --base stage2 --head "$branch" --title "Utility OS $UOS_VERSION" --body-file "$RUNNER_TEMP/pr.md"
```

- [x] **Step 4: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsWorkflowTest'`
Expected: `phpunit exit 0`.

- [x] **Step 5: The YAML parses**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
python3 -c "import yaml" 2>/dev/null || { export DEBIAN_FRONTEND=noninteractive; apt-get install -y -qq python3-yaml > /dev/null; }
python3 -c "import sys, yaml; d = yaml.safe_load(open('/root/dev/.github/workflows/utility-os.yml')); print(sorted(d[True].keys()), len(d['jobs']['build']['steps']))"
EOF
```
Expected: `['pull_request', 'schedule', 'workflow_dispatch'] 11`. (PyYAML reads the key `on` as `True`.)

- [x] **Step 6: Commit**

Write `$SP/commit-270-t10.txt`:
```
CI: the utility OS on demand, on pull requests, and monthly

The monthly run asks update.sh --check; when something moved it
updates, builds, checks under QEMU and opens a pull request to stage2
with the versions table and the checks. Artifacts: the built directory
and the legal-info archive.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add .github/workflows/utility-os.yml tests/Unit/UtilityOsWorkflowTest.php && git commit -q -F "$SP/commit-270-t10.txt"`

---

### Task 11: Documentation

**Files:**
- Create: `docs/utility-os.md`
- Modify: `CLAUDE.md`, `README.md`, `ROADMAP.md`, `debian/changelog` (dev entry text)
- Test: `tests/Unit/UtilityOsDocsTest.php` (create)

**Interfaces:**
- Consumes: everything above (names of scripts, files, keys, texts).
- Produces: —

- [x] **Step 1: Write the failing test**

Create `tests/Unit/UtilityOsDocsTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The utility OS is documented where people look (2.7.0): docs/utility-os.md names every script and the switch, and
    CLAUDE.md no longer says the boot files are ready-made binaries without sources */
class UtilityOsDocsTest extends TestCase
{
    protected function file(string $path): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/'.$path);
    }

    public function test_the_guide_names_the_scripts_the_switch_and_the_sources()
    {
        $doc = $this->file('docs/utility-os.md');
        foreach (['tools/utility-os/build.sh', 'tools/utility-os/update.sh', 'tools/utility-os/test.sh', 'utility-os/versions.env',
                  '.github/workflows/utility-os.yml', 'etc/boot', 'sudo -u www-data ln -sfn ../scriptexecute /var/lib/cmprovision/etc/boot',
                  'legal-info', 'bootfiles.bin', 'MANIFEST', 'Settings → Utility OS'] as $needle)
            $this->assertStringContainsString($needle, $doc, $needle);
    }

    public function test_claude_md_describes_both_systems()
    {
        $claude = $this->file('CLAUDE.md');
        $this->assertStringNotContainsString('исходников в репозитории нет', $claude);
        foreach (['## Утилитарная ОС', 'scriptexecute64/', 'utility-os/versions.env', 'tools/boot-link', 'cms.utility_os',
                  'utility-os/board/cmprovision/cmdline.txt'] as $needle)
            $this->assertStringContainsString($needle, $claude, $needle);
    }

    public function test_readme_and_roadmap_know_the_release()
    {
        $this->assertStringContainsString('Utility OS', $this->file('README.md'));
        $this->assertStringContainsString('2.7.0', $this->file('ROADMAP.md'));
    }
}
```

- [x] **Step 2: Run it to see it fail**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsDocsTest'`
Expected: `phpunit exit 1` (no `docs/utility-os.md`, CLAUDE.md still has «исходников в репозитории нет»).

- [x] **Step 3: The guide**

Create `docs/utility-os.md` (Russian, like `docs/leds.md`):
```markdown
# Утилитарная ОС

Модуль прошивается из маленькой ОС, которую грузит по сети (TFTP от dnsmasq) или по USB (`rpiboot`). С 2.7.0 их в
пакете две:

| Каталог | Что это |
|---|---|
| `scriptexecute64/` | наша: buildroot LTS, 64-битное ядро Raspberry Pi, свежие BusyBox, curl, e2fsprogs, flashrom. Собирается из `utility-os/` |
| `scriptexecute/` | старая: готовые файлы raspberrypi/scriptexecutor 2022 года, 32 бита. Уйдёт отдельным выпуском |

Какую получают модули, задаёт ссылка `/var/lib/cmprovision/etc/boot` (`../scriptexecute` или `../scriptexecute64`):
по ней идут `tftp-root` у dnsmasq и `rpiboot -d`. Переключатель — Settings → Utility OS. Он заменяет ссылку сразу, без
перезапуска служб, и пишет в журнал Laravel, кто переключил. Переключайте, когда ни один модуль не грузится: модуль,
который грузится в эту секунду, может взять файлы обеих.

Вручную, если веб-интерфейс недоступен:

    sudo -u www-data ln -sfn ../scriptexecute /var/lib/cmprovision/etc/boot      # старая
    sudo -u www-data ln -sfn ../scriptexecute64 /var/lib/cmprovision/etc/boot    # наша

Ссылку делает и чинит `tools/boot-link` (`/usr/lib/cmprovision/boot-link`). Его зовут `postinst`, `prerm` при откате
ниже 2.7 и `cmprovision-restore`, всегда от www-data. Откат пакета ниже 2.7 возвращает модули на старую ОС.

В обоих каталогах лежит `bootfiles.bin` из usbboot. С usbboot 20261002 `rpiboot` не несёт `bootcode4.bin` в себе, а с
28 сентября 2026 usbboot держит его только внутри `bootfiles.bin` (член `2711/bootcode4.bin` вместе с нужными ему
файлами). Найдя `bootfiles.bin` в каталоге `-d`, `rpiboot` берёт вторую ступень оттуда, а остальное — из каталога.

`MANIFEST` каждого каталога говорит, что это за ОС (версия, ядро, разрядность, дата сборки), и перечисляет файлы с SHA256.
Settings показывает его, а тесты сверяют с файлами.

## Устройство

- `utility-os/` — BR2_EXTERNAL-дерево `CMPROVISION`: `configs/cmprovision_defconfig`, `board/cmprovision/` (фрагменты
  конфигурации ядра и BusyBox, init `S99scriptexec`, `post-build.sh`, `post-image.sh`, шаблоны `config.txt` и
  `cmdline.txt`), `package/` (загрузочные файлы, `raspberrypi/utils`, `bootfiles.bin`), `legacy-commands.txt`.
- `utility-os/versions.env` — единственное место с версиями и SHA256.
- `tools/utility-os/build.sh` — сборка, `test.sh` — проверки, `update.sh` — подъём версий, `manifest.sh` — `MANIFEST`.
- `.github/workflows/utility-os.yml` — те же сборка и проверки в GitHub Actions.

Init повторяет старый: те же параметры старта в тех же форматах, плюс `uos=<версия>`. Сервер узнаёт модуль по серийному
номеру, поэтому форматы менять нельзя (тест `UtilityOsInitTest`).

## Сборка

Хост — Debian или Ubuntu x86_64: WSL-стенд или раннер `ubuntu-24.04`. Не в OneDrive и не на провижинере.

    UOS_WORK=$HOME/uos-work UOS_CACHE=$HOME/uos-cache sh tools/utility-os/build.sh

Скрипт проверяет программы хоста, скачивает buildroot из `versions.env` со сверкой SHA256 и собирает. Готовый каталог
кладёт в `scriptexecute64/`, исходники и лицензии — в `$UOS_WORK/output/legal-info`. Первая сборка идёт около часа,
дальше помогают кэш загрузок и ccache в `$UOS_CACHE`.

## Проверки

    sh tools/utility-os/test.sh

- ядро: обязательные опции встроены;
- состав: все команды из `legacy-commands.txt` есть, модулей ядра нет, нет служб входа по сети;
- QEMU (`qemu-system-aarch64 -M virt`) грузит тот же `kernel8.img` и `scriptexecute.img`, init получает скрипт от
  тестового сервера после ответа 502 и возвращает параметры старта, версии программ и их сообщения;
- размер и `MANIFEST`.

Сообщения программ ложатся в `tests/fixtures/utility-os/messages.txt`. PHPUnit (`UtilityOsMessagesTest`) сверяет их с
шаблонами, по которым сервер объясняет сбои. Специфику Pi (светодиоды, SPI и flashrom, `vcgencmd`, перемычки, eMMC)
проверяет только живая проверка.

## Обновление версий

    sh tools/utility-os/update.sh --check     # 0 — нового нет, 10 — есть, 1 — не удалось спросить
    sh tools/utility-os/update.sh             # переписывает versions.env и печатает «было → стало»

Новейшее берётся так: buildroot — в пределах LTS-ветки, ядро — последний тег `stable_*`, прошивка — последний релиз,
`raspberrypi/utils` — последний коммит, usbboot — последний тег. Переход на следующую LTS buildroot делается вручную.
Хэши строгие: файл, скачавшийся с другим SHA256, останавливает сборку.

Раз в месяц CI делает то же сам. Если есть новое, он собирает, проверяет и открывает PR в stage2 с таблицей версий и
размерами. Для этого в GitHub нужно включить Actions в форке и разрешить им создавать PR (Settings → Actions → General).
PR, открытый токеном `GITHUB_TOKEN`, запускает свою проверку только после одобрения («Approve workflows to run»): сборка
и проверки к этому времени уже прошли в прогоне по расписанию.
GitHub выключает расписание после 60 дней без коммитов: снова включает его кнопка «Enable workflow».

## Выпуск новой версии ОС

1. `update.sh` (или PR из CI), `build.sh`, `test.sh`.
2. Коммит `utility-os/versions.env`, `scriptexecute64/`, `tests/fixtures/utility-os/messages.txt`; PHPUnit целиком.
3. Пакет, установка на провижинер, живая проверка через переключатель: запись со сверкой, EEPROM, светодиоды, проверка ОС,
   докачка после перезапуска по PoE, время загрузки.
4. Выпуск cmprovision. Вместе с ним — GitHub Release `utility-os-<версия>` с архивом `legal-info`.

## Исходники и лицензии

В ОС — программы под GPL (ядро, BusyBox и другие). Исходники всех компонентов и тексты лицензий собирает
`make legal-info` (`$UOS_WORK/output/legal-info`). Архив публикуется GitHub Release `utility-os-<версия>`, а CI кладёт
его в артефакты каждого прогона.

## Разбор проблем

- Модуль с новой ОС не стартует или стартует не так: Settings → Utility OS → старая, модуль перезапустить. Журнал консоли
  модуля (экран, tty1) и отчёты на сервере покажут, на каком шаге он встал.
- Settings предупреждает, что dnsmasq не идёт по ссылке: в `etc/dnsmasq.conf` должна быть строка
  `tftp-root=/var/lib/cmprovision/etc/boot`, затем `systemctl restart cmprovision-dnsmasq`.
- Загрузка по USB не идёт: в каталоге, на который указывает `etc/boot`, должен лежать `bootfiles.bin`. Без него
  `rpiboot` на первом же CM4 пишет «Failed to open second stage bootloader» и завершается (служба поднимает его снова).
- Сборка встала на хэше: файл по закреплённой версии изменился у источника. `update.sh` пересчитает хэши.
- Проверка в QEMU не дошла до скрипта: в `$UOS_WORK/qemu.log` — консоль модуля в QEMU, в `$UOS_WORK/smoke/` — то, что
  получил тестовый сервер.
```

- [x] **Step 4: CLAUDE.md**

Edit these places in `CLAUDE.md`:

1. In «## Провижининг: сквозной поток», the first paragraph «Модуль загружает `scriptexecute` — готовые бинарники из `scriptexecute/` (ядро, initramfs `scriptexecute.img`, загрузочные файлы, оверлеи; исходников в репозитории нет):» becomes:
   «Модуль загружает утилитарную ОС: нашу (`scriptexecute64/`, собирается из `utility-os/`, раздел «Утилитарная ОС») или старую (`scriptexecute/`, готовые файлы raspberrypi/scriptexecutor 2022, 32 бита). Какую — задаёт ссылка `etc/boot`:»
2. In the «CM4 / Pi 4 — по Ethernet» bullet: «и отдаёт `scriptexecute/` по TFTP» → «и отдаёт по TFTP каталог, на который указывает `etc/boot` (`tftp-root=/var/lib/cmprovision/etc/boot`)».
3. In the «CM4 по USB» bullet: «получает `scriptexecute` от `rpiboot -l -d scriptexecute`» → «получает утилитарную ОС от `rpiboot -l -d /var/lib/cmprovision/etc/boot`; с usbboot 20261002 `rpiboot` берёт вторую ступень (`bootcode4.bin`) из `bootfiles.bin` этого каталога, он есть в обоих; служба поднимает `rpiboot` снова, если он завершился».
4. In step 2 of the flow: «стартовый скрипт утилитарной ОС (`S99scriptexec`, `set -e`) после ненулевого выхода скрипта просто останавливается» → «стартовый скрипт старой утилитарной ОС (`S99scriptexec`, `set -e`) после ненулевого выхода скрипта просто останавливается, у нашей init печатает код выхода на консоль и тоже больше ничего не делает».
5. In «Связи между файлами», the first bullet becomes: «Параметры запроса модуля задаются плейсхолдерами в `scriptexecute/cmdline.txt` и `utility-os/board/cmprovision/cmdline.txt` (их подставляет init утилитарной ОС; у нашей в конце ещё `&uos=<версия>`) и читаются в `ScriptExecuteController::startProvisoning()`. Меняйте все стороны вместе.»; the subnet bullet adds `utility-os/board/cmprovision/cmdline.txt` after `scriptexecute/cmdline.txt`.
6. In «Команды», the owners bullet: «`etc/` (dnsmasq.conf)» → «`etc/` (dnsmasq.conf и ссылка `etc/boot`)».
7. In «Тесты», after the `EepromTransparencyTest` sentence add: «Утилитарная ОС: ссылку и её помощник — `BootLinkTest` (настоящий `tools/boot-link` под `sh`), сервис и раздел Settings — `UtilityOsTest`, `UtilityOsSettingsTest` (трейт `Tests\Support\UtilityOsTree`), `uos=` и карточку — `UtilityOsModuleTest`, init новой ОС — `UtilityOsInitTest` (под `busybox sh` с подставными `curl`, `udhcpc`, `pinctrl`, `vcgencmd` и деревом `/proc`, `/sys` в каталоге теста), дерево buildroot — `UtilityOsTreeTest`, файлы каталогов и `MANIFEST` — `UtilityOsFilesTest`, сообщения программ новой ОС против шаблонов сервера — `UtilityOsMessagesTest`, `update.sh` — `UtilityOsUpdateTest` (подставные `git` и `curl`), workflow CI — `UtilityOsWorkflowTest`, документацию — `UtilityOsDocsTest`.»
8. Add a new section before «## Данные и хранилище»:
```markdown
## Утилитарная ОС

С 2.7.0 (спека `docs/superpowers/specs/2026-10-16-utility-os-design.md`, руководство `docs/utility-os.md`).

- Наша ОС — BR2_EXTERNAL-дерево `utility-os/` (имя `CMPROVISION`) на buildroot LTS 2025.02.x; версии и SHA256 всех
  компонентов — только в `utility-os/versions.env` (ключи `UOS_*`), `build.sh` подставляет их в конфигурацию и в файлы
  хэшей (`BR2_GLOBAL_PATCH_DIR` в рабочем каталоге), `update.sh` переписывает только этот файл. Ядро — raspberrypi/linux по
  тегу `stable_*` (git неглубоко, воспроизводимый архив buildroot), arm64 `bcm2711_defconfig` плюс
  `board/cmprovision/linux.fragment`; `CONFIG_MODULES` включён (без него Kconfig встроил бы каждое `=m`), модули
  собираются и убираются из образа в `post-build.sh`. Загрузочные файлы прошивки и `bootfiles.bin` usbboot качаются
  отдельными файлами по тегу.
- Собранный каталог `scriptexecute64/` и `tests/fixtures/utility-os/messages.txt` коммитятся (пакет собирается без сети),
  только когда новая версия ОС принята. Версия ОС — `ГГГГ.ММ` (`2026.10.1` — вторая за месяц).
- Init `board/cmprovision/rootfs-overlay/etc/init.d/S99scriptexec` (POSIX sh, без `set -e`) подставляет в шаблон
  `script=` из `cmdline.txt` те же значения в тех же форматах, что старый, плюс `uos=`; перемычки читает `pinctrl`
  (номера GPIO в sysfs с ядра 6.6 сдвинуты). Ответ 200 исполняет, 5xx и обрыв повторяет через 10 с, другой код печатает и
  останавливается; вернувшийся скрипт второй раз не запускает. Пути `/proc`, `/sys`, `/tmp` переопределяются
  переменными `UOS_PROC`, `UOS_SYS`, `UOS_TMP` (так его гоняет тест).
- Состав — надмножество `utility-os/legacy-commands.txt` (команды старой ОС без камеры и мультимедиа rpi-userland);
  консоль как у старой: ход на tty1, getty на tty2; служб входа по сети нет (`CONFIG_TELNETD` выключен, `test.sh`
  сверяет список `/etc/init.d/S*`).
- Ссылка `etc/boot` (www-data) → `../scriptexecute` или `../scriptexecute64`: её делает и чинит `tools/boot-link`
  (`/usr/lib/cmprovision/boot-link fix|legacy <app>`, от www-data из `postinst`, `prerm` при откате ниже 2.7 и
  `restore.sh`), а Settings → Utility OS (`UtilityOsSettings`, сервис `App\Services\UtilityOs`) заменяет атомарно
  (новая ссылка и `rename`) и только на каталог пакета с годным `MANIFEST`. Поставляемый conffile `etc/dnsmasq.conf`
  сохраняет `tftp-root=/var/lib/cmprovision/scriptexecute`: правится только живой файл. Модуль, начавший загрузку до
  переключения, может взять файлы обеих ОС.
- Старт хранит версию в `cms.utility_os` (`uos=`, только `[0-9A-Za-z.-]{1,32}`; пусто — старая ОС), карточка и строки
  журнала о старте и докачке называют её (`Cm::utilityOsLabel()`).
- `MANIFEST` (пишет `tools/utility-os/manifest.sh`): `version`, `bits`, `kernel`, `built` и у нашей версии компонентов,
  затем `file=<sha256> <size> <путь>` по каждому файлу.
- CI `.github/workflows/utility-os.yml`: кнопка, раз в месяц (`update.sh --check`, при новом — сборка, проверки и PR в
  stage2 токеном `GITHUB_TOKEN`) и PR, трогающий `utility-os/` или `tools/utility-os/`; артефакты — каталог и
  `legal-info`.
```

- [x] **Step 5: README and ROADMAP**

In `README.md`, before the heading `## The OS check after the first boot ##`, add:
```markdown
## Utility OS ##

Modules are provisioned from a small OS they boot over the network or over USB. Since 2.7.0 the package ships two: ours
(`scriptexecute64/`, built with buildroot from current versions, 64-bit) and the legacy one (`scriptexecute/`, 2022,
32-bit). Settings → Utility OS picks the one modules get; switching back is the same button. How the OS is built and
updated: [docs/utility-os.md](docs/utility-os.md).

```

In `ROADMAP.md`, section «### 3. Из upstream, дальше», the bullet «**Новая утилитарная ОС `scriptexecute`** (#73, #47, #26). …» becomes:
```markdown
- **Новая утилитарная ОС `scriptexecute`** (#73, #47, #26) — сделано в 2.7.0: своя ОС на buildroot LTS, 64 бита, свежие
  BusyBox, curl, e2fsprogs и flashrom, обе ОС в пакете с переключателем в Settings, `bootfiles.bin` для `rpiboot`.
  Дальше — CM5 (деревья bcm2712, RP1, сигнатура RPi5 у dnsmasq) и удаление старой ОС отдельными выпусками.
```

In `debian/changelog`, the dev entry's bullet becomes:
```
  * Our own utility OS, built with buildroot from current versions
    (64-bit kernel, BusyBox, curl, e2fsprogs, flashrom), next to the
    legacy one; Settings → Utility OS picks the one modules get.
  * rpiboot boots a CM4 over USB again: both utility OS directories
    carry usbboot's bootfiles.bin, since rpiboot 20261002 has no
    bootcode4.bin of its own; the service starts rpiboot again when it
    exits.
```

- [x] **Step 6: Run the test**

Run: `bash "$SP/wsl-bench/tests-ok.sh" 'UtilityOsDocsTest|LedDocsTest'`
Expected: `phpunit exit 0`.

- [x] **Step 7: Commit**

Write `$SP/commit-270-t11.txt`:
```
Docs: the utility OS, how it is built, checked, updated and switched

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```
Run: `git add docs/utility-os.md CLAUDE.md README.md ROADMAP.md debian/changelog tests/Unit/UtilityOsDocsTest.php && git commit -q -F "$SP/commit-270-t11.txt"`

---

### Task 12: The package on the bench: install, switch, downgrade, restore

**Files:** none changed unless a step fails.

**Interfaces:**
- Consumes: the whole branch.
- Produces: a ledger record of the rehearsal.

- [x] **Step 1: The whole suite**

Run: `bash "$SP/wsl-bench/suite-ok.sh"`
Expected: last line `php rc=0 js rc=0`.

- [x] **Step 2: Build 2.6.2 (the version to go back to) and the branch**

Run:
```bash
cd "$REPO"
git archive --prefix=./ v2.6.2 | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh /tmp/src-wt.tar
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/13-build-wt.sh
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
mkdir -p /root/keep && cp /root/own/last.deb /root/keep/cmprovision4_2.6.2_all.deb && dpkg-deb -f /root/keep/cmprovision4_2.6.2_all.deb Version
EOF
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | sed 's#^#./#' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh /tmp/src-wt.tar
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/13-build-wt.sh
```
Expected: `built 2.6.2: …`, `2.6.2`, then `built 2.7.0~dev1: … bytes` (about 15 MB larger than 2.6.2).

- [x] **Step 3: 2.6.2 installed, a snapshot of it, then the branch over it**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin DEBIAN_FRONTEND=noninteractive
A=/var/lib/cmprovision
show() {
    echo "  version $(dpkg-query -W -f='${Version}' cmprovision4)"
    echo "  link: $(readlink $A/etc/boot 2>&1) owner $(stat -c %U $A/etc/boot 2>&1)"
    echo "  tftp-root: $(grep '^tftp-root=' $A/etc/dnsmasq.conf)"
    echo "  rpiboot: $(grep '^ExecStart=' /lib/systemd/system/cmprovision-rpiboot.service)"
    echo "  helper: $(stat -c '%a %U' /usr/lib/cmprovision/boot-link 2>&1)"
    echo "  dirs: $(ls -d $A/scriptexecute* | tr '\n' ' ')"
    echo "  bootfiles: $(ls $A/scriptexecute*/bootfiles.bin 2>/dev/null | wc -l)"
}
apt-get install -y --allow-downgrades /root/keep/cmprovision4_2.6.2_all.deb > /root/uos-i-262.log 2>&1 || { tail -20 /root/uos-i-262.log; exit 1; }
echo "== 2.6.2"; show
cmprovision-backup > /root/uos-backup.log 2>&1 && ls -d /var/backups/cmprovision/*-2.6.2 | tail -1 > /root/uos-snapshot
echo "  snapshot: $(cat /root/uos-snapshot)"
apt-get install -y /root/own/last.deb > /root/uos-i-270.log 2>&1 || { tail -20 /root/uos-i-270.log; exit 1; }
echo "== 2.7.0~dev1 over 2.6.2"; show
EOF
```
Expected:
- 2.6.2: `link: readlink: … No such file or directory`, `tftp-root: tftp-root=/var/lib/cmprovision/scriptexecute`, `rpiboot: … -d /var/lib/cmprovision/scriptexecute`, `helper: … No such file`, one dir, `bootfiles: 0`; a snapshot directory named `…-2.6.2`.
- 2.7.0~dev1: `link: ../scriptexecute owner www-data`, `tftp-root: tftp-root=/var/lib/cmprovision/etc/boot`, `rpiboot: ExecStart=/usr/bin/rpiboot -l -d /var/lib/cmprovision/etc/boot`, `helper: 755 root`, both dirs, `bootfiles: 2`.

- [x] **Step 4: The switch as the web app does it, the downgrade, the upgrade again, a data-only restore of the 2.6.2 snapshot**

Run:
```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin DEBIAN_FRONTEND=noninteractive
A=/var/lib/cmprovision
tinker() { runuser -u www-data -- env HOME=/tmp php $A/artisan tinker --execute="$1" 2>&1 | tail -1; }
echo "current: $(tinker 'echo app(App\Services\UtilityOs::class)->current();')"
echo "systems: $(tinker 'echo implode(",", array_map([App\Services\UtilityOs::class, "label"], app(App\Services\UtilityOs::class)->systems()));')"
tinker 'app(App\Services\UtilityOs::class)->switchTo("scriptexecute64");' >/dev/null
echo "after the switch: $(readlink $A/etc/boot)"
apt-get install -y --allow-downgrades /root/keep/cmprovision4_2.6.2_all.deb > /root/uos-d-262.log 2>&1 || { tail -20 /root/uos-d-262.log; exit 1; }
echo "== down to $(dpkg-query -W -f='${Version}' cmprovision4): link $(readlink $A/etc/boot), $(grep '^tftp-root=' $A/etc/dnsmasq.conf), dirs $(ls -d $A/scriptexecute* | wc -l)"
apt-get install -y /root/own/last.deb > /root/uos-u-270.log 2>&1 || { tail -20 /root/uos-u-270.log; exit 1; }
echo "== up to $(dpkg-query -W -f='${Version}' cmprovision4): link $(readlink $A/etc/boot), $(grep '^tftp-root=' $A/etc/dnsmasq.conf)"
cmprovision-restore --data-only "$(cat /root/uos-snapshot)" > /root/uos-restore.log 2>&1 || { tail -20 /root/uos-restore.log; exit 1; }
echo "== data-only restore of the 2.6.2 snapshot: link $(readlink $A/etc/boot), $(grep '^tftp-root=' $A/etc/dnsmasq.conf)"
EOF
```
Expected:
- `current: scriptexecute`; `systems: 2026.10 (64-bit),legacy (32-bit)` (the version of the committed build); `after the switch: ../scriptexecute64`;
- `== down to 2.6.2: link ../scriptexecute, tftp-root=/var/lib/cmprovision/etc/boot, dirs 1`;
- `== up to 2.7.0~dev1: link ../scriptexecute, tftp-root=/var/lib/cmprovision/etc/boot`;
- `== data-only restore …: link ../scriptexecute, tftp-root=/var/lib/cmprovision/etc/boot` (the snapshot brought the old line, restore moved it back to the link).

- [x] **Step 5: Ledger**

Write the outputs of steps 2–4 into the ledger. No commit (nothing changed).

---

### Task 13: On the user's word only — the provisioner, the live check, the release

Stop here and ask the user. Nothing in this task runs without their explicit word, step by step: building on wlanpi-da2, installing there, the live check on sensor1, merging, tagging, pushing, the GitHub settings and the GitHub Release.

When the user says go, the order they set for releases:
1. The final review's findings, minors included, fixed on stage2.
2. Release commit: `debian/changelog` 2.7.0 (the dev entry's text, version `2.7.0`, today's date), ROADMAP «2.7.0 вышла …». Local `git merge --no-ff stage2` into main, local tag `v2.7.0`.
3. The tree of the tag to wlanpi-da2 as a tar checked by SHA256 on both sides (docs/deploy-to-provisioner.md), `dpkg-buildpackage -b -us -uc -d`, `dpkg -i`. Then check: the link, `tftp-root`, the rpiboot unit, Settings → Utility OS, Service status.
4. The live check on sensor1 under the new OS (switched in Settings), as the spec lists:
   - the module stays the same CMs row. The start parameters of both OSes match in nginx's access log on da2 (`serial`, `model`, `storagesize`, `mac`, `memorysize`, `cid`, `csd`, `bootmode`, `inversejumper`; `temp` close);
   - a write with verify;
   - EEPROM «identical», then «written» with a harmless setting (`DHCP_TIMEOUT=45000`) and back;
   - the LEDs in every mode, Identify and Stop. The user watches;
   - the OS check;
   - the resume after a PoE restart on a project with pieces;
   - the time from power-on to the script's start, next to the same measurement under the legacy OS.
   Then switch to the legacy OS and run once more. USB boot through rpiboot only if a CM4 that can boot over USB is at hand; else mark it unchecked, and the tty2 shell too (sensor1 has no screen or keyboard).
5. Push main, stage2 and the tag only after the install and the live check are fine. Then the GitHub Release `utility-os-<version>` with the `legal-info` archive. The user enables Actions in the fork and allows them to create PRs.
