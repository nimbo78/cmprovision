<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\FailureAdvice;
use App\Support\StorageDiagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class StorageDiagnosticsTest extends TestCase
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
            ->assertSee('Storage diagnostics')->assertSee('Card stuck being busy!')->assertSee('Collect again')->assertSee('Download report');
    }

    public function test_a_snapshot_does_not_change_provisioning_phase_or_progress()
    {
        $this->start(false);
        $before = $this->cm();
        $this->upload($this->snapshot('startup', 'present', ''))->assertNoContent();
        $after = $this->cm();
        $this->assertSame([$before->phase, $before->timeline, $before->progress_bytes], [$after->phase, $after->timeline, $after->progress_bytes]);
        $this->assertSame([], StorageDiagnostics::summary($after->storage_diagnostics)['issues']);
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
        $this->upload(str_repeat('x', StorageDiagnostics::MAX_BYTES + 1))->assertStatus(413);
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
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('collectStorageDiagnostics')
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
            Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('collectStorageDiagnostics');
            $this->assertNull($cm->fresh()->diagnostics_requested_at);
        }
    }

    public function test_reports_are_escaped_and_download_does_not_expose_run_token()
    {
        $cm = $this->onlineSnapshot();
        $evil = '<script>alert(1)</script>';
        $this->upload(str_replace('kernel=6.18.32-v8', 'kernel='.$evil, $this->snapshot()))->assertNoContent();
        $this->actingAs(User::factory()->create())->get('/cms/'.self::SERIAL)->assertOk()->assertSee($evil)->assertDontSee($evil, false);
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->call('downloadStorageDiagnostics')
            ->assertFileDownloaded('cm-'.$cm->id.'-storage-diagnostics.txt');
        $this->assertStringNotContainsString($cm->diagnostics_token, StorageDiagnostics::text($cm->fresh()));
    }

    public function test_power_wear_and_recovered_busy_warnings_are_not_reported_as_confirmed_failed_emmc()
    {
        $this->start(false);
        $report = StorageDiagnostics::parse($this->snapshot('startup', 'present'), $this->cm());
        $summary = StorageDiagnostics::summary($report);
        $this->assertSame('warning', $summary['severity']);
        $this->assertSame('MMC initialization errors were reported', $summary['title']);
        $report['facts']['throttled'] = '0x10000';
        $report['facts']['life_time'] = '0x01 0x0b';
        $report['facts']['pre_eol_info'] = '0x03';
        $this->assertSame(['mmc_busy', 'undervoltage', 'emmc_wear'], array_column(StorageDiagnostics::summary($report)['issues'], 'code'));
        $report['facts']['throttled'] = '0x20002';
        $report['facts']['life_time'] = '0x00 0x00';
        $report['facts']['pre_eol_info'] = 'unknown';
        $this->assertSame(['mmc_busy'], array_column(StorageDiagnostics::summary($report)['issues'], 'code'));
    }

    public function test_errors_from_another_mmc_host_do_not_blame_the_selected_storage()
    {
        $this->start(false);
        $report = StorageDiagnostics::parse($this->snapshot('startup', 'present',
            "[3.7] mmc1: Card stuck being busy!\n[4.0] I/O error, dev sda, sector 1"), $this->cm());
        $this->assertSame([], StorageDiagnostics::summary($report)['issues']);
    }

    public function test_failure_diagnosis_is_kept_in_history_once_even_after_the_next_run()
    {
        $this->start();
        $this->upload($this->snapshot())->assertNoContent();
        $this->upload($this->snapshot())->assertNoContent();
        $this->start(false);
        $this->assertSame(1, \App\Models\Cmlog::where('cm', self::SERIAL)->where('msg', 'like', 'Storage diagnostics:%')->count());
    }

    public function test_generic_network_write_failure_keeps_its_original_advice_despite_power_warning()
    {
        $cm = $this->onlineSnapshot();
        $cm->update(['phase_detail' => 'Error during dd. Return code 1. Diagnosis: the connection to the provisioning server was closed before the download finished.']);
        $this->assertStringContainsString('connection to the provisioning server', FailureAdvice::for($cm)['advice']);
    }
}
