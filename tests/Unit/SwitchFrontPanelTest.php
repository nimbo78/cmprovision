<?php

namespace Tests\Unit;

use App\Support\SwitchFrontPanel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SwitchFrontPanelTest extends TestCase
{
    protected function ports(string $prefix, int $count): array
    {
        $ports = [];
        for ($i = 1; $i <= $count; $i++)
            $ports[] = ['key' => 'port-'.$prefix.$i, 'name' => $prefix.$i, 'number' => $i];
        return $ports;
    }

    public function test_compact_cisco_has_two_paired_banks_then_bottom_aligned_copper_and_fiber(): void
    {
        $ports = array_reverse($this->ports('Gi0/', 12));
        $panel = SwitchFrontPanel::resolve('WS-C2960CX-8PC-L', $ports);
        $this->assertSame([2, 2, 1, 1], array_column($panel['blocks'], 'rows'));
        $this->assertSame([false, false, true, true], array_column($panel['blocks'], 'uplinks'));
        $this->assertSame(['Gi0/1', 'Gi0/2', 'Gi0/3', 'Gi0/4'], array_column($panel['blocks'][0]['tiles'], 'name'));
        $this->assertSame(['Gi0/5', 'Gi0/6', 'Gi0/7', 'Gi0/8'], array_column($panel['blocks'][1]['tiles'], 'name'));
        $this->assertSame(['Gi0/9', 'Gi0/10'], array_column($panel['blocks'][2]['tiles'], 'name'));
        $fiber = $panel['blocks'][3]['tiles'];
        $this->assertSame([1, 2], array_column($fiber, 'panel_number'), 'labels printed on the SFP slots');
        $this->assertSame([11, 12], array_column($fiber, 'number'), 'logical interface numbers are retained');
        $this->assertSame(['port-Gi0/11', 'port-Gi0/12'], array_column($fiber, 'key'), 'select/PoE actions keep their original keys');
    }

    public static function fixedCiscoModels(): array
    {
        return [
            ['C9200L-24P-4G', 24, 'Gi', 'SFP'], ['C9200L-24T-4X', 24, 'Te', 'SFP+'],
            ['C9200L-48P-4X', 48, 'Te', 'SFP+'], ['C9200L-48T-4G-E', 48, 'Gi', 'SFP'],
        ];
    }

    #[DataProvider('fixedCiscoModels')]
    public function test_fixed_cisco_keeps_downlinks_in_paired_banks_and_four_uplinks_on_one_row(string $model, int $count, string $type, string $label): void
    {
        $ports = array_merge($this->ports('Gi2/0/', $count), $this->ports($type.'2/1/', 4));
        $panel = SwitchFrontPanel::resolve($model, array_reverse($ports));
        $this->assertNotNull($panel);
        $this->assertCount(intdiv($count, 12) + 1, $panel['blocks']);
        foreach (array_slice($panel['blocks'], 0, -1) as $bank)
        {
            $this->assertSame(2, $bank['rows']);
            $this->assertCount(12, $bank['tiles']);
        }
        $uplink = $panel['blocks'][count($panel['blocks']) - 1];
        $this->assertSame([1, $label, [1, 2, 3, 4]], [$uplink['rows'], $uplink['label'], array_column($uplink['tiles'], 'panel_number')]);
        $this->assertSame(array_column($ports, 'key'), array_merge(...array_map(fn ($b) => array_column($b['tiles'], 'key'), $panel['blocks'])));
    }

    public function test_unknown_incomplete_extra_and_ambiguous_ports_do_not_get_a_verified_panel(): void
    {
        $ports = $this->ports('Gi0/', 12);
        foreach ([null, 'S5731', 'WS-C2960C-8PC-L', 'WS-C2960CX-8PD-L', 'WS-C3560CX-8PC-S'] as $model)
            $this->assertNull(SwitchFrontPanel::resolve($model, $ports), (string) $model);
        $this->assertNull(SwitchFrontPanel::resolve('WS-C2960CX-8PC-L', array_slice($ports, 0, 11)));
        $this->assertNull(SwitchFrontPanel::resolve('WS-C2960CX-8PC-L', [...$ports, ...$this->ports('Gi0/', 1)]));
        $this->assertNull(SwitchFrontPanel::resolve('WS-C2960CX-8PC-L', [...$ports, ['name' => 'Gi0/0']]));
        $this->assertNull(SwitchFrontPanel::resolve('C9200L-24P-4G', array_merge($this->ports('Gi1/0/', 8), $this->ports('Te1/1/', 2))), 'reduced simulator fixture is not real hardware');
        $this->assertNull(SwitchFrontPanel::resolve('C9200L-24P-4G', array_merge($this->ports('Gi1/0/', 24), $this->ports('Gi2/1/', 4))), 'ports from different stack members');
        $this->assertNull(SwitchFrontPanel::resolve('C9200L-24P-4G', array_merge($this->ports('Gi1/0/', 24), $this->ports('Te1/1/', 4))), '10G uplinks contradict a 4G profile');
    }

    public function test_full_interface_names_and_non_poe_compact_variant_are_recognised(): void
    {
        $panel = SwitchFrontPanel::resolve('ws-c2960cx-8tc-l', $this->ports('GigabitEthernet0/', 12));
        $this->assertNotNull($panel);
        $this->assertSame('GigabitEthernet0/12', $panel['blocks'][3]['tiles'][1]['name']);
    }

    public function test_mikrotik_has_eight_sfp_ports_then_ethernet_in_a_single_row(): void
    {
        $ports = array_merge($this->ports('ether', 1), array_reverse($this->ports('sfp-sfpplus', 8)));
        foreach (['CRS309-1G-8S+IN', 'RouterOS CRS309-1G-8S+', 'RouterOS CRS309'] as $model)
        {
            $panel = SwitchFrontPanel::resolve($model, $ports);
            $this->assertSame([1, 1], array_column($panel['blocks'], 'rows'));
            $this->assertSame(range(1, 8), array_column($panel['blocks'][0]['tiles'], 'panel_number'));
            $this->assertSame('sfp-sfpplus1', $panel['blocks'][0]['tiles'][0]['name']);
            $this->assertSame('ether1', $panel['blocks'][1]['tiles'][0]['name']);
            $this->assertSame([false, false], array_column($panel['blocks'], 'uplinks'), 'all nine are switched interfaces');
        }
        $this->assertNull(SwitchFrontPanel::resolve('RouterOS CRS309', array_merge($this->ports('ether', 8), $this->ports('sfp-sfpplus', 1))), 'synthetic fixture is not the physical CRS309');
        $this->assertNull(SwitchFrontPanel::resolve('CRS305-1G-4S+IN', $ports));
        $this->assertNull(SwitchFrontPanel::resolve('CRS309-1G-8S+IN', array_slice($ports, 0, 8)));
    }
}
