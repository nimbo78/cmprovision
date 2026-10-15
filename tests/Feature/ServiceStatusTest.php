<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Service status: the cold spare of the built-in Wi-Fi has a row, and its log is its journal */
class ServiceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cold_spare_has_a_row_with_a_log()
    {
        Livewire::test(Settings::class)
            ->assertSee('uplink cold spare (built-in Wi-Fi)')
            ->assertSeeHtml('wire:click="viewLogUplinkSpare()"')
            ->call('viewLogUplinkSpare')
            ->assertSet('logModalOpen', true)
            ->assertSet('logTitle', 'Uplink cold spare log since boot');
    }

    public function test_its_log_is_what_the_helper_wrote_to_the_journal()
    {
        if (trim((string) shell_exec('command -v logger 2>/dev/null')) === '' || !is_executable('/bin/journalctl'))
            $this->markTestSkipped('needs logger and journalctl');
        $marker = 'cold spare test '.bin2hex(random_bytes(4));
        exec('logger -t cmprovision-uplink '.escapeshellarg($marker));
        // journald takes the line a moment later; without a journal that takes it there is nothing to check
        $seen = false;
        for ($i = 0; $i < 20 && !$seen; $i++) {
            usleep(100000);
            $seen = str_contains((string) shell_exec('/bin/journalctl --boot -t cmprovision-uplink 2>/dev/null'), $marker);
        }
        if (!$seen)
            $this->markTestSkipped('the journal does not take logger lines here');

        $log = Livewire::test(Settings::class)->call('viewLogUplinkSpare')->get('logOutput');

        $this->assertStringContainsString($marker, $log);
    }
}
