<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/** The logs the Settings page and the Wi-Fi section show in their windows: a fixed list, nothing else can be asked for */
class ServiceLogs
{
    /** lines of a journal in the window */
    const LINES = 1000;

    /** the end of a file log */
    const FILE_BYTES = 262144;

    /** key => [window title, journalctl option, unit or syslog tag]; the Laravel log is a file, the Wi-Fi helper's changes come from WifiNetworks */
    const SOURCES = [
        'dnsmasq' => ['Dnsmasq log since boot', '-u', 'cmprovision-dnsmasq'],
        'rpiboot' => ['Rpiboot log since boot', '-u', 'cmprovision-rpiboot'],
        'usb-diagnostics' => ['USB diagnostics receiver log since boot', '-u', 'cmprovision-usb-diagnostics'],
        'queue' => ['Queue worker log since boot', '-u', 'cmprovision-queue'],
        'queue-images' => ['Image checksum worker log since boot', '-u', 'cmprovision-queue-images'],
        'uplink-spare' => ['Uplink cold spare log since boot', '-t', 'cmprovision-uplink'],
        'laravel' => ['Laravel error log', null, null],
        'wifi' => ['Wi-Fi changes', null, null],
    ];

    /** @var callable(array): string runs a command given as an argument list and returns what it printed */
    protected $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? [static::class, 'run'];
    }

    public static function has(string $source): bool
    {
        return isset(self::SOURCES[$source]);
    }

    public static function title(string $source): string
    {
        return self::SOURCES[$source][0] ?? throw new \InvalidArgumentException("There is no log '$source'.");
    }

    public function read(string $source): string
    {
        if (!self::has($source)) {
            throw new \InvalidArgumentException("There is no log '$source'.");
        }
        if ($source === 'laravel') {
            return $this->tail(config('cmprovision.laravel_log'));
        }
        if ($source === 'wifi') {
            return app(WifiNetworks::class)->log();
        }
        [, $option, $name] = self::SOURCES[$source];

        return (string) call_user_func($this->runner, ['/bin/journalctl', '--boot', $option, $name, '-n', (string) self::LINES, '--no-pager']);
    }

    /** the last FILE_BYTES of a file, starting at a whole line */
    protected function tail(string $path): string
    {
        $size = @filesize($path);
        $handle = $size === false ? false : @fopen($path, 'rb');
        if ($handle === false) {
            return "There is no log file yet ($path).\n";
        }
        try {
            if ($size > self::FILE_BYTES) {
                fseek($handle, $size - self::FILE_BYTES);
                fgets($handle);   // the line the cut fell into
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /** a command as an argument list (no shell), the journal readable by the web server's user (group systemd-journal) */
    public static function run(array $argv): string
    {
        $process = new Process($argv);
        $process->setTimeout(10);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return 'The log could not be read: '.$e->getMessage()."\n";
        }

        return $process->getOutput() !== '' ? $process->getOutput() : $process->getErrorOutput();
    }
}
