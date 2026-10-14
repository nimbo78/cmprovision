<?php

namespace App\Console\Commands;

use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Services\SwitchPortFinder;
use Illuminate\Console\Command;

/* Add a switch of the stand by SNMP v2c, or change the one at that address (its write access and SNMPv3 settings stay);
   the settings page does the rest */
class ConfigureEthernetSwitch extends Command
{
    protected $signature = 'ethernetswitch:configure {--remove= : address of a switch to remove}';

    protected $description = 'Add or change an Ethernet switch of the stand by SNMP v2c (SNMPv3, write access and the lookup method: settings page)';

    public function handle()
    {
        if ($this->option('remove') !== null)
        {
            $removed = EthernetSwitch::where('host', $this->option('remove'))->first();
            if (!$removed)
            {
                $this->error('No switch at '.$this->option('remove').'.');
                return 1;
            }
            // nothing could switch such a port on from here once its switch is gone (the settings page refuses as well)
            if (PoeOutage::stayedOff()->forSwitch($removed)->exists())
            {
                $this->error('Ports of '.$removed->hostname().' stayed off after a PoE restart. Switch them on (Power on) or forget them first.');
                return 1;
            }
            $removed->delete();
            $this->info('Removed '.$removed->hostname().' ('.$removed->host.').');
            return 0;
        }

        $ip = $this->ask('Ethernet switch IP-address (leave empty to list the switches)');
        if (!$ip)
        {
            $rows = EthernetSwitch::orderBy('id')->get()->map(function ($s) { return [$s->hostname(), $s->host, $s->snmp_version]; })->all();
            if ($rows)
                $this->table(['Name', 'Address', 'SNMP'], $rows);
            else
                $this->line('No switches.');
            $this->line('Nothing changed. Remove one with --remove=<address>; SNMPv3 and write access: settings page.');
            return 0;
        }

        $known = EthernetSwitch::where('host', $ip)->first();
        if ($known && $known->snmp_version === '3')
        {
            $this->error($known->hostname().' ('.$ip.') uses SNMPv3: change it on the settings page.');
            return 1;
        }

        do
        {
            $community = $this->ask('SNMP v2c community name');
        } while (!$community);

        $this->line('Trying to communicate with Ethernet switch...');
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
            $this->line(sprintf('  %-8s %s', $method, $answer));

        if (!$scan['ports'])
        {
            $this->error('No MAC address table found. Settings unchanged.');
            return 1;
        }

        $this->table(['MAC', 'Port', 'VLAN'], array_map(function ($mac) use ($scan) {
            return [$mac, $scan['ports'][$mac], $scan['vlans'][$mac]];
        }, array_keys($scan['ports'])));

        $switch = EthernetSwitch::firstOrNew(['host' => $ip]);
        $switch->fill(['snmp_version' => '2c', 'community' => $community, 'method' => 'auto', 'method_detected' => $scan['method']])->save();
        $this->info('Communication successful ('.SwitchPortFinder::METHODS[$scan['method']].'). '
            .($switch->wasRecentlyCreated ? 'Added' : 'Changed').' '.$ip.'.');
        return 0;
    }
}
