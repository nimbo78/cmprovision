<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupsRestoreFinalizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_restored_runtime_state_is_inert_and_missing_payloads_disable_project(): void
    {
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => '78']);
        Setting::updateOrCreate(['key' => 'usb_prep_until'], ['value' => '9999999999']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->artisan('backups:restore-finalize --deactivate-project')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull(Setting::find('active_project'));
        $this->assertSame('0', Setting::find('usb_prep_until')->value);
    }
}
