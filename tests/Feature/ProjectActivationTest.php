<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\EepromImage;
use App\Services\ProjectActivator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Activating a project has side effects beyond the "active_project" setting: the EEPROM image
 * the modules download (public/uploads/pieeprom.bin) is rebuilt with the project's settings.
 * Every path that changes the active project must go through the same code.
 */
class ProjectActivationTest extends TestCase
{
    use RefreshDatabase;

    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprovision-act-'.uniqid();
        mkdir($this->dir.'/default', 0755, true);
        config(['cmprovision.firmware_dir' => $this->dir]);
        file_put_contents($this->dir.'/default/pieeprom-2026-09-23.bin', self::eepromImage("[all]\nBOOT_UART=0\n"));
        @unlink(public_path('uploads/pieeprom.bin'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        @unlink(public_path('uploads/pieeprom.bin'));
        parent::tearDown();
    }

    /** A minimal bootloader image: one bootconf.txt section, then erased flash. */
    public static function eepromImage($config)
    {
        $section = pack('N', EepromImage::FILE_MAGIC)
            .pack('N', strlen($config) + EepromImage::FILENAME_LEN + 4)
            .str_pad("bootconf.txt", EepromImage::FILENAME_LEN, "\0")
            ."\0\0\0\0"
            .$config;
        return str_pad($section, 16384, "\xff");
    }

    protected function project(array $attrs = [])
    {
        return Project::create(array_merge([
            'name' => 'p-'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false,
            'eeprom_firmware' => 'default/pieeprom-2026-09-23.bin',
            'eeprom_settings' => "[all]\nBOOT_UART=0\nBOOT_ORDER=0xf21\n",
        ], $attrs));
    }

    public function test_activating_a_project_builds_the_eeprom_image_with_its_settings()
    {
        $project = $this->project();

        (new ProjectActivator)->activate($project);

        $this->assertSame((string) $project->id, Setting::find('active_project')->value);
        $image = file_get_contents(public_path('uploads/pieeprom.bin'));
        $this->assertSame("[all]\nBOOT_UART=0\nBOOT_ORDER=0xf21\n", EepromImage::getConfig($image));
        $this->assertSame(hash('sha256', $image), Setting::find('active_eeprom_sha256')->value);
    }

    public function test_activating_a_project_without_firmware_removes_the_eeprom_image()
    {
        (new ProjectActivator)->activate($this->project());
        $this->assertFileExists(public_path('uploads/pieeprom.bin'));

        (new ProjectActivator)->activate($this->project(['eeprom_firmware' => null, 'eeprom_settings' => null]));

        $this->assertFileDoesNotExist(public_path('uploads/pieeprom.bin'));
    }

    public function test_a_missing_firmware_file_is_reported_and_leaves_no_stale_image()
    {
        (new ProjectActivator)->activate($this->project());
        $project = $this->project(['eeprom_firmware' => 'default/pieeprom-2099-01-01.bin']);

        try {
            (new ProjectActivator)->activate($project);
            $this->fail('expected an exception about the missing firmware file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pieeprom-2099-01-01.bin', $e->getMessage());
        }

        $this->assertSame((string) $project->id, Setting::find('active_project')->value);
        $this->assertFileDoesNotExist(public_path('uploads/pieeprom.bin'));
    }

    public function test_only_images_from_the_firmware_store_are_accepted()
    {
        file_put_contents($this->dir.'/secret.txt', 'not a firmware');
        $project = $this->project(['eeprom_firmware' => '../'.basename($this->dir).'/secret.txt']);

        try {
            (new ProjectActivator)->activate($project);
            $this->fail('a path outside the channel directories must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no longer available', $e->getMessage());
        }
        $this->assertFileDoesNotExist(public_path('uploads/pieeprom.bin'));
    }

    public function test_patching_the_active_project_through_the_api_rebuilds_the_eeprom_image()
    {
        $project = $this->project();
        (new ProjectActivator)->activate($project);
        $before = Setting::find('active_eeprom_sha256')->value;
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson("/api/projects/{$project->id}", ['eeprom_settings' => "[all]\nBOOT_UART=1\n"])->assertOk();

        $image = file_get_contents(public_path('uploads/pieeprom.bin'));
        $this->assertSame("[all]\nBOOT_UART=1\n", EepromImage::getConfig($image));
        $this->assertNotSame($before, Setting::find('active_eeprom_sha256')->value);
    }

    public function test_patching_an_inactive_project_through_the_api_leaves_the_eeprom_image_alone()
    {
        $active = $this->project();
        (new ProjectActivator)->activate($active);
        $other = $this->project(['name' => 'other']);
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson("/api/projects/{$other->id}", ['eeprom_settings' => "[all]\nBOOT_UART=1\n"])->assertOk();

        $this->assertSame("[all]\nBOOT_UART=0\nBOOT_ORDER=0xf21\n", EepromImage::getConfig(file_get_contents(public_path('uploads/pieeprom.bin'))));
    }

    public function test_eeprom_image_config_roundtrip_and_corruption_detection()
    {
        $image = self::eepromImage("[all]\nA=1\n");
        $this->assertSame("[all]\nA=1\n", EepromImage::getConfig($image));

        $this->assertTrue(EepromImage::setConfig($image, "[all]\nB=2\nC=3\n"));
        $this->assertSame("[all]\nB=2\nC=3\n", EepromImage::getConfig($image));
        $this->assertSame(16384, strlen($image));

        $garbage = str_repeat("\x00", 4096);
        $this->assertFalse(EepromImage::getConfig($garbage));
        $this->assertFalse(EepromImage::setConfig($garbage, "x"));
    }
}
