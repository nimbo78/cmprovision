<?php

namespace Tests\Feature;

use App\Models\Firmware;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FirmwareStoreTest extends TestCase
{
    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprovision-fwstore-'.uniqid();
        config(['cmprovision.firmware_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function image($channel, $name)
    {
        if (!is_dir($this->dir.'/'.$channel))
            mkdir($this->dir.'/'.$channel, 0755, true);
        file_put_contents($this->dir.'/'.$channel.'/'.$name, 'x');
    }

    public function test_lists_current_channels_first_and_legacy_channels_that_still_have_images()
    {
        $this->image('latest', 'pieeprom-2026-09-23.bin');
        $this->image('default', 'pieeprom-2026-05-17.bin');
        $this->image('stable', 'pieeprom-2023-01-11.bin');
        $this->image('stable', 'recovery.bin');
        mkdir($this->dir.'/beta');   // empty legacy channel: nothing to offer

        $paths = array_map(fn ($f) => $f->path, Firmware::all());

        $this->assertSame([
            'default/pieeprom-2026-05-17.bin',
            'latest/pieeprom-2026-09-23.bin',
            'stable/pieeprom-2023-01-11.bin',
        ], $paths);
        $this->assertSame(['default', 'latest', 'stable'], Firmware::channels());
    }

    public function test_ignores_a_stale_file_where_a_channel_directory_should_be()
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/latest', 'stable');
        $this->image('default', 'pieeprom-2026-05-17.bin');

        $this->assertSame(['default/pieeprom-2026-05-17.bin'], array_map(fn ($f) => $f->path, Firmware::all()));
        $this->assertSame([], Firmware::allOfChannel('latest'));
    }

    public function test_store_directory_comes_from_configuration()
    {
        $this->assertSame($this->dir, Firmware::basedir());
    }
}
