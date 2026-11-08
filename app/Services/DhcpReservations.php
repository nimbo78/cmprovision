<?php

namespace App\Services;

use App\Models\Host;
use RuntimeException;

/** Generate the host section from the database while preserving the stand's other dnsmasq options. */
class DhcpReservations
{
    public function write(): void
    {
        $path = config('cmprovision.dnsmasq_conf');
        $old = @file_get_contents($path);
        if (!$old) throw new RuntimeException("Error reading existing $path");
        $lines = array_filter(explode("\n", trim($old)), fn ($line) => !str_starts_with($line, 'dhcp-host='));
        foreach (Host::orderBy('ip')->get() as $host) {
            $lines[] = 'dhcp-host='.$host->mac.',set:client_is_a_pi,'.$host->ip.($host->hostname ? ','.$host->hostname : '');
        }
        if (!@file_put_contents($path, implode("\n", $lines)."\n")) throw new RuntimeException("Error writing to $path. Check file permissions.");
    }

    public function restart(): bool
    {
        exec(config('cmprovision.dnsmasq_restart'), $output, $code);
        return $code === 0;
    }
}
