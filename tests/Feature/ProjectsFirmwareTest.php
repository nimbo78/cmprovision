<?php

namespace Tests\Feature;

use App\Http\Livewire\Firmwares;
use App\Http\Livewire\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The firmware lists are plain data for Livewire 3, and a project only takes a file of the firmware
 * store: the name comes from the browser, so it must not reach other files of the server.
 */
class ProjectsFirmwareTest extends TestCase
{
    use RefreshDatabase;

    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/fw-'.uniqid();
        mkdir($this->dir.'/default', 0755, true);
        file_put_contents($this->dir.'/default/pieeprom-2026-09-23.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n"));
        file_put_contents($this->dir.'/outside.bin', ProjectActivationTest::eepromImage("[all]\nSECRET=1\n"));
        config(['cmprovision.firmware_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_firmware_page_lists_the_store()
    {
        Livewire::test(Firmwares::class)->assertSee('pieeprom-2026-09-23.bin')->assertSee('default');
    }

    public function test_choosing_a_firmware_fills_in_its_settings()
    {
        Livewire::test(Projects::class)
            ->call('create')
            ->set('firmware', 'default/pieeprom-2026-09-23.bin')
            ->assertSet('eeprom_settings', "[all]\nBOOT_UART=0\n");
    }

    public function test_a_file_outside_the_store_gives_no_settings_and_cannot_be_saved()
    {
        Livewire::test(Projects::class)
            ->call('create')
            ->set('name', 'x')
            ->set('firmware', '../outside.bin')
            ->assertSet('eeprom_settings', '')
            ->call('store')
            ->assertHasErrors(['firmware']);
    }

    public function test_the_settings_reader_is_not_an_action()
    {
        $this->expectException(MethodNotFoundException::class);

        Livewire::test(Projects::class)->call('getEepromSettingsFromFirmwareFile', '../outside.bin');
    }
}
