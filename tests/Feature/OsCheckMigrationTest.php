<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2.6.0 turns two enums into strings, which in SQLite rebuilds the scripts and projects tables. The rows of 2.5 stay
 * as they were: a project keeps its scripts, its image and its modules (cascades would delete or clear them if the
 * rebuild ran with the foreign keys on). No RefreshDatabase: its transaction around a test keeps SQLite's foreign keys
 * on, and without that transaction it does not migrate a test's in-memory database. Each test migrates its own.
 */
class OsCheckMigrationTest extends TestCase
{
    const MIGRATION = '2026_10_15_200000_add_os_check.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->assertExitCode(0);
    }

    /* Steps back to just before 2.6.0: its migrations and every later one */
    protected function stepsBackTo260(): int
    {
        $this->assertFileExists(database_path('migrations/'.self::MIGRATION));
        $files = array_map('basename', glob(database_path('migrations/*.php')));
        sort($files);
        return count($files) - array_search(self::MIGRATION, $files, true);
    }

    public function test_a_25_project_keeps_its_scripts_image_and_modules()
    {
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackTo260()])->assertExitCode(0);
        $this->assertFalse(Schema::hasColumn('projects', 'os_check'));
        $now = now();
        $imageId = DB::table('images')->insertGetId(['filename' => 'wlanpi-os.img.gz', 'filename_on_server' => 'img.gz',
            'filename_extension' => 'gz', 'sha256' => str_repeat('a', 64), 'created_at' => $now, 'updated_at' => $now]);
        $projectId = DB::table('projects')->insertGetId(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $imageId, 'label_moment' => 'postinstall', 'verify' => true, 'created_at' => $now, 'updated_at' => $now]);
        $scriptId = DB::table('scripts')->insertGetId(['name' => 'keys', 'script_type' => 'postinstall', 'bg' => false,
            'priority' => 10, 'script' => "#!/bin/sh\ntrue\n", 'created_at' => $now, 'updated_at' => $now]);
        DB::table('project_script')->insert(['project_id' => $projectId, 'script_id' => $scriptId]);
        DB::table('cms')->insert(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'project_id' => $projectId,
            'created_at' => $now, 'updated_at' => $now]);

        $this->artisan('migrate')->assertExitCode(0);

        $this->assertSame(1, DB::table('project_script')->where(['project_id' => $projectId, 'script_id' => $scriptId])->count());
        $this->assertSame($projectId, (int) DB::table('cms')->where('serial', '10000000e611110c')->value('project_id'));
        $project = Project::find($projectId);
        $this->assertSame(['postinstall', true, false, $imageId], [$project->label_moment, $project->verify, $project->os_check, $project->image_id]);
        $this->assertCount(2, Schema::getForeignKeys('projects'), 'image_id and label_id still point at their tables');
        $this->assertSame([], DB::select('pragma foreign_key_check'));
        $this->assertSame(1, (int) DB::selectOne('pragma foreign_keys')->foreign_keys, 'foreign keys are on again');
    }

    public function test_the_new_values_fit_and_names_stay_unique()
    {
        $now = now();
        DB::table('scripts')->insert(['name' => 'services', 'script_type' => 'firstboot', 'bg' => false, 'priority' => 10,
            'script' => 'true', 'created_at' => $now, 'updated_at' => $now]);
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'firstboot',
            'verify' => false, 'os_check' => true]);
        $this->assertSame(['firstboot', true], [$project->fresh()->label_moment, $project->fresh()->os_check]);

        $this->expectException(QueryException::class);
        DB::table('scripts')->insert(['name' => 'services', 'script_type' => 'postinstall', 'bg' => false, 'priority' => 10,
            'script' => 'true', 'created_at' => $now, 'updated_at' => $now]);
    }

    public function test_a_module_and_a_package_keep_what_the_check_needs()
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'os_check' => 'passed',
            'hostname' => 'wlanpi-a1b', 'os_state' => 'degraded', 'os_failed_units' => 'firefox-setup.service',
            'os_checked_at' => now(), 'os_check_output' => 'log']);
        $this->assertSame(['passed', 'wlanpi-a1b', 'degraded', 'firefox-setup.service', 'log'],
            [$cm->fresh()->os_check, $cm->fresh()->hostname, $cm->fresh()->os_state, $cm->fresh()->os_failed_units, $cm->fresh()->os_check_output]);
        $this->assertNotNull($cm->fresh()->os_checked_at);

        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false]);
        $package = Package::create(['filename' => 'extra_1.0_arm64.deb', 'filename_on_server' => 'x.deb', 'size' => 10,
            'sha256' => str_repeat('c', 64), 'deb_package' => 'extra', 'deb_version' => '1.0', 'deb_architecture' => 'arm64']);
        $project->packages()->attach($package->id);
        $this->assertSame([$package->id], $project->fresh()->packages->pluck('id')->all());
        $this->assertSame([$project->id], $package->projects->pluck('id')->all());
        $this->assertSame(public_path('uploads/packages/x.deb'), $package->path());
    }
}
