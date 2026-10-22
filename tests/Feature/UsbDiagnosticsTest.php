<?php

namespace Tests\Feature;

use App\Http\Livewire\UsbDiagnostics as Page;
use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Models\User;
use App\Services\UsbDiagnostics;
use App\Support\ModuleDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class UsbDiagnosticsTest extends TestCase
{
    use RefreshDatabase;
    const SERIAL = '100000002b205d97';
    const BOOT = '11111111-2222-3333-4444-555555555555';
    const EVENT = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    protected function metadata(array $over = []): array
    {
        return array_merge(['serial' => self::SERIAL, 'descriptor_serial' => self::SERIAL, 'boot_id' => self::BOOT,
            'event_id' => self::EVENT, 'context' => 'discovery', 'age' => 90, 'collected' => 0, 'token' => '-'], $over);
    }

    protected function bytes(array $m): string
    {
        return "CMPROVISION_DIAGNOSTICS_V1\nserial=".self::SERIAL."\nboot_id=".$m['boot_id']."\nevent_id=".$m['event_id']
            ."\ncontext=".$m['context']."\ntarget=/dev/mmcblk0\nblock=present\ncontroller=present\nhost=mmc0\nthrottled=0x0\n"
            ."mac=e4:5f:01:62:9f:d3\nnetwork_stage=waiting_link\nethernet=present\neth_carrier=0\nuptime_seconds=120\n[kernel]\n";
    }

    protected function input(array $m, ?string $bytes = null): array
    {
        return ['operation' => 'accept', 'status' => $m, 'report' => base64_encode($bytes ?? $this->bytes($m)),
            'token' => $m['token'], 'usb_path' => '1-1.2'];
    }

    protected function cm(): Cm
    {
        return Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'failed',
            'provisioning_storage' => '/dev/mmcblk0', 'timeline' => [], 'progress_bytes' => 512,
            'diagnostics_token' => str_repeat('a', 40), 'polled_at' => now()]);
    }

    public function test_usb_waits_for_ethernet_and_does_not_duplicate_a_delivered_report()
    {
        $receiver = app(UsbDiagnostics::class);
        $this->assertSame('wait', $receiver->status($this->metadata(['age' => 89]))['action']);
        $this->assertSame('collect', $receiver->status($this->metadata())['action']);
        $cm = $this->cm();
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'startup']);
        $cm->update(['storage_diagnostics' => ModuleDiagnostics::parse($this->bytes($m), $cm) + ['source' => 'ethernet'], 'storage_diagnostics_at' => now()]);
        $this->assertSame('skip', $receiver->status($m)['action']);
        $this->assertSame('skip', $receiver->status($this->metadata(['token' => $cm->diagnostics_token, 'context' => 'idle', 'event_id' => '-']))['action']);
    }

    public function test_a_new_board_gets_a_report_without_becoming_a_provisioning_run()
    {
        $receiver = app(UsbDiagnostics::class);
        $m = $this->metadata();
        $result = $receiver->accept($this->input($m));
        $this->assertSame('stored', $result['action']);
        $this->assertSame(0, Cm::count());
        $row = UsbDiagnosticReport::sole();
        $this->assertSame(['usb', 'discovery'], [$row->report['source'], $row->report['context']]);
        $this->assertSame('ignored', $receiver->accept($this->input($m))['action']);
        $this->assertSame(1, UsbDiagnosticReport::count());
    }

    public function test_an_old_run_token_cannot_replace_the_current_run()
    {
        $cm = $this->cm();
        $m = $this->metadata(['token' => str_repeat('b', 40), 'context' => 'failure']);
        $this->assertSame('ignored', app(UsbDiagnostics::class)->accept($this->input($m))['action']);
        $this->assertNull($cm->fresh()->storage_diagnostics);
        $this->assertSame(0, UsbDiagnosticReport::count());
    }

    public function test_current_run_fallback_changes_only_diagnostics_not_flash_progress_or_result()
    {
        $cm = $this->cm();
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'failure']);
        app(UsbDiagnostics::class)->accept($this->input($m));
        $after = $cm->fresh();
        $this->assertSame([$cm->phase, $cm->progress_bytes, $cm->timeline], [$after->phase, $after->progress_bytes, $after->timeline]);
        $this->assertSame('usb', $after->storage_diagnostics['source']);
        $this->assertStringNotContainsString($cm->diagnostics_token, json_encode(UsbDiagnosticReport::sole()->toArray()));
    }

    public function test_ethernet_arriving_during_the_usb_transfer_wins()
    {
        $cm = $this->cm();
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'startup']);
        $receiver = app(UsbDiagnostics::class);
        $this->assertSame('collect', $receiver->status($m)['action']);
        $cm->update(['storage_diagnostics' => ModuleDiagnostics::parse($this->bytes($m), $cm) + ['source' => 'ethernet'], 'storage_diagnostics_at' => now()]);
        $this->assertSame('ignored', $receiver->accept($this->input($m))['action']);
        $this->assertSame('ethernet', $cm->fresh()->storage_diagnostics['source']);
        $this->assertSame(0, UsbDiagnosticReport::count());
    }

    public function test_a_pre_registration_report_does_not_overwrite_a_previous_boots_cm_record()
    {
        $cm = $this->cm();
        app(UsbDiagnostics::class)->accept($this->input($this->metadata()));
        $this->assertSame(1, UsbDiagnosticReport::count());
        $this->assertNull($cm->fresh()->storage_diagnostics);
        $this->assertSame('failed', $cm->fresh()->phase);
    }

    public function test_usb_can_complete_a_timed_out_manual_request_after_network_heartbeats_stop()
    {
        $cm = $this->cm();
        $cm->update(['diagnostics_requested_at' => now()->subSeconds(95), 'polled_at' => now()->subMinutes(5)]);
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'idle', 'event_id' => '-']);
        $receiver = app(UsbDiagnostics::class);
        $decision = $receiver->status($m);
        $this->assertSame(['collect', 'manual'], [$decision['action'], $decision['context']]);
        $fresh = array_merge($m, ['event_id' => $decision['event_id'], 'context' => 'manual']);
        $receiver->accept($this->input($m, $this->bytes($fresh)));
        $this->assertNull($cm->fresh()->diagnostics_requested_at);
        $this->assertSame('manual', $cm->fresh()->storage_diagnostics['context']);
    }

    public function test_mismatched_descriptors_boots_events_or_contexts_are_rejected()
    {
        $receiver = app(UsbDiagnostics::class);
        $m = $this->metadata();
        $bad = [
            $this->input($this->metadata(['descriptor_serial' => '1000000000000001'])),
            $this->input($m, str_replace(self::BOOT, self::EVENT, $this->bytes($m))),
            $this->input($m, str_replace(self::EVENT, self::BOOT, $this->bytes($m))),
            $this->input($m, str_replace('context=discovery', 'context=failure', $this->bytes($m))),
            $this->input($m, str_repeat('x', ModuleDiagnostics::MAX_BYTES + 1)),
        ];
        foreach ($bad as $input) {
            try { $receiver->accept($input); $this->fail('Untrusted identity accepted'); }
            catch (InvalidArgumentException $expected) {}
        }
        $this->assertSame(0, UsbDiagnosticReport::count());
        $this->assertSame(0, Cm::count());
    }

    public function test_usb_reports_require_login_and_can_be_viewed_and_exported_without_a_cm()
    {
        app(UsbDiagnostics::class)->accept($this->input($this->metadata()));
        $row = UsbDiagnosticReport::sole();
        $this->get('/diagnostics')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/diagnostics/'.$row->id)->assertOk()
            ->assertSee(self::SERIAL)->assertSee('via USB')->assertSee('No provisioning record yet.');
        Livewire::test(Page::class)->call('download', $row->id)->assertFileDownloaded('usb-diagnostics-'.$row->id.'.txt');
        $this->assertSame(0, Cm::count());
    }

    public function test_observing_usb_does_not_contend_with_the_operation_lock_or_change_flash_state()
    {
        $cm = $this->cm();
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'failure']);
        $reply = \App\Services\ModuleOperationLock::run([$cm->serial], fn () => app(UsbDiagnostics::class)->accept($this->input($m)));
        $this->assertSame('stored', $reply['action']);
        $this->assertSame('failed', $cm->fresh()->phase);
    }

    public function test_a_late_usb_event_cannot_replace_a_newer_usb_snapshot_in_the_same_boot()
    {
        $cm = $this->cm();
        $m = $this->metadata(['token' => $cm->diagnostics_token, 'context' => 'failure', 'collected' => 100]);
        $new = $m; $new['event_id'] = self::BOOT;
        $report = ModuleDiagnostics::parse(str_replace('uptime_seconds=120', 'uptime_seconds=150', $this->bytes($new)), $cm);
        $cm->update(['storage_diagnostics' => $report + ['source' => 'usb'], 'storage_diagnostics_at' => now()]);
        $this->assertSame('skip', app(UsbDiagnostics::class)->status($m)['action']);
        $this->assertSame('ignored', app(UsbDiagnostics::class)->accept($this->input($m))['action']);
        $this->assertSame(self::BOOT, $cm->fresh()->storage_diagnostics['facts']['event_id']);
    }

}
