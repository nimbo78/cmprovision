<?php

namespace Tests\Feature;

use App\Http\Livewire\Firmwares;
use App\Http\Livewire\Projects;
use App\Models\Setting;
use App\Services\FirmwareUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class FirmwarePagesTest extends TestCase
{
    use RefreshDatabase;

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprovision-fwpages-'.uniqid();
        config(['cmprovision.firmware_dir' => $this->dir]);
        config(['cmprovision.system_firmware_dir' => $this->dir.'-none']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function fakeGithubWith($name, $content)
    {
        Http::fake(function (Request $request) use ($name, $content) {
            if ($request->url() === FirmwareUpdater::GITHUB_CONTENTS.'/latest') {
                return Http::response([[
                    'name' => $name, 'type' => 'file', 'size' => strlen($content),
                    'sha' => FirmwareUpdater::gitBlobSha1($content),
                    'download_url' => "https://raw.githubusercontent.com/x/$name",
                ]], 200);
            }
            if ($request->url() === FirmwareUpdater::GITHUB_CONTENTS.'/default') {
                return Http::response([], 200);
            }
            return Http::response($content, 200);
        });
    }

    public function test_firmware_page_button_downloads_new_images_and_reports_what_was_added()
    {
        $this->fakeGithubWith('pieeprom-2026-09-23.bin', 'img');

        Livewire::test(Firmwares::class)
            ->call('update')
            ->assertSee('Added 1 new image')
            ->assertSee('latest/pieeprom-2026-09-23.bin')
            ->assertSee('pieeprom-2026-09-23.bin');

        $this->assertFileExists($this->dir.'/latest/pieeprom-2026-09-23.bin');
        $this->assertNotNull(Setting::find('firmware_last_update'));
    }

    public function test_firmware_page_reports_errors_and_the_time_of_the_last_successful_check()
    {
        Http::fake(fn () => Http::response('', 503));

        Livewire::test(Firmwares::class)
            ->call('update')
            ->assertSee('GitHub listing of default failed: HTTP 503');

        $this->assertNull(Setting::find('firmware_last_update'));
    }

    public function test_project_form_offers_images_grouped_by_channel()
    {
        foreach (['default' => 'pieeprom-2026-05-17.bin', 'latest' => 'pieeprom-2026-09-23.bin', 'stable' => 'pieeprom-2023-01-11.bin'] as $channel => $name) {
            mkdir($this->dir.'/'.$channel, 0755, true);
            file_put_contents($this->dir.'/'.$channel.'/'.$name, 'x');
        }

        Livewire::test(Projects::class)
            ->call('create')
            ->assertSeeHtmlInOrder([
                '<optgroup label="default',
                'value="default/pieeprom-2026-05-17.bin"',
                '<optgroup label="latest',
                'value="latest/pieeprom-2026-09-23.bin"',
                '<optgroup label="stable',
                'value="stable/pieeprom-2023-01-11.bin"',
            ]);
    }

    public function test_artisan_command_updates_the_store()
    {
        $this->fakeGithubWith('pieeprom-2026-09-23.bin', 'img');

        $this->artisan('firmware:update')
            ->expectsOutput('Added latest/pieeprom-2026-09-23.bin')
            ->assertExitCode(0);

        $this->assertFileExists($this->dir.'/latest/pieeprom-2026-09-23.bin');
    }

    public function test_artisan_command_fails_when_a_source_is_unreachable()
    {
        Http::fake(fn () => Http::response('', 503));

        $this->artisan('firmware:update')->assertExitCode(1);
    }
}
