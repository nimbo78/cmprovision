<?php

namespace Tests\Feature;

use App\Services\FirmwareUpdater;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FirmwareUpdaterTest extends TestCase
{
    protected $dir;
    protected $systemDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprovision-fw-'.uniqid();
        $this->systemDir = $this->dir.'-system';
        config(['cmprovision.firmware_dir' => $this->dir]);
        config(['cmprovision.system_firmware_dir' => $this->systemDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->systemDir);
        parent::tearDown();
    }

    /** GitHub "contents" API entry for a file, as the updater sees it. */
    protected function entry($name, $content)
    {
        return [
            'name' => $name,
            'type' => 'file',
            'size' => strlen($content),
            'sha' => sha1("blob ".strlen($content)."\0".$content),
            'download_url' => "https://raw.githubusercontent.com/raspberrypi/rpi-eeprom/master/firmware-2711/x/$name",
        ];
    }

    protected function fakeGithub(array $channels, array $downloads)
    {
        Http::fake(function (Request $request) use ($channels, $downloads) {
            $url = $request->url();
            foreach ($channels as $channel => $entries) {
                if ($url === FirmwareUpdater::GITHUB_CONTENTS.'/'.$channel) {
                    return Http::response($entries, 200);
                }
            }
            foreach ($downloads as $name => $content) {
                if (substr($url, -strlen('/'.$name)) === '/'.$name) {
                    return Http::response($content, 200);
                }
            }
            return Http::response('not found', 404);
        });
    }

    public function test_downloads_missing_images_from_default_and_latest_channels()
    {
        $old = str_repeat("\x01", 100);
        $new = str_repeat("\x02", 120);
        $this->fakeGithub([
            'default' => [$this->entry('pieeprom-2026-05-17.bin', $old), $this->entry('recovery.bin', 'rec')],
            'latest'  => [$this->entry('pieeprom-2026-05-17.bin', $old), $this->entry('pieeprom-2026-09-23.bin', $new)],
        ], [
            'pieeprom-2026-05-17.bin' => $old,
            'pieeprom-2026-09-23.bin' => $new,
            'recovery.bin' => 'rec',
        ]);

        $result = (new FirmwareUpdater)->update();

        $this->assertSame($old, file_get_contents($this->dir.'/default/pieeprom-2026-05-17.bin'));
        $this->assertSame($old, file_get_contents($this->dir.'/latest/pieeprom-2026-05-17.bin'));
        $this->assertSame($new, file_get_contents($this->dir.'/latest/pieeprom-2026-09-23.bin'));
        $this->assertFileDoesNotExist($this->dir.'/default/recovery.bin');
        $this->assertSame(
            ['default/pieeprom-2026-05-17.bin', 'latest/pieeprom-2026-05-17.bin', 'latest/pieeprom-2026-09-23.bin'],
            $result['added']
        );
        $this->assertSame([], $result['errors']);
    }

    public function test_skips_images_already_in_the_store_without_downloading_them()
    {
        $content = str_repeat("\x03", 50);
        mkdir($this->dir.'/latest', 0755, true);
        file_put_contents($this->dir.'/latest/pieeprom-2026-05-17.bin', $content);
        $this->fakeGithub(
            ['default' => [], 'latest' => [$this->entry('pieeprom-2026-05-17.bin', $content)]],
            ['pieeprom-2026-05-17.bin' => $content]
        );

        $result = (new FirmwareUpdater)->update();

        $this->assertSame([], $result['added']);
        Http::assertNotSent(fn (Request $r) => substr($r->url(), -strlen('pieeprom-2026-05-17.bin')) === 'pieeprom-2026-05-17.bin');
    }

    public function test_rejects_a_download_whose_checksum_differs_from_the_listing()
    {
        $this->fakeGithub(
            ['default' => [$this->entry('pieeprom-2026-05-17.bin', 'expected bytes')], 'latest' => []],
            ['pieeprom-2026-05-17.bin' => 'corrupted bytes']
        );

        $result = (new FirmwareUpdater)->update();

        $this->assertFileDoesNotExist($this->dir.'/default/pieeprom-2026-05-17.bin');
        $this->assertSame([], $result['added']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('pieeprom-2026-05-17.bin', $result['errors'][0]);
        $this->assertSame([], glob($this->dir.'/default/*'), 'no partial file left behind');
    }

    public function test_replaces_a_stale_file_named_like_a_channel_directory()
    {
        // The old updater extracted the upstream "latest" symlink as a plain file holding its target.
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/latest', 'stable');
        $content = str_repeat("\x04", 40);
        $this->fakeGithub(
            ['default' => [], 'latest' => [$this->entry('pieeprom-2026-09-23.bin', $content)]],
            ['pieeprom-2026-09-23.bin' => $content]
        );

        $result = (new FirmwareUpdater)->update();

        $this->assertDirectoryExists($this->dir.'/latest');
        $this->assertSame($content, file_get_contents($this->dir.'/latest/pieeprom-2026-09-23.bin'));
        $this->assertSame(['latest/pieeprom-2026-09-23.bin'], $result['added']);
    }

    public function test_imports_images_shipped_by_the_rpi_eeprom_package_without_network()
    {
        $content = str_repeat("\x05", 60);
        mkdir($this->systemDir.'/default', 0755, true);
        file_put_contents($this->systemDir.'/default/pieeprom-2025-12-08.bin', $content);
        file_put_contents($this->systemDir.'/default/recovery.bin', 'rec');
        Http::fake(fn () => Http::response('offline', 500));

        $result = (new FirmwareUpdater)->update();

        $this->assertSame($content, file_get_contents($this->dir.'/default/pieeprom-2025-12-08.bin'));
        $this->assertFileDoesNotExist($this->dir.'/default/recovery.bin');
        $this->assertSame(['default/pieeprom-2025-12-08.bin'], $result['added']);
    }

    public function test_reports_github_being_unreachable_instead_of_throwing()
    {
        Http::fake(fn () => Http::response('', 503));

        $result = (new FirmwareUpdater)->update();

        $this->assertSame([], $result['added']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('503', $result['errors'][0]);
    }
}
