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

    /* The button says what it does and its name for a screen reader says what for: the title of the tile says the rest */
    protected function assertButton(string $text, string $name, string $html): void
    {
        $this->assertMatchesRegularExpression('/<button\b[^>]*aria-label="'.preg_quote($name, '/').'"[^>]*>\s*'.preg_quote($text, '/').'\s*<\/button>/', $html);
    }

    public function test_the_dashboard_has_the_button_and_it_starts_and_stops_the_mode()
    {
        $this->actingAs(User::factory()->create());
        $page = $this->get('/dashboard')->assertOk()->assertSeeLivewire(UsbPrepPanel::class)->assertSee('Set boards to boot from the network')
            ->assertSeeHtml('href="'.route('settings', ['tab' => 'provisioning']).'#usb-prep"');
        $this->assertButton('Start', 'Start USB prep', $page->getContent());

        $panel = Livewire::test(UsbPrepPanel::class)->call('start');
        $this->assertTrue(app(UsbPrep::class)->active());
        $this->assertButton('Stop', 'Stop USB prep', $panel->html());
        $panel->assertSee('on · 60 min left')->assertDontSeeHtml('aria-label="Start USB prep"')
            ->assertSee('Connect a board over USB')->assertSee('Waiting for a board');

        $panel->call('stop');
        $this->assertFalse(app(UsbPrep::class)->active());
        $this->assertButton('Start', 'Start USB prep', $panel->html());
        $panel->assertDontSee('Connect a board over USB');
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

    public function test_the_tile_says_what_to_do_next_for_the_board_prepared_last()
    {
        $panel = Livewire::test(UsbPrepPanel::class)->call('start');

        $ready = $this->board();   // done
        $panel->call('$refresh')->assertSee('ready')->assertSee('Unplug USB and power, plug it into the PoE switch')->assertSee('e4:5f:01:62:9f:d3')
            ->assertSeeHtml('href="'.route('cm', $ready->mac).'"');

        $ready->update(['usb_prep_at' => now()->subMinutes(30)]);
        $this->board(['serial' => '10000000e611110e', 'mac' => 'e4:5f:01:62:9f:d5', 'phase' => 'postinstall', 'provisioning_complete_at' => null, 'usb_prep_at' => now()->subMinute()]);
        $panel->call('$refresh')->assertSee('flashing')->assertSee('Wait for ready, or both LEDs fading in turn')->assertSee('e4:5f:01:62:9f:d5')
            ->assertSee('+1 more')->assertDontSee('e4:5f:01:62:9f:d3');

        $this->board(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'failed', 'phase_detail' => 'Error during preinstall. Return code 1.',
            'usb_prep_at' => now()]);
        $panel->call('$refresh')->assertSee('failed')->assertSee('Error during preinstall. Return code 1.')->assertSee('+2 more')->assertDontSee('flashing');
    }

    public function test_the_boards_are_for_the_time_the_mode_is_on_and_only_those_waiting_for_the_switch()
    {
        $this->board();
        $this->board(['serial' => '10000000e611110f', 'mac' => 'e4:5f:01:62:9f:d6', 'usb_prep_at' => now()->subHours(3)]);
        $this->board(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:62:9f:d7', 'usb_prep_at' => null]);

        $this->assertStringNotContainsString('e4:5f:01:62:9f:d3', Livewire::test(UsbPrepPanel::class)->html(), 'nothing of it while the mode is off');
        $html = Livewire::test(UsbPrepPanel::class)->call('start')->html();
        $this->assertStringContainsString('e4:5f:01:62:9f:d3', $html);
        $this->assertStringNotContainsString('e4:5f:01:62:9f:d6', $html, 'prepared hours ago: not the tile\'s business');
        $this->assertStringNotContainsString('e4:5f:01:62:9f:d7', $html, 'an ordinary board is not a prepared one');
        $this->assertStringNotContainsString('more', $html, 'one board: nothing to count');
    }

    public function test_a_board_that_went_quiet_while_flashing_says_so()
    {
        $this->board(['phase' => 'postinstall', 'provisioning_complete_at' => null, 'provisioning_started_at' => now()->subMinutes(30),
            'progress_updated_at' => now()->subMinutes(30)]);

        Livewire::test(UsbPrepPanel::class)->call('start')->assertSee('no report')->assertSee('check the board and its cables')->assertDontSee('flashing');
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
