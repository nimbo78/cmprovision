<?php

namespace Tests\Feature;

use App\Http\Livewire\Ports;
use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetworkStatus;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\SwitchSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Support\FakeSnmp;
use Tests\Support\FakeSwitches;
use Tests\Support\SwitchRow;
use Tests\TestCase;

/**
 * The Ports page: each switch's front panel with the module in each port, the summary and one port's details.
 */
class PortsPageTest extends TestCase
{
    use RefreshDatabase, FakeSwitches;

    const MODULE_MAC = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    protected $fake;

    protected function setUp(): void
    {
        parent::setUp();
        // the provisioner's MAC on the modules' network; the top bar's indicator reads nothing here
        $this->mock(NetworkStatus::class, function (MockInterface $m) {
            $m->shouldReceive('serverMac')->andReturn('2c:cf:67:30:7d:a2');
            $m->shouldReceive('current')->andReturn(null);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* A Huawei with three PoE ports, the module on GE0/0/3 and the provisioner behind XGE0/0/1 */
    protected function switchWithModule()
    {
        SwitchRow::put(['ip' => '192.0.2.10', 'snmp_community' => 'public']);
        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X'];
        foreach ([6 => ['GE0/0/1', 2], 7 => ['GE0/0/2', 2], 8 => ['GE0/0/3', 1], 30 => ['XGE0/0/1', 1]] as $i => [$name, $oper])
            $t += [
                SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => $oper,
                SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => $i === 30 ? 10000 : 1000,
            ];
        $t += [
            MacTable::OID_HUAWEI_FDB_PORT.'.'.self::MODULE_OID.'.10.0' => 8,
            MacTable::OID_HUAWEI_FDB_PORT.'.44.207.103.48.125.162.1.0' => 30,   // 2c:cf:67:30:7d:a2, the provisioner
            Poe::OID_HW_CONSUMING.'.8' => 6900, Poe::OID_HW_POWER_STATUS.'.8' => 'Powered',
            Poe::OID_HW_CONSUMING.'.6' => 0, Poe::OID_HW_POWER_STATUS.'.6' => 'Detecting',
            Poe::OID_HW_CONSUMING.'.7' => 0, Poe::OID_HW_POWER_STATUS.'.7' => 'Detecting',
        ];
        $this->fake = new FakeSnmp(['' => $t]);
        $fake = $this->fake;
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });
        return Cm::create(['serial' => '10000000e611110c', 'mac' => self::MODULE_MAC, 'phase' => 'done', 'timeline' => [],
            'provisioning_started_at' => now()->subMinutes(6), 'provisioning_complete_at' => now()->subMinute(),
            'phase_started_at' => now()->subMinute(), 'polled_at' => now()]);
    }

    public function test_the_verified_panel_keeps_sfp_selection_on_its_logical_interface()
    {
        $switch = SwitchRow::put(['ip' => '192.0.2.10', 'snmp_community' => 'public']);
        $table = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'WS-C2960CX-8PC-L', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw-cx'];
        for ($i = 1; $i <= 12; $i++)
        {
            $index = 10100 + $i;
            $table += [SwitchSnapshot::OID_IFNAME.'.'.$index => 'Gi0/'.$i, SwitchSnapshot::OID_IFTYPE.'.'.$index => 6,
                       SwitchSnapshot::OID_IFCONNECTOR.'.'.$index => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$index => 2,
                       SwitchSnapshot::OID_IFHIGHSPEED.'.'.$index => 1000];
        }
        $fake = new FakeSnmp(['' => $table]);
        $this->app->bind(SnmpClient::class, fn () => $fake);
        $this->actingAs(User::factory()->create());
        Livewire::test(Ports::class)
            ->assertSee('Front panel · view from the front')
            ->assertSeeHtml('aria-label="sw-cx front panel"')
            ->assertSeeHtml('ports-bank one-row uplinks')
            ->call('select', 's'.$switch->id.'p10111')
            ->assertViewHas('tile', fn ($tile) => $tile['name'] === 'Gi0/11' && $tile['ifIndex'] === 10111 && $tile['panel_number'] === 1);
    }

    public function test_the_page_is_in_the_top_bar_and_behind_the_login()
    {
        $this->get('/ports')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertSee('href="'.route('ports').'"', false);
        $this->get('/ports')->assertOk()->assertSeeLivewire('ports')->assertSee('No switch is set up');
    }

    public function test_without_a_switch_it_says_where_to_set_one_up()
    {
        Livewire::test(Ports::class)
            ->assertSee('No switch is set up')
            ->assertSee(route('settings'), false)
            ->assertSee('Settings → Ethernet switch')
            ->assertDontSee('Other settings')          // the menu item is "Settings" since 2.2.0
            ->assertDontSeeHtml('class="py-8"');       // cmp-page gives the page its margins
    }

    public function test_the_module_tile_and_the_summary()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class)
            ->assertSee('S5731-S24P4X')
            ->assertSee('3 × 1G PoE · 1 × SFP')
            ->assertSee('110C')
            ->assertSee('1 module on the stand')
            ->assertSee('2 of 3 ports free')
            ->assertSeeHtml('<span class="label">1 done</span>')   // the chip text has a box of its own to trim (app.css)
            ->assertSee('All done: the batch can be replaced')
            ->assertSeeHtml('<span class="short">UP</span>');
    }

    public function test_the_block_line_names_the_switch_first_then_its_serial_mac_address_and_model()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->switchWithModule();
        $this->fake->tables[''] += [
            SwitchSnapshot::OID_SYSNAME.'.0' => 'stand-sw.corp.example',
            SwitchSnapshot::OID_ENT_CLASS.'.1' => 3, SwitchSnapshot::OID_ENT_MODEL.'.1' => 'S5731-S24P4X',
            SwitchSnapshot::OID_ENT_HW_REV.'.1' => 'VER.B', SwitchSnapshot::OID_ENT_SERIAL.'.1' => '2102351931P0K1000123',
            SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0' => hex2bin('f4dee0123456'),
        ];
        Livewire::test(Ports::class)
            ->assertSee('stand-sw · S/N 2102351931P0K1000123 · MAC f4:de:e0:12:34:56 · 192.0.2.10 · S5731-S24P4X VER.B · polled 0 s ago · PoE 6.9 W')
            ->assertSee('3 × 1G PoE · 1 × SFP');
    }

    public function test_cages_and_combo_ports_are_drawn_as_the_switch_lists_them()
    {
        $this->switchWithModule();
        $this->cagesAndCombo();

        Livewire::test(Ports::class)
            ->assertSeeHtml('<span class="combo-cage" aria-hidden="true"></span>')
            ->assertSee('3 × 1G PoE · 1 × SFP')
            ->assertSee('a dashed corner: a combo port, RJ45 or SFP, filled when an SFP is in');
    }

    public function test_the_details_name_a_cage_or_combo_port_and_whether_a_module_is_in()
    {
        $this->switchWithModule();
        // an SFP in XGE0/0/1's cage
        $this->cagesAndCombo([1042 => [10, 'XGE0/0/1', 30, 1041]]);

        Livewire::test(Ports::class)
            ->call('select', 's1p7')
            ->assertSeeHtml('<dt class="text-fg-muted">Connector</dt>')
            ->assertSee('combo: RJ45 or SFP, no SFP')
            ->call('select', 's1p30')
            ->assertSee('SFP cage, module in')
            ->call('select', 's1p8')
            ->assertDontSeeHtml('<dt class="text-fg-muted">Connector</dt>');
    }

    /* ENTITY-MIB the way Cisco lists it: GE0/0/2's RJ45 beside a cage (a combo port), XGE0/0/1 a cage; more entities
       [entity => [class, name, ifIndex, parent]] go in beside them */
    protected function cagesAndCombo(array $more = []): void
    {
        $t = [SwitchSnapshot::OID_ENT_CLASS.'.1' => 3];
        foreach ([1006 => [10, 'GE0/0/1', 6], 1007 => [10, 'GE0/0/2', 7], 1008 => [10, 'GE0/0/3', 8],
                  1040 => [5, 'GE0/0/2 Container', null], 1041 => [5, 'XGE0/0/1 Container', null]] + $more as $entity => $row)
        {
            [$class, $name, $ifIndex] = $row;
            $t[SwitchSnapshot::OID_ENT_CLASS.'.'.$entity] = $class;
            $t[SwitchSnapshot::OID_ENT_NAME.'.'.$entity] = $name;
            $t[SwitchSnapshot::OID_ENT_CONTAINED_IN.'.'.$entity] = $row[3] ?? 1;
            if ($ifIndex)
                $t[Poe::OID_ENT_ALIAS.'.'.$entity.'.0'] = '.1.3.6.1.2.1.2.2.1.1.'.$ifIndex;
        }
        $this->fake->tables[''] += $t;
    }

    public function test_a_name_that_is_already_the_model_is_not_repeated()
    {
        $this->switchWithModule();
        $this->fake->tables[''][SwitchSnapshot::OID_SYSDESCR.'.0'] = '';
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'stand-sw';
        Livewire::test(Ports::class)
            ->assertSee('stand-sw · 192.0.2.10 · polled')
            ->assertDontSee('stand-sw · 192.0.2.10 · stand-sw');
    }

    public function test_a_snapshot_kept_by_the_previous_version_still_draws()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class);   // takes the snapshot all pages share
        // as 2.3.0 kept it in the cache: without the identity, and as 2.3.1 did: without the ports' connectors
        $state = Cache::get('switch-snapshot:192.0.2.10');
        unset($state['snapshot']['identity']);
        foreach (array_keys($state['snapshot']['ports']) as $ifIndex)
            unset($state['snapshot']['ports'][$ifIndex]['media'], $state['snapshot']['ports'][$ifIndex]['transceiver']);
        Cache::put('switch-snapshot:192.0.2.10', $state, 3600);
        Livewire::test(Ports::class)
            ->assertSee('192.0.2.10 · S5731-S24P4X · polled')
            ->assertSee('1 module on the stand')
            ->call('select', 's1p30')
            ->assertSeeHtml('<dd>SFP cage</dd>');
    }

    public function test_a_port_shows_its_details_with_the_module_card_and_identify()
    {
        $cm = $this->switchWithModule();
        Livewire::test(Ports::class)
            ->call('select', 's1p8')
            ->assertSee('GE0/0/3')
            ->assertSee(route('cm', $cm->serial), false)
            ->assertSee('delivering 6.9 W')
            ->call('identify', $cm->id);

        $this->assertTrue($cm->fresh()->isIdentifying());
    }

    public function test_the_switch_is_asked_once_per_freshness_window()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->switchWithModule();
        $page = Livewire::test(Ports::class);
        $walks = count($this->fake->walks);
        Carbon::setTestNow('2026-10-12 10:00:03');
        $page->call('$refresh');
        $this->assertSame($walks, count($this->fake->walks));
    }

    public function test_a_switch_that_stops_answering_keeps_its_last_picture_with_a_warning()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->switchWithModule();
        $page = Livewire::test(Ports::class);
        $this->fake->timeout = true;
        Carbon::setTestNow('2026-10-12 10:00:30');
        $page->call('$refresh')
            ->assertSee('not answering since')
            ->assertSee('No response from 192.0.2.1')
            ->assertSee('110C');
    }

    public function test_a_switch_that_never_answered_says_so()
    {
        $this->switchWithModule();
        $this->fake->timeout = true;
        Livewire::test(Ports::class)->assertSee('Cannot read the switch: No response from 192.0.2.1');
    }

    public function test_a_switch_name_that_does_not_resolve_says_so_instead_of_breaking_the_page()
    {
        SwitchRow::put(['ip' => 'switch.invalid']);
        $this->app->bind(SnmpClient::class, function () {
            throw new \ErrorException('SNMP::__construct(): Unknown host switch.invalid');
        });

        Livewire::test(Ports::class)->assertSee('Cannot read the switch: SNMP::__construct(): Unknown host switch.invalid');
    }

    public function test_four_modules_behind_one_port_are_all_on_the_map()
    {
        $this->switchWithModule();
        foreach ([2, 3, 4] as $n) {
            $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.'.(211 + $n).'.10.0'] = 8;
            // finished and still on the stand: it asks the server whether to blink
            Cm::create(['serial' => '10000000e61111'.sprintf('%02d', $n), 'mac' => sprintf('e4:5f:01:62:9f:%02x', 211 + $n),
                'phase' => 'done', 'timeline' => [], 'provisioning_complete_at' => now(), 'polled_at' => now()]);
        }

        Livewire::test(Ports::class)
            ->assertSee('4 modules on the stand')
            ->assertSee('×4');
    }

    public function test_a_map_from_before_the_switch_went_silent_says_so_in_the_verdict()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->switchWithModule();
        $page = Livewire::test(Ports::class);
        $this->fake->timeout = true;
        Carbon::setTestNow('2026-10-12 10:00:30');
        $page->call('$refresh')->assertSee('192.0.2.10 is not answering: All done: the batch can be replaced');
    }

    public function test_while_another_page_asks_the_switch_it_says_so_and_names_the_switch_once()
    {
        SwitchRow::put(['ip' => '192.0.2.10']);
        // real time here: the page waits for the lock by the clock
        $lock = \Illuminate\Support\Facades\Cache::lock('switch-snapshot-lock:192.0.2.10', 60);
        $this->assertTrue($lock->get());
        try {
            Livewire::test(Ports::class)
                ->assertSee('Asking the switch')
                ->assertDontSee('192.0.2.10 · 192.0.2.10');
        } finally {
            $lock->release();
        }
    }

    /* A second Huawei at 192.0.2.11 called sw02, with a device on GE0/0/3; the first one is called sw01 */
    protected function secondFakeSwitch(bool $silent = false): FakeSnmp
    {
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw01';
        $second = new FakeSnmp(['' => [
            SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5735-S24P4X', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw02',
            SwitchSnapshot::OID_IFNAME.'.8' => 'GE0/0/3', SwitchSnapshot::OID_IFTYPE.'.8' => 6,
            SwitchSnapshot::OID_IFCONNECTOR.'.8' => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.8' => 1,
            SwitchSnapshot::OID_IFHIGHSPEED.'.8' => 1000,
        ]], '192.0.2.11');
        $second->timeout = $silent;
        EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'public']);
        $this->bindSwitches(['192.0.2.10' => $this->fake, '192.0.2.11' => $second]);
        return $second;
    }

    public function test_each_switch_has_its_block_with_its_line_inside()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch();
        Livewire::test(Ports::class)
            ->assertSeeInOrder(['sw01 · 192.0.2.10 · S5731-S24P4X', 'sw02 · 192.0.2.11 · S5735-S24P4X'])
            ->assertSeeHtml('wire:key="s2p8"');
    }

    public function test_a_silent_switch_keeps_its_last_snapshot_in_its_block()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        Livewire::test(Ports::class);                 // both answer once
        $second->timeout = true;
        Carbon::setTestNow('2026-10-14 10:00:40');
        Livewire::test(Ports::class)
            ->assertSee('not answering since')
            ->assertSee('No response from 192.0.2.11')
            ->assertSee('sw02 is not answering: ')
            ->assertSeeHtml('wire:key="s2p8"');      // its last snapshot's ports stay
    }

    public function test_a_switch_that_never_answered_shows_a_placeholder()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch(true);
        Livewire::test(Ports::class)
            ->assertSee('Cannot read the switch: No response from 192.0.2.11')
            ->assertDontSeeHtml('wire:key="s2p8"')
            ->assertSeeHtml('wire:key="s1p8"');
    }

    public function test_two_switches_with_one_name_are_warned_about()
    {
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'Switch';
        $second->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'Switch';
        Livewire::test(Ports::class)
            ->assertSee('Two switches are called Switch: their boards look the same. Rename one on the switch.');
    }

    public function test_one_switch_under_two_addresses_is_shown_once_with_what_to_do()
    {
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        foreach ([$this->fake, $second] as $fake) {
            $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
            $fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw01';
        }
        Livewire::test(Ports::class)
            ->assertSee('Same switch as sw01 (192.0.2.10): delete one of them under Settings → Ethernet switches.')
            ->assertDontSee('Two switches are called')
            ->assertDontSeeHtml('wire:key="s2p8"')
            ->assertSee('1 module on the stand');
    }

    public function test_a_port_of_the_second_switch_opens_its_details()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch();
        Livewire::test(Ports::class)
            ->call('select', 's2p8')->assertSet('selected', 's2p8')->assertSee('sw02 GE0/0/3')
            ->call('select', 'p8')->assertSet('selected', null);
    }

    public function test_a_link_between_the_switches_is_drawn_as_one()
    {
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        $second->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 1;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.128.1.0'] = 6;   // sw02's base MAC on GE0/0/1
        Livewire::test(Ports::class)
            ->assertSeeHtml('title="sw01 GE0/0/1 · link to sw02"')
            ->assertSeeHtml('<span class="short">→</span>')
            ->assertSeeHtml('<span class="mark">sw02</span>');
    }

    public function test_the_selected_port_is_set_by_the_page_only()
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Ports::class)->set('selected', 's1p8');
    }

    public function test_a_switch_without_a_mac_table_says_modules_cannot_be_matched_to_ports()
    {
        $this->switchWithModule();
        foreach (array_keys($this->fake->tables['']) as $oid)
            if (strpos($oid, MacTable::OID_HUAWEI_FDB_PORT) === 0)
                unset($this->fake->tables[''][$oid]);

        Livewire::test(Ports::class)->assertSee('The switch gave no MAC address table')->assertSee('Settings → Ethernet switch')
            ->assertDontSee('Other settings');
    }

    public function test_a_quiet_port_says_how_long_its_link_has_been_up()
    {
        $this->switchWithModule();
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 1;      // GE0/0/1: a link, no MAC
        $this->fake->tables[''][SwitchSnapshot::OID_SYSUPTIME.'.0'] = 100000000;
        $this->fake->tables[''][SwitchSnapshot::OID_IFLASTCHANGE.'.6'] = 1000;    // up for 11 days

        Livewire::test(Ports::class)
            ->assertSeeHtml('<span class="short">LNK</span>')
            ->call('select', 's1p6')
            ->assertSee('Link up for 1 week');
    }

    public function test_a_raspberry_pi_the_server_has_not_heard_from_yet_is_booting()
    {
        $this->switchWithModule();
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 1;          // GE0/0/1 came up 20 s ago
        $this->fake->tables[''][SwitchSnapshot::OID_SYSUPTIME.'.0'] = 100000000;
        $this->fake->tables[''][SwitchSnapshot::OID_IFLASTCHANGE.'.6'] = 99998000;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 6;   // e4:5f:01:62:9f:d4

        Livewire::test(Ports::class)
            ->assertSee('1 booting')
            ->call('select', 's1p6')
            ->assertSeeHtml('A Raspberry Pi is on this port and has not asked the server yet: <span class="ports-serial">e4:5f:01:62:9f:d4</span>');
    }

    public function test_booting_and_silent_ports_count_as_in_progress_not_as_running()
    {
        $cm = $this->switchWithModule();
        $cm->update(['phase' => 'write', 'provisioning_complete_at' => null, 'progress_updated_at' => now()]);
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 1;   // GE0/0/1: a link, nothing heard yet
        Livewire::test(Ports::class)
            ->assertSee('1 running')
            ->assertSee('1 booting')
            ->assertSee('2 still in progress, 0 failed: keep the batch on the stand')
            ->assertSeeHtml('bg-info-bg text-info')      // the verdict's text in the info colour, as its box
            ->assertDontSeeHtml('bg-info-bg text-link');
    }

    public function test_a_chip_whose_count_drops_to_zero_stays_while_it_is_selected()
    {
        $this->switchWithModule();
        // nothing runs now: the "running" chip is hidden unless the operator's filter holds it
        Livewire::test(Ports::class)
            ->assertSeeHtml('x-show="filter.includes(\'run\')" style="display: none"')
            ->assertSee('1 done');
    }

    protected function writeAccess()
    {
        SwitchRow::put(['snmp_write_community' => 'private']);
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 1;
    }

    public function test_a_port_with_our_module_can_be_restarted_from_its_details()
    {
        Sleep::fake();
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 's1p8')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on 192.0.2.10 GE0/0/3? PoE goes off for 5 s; the module boots the system it was flashed with."')
            ->call('restart', 's1p8')
            ->assertSee('Restarted 192.0.2.10 GE0/0/3 (10000000e611110c): PoE was off for 5 s.')
            ->assertDispatched('poe-outages-changed');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', 2], [Poe::OID_HW_ENABLE.'.8', 'i', 1]], $this->fake->sets);
    }

    public function test_without_write_access_there_is_no_restart()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class)->call('select', 's1p8')
            ->assertDontSeeHtml('wire:click="restart(')
            ->assertDontSee('No PoE restart');
    }

    public function test_a_port_that_may_not_be_restarted_says_why()
    {
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 's1p30')->assertSee('No PoE restart: no PoE on 192.0.2.10 XGE0/0/1');
    }

    public function test_failed_modules_on_the_stand_have_one_button()
    {
        Sleep::fake();
        $cm = $this->switchWithModule();
        $cm->update(['phase' => 'failed', 'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_complete_at' => null]);
        $this->writeAccess();
        Livewire::test(Ports::class)
            ->assertSee('Restart failed (1)')
            ->assertSeeHtml('wire:confirm="Restart 1 module (failed or without a report)? PoE goes off for 5 s on each port."')
            ->call('restartFailed')
            ->assertSee('Restarted 192.0.2.10 GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }
}
