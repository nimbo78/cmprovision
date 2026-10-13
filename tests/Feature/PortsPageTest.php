<?php

namespace Tests\Feature;

use App\Http\Livewire\Ports;
use App\Models\Cm;
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
use Tests\TestCase;

/**
 * The Ports page: the switch's front panel with the module in each port, the summary and one port's details.
 */
class PortsPageTest extends TestCase
{
    use RefreshDatabase;

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
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => '192.0.2.10']);
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'public']);
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

    public function test_the_header_names_the_switch_by_its_model_name_serial_and_mac()
    {
        $this->switchWithModule();
        $this->fake->tables[''] += [
            SwitchSnapshot::OID_SYSNAME.'.0' => 'stand-sw',
            SwitchSnapshot::OID_ENT_CLASS.'.1' => 3, SwitchSnapshot::OID_ENT_MODEL.'.1' => 'S5731-S24P4X',
            SwitchSnapshot::OID_ENT_HW_REV.'.1' => 'VER.B', SwitchSnapshot::OID_ENT_SERIAL.'.1' => '2102351931P0K1000123',
            SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0' => hex2bin('f4dee0123456'),
        ];
        Livewire::test(Ports::class)
            ->assertSeeHtml('<span class="font-bold text-fg">S5731-S24P4X VER.B</span>')
            ->assertSee('stand-sw · S/N 2102351931P0K1000123 · MAC f4:de:e0:12:34:56 · 192.0.2.10');
    }

    public function test_a_name_that_is_already_the_title_is_not_repeated()
    {
        $this->switchWithModule();
        $this->fake->tables[''][SwitchSnapshot::OID_SYSDESCR.'.0'] = '';
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'stand-sw';
        Livewire::test(Ports::class)
            ->assertSeeHtml('<span class="font-bold text-fg">stand-sw</span>')
            ->assertDontSee('stand-sw · ');
    }

    public function test_a_snapshot_kept_by_the_previous_version_still_draws()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class);   // takes the snapshot all pages share
        // as 2.3.0 kept it in the cache: without the identity
        $state = Cache::get('switch-snapshot:192.0.2.10');
        unset($state['snapshot']['identity']);
        Cache::put('switch-snapshot:192.0.2.10', $state, 3600);
        Livewire::test(Ports::class)
            ->assertSeeHtml('<span class="font-bold text-fg">S5731-S24P4X</span>')
            ->assertSee('1 module on the stand');
    }

    public function test_a_port_shows_its_details_with_the_module_card_and_identify()
    {
        $cm = $this->switchWithModule();
        Livewire::test(Ports::class)
            ->call('select', 'p8')
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
            ->assertSee('The switch has not answered for 0 s')
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
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => 'switch.invalid']);
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
        $page->call('$refresh')->assertSee('before the switch went silent: All done: the batch can be replaced');
    }

    public function test_while_another_page_asks_the_switch_it_says_so_and_names_the_switch_once()
    {
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => '192.0.2.10']);
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

    public function test_the_selected_port_is_set_by_the_page_only()
    {
        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(Ports::class)->set('selected', 'p8');
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
            ->call('select', 'p6')
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
            ->call('select', 'p6')
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
        Setting::create(['key' => 'ethernetswitch_snmp_write_community', 'value' => 'private']);
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 1;
    }

    public function test_a_port_with_our_module_can_be_restarted_from_its_details()
    {
        Sleep::fake();
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 'p8')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots the system it was flashed with."')
            ->call('restart', 'p8')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.')
            ->assertDispatched('poe-outages-changed');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', 2], [Poe::OID_HW_ENABLE.'.8', 'i', 1]], $this->fake->sets);
    }

    public function test_without_write_access_there_is_no_restart()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class)->call('select', 'p8')
            ->assertDontSeeHtml('wire:click="restart(')
            ->assertDontSee('No PoE restart');
    }

    public function test_a_port_that_may_not_be_restarted_says_why()
    {
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 'p30')->assertSee('No PoE restart: no PoE on XGE0/0/1');
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
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }
}
