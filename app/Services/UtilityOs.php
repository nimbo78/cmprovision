<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/* The utility OS modules boot to be provisioned (2.7.0). The package ships two in the application directory, each with a
   MANIFEST (tools/utility-os/manifest.sh): scriptexecute64/ (ours, buildroot, docs/utility-os.md) and scriptexecute/ (the
   legacy one from raspberrypi/scriptexecutor). The link etc/boot picks the one modules get: dnsmasq's tftp-root and
   rpiboot -d (the usb/ ramdisk beneath it) follow it on every file, so a switch replaces the link and takes effect at once. */
class UtilityOs
{
    /** the directories an OS of the package lives in, in the order the page lists them */
    const DIRS = ['scriptexecute64', 'scriptexecute'];
    const MANAGED = '/var/lib/cmprovision-utility-os/releases';

    /** How long a verified directory is taken as it was while no file of it changes size, time or inode. Hashing the files
        (tens of megabytes) on every page load and every Livewire render took seconds; switching always hashes afresh. */
    const VERIFIED_SECONDS = 3600;

    public function link(): string
    {
        return config('cmprovision.boot_link');
    }

    protected function root(): string
    {
        return rtrim(config('cmprovision.boot_root'), '/');
    }

    protected function path(string $dir): ?string
    {
        if (in_array($dir, self::DIRS, true)) return $this->root().'/'.$dir;
        if (!preg_match('/\Amanaged:([0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?)\z/D', $dir, $m)) return null;
        $base = self::MANAGED.'/'.$m[1];
        foreach (['/var/lib/cmprovision-utility-os', self::MANAGED, $base, $base.'/boot', $base.'/receipt.json'] as $path) {
            $info = @lstat($path);
            if (!$info || $info['uid'] !== 0 || ($info['mode'] & 0022) || is_link($path)) return null;
        }
        $receipt = json_decode((string) @file_get_contents($base.'/receipt.json', false, null, 0, 131073), true);
        if (($receipt['manifest']['component'] ?? '') !== 'utility-os' || ($receipt['manifest']['version'] ?? '') !== $m[1]) return null;
        return $base.'/boot';
    }

    public function selector(): ?string
    {
        return match ($current = $this->current()) {
            'scriptexecute' => 'legacy', 'scriptexecute64' => 'bundled-modern', default => $current,
        };
    }

    public function select(string $selector): void
    {
        $dir = match ($selector) { 'legacy' => 'scriptexecute', 'bundled-modern' => 'scriptexecute64', default => $selector };
        $this->switchTo($dir);
    }

    /** Every OS of the package with a good MANIFEST: dir => what it says */
    public function systems(): array
    {
        $systems = [];
        $dirs = self::DIRS;
        foreach (glob(self::MANAGED.'/*', GLOB_ONLYDIR) ?: [] as $path) $dirs[] = 'managed:'.basename($path);
        foreach ($dirs as $dir)
            if (($manifest = $this->manifest($dir)) !== null)
                $systems[$dir] = $manifest;
        return $systems;
    }

    /** The MANIFEST of one directory: its key=value lines, the file= lines as files; null when it is no OS of the package */
    public function manifest(string $dir, bool $fresh = false): ?array
    {
        if (($base = $this->path($dir)) === null)
            return null;
        $text = @file_get_contents($base.'/MANIFEST');
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
                if (!preg_match('/^([0-9a-f]{64}) (\d+) (.+)$/', $value, $m) || isset($values['files'][$m[3]]))
                    return null;
                $values['files'][$m[3]] = ['sha256' => $m[1], 'size' => (int) $m[2]];
            }
            elseif (!in_array($key, ['dir', 'files'], true))
                $values[$key] = $value;
        }
        if (!preg_match('/^[0-9A-Za-z.-]{1,32}$/D', $values['version'] ?? '') || !$values['files'])
            return null;
        return $this->filesMatch($dir, $values['files'], $fresh) ? $values : null;
    }

    /** A readable inventory is not enough: an incomplete or damaged directory must never be offered for boot. */
    protected function filesMatch(string $dir, array $files, bool $fresh = false): bool
    {
        $path = $this->path($dir);
        $base = $path ? realpath($path) : false;
        if ($base === false || is_link($path))
            return false;
        $stats = [];
        foreach ($files as $path => $expected)
        {
            if ($path[0] === '/' || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path)
                || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path))
                return false;
            $file = $base.'/'.$path;
            $real = realpath($file);
            if ($real === false || !str_starts_with($real, $base.'/') || is_link($file) || !is_file($file)
                || @filesize($file) !== $expected['size'])
                return false;
            $stat = @stat($file);
            $stats[$path] = $stat ? [$stat['size'], $stat['mtime'], $stat['ino']] : null;
        }
        if (!$this->contentsMatch($dir, $files, $stats, $fresh))
            return false;
        $actual = [];
        try
        {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file)
            {
                if ($file->isLink())
                    return false;
                if ($file->isFile())
                {
                    $path = substr($file->getPathname(), strlen($base) + 1);
                    if ($path !== 'MANIFEST')
                        $actual[] = $path;
                }
            }
        }
        catch (\UnexpectedValueException $e)
        {
            return false;
        }
        $listed = array_keys($files);
        sort($actual, SORT_STRING);
        sort($listed, SORT_STRING);
        return $actual === $listed;
    }

    /** The bytes: what the MANIFEST says, hashed once and then taken as it was while the files stay as they are (their
        sizes, times and inodes, and the hashes the MANIFEST expects, make the key). $fresh hashes whatever the key says. */
    protected function contentsMatch(string $dir, array $files, array $stats, bool $fresh): bool
    {
        $key = 'utility-os-verified:'.$dir.':'.sha1(json_encode([$files, $stats]));
        if (!$fresh && Cache::get($key) === true)
            return true;
        $base = realpath($this->path($dir));
        foreach ($files as $path => $expected)
            if ($this->hash($base.'/'.$path) !== $expected['sha256'])
                return false;
        Cache::put($key, true, self::VERIFIED_SECONDS);
        return true;
    }

    /** The one place a file is hashed (the tests count it) */
    protected function hash(string $file): string|false
    {
        return @hash_file('sha256', $file);
    }

    /** The directory modules get now: where the link points, when that is an OS of the package */
    public function current(): ?string
    {
        $target = @readlink($this->link());
        if ($target === false)
            return null;
        foreach (self::DIRS as $dir)
            if ($target === '../'.$dir && $this->manifest($dir) !== null)
                return $dir;
        if (preg_match('#\A'.preg_quote(self::MANAGED, '#').'/([0-9]{4}\.[0-9]{2}(?:\.[0-9]+)?)/boot\z#D', $target, $m)
            && $this->manifest('managed:'.$m[1]) !== null) return 'managed:'.$m[1];
        return null;
    }

    /** Serve the OS in $dir to modules from now on: a new link next to the old one, renamed over it, so there is never a
        moment without one */
    public function switchTo(string $dir): void
    {
        if ($this->manifest($dir, true) === null)
            throw new InvalidArgumentException(($dir === '' ? 'nothing' : $dir).' is not a utility OS of the package');
        $link = $this->link();
        $new = dirname($link).'/.boot-'.bin2hex(random_bytes(4));
        $target = str_starts_with($dir, 'managed:') ? $this->path($dir) : '../'.$dir;
        if (!@symlink($target, $new))
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
