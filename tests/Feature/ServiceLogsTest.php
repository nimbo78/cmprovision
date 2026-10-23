<?php

namespace Tests\Feature;

use App\Http\Livewire\ServiceLog;
use App\Http\Livewire\Settings;
use App\Http\Livewire\WifiSettings;
use App\Models\User;
use App\Services\ServiceLogs;
use App\Services\WifiNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeWifiNetworks;
use Tests\TestCase;

/** The log windows (Settings → Status, Wi-Fi changes) follow the journal by themselves: new lines appear while
    the window is open, and the window can be paused */
class ServiceLogsTest extends TestCase
{
    use RefreshDatabase;

    protected array $asked = [];
    protected string $journal = "line one\nline two\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ServiceLogs::class, new ServiceLogs(function (array $argv) {
            $this->asked[] = $argv;
            return $this->journal;
        }));
        $this->app->instance(WifiNetworks::class, new FakeWifiNetworks);
        $this->actingAs(User::factory()->create());
    }

    public function test_every_source_is_a_fixed_journal_query_and_nothing_else_can_be_asked_for()
    {
        $logs = app(ServiceLogs::class);
        foreach (['dnsmasq' => 'cmprovision-dnsmasq', 'rpiboot' => 'cmprovision-rpiboot', 'usb-diagnostics' => 'cmprovision-usb-diagnostics',
                  'queue' => 'cmprovision-queue', 'queue-images' => 'cmprovision-queue-images'] as $key => $unit)
        {
            $this->asked = [];
            $this->assertSame($this->journal, $logs->read($key));
            $this->assertSame([['/bin/journalctl', '--boot', '-u', $unit, '-n', '1000', '--no-pager']], $this->asked, $key);
        }
        $this->asked = [];
        $logs->read('uplink-spare');
        $this->assertSame([['/bin/journalctl', '--boot', '-t', 'cmprovision-uplink', '-n', '1000', '--no-pager']], $this->asked);
        // the Wi-Fi helper's changes are read by the Wi-Fi service itself: one place knows its journal tag
        $this->assertStringContainsString("anna saved '78' (WPA2)", $logs->read('wifi'));
        foreach (['', 'nginx', '../laravel', 'cmprovision-dnsmasq; id'] as $bad)
        {
            try {
                $logs->read($bad);
                $this->fail("'$bad' is no log");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_the_laravel_log_is_its_end_not_the_whole_file()
    {
        $path = sys_get_temp_dir().'/laravel-'.uniqid().'.log';
        config(['cmprovision.laravel_log' => $path]);
        try {
            $this->assertStringContainsString('no log', app(ServiceLogs::class)->read('laravel'), 'a missing file is a sentence, not an error');
            file_put_contents($path, str_repeat('old line '.str_repeat('x', 90)."\n", 20000)."the last line\n");
            $text = app(ServiceLogs::class)->read('laravel');
            $this->assertStringEndsWith("the last line\n", $text);
            $this->assertLessThanOrEqual(ServiceLogs::FILE_BYTES + 200, strlen($text));
            $this->assertStringStartsWith('old line ', $text, 'it starts on a whole line');
        } finally {
            @unlink($path);
        }
    }

    public function test_the_window_shows_the_log_polls_every_five_seconds_and_follows_new_lines()
    {
        $component = Livewire::test(ServiceLog::class, ['source' => 'rpiboot'])
            ->assertSee('line two')
            ->assertSeeHtml('wire:poll.5s="refresh"');
        $this->journal .= "line three, a new one\n";
        $component->call('refresh')->assertSee('line three, a new one');
    }

    public function test_an_unchanged_journal_costs_no_render_and_a_paused_window_stops_polling()
    {
        $component = Livewire::test(ServiceLog::class, ['source' => 'rpiboot']);
        $hash = $component->get('hash');
        $component->call('refresh');
        $this->assertSame($hash, $component->get('hash'));
        $this->journal .= "more\n";
        $component->call('refresh');
        $this->assertNotSame($hash, $component->get('hash'));

        $component->call('togglePause')->assertSet('paused', true)->assertDontSeeHtml('wire:poll')->assertSee('Paused');
        $this->journal .= "while paused\n";
        $component->assertDontSee('while paused');
        $component->call('togglePause')->assertSet('paused', false)->assertSeeHtml('wire:poll.5s="refresh"');
    }

    public function test_the_window_cannot_be_pointed_at_another_log()
    {
        $this->expectExceptionMessage("There is no log 'nginx'");   // wrapped by the view the component is mounted in
        Livewire::test(ServiceLog::class, ['source' => 'nginx']);
    }

    public function test_settings_and_the_wifi_page_open_the_live_window_and_close_it()
    {
        Livewire::test(Settings::class)
            ->call('viewLogRpiboot')
            ->assertSet('logModalOpen', true)
            ->assertSet('logSource', 'rpiboot')
            ->assertSeeLivewire(ServiceLog::class)
            ->assertSee('line two')
            ->call('closeModal')
            ->assertSet('logModalOpen', false)
            ->assertDontSeeLivewire(ServiceLog::class);
        Livewire::test(WifiSettings::class)->call('showLog')->assertSet('logSource', 'wifi')->assertSeeLivewire(ServiceLog::class);
    }
}
