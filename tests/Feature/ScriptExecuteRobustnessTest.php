<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The module executes whatever /scriptexecute answers, and retries an HTTP 500 for a quarter of
 * an hour before running the error page as a shell script. So the endpoint must never answer
 * with an error page: problems go to the web log and to the module as a readable message.
 */
class ScriptExecuteRobustnessTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000cafebabe';
    const QUERY = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:02';

    protected function activeProject($extension = 'gz')
    {
        $image = new Image;
        $image->filename = "os.img.$extension";
        $image->filename_extension = $extension;
        $image->filename_on_server = "img.$extension";
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

    public function test_unreachable_snmp_switch_does_not_stop_provisioning()
    {
        if (! function_exists('snmp2_real_walk')) {
            return $this->markTestSkipped('php-snmp is not installed.');
        }
        $this->activeProject();
        Setting::updateOrCreate(['key' => 'ethernetswitch_ip'], ['value' => '192.0.2.1']);   // TEST-NET, never answers
        Setting::updateOrCreate(['key' => 'ethernetswitch_snmp_community'], ['value' => 'public']);

        $response = $this->get(self::QUERY)->assertOk();

        $this->assertStringContainsString('Writing image from', $response->getContent());
        $this->assertNull(Cm::where('serial', self::SERIAL)->firstOrFail()->provisioning_board);
        $warning = Cmlog::where('cm', self::SERIAL)->where('loglevel', 'warning')->first();
        $this->assertNotNull($warning, 'the failed switch lookup should be visible in the web log');
        $this->assertStringContainsString('192.0.2.1', $warning->msg);
    }

    public function test_internal_error_is_reported_to_the_web_log_and_to_the_module_instead_of_an_error_page()
    {
        Log::shouldReceive('error')->once();
        $this->activeProject('zip');    // no decompressor for this type: the controller trips over it

        $response = $this->get(self::QUERY);

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString('Provisioning server error', $response->getContent());
        $this->assertStringContainsString('exit 1', $response->getContent());
        $entry = Cmlog::where('cm', self::SERIAL)->where('loglevel', 'error')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Provisioning server error', $entry->msg);
    }

    public function test_completion_of_a_module_whose_project_was_deleted_is_logged_not_crashed()
    {
        Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:00:00:02', 'project_id' => null]);

        $this->get('/scriptexecute?serial='.self::SERIAL.'&alldone=1&temp=40')->assertOk();

        $this->assertNotNull(Cm::where('serial', self::SERIAL)->firstOrFail()->provisioning_complete_at);
        $this->assertStringContainsString('Provisioning completed', Cmlog::where('cm', self::SERIAL)->latest('id')->firstOrFail()->msg);
    }
}
