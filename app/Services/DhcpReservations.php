<?php

namespace App\Services;

use App\Models\Host;
use RuntimeException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Generate the host section from the database while preserving the stand's other dnsmasq options. */
class DhcpReservations
{
    /** Serialize DB/config/reload changes, including restoration before another caller can enter. */
    public function change(callable $mutation): bool
    {
        try {
            return Cache::lock('dhcp-reservations', 120)->block(5, function () use ($mutation) {
                return DB::transaction(function () use ($mutation) {
                    $path = config('cmprovision.dnsmasq_conf');
                    $old = $this->read($path);
                    $written = $attempted = false;
                    try {
                        $mutation();
                        $this->write();
                        $written = true;
                        $enabled = app(ServiceState::class)->status('cmprovision-dnsmasq') !== 'disabled';
                        if ($enabled) {
                            $attempted = true;
                            if (!$this->restart()) throw new RuntimeException('DHCP could not reload the new reservations.');
                        }
                        return $enabled;
                    } catch (\Throwable $error) {
                        if ($written) {
                            $this->replace($path, $old);
                            $restored = !$attempted || $this->restart();
                            throw new RuntimeException('Previous reservations restored.'.($restored ? '' : ' DHCP could not restart; check Service status.'), 0, $error);
                        }
                        throw $error;
                    }
                });
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $busy) {
            throw new RuntimeException('Another DHCP reservation change is in progress. Try again shortly.', 0, $busy);
        }
    }

    public function write(): void
    {
        $path = config('cmprovision.dnsmasq_conf');
        $old = $this->read($path);
        $lines = array_filter(explode("\n", trim($old)), fn ($line) => !str_starts_with($line, 'dhcp-host='));
        foreach (Host::orderBy('ip')->get() as $host) {
            $lines[] = 'dhcp-host='.$host->mac.',set:client_is_a_pi,'.$host->ip.($host->hostname ? ','.$host->hostname : '');
        }
        $this->replace($path, implode("\n", $lines)."\n");
    }

    private function read(string $path): string
    {
        $old = is_link($path) ? false : @file_get_contents($path);
        if ($old === false || $old === '') throw new RuntimeException("Error reading existing $path");
        return $old;
    }

    private function replace(string $path, string $contents): void
    {
        $tmp = @tempnam(dirname($path), '.dnsmasq-');
        if ($tmp === false || dirname($tmp) !== dirname($path)) {
            if ($tmp !== false) @unlink($tmp);
            throw new RuntimeException('Could not prepare the DHCP configuration. Check file permissions.');
        }
        try {
            if (is_link($path) || @file_put_contents($tmp, $contents) !== strlen($contents)
                || !@chmod($tmp, fileperms($path) & 0777) || !@rename($tmp, $path))
                throw new RuntimeException('Could not atomically replace the DHCP configuration. Check file permissions.');
        } finally { if (is_file($tmp)) @unlink($tmp); }
    }

    public function restart(): bool
    {
        $process = Process::fromShellCommandline(config('cmprovision.dnsmasq_restart'));
        $process->setTimeout(15);
        try { $process->run(); return $process->isSuccessful(); }
        catch (\Throwable $error) { return false; }
    }
}
