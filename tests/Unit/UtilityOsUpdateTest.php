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
            'exit $(cat "$FAKE/$f.exit" 2>/dev/null || echo 0)',
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

    public function test_a_partial_git_answer_is_not_treated_as_success()
    {
        file_put_contents($this->dir.'/linux.exit', '128');

        [$code, $out] = $this->update('--check');

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('could not find out the newest kernel', $out);
    }

    public function test_the_utils_head_must_be_a_commit_hash()
    {
        file_put_contents($this->dir.'/utils.refs', "not-a-commit\tHEAD\n");

        [$code, $out] = $this->update('--check');

        $this->assertSame(1, $code, $out);
    }

    public function test_an_unknown_option_cannot_run_an_update()
    {
        file_put_contents($this->dir.'/utils.refs', self::UTILS_NEW."\tHEAD\n");
        $this->serve('github.com/raspberrypi/utils/archive/'.self::UTILS_NEW.'/cmp-rpi-utils-'.self::UTILS_NEW.'.tar.gz', 'new utils');
        $before = file_get_contents($this->dir.'/versions.env');

        [$code, $out] = $this->update('--chek');

        $this->assertSame(1, $code, $out);
        $this->assertSame($before, file_get_contents($this->dir.'/versions.env'));
    }

    public function test_an_incomplete_linux_version_keeps_the_pins_unchanged()
    {
        $this->world(['linux' => [self::KERNEL_NEW."\trefs/tags/stable_20261009"]]);
        $this->serve('raw.githubusercontent.com/raspberrypi/linux/'.self::KERNEL_NEW.'/Makefile', "VERSION = 6\n");
        $this->serve('github.com/raspberrypi/linux/archive/'.self::KERNEL_NEW.'/linux-'.self::KERNEL_NEW.'.tar.gz', 'kernel');
        $before = file_get_contents($this->dir.'/versions.env');

        [$code, $out] = $this->update();

        $this->assertSame(1, $code, $out);
        $this->assertSame($before, file_get_contents($this->dir.'/versions.env'));
    }

    public function test_buildroot_stays_in_its_lts_branch_and_names_the_next_one()
    {
        $this->world(['buildroot' => ["cccc\trefs/tags/2025.02.19", "dddd\trefs/tags/2027.02", "eeee\trefs/tags/2027.02.3", "ffff\trefs/tags/2025.02.19-rc1"]]);

        [$code, $out] = $this->update('--check');

        $this->assertSame(10, $code, $out);
        $this->assertStringContainsString('buildroot: 2025.02.18 -> 2025.02.19', $out);
        $this->assertStringContainsString('note: buildroot 2027.02 is a newer LTS', $out);
    }

    public function test_an_even_year_february_release_is_not_a_new_lts()
    {
        $this->world(['buildroot' => ["dddd\trefs/tags/2026.02", "eeee\trefs/tags/2026.02.3"]]);

        [$code, $out] = $this->update('--check');

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('newer LTS', $out);
    }

    public function test_update_rewrites_the_pins_and_raises_the_version()
    {
        // Keep this release-number fixture independent of the currently committed utility OS.
        file_put_contents($this->dir.'/versions.env', preg_replace('/^UOS_VERSION=.*$/m', 'UOS_VERSION=2026.10', file_get_contents($this->dir.'/versions.env')));
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
