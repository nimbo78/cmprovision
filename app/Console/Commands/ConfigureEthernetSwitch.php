<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Setting;
use App\Services\SwitchPortFinder;

class ConfigureEthernetSwitch extends Command
{
    protected $signature = 'ethernetswitch:configure';

    protected $description = 'Connect to managed Ethernet switch by SNMP v2c for port identification (SNMPv3 and the lookup method: settings page)';

    public function handle()
    {
        $ip = $this->ask("Ethernet switch IP-address (leave empty to disable module)");
        if (!$ip)
        {
            Setting::where('key', 'like', 'ethernetswitch_%')->delete();
            $this->info("Disabled Ethernet switch integration");
            return 0;
        }

        do
        {
            $community = $this->ask("SNMP v2c community name");
        } while (!$community);

        $this->line("Trying to communicate with Ethernet switch...");
        try
        {
            $scan = SwitchPortFinder::forConfig(['host' => $ip, 'version' => '2c', 'community' => $community])->scan();
        }
        catch (\Throwable $e)
        {
            $this->error($e->getMessage());
            return 1;
        }

        foreach ($scan['tried'] as $method => $answer)
            $this->line(sprintf("  %-8s %s", $method, $answer));

        if (!$scan['ports'])
        {
            $this->error("No MAC address table found. Settings unchanged.");
            return 1;
        }

        $this->table(['MAC', 'Port', 'VLAN'], array_map(function ($mac) use ($scan) {
            return [$mac, $scan['ports'][$mac], $scan['vlans'][$mac]];
        }, array_keys($scan['ports'])));

        Setting::where('key', 'like', 'ethernetswitch_%')->delete();
        foreach (['ip' => $ip, 'snmp_version' => '2c', 'snmp_community' => $community, 'method' => 'auto', 'method_detected' => $scan['method']] as $key => $value)
            Setting::create(['key' => 'ethernetswitch_'.$key, 'value' => $value]);

        $this->info("Communication successful (".SwitchPortFinder::METHODS[$scan['method']]."). Enabled Ethernet switch integration.");
        return 0;
    }
}
