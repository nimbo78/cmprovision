<?php

namespace Tests\Feature;

use App\Http\Livewire\Cms;
use App\Http\Livewire\Firmwares;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Timestamps are stored in UTC (so exports and the API stay unambiguous) and shown in the
 * server's local time zone, which is what the operator standing next to the modules expects.
 */
class LocalTimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Moscow']);   // UTC+3, no DST
    }

    public function test_display_timezone_defaults_to_a_valid_zone()
    {
        $this->assertContains(config('app.display_timezone'), timezone_identifiers_list());
        $this->assertSame('UTC', config('app.timezone'), 'storage stays in UTC');
    }

    public function test_cm_list_shows_completion_time_in_local_time()
    {
        Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01',
            'provisioning_started_at' => '2026-10-08 20:00:00', 'provisioning_complete_at' => '2026-10-08 20:05:00']);

        Livewire::test(Cms::class)->set('projectId', 0)->assertSee('2026-10-08 23:05:00');
    }

    public function test_dashboard_log_shows_local_time()
    {
        Cmlog::create(['cm' => '1000000000000001', 'loglevel' => 'info', 'msg' => 'Provisioning started.']);
        Cmlog::where('cm', '1000000000000001')->update(['created_at' => '2026-10-08 20:00:00']);

        $this->actingAs(User::factory()->create())->get('/dashboard')->assertSee('23:00:00');
    }

    public function test_firmware_page_shows_the_last_check_in_local_time()
    {
        Setting::updateOrCreate(['key' => 'firmware_last_update'], ['value' => '2026-10-08 20:00:00']);

        Livewire::test(Firmwares::class)->assertSee('2026-10-08 23:00:00');
    }
}
