<?php

namespace Tests\Feature;

use App\Http\Livewire\UplinkIndicator;
use App\Models\User;
use App\Services\NetworkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\UplinkSummaryTest;

/**
 * The icon in the top bar: how the provisioner reaches the network. It refreshes on its own only every
 * few minutes, and reads fresh data when the operator points at it.
 */
class UplinkIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private $network;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 20:20:14');
        config(['app.display_timezone' => 'Europe/Moscow']);
        $this->network = new class extends NetworkStatus {
            public $reads = 0;
            public $answer;

            public function __construct()
            {
            }

            public function read(): ?array
            {
                $this->reads++;
                return $this->answer;
            }
        };
        $this->network->answer = UplinkSummaryTest::snapshot(['checked_at' => now()->toIso8601String()]);
        $this->app->instance(NetworkStatus::class, $this->network);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_shows_the_uplink_and_the_details()
    {
        Livewire::test(UplinkIndicator::class)
            ->assertSeeHtml('aria-label="Internet via Wi-Fi 6, excellent signal, 1.2 Gbit/s"')
            ->assertSee('Internet via Wi-Fi')
            ->assertSee('1.2 Gbit/s, 2 streams')
            ->assertSee('M.2 card (wlan1)')
            ->assertSee('Backup: built-in Wi-Fi (wlan0)')
            ->assertSee('1 Gbit/s full duplex, 172.20.0.1/16')
            ->assertSee('Checked at 23:20:14');
    }

    public function test_refreshes_on_its_own_every_five_minutes()
    {
        Livewire::test(UplinkIndicator::class)->assertSeeHtml('wire:poll.300s="refreshPassive"');
    }

    public function test_pointing_at_the_icon_reads_fresh_data_at_most_every_ten_seconds()
    {
        $component = Livewire::test(UplinkIndicator::class);
        $this->assertSame(1, $this->network->reads);

        $component->call('refreshNow');
        $this->assertSame(1, $this->network->reads, 'data read a moment ago is fresh enough');

        Carbon::setTestNow(now()->addSeconds(11));
        $this->network->answer = UplinkSummaryTest::snapshot(['uplink' => null, 'backups' => [], 'checked_at' => now()->toIso8601String()]);
        $component->call('refreshNow')->assertSee('No internet connection');
        $this->assertSame(2, $this->network->reads);
    }

    public function test_the_passive_refresh_and_other_tabs_share_the_cache()
    {
        Livewire::test(UplinkIndicator::class);
        Carbon::setTestNow(now()->addMinutes(4));
        $other = Livewire::test(UplinkIndicator::class);
        $other->call('refreshPassive');
        $this->assertSame(1, $this->network->reads);

        Carbon::setTestNow(now()->addMinutes(2));
        $other->call('refreshPassive');
        $this->assertSame(2, $this->network->reads);
    }

    public function test_hidden_where_the_network_cannot_be_read()
    {
        $this->network->answer = null;

        Livewire::test(UplinkIndicator::class)
            ->assertDontSeeHtml('wire:poll')
            ->assertDontSee('Internet');
    }

    public function test_the_top_bar_carries_the_indicator()
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()
            ->assertSeeLivewire('uplink-indicator');
    }
}
