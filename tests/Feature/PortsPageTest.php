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
            ->assertSee(route('settings'), false);
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
            ->assertSee('1 done')
            ->assertSee('All done: the batch can be replaced')
            ->assertSeeHtml('<span class="short">UP</span>');
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
}
