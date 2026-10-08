<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The module downloads, decompresses and writes the image in one pipeline. When it fails, the
 * web log must show why: the exit code of curl, the decompressor's message and a diagnosis.
 */
class ImageWriteDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000deadbeef';

    protected function activeProjectWithImage($extension)
    {
        $image = new Image;
        $image->filename = "os.img.$extension";
        $image->filename_extension = $extension;
        $image->filename_on_server = "abc.$extension";
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 1024 * 1024 * 1024;
        $image->save();

        $project = Project::create([
            'name' => 'p', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false,
        ]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function provisioningScript($extension)
    {
        $this->activeProjectWithImage($extension);
        // storagesize is in 512-byte sectors: 32 GB
        return $this->get('/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:01')
            ->assertOk()
            ->getContent();
    }

    public function test_script_records_curl_exit_code_and_decompressor_errors_in_the_write_log()
    {
        $script = $this->provisioningScript('gz');

        $this->assertStringContainsString(': > /tmp/dd.log', $script);
        $this->assertStringContainsString('echo "curl exit code $RC" >> /tmp/dd.log', $script);
        $this->assertStringContainsString('gzip -dc 2>> /tmp/dd.log', $script);
        $this->assertStringContainsString('dd of=$STORAGE conv=fsync obs=1M >> /tmp/dd.log 2>&1', $script);
    }

    public function test_script_uses_the_decompressor_matching_the_image_type()
    {
        $this->assertStringContainsString('xz -dc 2>> /tmp/dd.log', $this->provisioningScript('xz'));
    }

    protected function postWriteLog($log, $retcode = 1)
    {
        Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:00:00:01']);
        return $this->post('/scriptexecute?serial='.self::SERIAL."&retcode=$retcode&phase=dd", [
            'log' => UploadedFile::fake()->createWithContent('dd.log', $log),
        ])->assertOk();
    }

    public function test_dropped_download_is_explained_in_the_web_log()
    {
        $this->postWriteLog("curl exit code 18\ngzip: unexpected end of file\n0+0 records in\n0+0 records out\n");

        $entry = Cmlog::where('loglevel', 'error')->firstOrFail();
        $this->assertStringContainsString('Error during dd', $entry->msg);
        $this->assertStringContainsString('connection to the provisioning server was closed before the download finished', $entry->msg);
        $this->assertStringContainsString('curl exit code 18', $entry->msg);
    }

    public function test_corrupted_archive_is_explained_in_the_web_log()
    {
        $this->postWriteLog("gzip: invalid magic\n0+0 records in\n0+0 records out\n");

        $entry = Cmlog::where('loglevel', 'error')->firstOrFail();
        $this->assertStringContainsString('not a valid compressed image', $entry->msg);
    }

    public function test_unknown_failure_keeps_the_raw_output_only()
    {
        $this->postWriteLog("something odd happened\n12+0 records in\n11+0 records out\n");

        $entry = Cmlog::where('loglevel', 'error')->firstOrFail();
        $this->assertStringContainsString("something odd happened", $entry->msg);
        $this->assertStringNotContainsString('Diagnosis', $entry->msg);
    }
}
