<?php

namespace Tests\Feature;

use App\Http\Controllers\ScriptExecuteController;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The server's side of the resuming download (2.6.1): the module's script writes through write_image, a resumed download
    is a line in the module's log, and the new ways a write fails are explained */
class ImageWriteServerTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';

    protected $file;

    protected function tearDown(): void
    {
        if ($this->file && is_file($this->file))
            unlink($this->file);
        parent::tearDown();
    }

    protected function project()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'write-'.uniqid().'.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1048576;
        $image->save();
        $this->file = $image->imagepath();
        file_put_contents($this->file, str_repeat('z', 12345));
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => false, 'os_check' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    public function test_the_module_writes_through_write_image_with_the_size_of_its_download()
    {
        $this->project();

        $script = $this->get(self::START)->assertOk()->getContent();

        $this->assertStringContainsString("IMAGE_BYTES=12345\n", $script);
        $this->assertStringContainsString("\nwrite_image\nRETCODE=\$?\n", $script);
        $this->assertStringContainsString('fetch_image 0 | { gzip -dc 2>>"$WRITE_LOG"; } | write_plain', $script);
        $this->assertStringContainsString('progress_note() {', $script);
        $this->assertStringNotContainsString('curl --retry 10 -g "', $script);
    }

    public function test_a_resumed_download_is_a_line_in_the_module_log_and_leaves_the_step_alone()
    {
        $this->project();
        $this->get(self::START)->assertOk();
        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&detail=&sectors=2048')->assertOk();
        $timeline = Cm::where('serial', self::SERIAL)->first()->timeline;

        $this->get('/scriptexecute?serial='.self::SERIAL.'&progress=write&resumed=1200000000&of=1600000000&curl=56')->assertOk();

        $cm = Cm::where('serial', self::SERIAL)->first();
        $this->assertSame($timeline, $cm->timeline);
        $this->assertSame(2048 * 512, $cm->progress_bytes);
        $this->assertTrue(Cmlog::where('cm', 'e4:5f:01:62:9f:d3')
            ->where('msg', 'Download resumed at 1.2 GB of 1.6 GB (the connection broke: curl exit 56).')->exists());
    }

    public static function hints(): array
    {
        return [
            'ten quiet minutes' => ["no data from the provisioning server for 10 minutes (curl exit code 7)\n",
                'the provisioning server sent nothing for 10 minutes (network or server down)'],
            'a file gone' => ["curl exit code 22 (HTTP 404)\n", 'the server answered with an error: the image or its pieces were removed'],
            'no range' => ["curl exit code 33\n", 'the server would not send the rest of the file'],
            'a storage written since' => ["1+0 records in\n1+0 records out\nthe storage was written after the write stopped\n",
                'the storage was written after the write stopped (flashed elsewhere, or another card), so the next start writes the image from the beginning'],
            'a shorter file' => ["the image on the server ends at 1000 of 2000 bytes (curl exit code 0)\n",
                'the image file on the server is shorter than expected (damaged or replaced)'],
            'a broken first MiB' => ["gzip: corrupted data\nthe first MiB in the script is broken\n", 'the first MiB of the pieces copy is damaged'],
        ];
    }

    #[DataProvider('hints')]
    public function test_the_ways_the_resuming_download_fails_are_explained($log, $hint)
    {
        $this->assertSame($hint, ScriptExecuteController::diagnoseWriteFailure($log));
    }
}
