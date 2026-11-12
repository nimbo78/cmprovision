<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\User;
use App\Models\UsbDiagnosticReport;
use App\Support\ModuleHardware;
use App\Support\ModuleDiagnostics;
use App\Support\WallboardSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ModuleHardwareTest extends TestCase
{
    use RefreshDatabase;

    private function facts(array $overrides = []): array
    {
        return array_merge(['model' => 'Raspberry Pi Compute Module 4 Rev 1.1', 'revision_code' => '00c03141',
            'board_revision_ext' => '00000000', 'emmc_sectors' => '61071360', 'emmc' => 'detected'], $overrides);
    }

    public function test_cm4_factory_attributes_and_observed_capacity_form_an_explicitly_inferred_sku(): void
    {
        $this->assertSame(['model' => 'Raspberry Pi Compute Module 4', 'revision' => '1.1', 'ram' => '4 GiB',
            'emmc' => '32 GB (detected)', 'wifi' => 'Built-in', 'sku' => 'CM4104032 (inferred)'], ModuleHardware::fromFacts($this->facts()));
    }

    public function test_lite_and_no_wireless_are_factory_flags_not_missing_linux_devices(): void
    {
        $hw = ModuleHardware::fromFacts($this->facts(['revision_code' => 'a03140', 'board_revision_ext' => 'c0000000',
            'emmc_sectors' => '', 'emmc' => 'not_detected']));
        $this->assertSame('1.0', $hw['revision']);
        $this->assertSame('1 GiB', $hw['ram']);
        $this->assertSame('Not fitted (Lite)', $hw['emmc']);
        $this->assertSame('Not fitted', $hw['wifi']);
        $this->assertSame('CM4001000 (inferred)', $hw['sku']);
    }

    public function test_missing_emmc_is_never_lite_or_a_guessed_capacity(): void
    {
        $hw = ModuleHardware::fromFacts($this->facts(['emmc_sectors' => '', 'emmc' => 'not_detected']));
        $this->assertSame('Fitted; not detected', $hw['emmc']);
        $this->assertSame('Unknown', $hw['sku']);
    }

    public function test_invalid_or_missing_attributes_do_not_decode_as_zero(): void
    {
        foreach (['', 'zz', '0x', '123456789', ['0']] as $bad)
        {
            $hw = ModuleHardware::fromFacts($this->facts(['board_revision_ext' => $bad, 'revision_code' => $bad]));
            $this->assertSame('Unknown', $hw['wifi']);
            $this->assertSame('Unknown', $hw['sku']);
        }
    }

    public function test_unrecognized_capacity_does_not_get_rounded_into_a_catalogue_sku(): void
    {
        $hw = ModuleHardware::fromFacts($this->facts(['emmc_sectors' => '1000000']));
        $this->assertStringContainsString('detected', $hw['emmc']);
        $this->assertSame('Unknown', $hw['sku']);
    }

    public function test_non_cm4_and_conflicting_model_do_not_interpret_cm4_extended_flags(): void
    {
        foreach (['Raspberry Pi 4 Model B Rev 1.1', 'Raspberry Pi Compute Module 5 Rev 1.0'] as $model)
        {
            $hw = ModuleHardware::fromFacts($this->facts(['model' => $model]));
            $this->assertSame('Unknown', $hw['wifi']);
            $this->assertSame('Unknown', $hw['sku']);
        }
        $hw = ModuleHardware::fromFacts($this->facts(['revision_code' => 'c03111']));
        $this->assertSame('Unknown', $hw['wifi']);
        $this->assertSame('Unknown', $hw['sku']);
    }

    public function test_old_modules_keep_their_measured_facts_without_inventing_emmc_or_wifi(): void
    {
        $hw = ModuleHardware::forModule(new Cm(['model' => 'Raspberry Pi Compute Module 4 Rev 1.0',
            'memory_in_gb' => 4, 'storage' => 31268536320]));
        $this->assertSame('1.0', $hw['revision']);
        $this->assertSame('4 GiB', $hw['ram']);
        $this->assertSame('Unknown', $hw['emmc']);
        $this->assertSame('Unknown', $hw['wifi']);
        $this->assertSame('Unknown', $hw['sku']);
    }

    public function test_normal_revision_high_flags_are_not_mistaken_for_extended_flags(): void
    {
        $hw = ModuleHardware::fromFacts($this->facts(['revision_code' => 'c0c03141']));
        $this->assertSame('Built-in', $hw['wifi']);
        $this->assertSame('4 GiB', $hw['ram']);
    }

    public function test_all_32_standard_cm4_configurations_are_inferred_from_bits_not_a_fixed_revision_table(): void
    {
        foreach ([1 => 2, 2 => 3, 4 => 4, 8 => 5] as $ram => $bits)
            foreach ([false, true] as $wifi)
                foreach ([0, 8, 16, 32] as $storage)
                {
                    // Manufacturer differs from the stand's Sony UK modules; it must not break decoding.
                    $code = 0x800000 | ($bits << 20) | (3 << 16) | (3 << 12) | (0x14 << 4);
                    $ext = ($wifi ? 0 : 0x40000000) | ($storage ? 0 : 0x80000000);
                    $hw = ModuleHardware::fromFacts($this->facts(['revision_code' => dechex($code), 'board_revision_ext' => dechex($ext),
                        'emmc' => $storage ? 'detected' : 'not_detected', 'emmc_sectors' => (string) (int) ($storage * 1e9 * .97 / 512)]));
                    $this->assertSame(sprintf('CM4%d%02d%03d (inferred)', $wifi ? 1 : 0, $ram, $storage), $hw['sku']);
                }
    }

    public function test_conflicting_lite_and_detected_mmc_evidence_prevents_sku_inference(): void
    {
        $hw = ModuleHardware::fromFacts($this->facts(['board_revision_ext' => '80000000']));
        $this->assertSame('Conflict: Lite / eMMC detected', $hw['emmc']);
        $this->assertSame('Unknown', $hw['sku']);
    }

    public function test_usb_only_hardware_reaches_local_displays_and_the_diagnostic_details(): void
    {
        $report = ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=discovery\nserial=10000000e611110c\ntarget=/dev/mmcblk0\n"
            .implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($this->facts()), $this->facts())));
        UsbDiagnosticReport::create(['serial' => '10000000e611110c', 'boot_id' => 'boot', 'event_id' => 'event',
            'usb_path' => '/dev/ttyACM0', 'report' => $report, 'received_at' => now()]);
        $row = app(WallboardSnapshot::class)->current()['modules'][0];
        $this->assertFalse($row['registered']);
        $this->assertSame(ModuleHardware::fromFacts($report['facts']), $row['hardware']);
        $html = view('livewire.diagnostics-snapshot', ['cm' => null, 'report' => $report,
            'diagnosis' => ModuleDiagnostics::summary($report), 'receivedAt' => now()])->render();
        $this->assertStringContainsString('CM4104032 (inferred)', $html);
    }

    public function test_web_and_local_displays_receive_the_same_hardware_summary(): void
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'done',
            'polled_at' => now(), 'storage_diagnostics_at' => now(), 'model' => 'Raspberry Pi Compute Module 4 Rev 1.1',
            'storage_diagnostics' => ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=startup\nserial=10000000e611110c\ntarget=/dev/mmcblk0\n"
                .implode("\n", array_map(fn ($key, $value) => $key.'='.$value, array_keys($this->facts()), $this->facts())))]);
        $this->actingAs(User::factory()->create());
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('Inferred SKU')->assertSee('CM4104032 (inferred)')
            ->assertSee('32 GB (detected)')->assertSee('Built-in')->assertSee('PCB revision');
        $row = app(WallboardSnapshot::class)->current()['modules'][0];
        $this->assertSame(ModuleHardware::forModule($cm), $row['hardware']);
    }
}
