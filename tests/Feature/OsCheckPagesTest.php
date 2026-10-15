<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Http\Livewire\Cms;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Setting;
use App\Services\Notifier;
use App\Support\FailureAdvice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** What the pages and the messages say about the OS check (2.6.0) */
class OsCheckPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
        config(['app.display_timezone' => 'Europe/Moscow']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function module(array $attributes)
    {
        return Cm::create(array_merge(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3',
            'provisioning_started_at' => now()->subMinutes(8)], $attributes));
    }

    public function test_the_card_shows_what_the_new_system_reported()
    {
        $cm = $this->module(['phase' => 'done', 'os_check' => 'passed', 'hostname' => 'wlanpi-a1b', 'os_state' => 'degraded',
            'os_failed_units' => 'firefox-setup.service', 'os_checked_at' => now(), 'provisioning_complete_at' => now(),
            'os_check_output' => "=== first boot script 'services'\nactive\n"]);

        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('OS check')->assertSee('passed')->assertSee('wlanpi-a1b')->assertSee('systemd degraded')
            ->assertSeeHtml('failed units: <span class="font-mono">firefox-setup.service</span>')->assertSee('First boot (OS check)')
            ->assertSee("=== first boot script 'services'");
    }

    public function test_the_bench_row_names_the_new_system_the_module_reported_from()
    {
        // the new system does not ask the server, so there is no Identify; the card still knows where the module went
        $text = 'in its new system wlanpi-a1b, reported at 16.10.2026 13:00:00: from there the module does not ask the server, so there is no Identify';
        $cm = $this->module(['phase' => 'done', 'os_check' => 'passed', 'hostname' => 'wlanpi-a1b', 'os_checked_at' => now(),
            'provisioning_complete_at' => now()]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee($text)->assertDontSee('not known: the module does not ask');

        $cm->update(['phase' => 'failed', 'os_check' => 'failed', 'phase_detail' => "OS check failed: first boot script 'services' exited 3"]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee($text);

        $cm->update(['os_check' => null, 'os_checked_at' => null, 'hostname' => null]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('not known: the module does not ask the server');
    }

    public function test_the_card_says_a_module_waits_for_its_new_system_or_was_not_checked()
    {
        $cm = $this->module(['phase' => 'firstboot', 'os_check' => 'waiting', 'phase_started_at' => now(), 'progress_updated_at' => now()]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('waiting for the new system since 13:00:00');

        $cm->update(['phase' => 'done', 'os_check' => 'skipped']);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('skipped: the module booted over USB')
            ->assertDontSee('First boot (OS check)');
    }

    public static function failures(): array
    {
        return [
            'did not start' => ['The new system did not start: the module came back to network boot.', 'BOOT_ORDER'],
            'no report' => [Cm::OS_NO_REPORT, '/var/log/cmprovision-firstboot.log'],
            'the check failed' => ["OS check failed: first boot script 'services' exited 3", 'USB'],
        ];
    }

    #[DataProvider('failures')]
    public function test_advice_for_each_way_the_check_fails($reason, $hint)
    {
        $cm = $this->module(['phase' => 'failed', 'phase_detail' => $reason, 'os_check' => 'failed', 'timeline' => [
            ['phase' => 'firstboot', 'detail' => null, 'at' => now()->getTimestamp() - 60],
            ['phase' => 'failed', 'detail' => $reason, 'at' => now()->getTimestamp()]]]);

        $advice = FailureAdvice::for($cm);

        $this->assertSame('Checking the OS', $advice['step']);
        $this->assertStringContainsString($hint, $advice['advice']);
    }

    public function test_advice_when_the_check_could_not_go_into_the_system()
    {
        $cm = $this->module(['phase' => 'failed', 'phase_detail' => 'Error during postinstall. Return code 255.', 'timeline' => [
            ['phase' => 'postinstall', 'detail' => 'Installing the OS check', 'at' => now()->getTimestamp() - 5],
            ['phase' => 'failed', 'detail' => 'Error during postinstall. Return code 255.', 'at' => now()->getTimestamp()]]]);

        $advice = FailureAdvice::for($cm);

        $this->assertSame('Post-install: Installing the OS check', $advice['step']);
        $this->assertStringContainsString('root file system (ext4)', $advice['advice']);
    }

    public function test_the_cms_list_shows_and_finds_the_host_name()
    {
        $this->module(['phase' => 'done', 'hostname' => 'wlanpi-a1b']);
        Cm::create(['serial' => '10000000aaaa0002', 'mac' => 'e4:5f:01:00:00:02', 'phase' => 'done', 'hostname' => 'wlanpi-c3d']);

        Livewire::test(Cms::class)->set('projectId', 0)->assertSee('Host name')->assertSee('wlanpi-a1b')
            ->set('search', 'A1B')->assertSee('10000000e611110c')->assertDontSee('10000000aaaa0002');
    }

    public function test_messages_name_the_host()
    {
        Sleep::fake();
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => true]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        NotificationChannel::create(['name' => 'collector', 'type' => 'webhook', 'enabled' => true,
            'settings' => ['url' => 'https://collector.line.local/x'], 'events' => ['completed']]);
        Http::fake();
        $cm = $this->module(['phase' => 'done', 'hostname' => 'wlanpi-a1b', 'os_state' => 'degraded', 'provisioning_complete_at' => now()]);

        $this->assertSame('10000000e611110c · e4:5f:01:62:9f:d3 · wlanpi-a1b · 8:00', (new Notifier)->moduleText($cm, 'completed'));
        (new Notifier)->notify($cm, 'completed');

        Http::assertSent(function (Request $r) {
            return $r['hostname'] === 'wlanpi-a1b' && $r['os_state'] === 'degraded';
        });
    }
}
