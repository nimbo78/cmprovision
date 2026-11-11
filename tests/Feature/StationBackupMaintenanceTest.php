<?php

namespace Tests\Feature;

use App\Services\StationBackupsControl;
use App\Http\Middleware\StationBackupMaintenance;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StationBackupMaintenanceTest extends TestCase
{
    private string $lock;
    private StationBackupsControl $control;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = tempnam(sys_get_temp_dir(), 'backup-mutations-');
        chmod($this->lock, 0644);
        $this->control = new class extends StationBackupsControl {
            public bool $busy = false;
            public function available(): bool { return true; }
            public function pending(): bool { return $this->busy; }
        };
        $this->app->instance(StationBackupsControl::class, $this->control);
        $this->app->instance(StationBackupMaintenance::class, new StationBackupMaintenance($this->control, $this->lock, false));
        Route::post('/backup-test-mutate', fn () => response('changed'));
        Route::get('/settings/backups/status', fn () => response('status'));
    }

    protected function tearDown(): void { @unlink($this->lock); parent::tearDown(); }

    public function test_backup_pending_blocks_mutations_but_keeps_read_only_status(): void
    {
        $this->control->busy = true;
        $this->post('/backup-test-mutate')->assertStatus(503)->assertHeader('X-Cmprovision-Maintenance', 'station-backup');
        $this->get('/settings/backups/status')->assertOk()->assertSee('status');
    }

    public function test_admitted_mutation_holds_shared_lock_until_the_response_finishes(): void
    {
        Route::post('/backup-test-lock', function () {
            $handle = fopen($this->lock, 'r');
            try { return response(flock($handle, LOCK_EX | LOCK_NB) ? 'unprotected' : 'protected'); }
            finally { fclose($handle); }
        });
        $this->post('/backup-test-lock')->assertOk()->assertSee('protected');
        $handle = fopen($this->lock, 'r');
        try { $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB)); }
        finally { fclose($handle); }
    }

    public function test_exclusive_snapshot_lock_defers_new_mutations_without_waiting(): void
    {
        $handle = fopen($this->lock, 'r');
        flock($handle, LOCK_EX);
        try { $this->post('/backup-test-mutate')->assertStatus(503); }
        finally { fclose($handle); }
    }

    public function test_backup_gate_blocks_module_admission_and_queue_reservation(): void
    {
        $this->control->busy = true;
        $called = false;
        $response = (new \App\Services\NetworkChangeGate(false))->start(function () use (&$called) { $called = true; });
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($called);
        $gate = new \App\Services\StationUpdateQueueGate(new \App\Services\StationUpdatesControl);
        $this->assertFalse($gate->reserve());
    }

    public function test_backup_cancel_can_share_a_batch_with_read_only_display_refresh_but_not_display_changes(): void
    {
        $this->control->busy = true;
        $safe = ['snapshot' => json_encode(['memo' => ['name' => 'station-backups-panel']]), 'updates' => [], 'calls' => [['method' => 'cancel', 'params' => ['a']]]];
        $poll = ['snapshot' => json_encode(['memo' => ['name' => 'fpms-settings']]), 'updates' => [], 'calls' => [['method' => '$refresh', 'params' => []]]];
        $middleware = app(StationBackupMaintenance::class);
        $request = \Illuminate\Http\Request::create('/livewire/update', 'POST', ['components' => [$safe, $poll]]);
        $this->assertSame(200, $middleware->handle($request, fn () => response('allowed'))->getStatusCode());
        $poll['updates'] = ['enabled' => true];
        $request = \Illuminate\Http\Request::create('/livewire/update', 'POST', ['components' => [$safe, $poll]]);
        $this->assertSame(503, $middleware->handle($request, fn () => response('unsafe'))->getStatusCode());
    }
}
