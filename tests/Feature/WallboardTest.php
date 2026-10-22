<?php

namespace Tests\Feature;

use App\Http\Livewire\WallboardSettings;
use App\Models\Cm;
use App\Models\User;
use App\Models\UsbDiagnosticReport;
use App\Services\Wallboard;
use App\Support\ModuleDiagnostics;
use App\Support\WallboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

class WallboardTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_snapshot_uses_the_ports_map_and_shared_poll_without_exposing_secrets()
    {
        $cm = $this->stand();
        $cm->update(['diagnostics_token' => 'private-run-token']);
        // A disconnected historical run must not inflate ON STAND.
        Cm::create(['serial' => '10000000000000ff', 'mac' => 'e4:5f:01:00:00:ff', 'phase' => 'done']);
        $snapshot = app(WallboardSnapshot::class)->current();
        $calls = count($this->fake->walks);
        app(WallboardSnapshot::class)->current();
        $this->assertCount($calls, $this->fake->walks, 'the display reuses the Ports cache');
        $this->assertSame([$cm->serial], array_column($snapshot['modules'], 'serial'));
        $this->assertSame(1, $snapshot['counts']['failed']);
        $this->assertSame('sw01 GE0/0/3', $snapshot['modules'][0]['port']);
        $this->assertStringNotContainsString('private-run-token', json_encode($snapshot));
        $this->assertArrayNotHasKey('config', $snapshot['switches'][0]);
        $this->assertArrayNotHasKey('storage_diagnostics', $snapshot['modules'][0]);
        $this->assertSame('failed', $cm->fresh()->phase, 'a snapshot does not restart or alter a run');
    }

    public function test_a_display_takes_a_snapshot_up_to_fifteen_seconds_old_and_polls_after_that()
    {
        $this->stand();
        app(WallboardSnapshot::class)->current();
        $first = count($this->fake->walks);
        $this->assertGreaterThan(0, $first);
        $this->travel(10)->seconds();   // the Ports page would ask the switch again after 5
        app(WallboardSnapshot::class)->current();
        $this->assertCount($first, $this->fake->walks, 'ten seconds old is good enough for a display');
        $this->travel(10)->seconds();
        app(WallboardSnapshot::class)->current();
        $this->assertGreaterThan($first, count($this->fake->walks), 'past the window the display asks the switch itself');
    }

    public function test_switches_that_never_answered_do_not_empty_the_board()
    {
        $cm = $this->stand();
        $cm->update(['polled_at' => now()]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->fake->timeout = true;
        $data = app(WallboardSnapshot::class)->current();
        $this->assertSame([$cm->serial], array_column($data['modules'], 'serial'));
        $this->assertFalse($data['switches'][0]['answering']);
    }

    public function test_without_switches_only_current_reports_or_recent_runs_are_displayed()
    {
        $old = Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'write', 'provisioning_started_at' => now()->subDays(2)]);
        $current = Cm::create(['serial' => '1000000000000002', 'mac' => 'e4:5f:01:00:00:02', 'phase' => 'done', 'polled_at' => now()]);
        $data = app(WallboardSnapshot::class)->current();
        $this->assertSame([$current->serial], array_column($data['modules'], 'serial'));
        $this->assertSame([], $data['switches']);
        $this->assertSame('write', $old->fresh()->phase);
    }

    public function test_the_command_keeps_an_atomic_world_readable_file_for_the_display_user()
    {
        $directory = sys_get_temp_dir().'/wallboard-'.uniqid();
        mkdir($directory);
        try {
            $file = $directory.'/snapshot.json';
            $this->artisan('wallboard:snapshot', ['--output' => $file])->assertSuccessful();
            $data = json_decode(file_get_contents($file), true);
            $this->assertSame(1, $data['version']);
            $this->assertIsArray($data['modules']);
            $this->assertSame(0644, fileperms($file) & 0777, 'the display runs as another user and only reads it');
            $this->assertSame([basename($file)], array_values(array_diff(scandir($directory), ['.', '..'])), 'no temporary file is left behind');
            $this->artisan('wallboard:snapshot', ['--output' => 'relative.json'])->assertFailed();
            $this->artisan('wallboard:snapshot', ['--output' => $directory.'/missing/snapshot.json'])->assertFailed();
        } finally {
            array_map('unlink', glob($directory.'/{,.}*[!.]*', GLOB_BRACE));
            rmdir($directory);
        }
    }

    public function test_local_command_outputs_json_and_no_network_route_is_added()
    {
        $this->artisan('wallboard:snapshot')->assertSuccessful();
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode('\n');
        $this->assertStringNotContainsString('wallboard', $routes);
    }

    public function test_usb_only_observation_is_separate_and_diagnostics_remain_a_dated_bounded_snapshot()
    {
        $report = ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=discovery\nserial=1000000000000003\ntarget=/dev/mmcblk0\nblock=missing\ncontroller=present\nthrottled=0x0\n[kernel]\nprivate raw line\n");
        $report['source'] = 'usb';
        UsbDiagnosticReport::create(['serial' => '1000000000000003', 'boot_id' => 'boot', 'event_id' => 'event',
            'usb_path' => '/dev/ttyACM0', 'report' => $report, 'received_at' => now()->subSeconds(18)]);
        $data = app(WallboardSnapshot::class)->current();
        $module = $data['modules'][0];
        $this->assertFalse($module['registered']);
        $this->assertSame(0, $data['counts']['failed']);
        $this->assertSame(1, $data['counts']['unknown']);
        $this->assertSame('USB', $module['diagnostics']['source']);
        $this->assertSame(18, $data['generated_at'] - $module['diagnostics']['received_at']);
        $this->assertStringNotContainsString('private raw line', json_encode($module));
        $this->assertSame(0, Cm::count(), 'an observation does not register or provision a module');
    }

    public function test_settings_require_login_validate_all_fields_and_send_only_the_fixed_schema()
    {
        Livewire::test(WallboardSettings::class)->call('save')->assertForbidden();
        $service = $this->mock(Wallboard::class);
        $service->shouldReceive('settings')->andReturn(Wallboard::DEFAULTS);
        $service->shouldReceive('status')->andReturn(['state' => 'off', 'connected' => false]);
        $service->shouldReceive('save')->once()->with(['mode' => 'auto', 'palette' => 'warm', 'view' => 'auto', 'rotation' => 12]);
        $component = Livewire::actingAs(User::factory()->create())->test(WallboardSettings::class)
            ->set('mode', 'shell')->call('save')->assertHasErrors('mode')
            ->set('mode', 'auto')->set('rotation', 0)->call('save')->assertHasErrors('rotation')
            ->set('rotation', 12)->call('save')->assertHasNoErrors()->assertSee('Saved.');
        foreach (['warm-light', 'neutral', 'neutral-light'] as $palette) {
            $service->shouldReceive('save')->once()->with(['mode' => 'auto', 'palette' => $palette, 'view' => 'auto', 'rotation' => 12]);
            $component->set('palette', $palette)->call('save')->assertHasNoErrors();
        }
        $component->set('palette', 'invalid')->call('save')->assertHasErrors('palette');
    }
}
