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
        if (!is_executable('/bin/sh') || trim((string) shell_exec('command -v sha256sum 2>/dev/null')) === '')
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
