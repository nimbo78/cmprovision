<?php

namespace Tests\Feature;

use App\Http\Controllers\ScriptExecuteController;
use App\Models\Cm;
use App\Support\ShortCause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** What the front panel says went wrong, in two short lines at most: made from what the server already keeps */
class ShortCauseTest extends TestCase
{
    use RefreshDatabase;

    const NBSP = "\u{00A0}";

    protected function failed(string $detail, array $more = []): Cm
    {
        return Cm::create(array_merge(['serial' => '10000000aaaa0001', 'mac' => 'e4:5f:01:aa:00:01', 'phase' => 'failed',
            'phase_detail' => $detail], $more));
    }

    protected function steps(string ...$phases): array
    {
        return array_map(fn ($p) => ['phase' => $p, 'detail' => $p === 'postinstall' ? 'set hostname' : null,
            'at' => now()->toIso8601String()], [...$phases, 'failed']);
    }

    public function test_a_cut_download_names_its_curl_code()
    {
        $cm = $this->failed('Error during dd. Return code 1. Diagnosis: the connection to the provisioning server was closed '
            .'before the download finished (server timeout, network problem or server restart). Codes: curl 18.');

        $short = ShortCause::for($cm);

        $this->assertSame('Download cut (curl'.self::NBSP.'18)', $short['cause']);
        $this->assertSame('curl 18, exit 1', $short['code']);
        $this->assertStringStartsWith('The connection to the provisioning server was closed', $short['reason']);
        $this->assertStringNotContainsString('Codes', $short['reason']);
    }

    public function test_every_write_hint_has_a_short_cause()
    {
        foreach (ScriptExecuteController::WRITE_FAILURE_HINTS as $hint)
        {
            $cause = ShortCause::for($this->failed("Error during dd. Return code 1. Diagnosis: $hint."))['cause'];
            $this->assertNotSame('Writing image failed', $cause, $hint);
            $this->assertNotSame('Failed', $cause, $hint);
            Cm::query()->delete();
        }
    }

    public function test_short_causes_fit_two_panel_lines()
    {
        // only a failed image write carries a curl code
        $texts = [...ShortCause::catalogue(), ...array_map(fn ($c) => $c.' (curl'.self::NBSP.'56)', ShortCause::WRITES)];
        foreach ($texts as $text)
        {
            $this->assertLessThanOrEqual(40, mb_strlen($text), $text);
            $this->assertStringNotContainsString('...', $text);
        }
    }

    public function test_eeprom_failures()
    {
        $cases = ["flashrom v1.3\nNo EEPROM/flash device found.\n" => 'EEPROM: no SPI chip',
            "pieeprom.bin: FAILED\n" => 'EEPROM image mismatch',
            "curl: (7) Failed to connect\n" => 'EEPROM download failed',
            "Erasing and writing flash chip... FAILED!\n" => 'EEPROM flash failed'];
        foreach ($cases as $log => $cause)
        {
            $cm = $this->failed('Error during preinstall. Return code 1.', ['eeprom_result' => 'failed', 'pre_script_output' => $log]);
            $this->assertSame($cause, ShortCause::for($cm)['cause']);
            Cm::query()->delete();
        }
    }

    public function test_refusals_and_late_failures()
    {
        $cases = [
            ['Refused: no active project', [], 'No active project'],
            ['Refused: uncompressed SHA256 not computed yet', [], 'Image checksum not ready'],
            ['Refused: image size not dividable by sector size', [], 'Not a disk image'],
            ['Missing eMMC/SD card', [], 'No eMMC or SD card'],
            ['Image does not fit in storage', [], 'Image larger than eMMC'],
            ['The new system did not start: the module came back to network boot', [], 'New OS did not boot'],
            [Cm::OS_NO_REPORT, [], 'New OS did not report'],
            ['OS check failed: 2 units failed', [], 'OS check failed'],
            ['Provisioning server error: exception', [], 'Server error'],
            ['Error during postinstall. Return code 1.', ['timeline' => $this->steps('write', 'verify')], 'SHA256 mismatch after write'],
            ['Error during postinstall. Return code 3.', ['timeline' => $this->steps('write', 'postinstall')], 'Post-install script failed (code 3)'],
            ['Error during preinstall. Return code 2.', [], 'Pre-install script failed (code 2)'],
            ['Something nobody expected', ['timeline' => $this->steps('write')], 'Writing image failed'],
            ['Something nobody expected', [], 'Failed'],
        ];
        foreach ($cases as [$detail, $more, $cause])
        {
            $this->assertSame($cause, ShortCause::for($this->failed($detail, $more))['cause'], $detail);
            Cm::query()->delete();
        }
    }

    public function test_a_silent_module()
    {
        $cm = Cm::create(['serial' => '10000000aaaa0002', 'mac' => 'e4:5f:01:aa:00:02', 'phase' => 'write',
            'provisioning_started_at' => now()->subMinutes(10), 'progress_updated_at' => now()->subSeconds(360)]);

        $this->assertSame(['cause' => 'No report for 6 min', 'reason' => null, 'code' => null], ShortCause::for($cm));
    }

    public function test_a_running_or_finished_module_has_none()
    {
        $running = Cm::create(['serial' => '10000000aaaa0003', 'mac' => 'e4:5f:01:aa:00:03', 'phase' => 'write',
            'provisioning_started_at' => now()->subMinutes(1), 'progress_updated_at' => now()]);
        $done = Cm::create(['serial' => '10000000aaaa0004', 'mac' => 'e4:5f:01:aa:00:04', 'phase' => 'done']);

        $this->assertNull(ShortCause::for($running));
        $this->assertNull(ShortCause::for($done));
    }
}
