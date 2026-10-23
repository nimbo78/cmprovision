<?php

namespace Tests\Feature;

use App\Http\Livewire\UsbPrepPanel;
use App\Http\Livewire\UsbPrepSettings;
use App\Models\Cm;
use App\Models\User;
use App\Services\UsbPrep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/** The button on the Dashboard, the list of the boards it prepared, and its card in Settings → Provisioning */
class UsbPrepPagesTest extends TestCase
{
    use RefreshDatabase;

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/cmprovision-prep-pages-' . uniqid();
        mkdir($this->dir . '/default', 0755, true);
        config(['cmprovision.firmware_dir' => $this->dir]);
        file_put_contents($this->dir . '/default/pieeprom-2026-09-23.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n"));
        @unlink(public_path('uploads/pieeprom-usbprep.bin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        @unlink(public_path('uploads/pieeprom-usbprep.bin'));
        parent::tearDown();
    }

    protected function board(array $extra = []): Cm
    {
        return Cm::create(array_merge(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'done',
            'usb_prep_at' => now()->subMinutes(2), 'provisioning_complete_at' => now()->subMinute()], $extra));
    }

    public function test_the_dashboard_has_the_button_and_it_starts_and_stops_the_mode()
    {
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertOk()->assertSeeLivewire(UsbPrepPanel::class)->assertSee('Start USB prep')->assertSee('Set boards to boot from the network');

        $panel = Livewire::test(UsbPrepPanel::class)->call('start');
        $this->assertTrue(app(UsbPrep::class)->active());
        $panel->assertSee('Stop USB prep')->assertSee('on · 60 min left')->assertDontSee('Start USB prep')
            ->assertSee('Connect a board over USB')->assertSee('No board yet.');

        $panel->call('stop');
        $this->assertFalse(app(UsbPrep::class)->active());
        $panel->assertSee('Start USB prep')->assertDontSee('Connect a board over USB');
    }

    public function test_without_a_firmware_the_button_is_off_and_says_why()
    {
        File::deleteDirectory($this->dir . '/default');

        $panel = Livewire::test(UsbPrepPanel::class)->assertSee('There is no EEPROM firmware in the store')->assertSeeHtml('disabled')
            ->assertSeeHtml('aria-describedby="usb-prep-note"')->assertDontSeeHtml('title="There is no EEPROM firmware');   // the reason is on the page, not in a tooltip
        $panel->call('start');

        $this->assertFalse(app(UsbPrep::class)->active());
        $panel->assertSee('There is no EEPROM firmware in the store');
    }

    public function test_the_boards_it_prepared_are_listed_with_what_to_do_next()
    {
        $this->board();
        $this->board(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'failed', 'phase_detail' => 'Error during preinstall. Return code 1.']);
        $this->board(['serial' => '10000000e611110e', 'mac' => 'e4:5f:01:62:9f:d5', 'phase' => 'postinstall', 'provisioning_complete_at' => null]);
        $this->board(['serial' => '10000000e611110f', 'mac' => 'e4:5f:01:62:9f:d6', 'usb_prep_at' => now()->subHours(13)]);
        $this->board(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:62:9f:d7', 'usb_prep_at' => null]);

        $this->assertStringNotContainsString('e4:5f:01:62:9f:d3', Livewire::test(UsbPrepPanel::class)->html(), 'the list is for the time the mode is on');
        $html = Livewire::test(UsbPrepPanel::class)->call('start')->html();

        $this->assertStringContainsString('ready', $html);
        $this->assertStringContainsString('move it to the PoE switch', $html);
        $this->assertStringContainsString('Error during preinstall. Return code 1.', $html);
        $this->assertStringContainsString('flashing', $html);
        $this->assertStringContainsString('e4:5f:01:62:9f:d3', $html);
        $this->assertStringNotContainsString('e4:5f:01:62:9f:d6', $html, 'half a day old: not listed');
        $this->assertStringNotContainsString('e4:5f:01:62:9f:d7', $html, 'an ordinary board is not a prepared one');
    }

    public function test_a_board_that_went_quiet_while_flashing_says_so()
    {
        $this->board(['phase' => 'postinstall', 'provisioning_complete_at' => null, 'provisioning_started_at' => now()->subMinutes(30),
            'progress_updated_at' => now()->subMinutes(30)]);

        Livewire::test(UsbPrepPanel::class)->call('start')->assertSee('no report')->assertDontSee('flashing');
    }

    public function test_the_tile_is_the_last_cell_of_the_summary_row_and_a_prepared_board_is_ready_in_the_main_table()
    {
        $this->actingAs(User::factory()->create());
        $this->board(['phase_started_at' => now()->subMinute()]);
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'USB prep</h2>'), 'the tile is on the page once');
        $this->assertLessThan(strpos($html, 'class="cmp-card cmp-card-scroll"'), strpos($html, 'USB prep</h2>'), 'above the main table');
        $this->assertStringContainsString('grid-cols-1', $html, 'with no batch the tile is alone in its row');
        $this->assertStringContainsString('Ready (USB prep)', $html, 'a board that only had its bootloader set is not "Done"');
    }

    public function test_the_card_in_settings_shows_the_defaults_with_the_network_first()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings?tab=provisioning')->assertOk()->assertSeeLivewire(UsbPrepSettings::class)->assertSee('USB prep');
        Livewire::test(UsbPrepSettings::class)
            ->assertSet('firmware', 'default/pieeprom-2026-09-23.bin')
            ->assertSee('BOOT_ORDER=0xf12');
    }

    public function test_saving_makes_the_image_and_a_bad_firmware_is_said_not_stored()
    {
        $card = Livewire::test(UsbPrepSettings::class)
            ->set('settings', "[all]\r\nBOOT_ORDER=0xf2")
            ->call('save')
            ->assertSee('Saved: boards prepared from now on get this bootloader.');

        $this->assertSame("[all]\nBOOT_ORDER=0xf2\n", app(UsbPrep::class)->settings());
        $this->assertFileExists(public_path('uploads/pieeprom-usbprep.bin'));
        $card->assertSee('Image checksum ' . app(UsbPrep::class)->imageSha());

        $card->set('firmware', 'default/pieeprom-1999-01-01.bin')->call('save')->assertSee('no longer available in the firmware store');
        $this->assertSame('default/pieeprom-2026-09-23.bin', app(UsbPrep::class)->firmware(), 'the saved one stays');
    }

    public function test_the_defaults_button_brings_the_network_first_text_back()
    {
        Livewire::test(UsbPrepSettings::class)
            ->set('settings', "[all]\nBOOT_ORDER=0xf41\n")
            ->call('useDefaults')
            ->assertSet('settings', UsbPrep::DEFAULT_SETTINGS);
    }
}
