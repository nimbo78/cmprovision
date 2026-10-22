<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleOperationLock;
use App\Support\FailureAdvice;
use App\Support\ModuleDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ModuleDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '100000002041816d';

    protected function start(bool $missing = true): string
    {
        if (!Project::exists())
        {
            $image = Image::forceCreate(['filename' => 'os.img.gz', 'filename_extension' => 'gz', 'filename_on_server' => 'os.gz',
                'sha256' => str_repeat('a', 64), 'uncompressed_size' => 1048576]);
            $project = Project::create(['name' => 'diagnostics', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
            Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        }
        return $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&uos=2026.10&mac=e4:5f:01:00:00:01'
            .($missing ? '' : '&storagesize=61071360'))->assertOk()->getContent();
    }

    protected function cm(): Cm
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    protected function snapshot(string $context = 'failure', string $block = 'missing', string $kernel = '[3.77] mmc0: Card stuck being busy! __mmc_poll_for_busy'): string
    {
        return "CMPROVISION_DIAGNOSTICS_V1\ncontext=$context\nserial=".self::SERIAL."\ntarget=/dev/mmcblk0\n"
            ."block=$block\ncontroller=present\nhost=mmc0\nkernel=6.18.32-v8\nmodel=Raspberry Pi Compute Module 4 Rev 1.0\n"
            ."throttled=0x0\nreadonly=0\ncard_type=MMC\n[kernel]\n$kernel\n[partitions]\n1 0 4096 ram0\n[mmc_ios]\nclock: 400000 Hz\n";
    }

    protected function upload(string $text, ?string $token = null)
    {
        return $this->post('/scriptexecute?serial='.self::SERIAL.'&diagnostics=1', [
            'diagnostics_token' => $token ?? $this->cm()->diagnostics_token,
            'diagnostics' => UploadedFile::fake()->createWithContent('snapshot.txt', $text),
        ]);
    }

    protected function onlineSnapshot(): Cm
    {
        $this->start();
        $this->upload($this->snapshot())->assertNoContent();
        $cm = $this->cm();
        $cm->update(['polled_at' => now()]);
        return $cm->fresh();
    }

    public function test_missing_storage_receives_a_collector_instead_of_a_write_script()
    {
        $script = $this->start();
        $this->assertStringContainsString('Missing eMMC/SD card.', $script);
        $this->assertStringContainsString('cmp_diag_collect()', $script);
        $this->assertStringContainsString($this->cm()->diagnostics_token, $script);
        $this->assertStringNotContainsString('write_image', $script);
        $this->assertSame('failed', $this->cm()->phase);
    }

    public function test_real_busy_case_reaches_the_card_with_evidence_and_no_definitive_hardware_verdict()
    {
        $this->onlineSnapshot();
        $advice = FailureAdvice::for($this->cm());
        $this->assertStringContainsString('MMC storage did not initialize', $advice['advice']);
        $this->assertStringContainsString('does not prove', $advice['advice']);
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()
            ->assertSee('Module diagnostics')->assertSee('Card stuck being busy!')->assertSee('Collect again')->assertSee('Download report');
    }

    public function test_a_snapshot_does_not_change_provisioning_phase_or_progress()
    {
        $this->start(false);
        $before = $this->cm();
        $this->upload($this->snapshot('startup', 'present', ''))->assertNoContent();
        $after = $this->cm();
        $this->assertSame([$before->phase, $before->timeline, $before->progress_bytes], [$after->phase, $after->timeline, $after->progress_bytes]);
        $this->assertSame([], ModuleDiagnostics::summary($after->storage_diagnostics)['issues']);
    }

    public function test_old_or_invalid_reports_cannot_overwrite_the_current_run_or_mark_it_failed()
    {
        $this->start(false);
        $oldToken = $this->cm()->diagnostics_token;
        $this->upload($this->snapshot('startup'))->assertNoContent();
        $this->start(false);
        $before = $this->cm();
        $this->assertNull($before->storage_diagnostics);
        $this->assertNotSame($oldToken, $before->diagnostics_token);
        $this->upload($this->snapshot(), $oldToken)->assertForbidden();
        $this->upload('not a report')->assertUnprocessable();
        $this->upload(str_repeat('x', ModuleDiagnostics::MAX_BYTES + 1))->assertStatus(413);
        $this->upload(str_replace(self::SERIAL, '100000002b205d97', $this->snapshot()))->assertUnprocessable();
        $this->upload(str_replace('/dev/mmcblk0', '/dev/sda', $this->snapshot()))->assertUnprocessable();
        $this->assertSame($before->phase, $this->cm()->phase);
        $this->assertNull($this->cm()->storage_diagnostics);
        $this->get('/scriptexecute?serial='.self::SERIAL.'&diagnostics=1')->assertStatus(405);
    }

    public function test_manual_request_is_delivered_only_to_current_diagnostic_capable_poll_and_cleared_by_report()
    {
        $cm = $this->onlineSnapshot();
        $this->actingAs(User::factory()->create());
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('collectModuleDiagnostics')
            ->assertSee('Waiting for the module');
        $this->assertNotNull($this->cm()->diagnostics_requested_at);
        $url = '/scriptexecute?serial='.self::SERIAL.'&identify=poll';
        $this->get($url)->assertOk()->assertContent('');
        $this->get($url.'&diagnostics_token=old')->assertOk()->assertContent('');
        $this->get($url.'&diagnostics_token='.$cm->diagnostics_token)->assertOk()->assertContent('diagnostics');
        $this->upload($this->snapshot('manual'))->assertNoContent();
        $this->assertNull($this->cm()->diagnostics_requested_at);
        $this->assertSame('manual', $this->cm()->storage_diagnostics['context']);
    }

    public function test_identify_takes_priority_and_legacy_polls_remain_compatible()
    {
        $cm = $this->onlineSnapshot();
        $cm->identify();
        $cm->update(['diagnostics_requested_at' => now()]);
        $url = '/scriptexecute?serial='.self::SERIAL.'&identify=poll';
        $this->get($url)->assertContent('identify');
        $this->get($url.'&diagnostics_token='.$cm->diagnostics_token)->assertContent('identify');
    }

    public function test_active_offline_and_installed_os_modules_cannot_be_asked_to_collect()
    {
        $this->actingAs(User::factory()->create());
        $cm = $this->onlineSnapshot();
        foreach ([['phase' => 'write'], ['phase' => 'failed', 'polled_at' => now()->subMinutes(5)],
            ['phase' => 'done', 'polled_at' => now(), 'os_checked_at' => now()]] as $state)
        {
            $cm->update($state);
            Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('collectModuleDiagnostics');
            $this->assertNull($cm->fresh()->diagnostics_requested_at);
        }
    }

    public function test_reports_are_escaped_and_download_does_not_expose_run_token()
    {
        $cm = $this->onlineSnapshot();
        $evil = '<script>alert(1)</script>';
        $this->upload(str_replace('kernel=6.18.32-v8', 'kernel='.$evil, $this->snapshot()))->assertNoContent();
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()->assertSee($evil)->assertDontSee($evil, false);
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('downloadModuleDiagnostics')
            ->assertFileDownloaded('cm-'.$cm->id.'-module-diagnostics.txt');
        $this->assertStringNotContainsString($cm->diagnostics_token, ModuleDiagnostics::text($cm->fresh()));
    }

    public function test_power_wear_and_recovered_busy_warnings_are_not_reported_as_confirmed_failed_emmc()
    {
        $this->start(false);
        $report = ModuleDiagnostics::parse($this->snapshot('startup', 'present'), $this->cm());
        $summary = ModuleDiagnostics::summary($report);
        $this->assertSame('warning', $summary['severity']);
        $this->assertSame('MMC initialization errors were reported', $summary['title']);
        $report['facts']['throttled'] = '0x10000';
        $report['facts']['life_time'] = '0x01 0x0b';
        $report['facts']['pre_eol_info'] = '0x03';
        $this->assertSame(['mmc_busy', 'undervoltage', 'emmc_wear'], array_column(ModuleDiagnostics::summary($report)['issues'], 'code'));
        $report['facts']['throttled'] = '0x20002';
        $report['facts']['life_time'] = '0x00 0x00';
        $report['facts']['pre_eol_info'] = 'unknown';
        $this->assertSame(['mmc_busy', 'throttling'], array_column(ModuleDiagnostics::summary($report)['issues'], 'code'));
    }

    public function test_errors_from_another_mmc_host_do_not_blame_the_selected_storage()
    {
        $this->start(false);
        $report = ModuleDiagnostics::parse($this->snapshot('startup', 'present',
            "[3.7] mmc1: Card stuck being busy!\n[4.0] I/O error, dev sda, sector 1"), $this->cm());
        $this->assertSame([], ModuleDiagnostics::summary($report)['issues']);
    }

    public function test_failure_diagnosis_is_kept_in_history_once_even_after_the_next_run()
    {
        $this->start();
        $this->upload($this->snapshot())->assertNoContent();
        $this->upload($this->snapshot())->assertNoContent();
        $this->start(false);
        $this->assertSame(1, \App\Models\Cmlog::where('cm', self::SERIAL)->where('msg', 'like', 'Module diagnostics:%')->count());
    }

    public function test_generic_network_write_failure_keeps_its_original_advice_despite_power_warning()
    {
        $cm = $this->onlineSnapshot();
        $cm->update(['phase_detail' => 'Error during dd. Return code 1. Diagnosis: the connection to the provisioning server was closed before the download finished.']);
        $this->assertStringContainsString('connection to the provisioning server', FailureAdvice::for($cm)['advice']);
    }

    public function test_general_findings_distinguish_temperature_memory_and_ethernet_from_storage()
    {
        $this->start(false);
        $text = str_replace('[kernel]', "temperature_mc=83000\nthermal_trip_mc=80000\nthrottled=0x80008\n"
            ."mem_total_kb=4000000\nmem_available_kb=12000\nethernet=present\neth_carrier=0\neth_duplex=half\n[kernel]",
            $this->snapshot('failure', 'present', '[9.0] Out of memory: Killed process 102 (xz)'));
        $this->upload($text)->assertNoContent();
        $summary = ModuleDiagnostics::summary($this->cm()->storage_diagnostics);
        $codes = array_column($summary['issues'], 'code');
        $this->assertSame(['thermal_trip', 'soft_temperature_limit', 'memory_pressure', 'oom', 'ethernet_link', 'ethernet_duplex'], $codes);
        $this->assertSame([], ModuleDiagnostics::summary($this->cm()->storage_diagnostics, 'storage')['issues']);
        $this->upload($text)->assertNoContent();
        $this->assertSame(6, \App\Models\Cmlog::where('cm', self::SERIAL)->where('msg', 'like', 'Module diagnostics:%')->count());
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()
            ->assertSee('83.0 °C')->assertSee('Memory')->assertSee('Link down')->assertSee('not a physical RAM test');
    }

    public function test_throttle_flags_distinguish_current_and_historical_limits_without_inventing_a_cause()
    {
        $this->start(false);
        $report = ModuleDiagnostics::parse($this->snapshot('startup', 'present', ''), $this->cm());
        foreach (['0x2' => 'CPU performance is limited now', '0x40000' => 'CPU performance was limited since boot',
            '0x8' => 'Soft temperature limit is active', '0x80000' => 'Soft temperature limit occurred since boot'] as $flags => $title)
        {
            $report['facts']['throttled'] = $flags;
            $this->assertSame($title, ModuleDiagnostics::summary($report)['title']);
        }
    }

    public function test_missing_or_malformed_telemetry_is_not_turned_into_a_healthy_zero_reading()
    {
        $this->start(false);
        $text = str_replace('[kernel]', "temperature_mc=not a temperature\nthermal_trip_mc=-1\nmem_total_kb=0\nmem_available_kb=99999999999999999999\n"
            ."eth_carrier=no\neth_speed_mbps=-1\nsecret=password\n[kernel]", $this->snapshot('startup', 'present', ''));
        $report = ModuleDiagnostics::parse($text, $this->cm());
        foreach (['temperature_mc', 'thermal_trip_mc', 'mem_total_kb', 'mem_available_kb', 'eth_carrier', 'eth_speed_mbps'] as $key)
            $this->assertSame('', $report['facts'][$key]);
        $this->assertArrayNotHasKey('secret', $report['facts']);
        $checks = array_column(ModuleDiagnostics::summary($report)['checks'], 'value', 'group');
        $this->assertSame('not reported', $checks['memory']);
        $this->assertSame('link not reported', $checks['network']);
    }

    public function test_link_counters_are_displayed_without_declaring_a_cable_fault_or_warning_on_a_100mbps_link()
    {
        $this->start(false);
        $text = str_replace('[kernel]', "ethernet=present\neth_carrier=1\neth_speed_mbps=100\neth_duplex=full\neth_rx_errors=4\neth_tx_dropped=22\nboot_mode=00000003\n[kernel]",
            $this->snapshot('startup', 'present', ''));
        $this->upload($text)->assertNoContent();
        $this->assertSame([], ModuleDiagnostics::summary($this->cm()->storage_diagnostics)['issues']);
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()
            ->assertSee('Link up · 100 Mbps · full duplex')->assertSee('USB device (rpiboot)')->assertSee('totals since interface initialization');
        $this->assertSame('Network (PXE)', ModuleDiagnostics::bootMode('00000002'));
        $this->assertSame('USB mass storage', ModuleDiagnostics::bootMode('00000004'));
        $this->assertSame('not reported', ModuleDiagnostics::bootMode('3'));
    }

    public function test_general_report_exports_eeprom_dates_without_exporting_eeprom_configuration_secrets()
    {
        $cm = $this->onlineSnapshot();
        $cm->update(['eeprom_before' => "2021/04/29 17:11:25\n", 'firmware' => 'pieeprom-2026-09-23.bin',
            'eeprom_config_before' => 'HTTP_PASSWORD=secret-do-not-export']);
        $text = ModuleDiagnostics::text($cm->fresh());
        $this->assertStringContainsString('EEPROM before: 2021-04-29', $text);
        $this->assertStringContainsString('EEPROM after: 2026-09-23', $text);
        $this->assertStringNotContainsString('secret-do-not-export', $text);
        $this->assertStringContainsString('Context: failure', $text);
    }


    public function test_a_watchdog_on_another_interface_does_not_blame_eth0()
    {
        $this->start(false);
        $report = ModuleDiagnostics::parse($this->snapshot('startup', 'present',
            '[4.2] NETDEV WATCHDOG: wlan0 (brcmfmac): transmit queue 0 timed out'), $this->cm());
        $this->assertSame([], ModuleDiagnostics::summary($report)['issues']);
        $report['sections']['kernel'] = '[4.2] NETDEV WATCHDOG: eth0 (bcmgenet): transmit queue 0 timed out';
        $this->assertSame('ethernet_timeout', ModuleDiagnostics::summary($report)['issues'][0]['code']);
    }

    public function test_a_snapshot_is_stored_while_another_operation_holds_the_module_lock()
    {
        $this->start();
        ModuleOperationLock::run([self::SERIAL], function () {
            // A PoE restart or an SSH re-provision of this module is running: telemetry must not be refused or lost.
            $this->upload($this->snapshot())->assertNoContent();
        });
        $this->assertSame('failure', $this->cm()->storage_diagnostics['context']);
    }

    public function test_the_card_requests_a_snapshot_while_another_operation_holds_the_module_lock()
    {
        $this->onlineSnapshot();
        $this->actingAs(User::factory()->create());
        ModuleOperationLock::run([self::SERIAL], function () {
            Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('collectModuleDiagnostics')->assertHasNoErrors();
        });
        $this->assertNotNull($this->cm()->diagnostics_requested_at);
    }

    public function test_a_snapshot_for_a_run_that_ended_while_it_was_parsed_is_dropped_not_written_to_the_new_run()
    {
        $this->start();
        $old = $this->cm()->diagnostics_token;
        $logged = Cmlog::count();
        $fired = false;
        // The module restarts (a new run token) right after the controller has read the row: the write is bound to
        // the token the report arrived with, so it touches nothing of the new run.
        Cm::retrieved(function (Cm $cm) use (&$fired) {
            if (!$fired)
            {
                $fired = true;
                Cm::whereKey($cm->id)->update(['diagnostics_token' => 'new-run-token']);
            }
        });
        $this->upload($this->snapshot(), $old)->assertStatus(409);
        $cm = Cm::where('serial', self::SERIAL)->firstOrFail();
        $this->assertSame('new-run-token', $cm->diagnostics_token);
        $this->assertNull($cm->storage_diagnostics);
        $this->assertSame($logged, Cmlog::count());   // a dropped snapshot writes no finding into the log either
    }

    public function test_the_api_list_of_modules_has_neither_the_run_token_nor_the_snapshot()
    {
        $this->onlineSnapshot();
        $this->assertNotNull($this->cm()->storage_diagnostics);
        Sanctum::actingAs(User::factory()->create(), ['read']);
        $row = $this->getJson('/api/cms')->assertOk()->json(0);
        $this->assertSame(self::SERIAL, $row['serial']);
        $this->assertArrayNotHasKey('diagnostics_token', $row);
        $this->assertArrayNotHasKey('storage_diagnostics', $row);
    }

}
