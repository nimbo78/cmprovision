<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Setting;
use App\Support\FpmsPanelSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Exercise the real stdin protocol and persisted settings across the panel process boundary. */
class FpmsAppearanceCommandTest extends TestCase
{
    private string $directory;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cmprovision-appearance-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->database = $this->directory.'/database.sqlite');
        config(['database.connections.sqlite.database' => $this->database]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function request(array $request): array
    {
        $process = new Process([PHP_BINARY, 'artisan', 'station:power', '--json'], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->database,
            'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'NO_COLOR' => '1',
        ], json_encode($request, JSON_THROW_ON_ERROR), 15);
        $process->mustRun();
        return json_decode(trim($process->getOutput()), true, 16, JSON_THROW_ON_ERROR);
    }

    public function test_appearance_query_reads_the_web_setting_without_creating_settings(): void
    {
        FpmsPanelSettings::save('classic', 'whole', 'auto');
        $before = Setting::orderBy('key')->get()->toArray();
        $response = $this->request(['operation' => 'appearance']);
        $this->assertTrue($response['ok']);
        $this->assertSame('appearance', $response['result']['stage']);
        $this->assertSame('auto', $response['result']['mode']);
        $this->assertIsInt($response['result']['observed_at']);
        $this->assertSame($before, Setting::orderBy('key')->get()->toArray());
    }

    public function test_each_mode_persists_without_changing_layout_ports_hdmi_or_running_modules(): void
    {
        FpmsPanelSettings::save('classic', 'whole', 'auto');
        Setting::updateOrCreate(['key' => 'hdmi_color_mode'], ['value' => 'auto']);
        $cm = Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'write']);
        foreach (['light', 'dark', 'auto'] as $mode) {
            $response = $this->request(['operation' => 'set-appearance', 'mode' => $mode]);
            $this->assertTrue($response['ok']);
            $this->assertSame($mode, $response['result']['mode']);
            $this->assertSame(['theme' => 'classic', 'ports_map' => 'whole', 'color_mode' => $mode, 'screen_timeout' => 60], FpmsPanelSettings::get());
            $this->assertSame('auto', Setting::find('hdmi_color_mode')->value);
            $this->assertSame('write', $cm->fresh()->phase);
        }
    }

    public function test_invalid_modes_and_extra_power_fields_never_change_settings(): void
    {
        FpmsPanelSettings::save('new', 'pages', 'auto');
        $before = Setting::orderBy('key')->get()->toArray();
        $requests = array_map(fn ($mode) => ['operation' => 'set-appearance', 'mode' => $mode],
            ['blue', 'dark;reboot', '', null, false, 1, [], ['light']]);
        $requests[] = ['operation' => 'set-appearance'];
        $requests[] = ['operation' => 'set-appearance', 'mode' => 'light', 'action' => 'reboot'];
        $requests[] = ['operation' => 'appearance', 'action' => 'poweroff'];
        foreach ($requests as $request) {
            $this->assertFalse($this->request($request)['ok']);
            $this->assertSame($before, Setting::orderBy('key')->get()->toArray());
        }
    }

    public function test_web_form_reopens_with_the_mode_selected_on_the_panel(): void
    {
        $this->assertTrue($this->request(['operation' => 'set-appearance', 'mode' => 'light'])['ok']);
        $component = app(\App\Http\Livewire\FpmsSettings::class);
        $component->mount(app(\App\Services\Fpms::class));
        $this->assertSame('light', $component->color_mode);
        $this->assertSame('new', $component->theme);
    }

    public function test_panel_and_web_share_the_screen_timeout_and_reject_invalid_values(): void
    {
        $this->assertSame(60, $this->request(['operation' => 'screen-timeout'])['result']['seconds']);
        FpmsPanelSettings::save('classic', 'whole', 'auto');
        foreach ([15, 30, 60, 120, 300, 600, 1800, 3600, 0] as $seconds) {
            $response = $this->request(['operation' => 'set-screen-timeout', 'seconds' => $seconds]);
            $this->assertTrue($response['ok']);
            $this->assertSame($seconds, $response['result']['seconds']);
            $this->assertIsInt($response['result']['observed_at']);
        }
        foreach ([-1, 61, 3601, true, null, '60', []] as $invalid) {
            $this->assertFalse($this->request(['operation' => 'set-screen-timeout', 'seconds' => $invalid])['ok']);
        }
        $this->assertFalse($this->request(['operation' => 'set-screen-timeout', 'seconds' => 60, 'action' => 'reboot'])['ok']);
        $values = FpmsPanelSettings::get();
        $this->assertSame(0, $values['screen_timeout']);
        $this->assertSame('auto', $values['color_mode']);
        $component = app(\App\Http\Livewire\FpmsSettings::class);
        $component->mount(app(\App\Services\Fpms::class));
        $this->assertSame(0, $component->screen_timeout);
    }
}
