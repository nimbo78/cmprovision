<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningLog;
use App\Models\Cmlog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The provisioning log on the dashboard refreshes itself, like the progress panel above it. */
class ProvisioningLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'Europe/Moscow']);
        Carbon::setTestNow('2026-10-09 11:00:00');   // 14:00 in Moscow
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function entry($msg, $utc, $level = 'info')
    {
        $log = Cmlog::create(['cm' => '1000000000000d01', 'board' => 'GE0/0/4', 'loglevel' => $level, 'msg' => $msg]);
        Cmlog::whereKey($log->id)->update(['created_at' => $utc]);
    }

    public function test_today_shows_the_time_and_older_entries_the_date_too()
    {
        $this->entry('Provisioning started.', '2026-10-09 10:30:00');
        $this->entry('Provisioning completed.', '2026-10-07 20:00:00');

        Livewire::test(ProvisioningLog::class)
            ->assertSee('13:30:00 Provisioning started.')
            ->assertSee('07.10 23:00:00 Provisioning completed.');
    }

    public function test_the_time_zone_is_named()
    {
        Livewire::test(ProvisioningLog::class)->assertSee('Europe/Moscow');
    }

    public function test_the_log_refreshes_itself()
    {
        $this->assertStringContainsString('wire:poll', Livewire::test(ProvisioningLog::class)->payload['effects']['html']);
    }

    public function test_errors_stand_out_and_the_newest_comes_first()
    {
        $this->entry('Older entry', '2026-10-09 09:00:00');
        $this->entry('Error during dd. Return code 1.', '2026-10-09 10:00:00', 'error');

        $html = Livewire::test(ProvisioningLog::class)->payload['effects']['html'];
        $this->assertMatchesRegularExpression('/<tr[^>]*bg-red-100[^>]*>\s*<td[^>]*>GE0\/0\/4<\/td>\s*<td[^>]*>1000000000000d01<\/td>\s*<td[^>]*>13:00:00 Error during dd/s', $html);
        $this->assertLessThan(strpos($html, 'Older entry'), strpos($html, 'Error during dd'));
    }

    public function test_the_dashboard_embeds_the_live_log()
    {
        $this->entry('Provisioning started.', '2026-10-09 10:30:00');

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertOk()->assertSeeLivewire('provisioning-log')->assertSee('13:30:00 Provisioning started.');
    }
}
