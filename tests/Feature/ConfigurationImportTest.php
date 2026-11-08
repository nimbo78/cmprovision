<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\Label;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Models\User;
use App\Services\Configuration\TransferCipher;
use App\Services\Configuration\TransferExporter;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferWorkspace;
use App\Services\Sensor\ModuleMark;
use App\Services\Sensor\SavedAccounts;
use App\Support\StationSetupInput;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Real commits are intentional: after-commit failure/retry must not be hidden by a test transaction. */
class ConfigurationImportTest extends TestCase
{

    private string $directory;
    private TransferWorkspace $workspace;
    private int $sequence = 0;
    private array $publicFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        Bus::fake();
        $this->directory = sys_get_temp_dir().'/cmprov-apply-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['cmprovision.firmware_dir' => $this->directory.'/firmware']);
        $this->workspace = new TransferWorkspace($this->directory.'/operations');
        $this->app->instance(TransferWorkspace::class, $this->workspace);
    }

    protected function tearDown(): void
    {
        Event::forget('eloquent.creating: '.Script::class);
        Event::forget('eloquent.created: '.Script::class);
        foreach (Image::all() as $image) @unlink($image->imagepath());
        foreach (Package::all() as $package) @unlink($package->path());
        foreach ($this->publicFiles as $file) @unlink($file);
        DB::setDefaultConnection('sqlite');
        DB::purge('transfer_receiver');
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_apply_records_one_durable_result_and_retries_do_not_create_duplicates(): void
    {
        [$operation, $preview] = $this->prepared($this->manifest());
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertSame('applied', $operation->fresh()->status);
        $this->assertSame(1, Script::count());
        $this->assertSame(1, $result['counts']['created']);
        $this->assertSame('Setup', $result['objects']['script-1']['name']);
        $this->assertSame($result, app(TransferImporter::class)->apply($operation->fresh(), $preview['fingerprint']));
        $this->assertSame(1, Script::count());
        $this->assertNull($operation->fresh()->secrets);
        $this->assertArrayNotHasKey('preview', $operation->fresh()->options ?? []);
    }

    public function test_name_conflict_creates_a_separate_copy_and_keeps_the_existing_definition(): void
    {
        Script::create(array_replace($this->manifest()['objects'][0]['data'], ['script' => 'old contents']));
        [$operation, $preview] = $this->prepared($this->manifest());
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertSame(2, Script::count());
        $this->assertSame('old contents', Script::orderBy('id')->first()->script);
        $created = Script::findOrFail($result['objects']['script-1']['id']);
        $this->assertNotSame('Setup', $created->name);
        $this->assertSame($this->manifest()['objects'][0]['data']['script'], $created->script);
    }

    public function test_changed_receiver_data_makes_the_preview_stale_before_any_write(): void
    {
        $script = Script::create(array_replace($this->manifest()['objects'][0]['data'], ['script' => 'old contents']));
        [$operation, $preview] = $this->prepared($this->manifest(), ['objects' => ['script-1' => ['action' => 'replace', 'target' => $script->id]]]);
        $script->update(['script' => 'changed by another operator']);
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('A stale preview must not be applied.');
        } catch (RuntimeException $error) {
            $this->assertSame(1, Script::count());
            $this->assertSame('changed by another operator', $script->fresh()->script);
        }
    }

    public function test_old_fingerprint_cannot_apply_changed_operator_choices(): void
    {
        [$operation, $preview] = $this->prepared($this->manifest());
        app(TransferImporter::class)->preview($operation, ['objects' => ['script-1' => ['name' => 'Renamed']]]);
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
    }

    public function test_active_project_and_its_scripts_require_copy_instead_of_replacement(): void
    {
        $script = Script::create(array_replace($this->manifest()['objects'][0]['data'], ['script' => 'old contents']));
        $project = Project::create($this->projectAttributes('Active'));
        $project->scripts()->attach($script);
        Setting::create(['key' => 'active_project', 'value' => $project->id]);
        [$operation, $preview] = $this->prepared($this->manifest(), ['objects' => ['script-1' => ['action' => 'replace', 'target' => $script->id]]]);
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('An active dependency must not be replaced.');
        } catch (RuntimeException $error) {
            $this->assertSame('old contents', $script->fresh()->script);
            $this->assertSame($project->id, Project::getActiveId());
        }
    }

    public function test_a_live_run_started_after_preview_blocks_replacement(): void
    {
        $script = Script::create(array_replace($this->manifest()['objects'][0]['data'], ['script' => 'old contents']));
        $project = Project::create($this->projectAttributes('Running'));
        $project->scripts()->attach($script);
        [$operation, $preview] = $this->prepared($this->manifest(), ['objects' => ['script-1' => ['action' => 'replace', 'target' => $script->id]]]);
        Cm::create(['serial' => '1000000000000001', 'mac' => 'e4:5f:01:01:02:03', 'project_id' => $project->id, 'phase' => 'write', 'provisioning_started_at' => now()]);
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('A new live run must invalidate the preview.');
        } catch (RuntimeException $error) {
            $this->assertSame('old contents', $script->fresh()->script);
        }
    }

    public function test_registered_image_uses_verified_facts_and_a_new_server_filename(): void
    {
        [$operation, $preview] = $this->prepared($this->imageManifest(), [], true);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $image = Image::findOrFail($result['objects']['image-1']['id']);
        $this->assertFileExists($image->imagepath());
        $this->assertSame(hash('sha256', 'image'), $image->uncompressed_sha256);
        $this->assertSame(5, $image->uncompressed_size);
        $this->assertNotSame('sensor.img.gz', $image->filename_on_server);
        $this->assertNotSame('files/image-1.gz', $image->filename_on_server);
        $this->assertTrue($image->isReady());
    }

    public function test_changed_staged_bytes_are_refused_before_registration(): void
    {
        [$operation, $preview] = $this->prepared($this->imageManifest(), [], true);
        $member = $operation->fresh()->options['files']['image-1']['member'];
        $path = $this->workspace->path($operation, $member);
        $bytes = file_get_contents($path);
        $bytes[0] = chr(ord($bytes[0]) ^ 1);
        file_put_contents($path, $bytes);
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('Modified staged bytes must not be registered.');
        } catch (RuntimeException $error) {
            $this->assertSame(0, Image::count());
        }
    }

    public function test_failure_before_commit_rolls_back_rows_and_cleans_new_files(): void
    {
        $manifest = $this->imageManifest();
        $manifest['roots'][] = 'script-1';
        $manifest['objects'][] = $this->manifest()['objects'][0];
        [$operation, $preview] = $this->prepared($manifest, [], true);
        $before = glob(public_path('uploads/transfer-*')) ?: [];
        Event::listen('eloquent.creating: '.Script::class, function () { throw new RuntimeException('internal-secret-must-not-leak'); });
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('The injected transaction failure must be reported.');
        } catch (RuntimeException $error) {
            $this->assertSame(0, Image::count());
            $this->assertSame(0, Script::count());
            $this->assertSame($before, glob(public_path('uploads/transfer-*')) ?: []);
            $this->assertStringNotContainsString('internal-secret-must-not-leak', $error->getMessage());
        }
    }

    public function test_after_commit_error_preserves_registered_files_and_the_recorded_mapping(): void
    {
        $manifest = $this->imageManifest();
        $manifest['roots'][] = 'script-1';
        $manifest['objects'][] = $this->manifest()['objects'][0];
        [$operation, $preview] = $this->prepared($manifest, [], true);
        Event::listen('eloquent.created: '.Script::class, function () {
            DB::afterCommit(function () { throw new RuntimeException('simulated response loss after commit'); });
        });
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        Event::forget('eloquent.created: '.Script::class);
        $this->assertSame('applied', $operation->fresh()->status);
        $this->assertFileExists(Image::firstOrFail()->imagepath());
        $this->assertSame($result, app(TransferImporter::class)->apply($operation->fresh(), $preview['fingerprint']));
        $this->assertSame(1, Image::count());
        $this->assertSame(1, Script::count());
    }

    public function test_distinct_source_scripts_do_not_collapse_two_executions_into_one(): void
    {
        $manifest = $this->manifest();
        Script::create($manifest['objects'][0]['data']);
        $second = $manifest['objects'][0];
        $second['ref'] = 'script-2';
        $manifest['objects'][] = $second;
        $project = $this->projectObject();
        $project['data']['scripts'][] = 'script-2';
        $manifest['objects'][] = $project;
        $manifest['roots'] = ['project-1'];
        [$operation, $preview] = $this->prepared($manifest);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $imported = Project::findOrFail($result['objects']['project-1']['id']);
        $this->assertSame(2, $imported->scripts()->count());
        $this->assertNotSame($result['objects']['script-1']['id'], $result['objects']['script-2']['id']);
    }

    public function test_equal_priority_script_order_survives_reusing_opposite_receiver_ids(): void
    {
        $first = array_replace($this->manifest()['objects'][0]['data'], ['name' => 'First', 'script' => "echo CMFIRSTMARK\n"]);
        $second = array_replace($first, ['name' => 'Second', 'script' => "echo CMSECONDMARK\n"]);
        Script::create($second);
        Script::create($first);
        $manifest = $this->manifest();
        $manifest['objects'][0]['data'] = $first;
        $manifest['objects'][] = ['ref' => 'script-2', 'type' => 'script', 'data' => $second, 'omitted_secrets' => []];
        $project = $this->projectObject();
        $project['data']['scripts'][] = 'script-2';
        $manifest['objects'][] = $project;
        $manifest['roots'] = ['project-1'];
        [$operation, $preview] = $this->prepared($manifest);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $result['objects']['project-1']['id']]);
        $response = $this->get('/scriptexecute?serial=1000000000000002&model=CM4&storagesize=62500000&mac=e4:5f:01:01:02:04');
        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('CMFIRSTMARK', $body);
        $this->assertStringContainsString('CMSECONDMARK', $body);
        $this->assertLessThan(strpos($body, 'CMSECONDMARK'), strpos($body, 'CMFIRSTMARK'));
    }

    public function test_station_secrets_round_trip_between_independent_databases_and_application_keys(): void
    {
        $this->setKey('base64:'.base64_encode(str_repeat('a', 32)));
        $sourceImage = $this->sourceImage();
        $sourceScript = Script::create($this->manifest()['objects'][0]['data']);
        $label = Label::create(['name' => 'Printer', 'printer_type' => 'ftp', 'ftp_hostname' => '192.0.2.10',
            'ftp_username' => 'printer', 'ftp_password' => 'portable-printer-secret', 'print_command' => '', 'template' => 'Serial $serial', 'file_extension' => 'txt']);
        $project = Project::create(array_replace($this->projectAttributes('Source station'), ['image_id' => $sourceImage->id, 'label_id' => $label->id]));
        $project->scripts()->attach($sourceScript);
        $project->station_setup = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'username' => 'provision', 'password' => 'portable-linux-password', 'wifi' => true,
            'ssid' => 'Workshop', 'wifi_password' => 'portable-wifi-password', 'country' => 'IL',
        ]));
        $project->save();
        $sourceCiphertext = $project->getRawOriginal('station_setup');
        $mark = str_repeat('ab', 32);
        app(ModuleMark::class)->set($mark);
        app(SavedAccounts::class)->save(null, 'Source account', 'sensor', 'portable-account-password', false);
        $sender = $this->workspace->create(User::factory()->create()->id, 'source-session', 'export');
        app(TransferExporter::class)->prepare($sender, ['projects' => [$project->id], 'groups' => ['mark_key', 'sensor_accounts'], 'binaries' => ['images']], 'archive', true, 'shared-master-password');
        $sender->refresh();
        $source = $this->workspace->path($sender, $sender->result['member']);

        $receiverFile = $this->directory.'/receiver.sqlite';
        touch($receiverFile);
        config(['database.connections.transfer_receiver' => array_replace(config('database.connections.sqlite'), ['database' => $receiverFile])]);
        Artisan::call('migrate', ['--database' => 'transfer_receiver', '--force' => true]);
        DB::setDefaultConnection('transfer_receiver');
        $this->setKey('base64:'.base64_encode(str_repeat('b', 32)));
        Script::create(['name' => 'Receiver only', 'script_type' => 'preinstall', 'bg' => false, 'priority' => 1, 'script' => 'true']);
        $active = Project::create($this->projectAttributes('Receiver active'));
        Setting::create(['key' => 'active_project', 'value' => $active->id]);
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $source, 'shared-master-password');
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertTrue($preview['can_apply']);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $imported = Project::findOrFail(collect($result['objects'])->first(fn ($object) => $object['type'] === 'project')['id']);
        $this->assertNotSame($sourceCiphertext, $imported->getRawOriginal('station_setup'));
        $this->assertSame('portable-wifi-password', $imported->station_setup['wifi']['password']);
        $this->assertSame('portable-printer-secret', $imported->label->ftp_password);
        $this->assertSame($active->id, Project::getActiveId());
        $this->assertSame($mark, app(ModuleMark::class)->key());
        $this->assertContains('portable-account-password', array_column(app(SavedAccounts::class)->definitions(), 'password'));
        $this->assertFileExists($imported->image->imagepath());
        $operationId = $operation->id;
        DB::purge('transfer_receiver');
        $this->assertSame($result, app(TransferImporter::class)->apply(\App\Models\ConfigurationTransferOperation::findOrFail($operationId), $preview['fingerprint']));
    }

    public function test_generic_access_round_trips_between_independent_databases_and_application_keys(): void
    {
        $this->setKey('base64:'.base64_encode(str_repeat('a', 32)));
        $image = $this->sourceImage();
        $project = Project::create(array_replace($this->projectAttributes('Portable SSH sensor'), ['image_id' => $image->id]));
        $saved = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'profile' => 'raspios', 'hostname' => '', 'username' => 'operator', 'source_username' => 'wlanpi',
            'password' => 'Fixture-Portable-Password', 'sudo_passwordless' => true]));
        $project->station_setup = $saved;
        $project->save();
        $sourceCiphertext = $project->getRawOriginal('station_setup');
        $sender = $this->workspace->create(User::factory()->create()->id, 'source-session', 'export');
        app(TransferExporter::class)->prepare($sender, ['projects' => [$project->id], 'binaries' => ['images']],
            'archive', true, 'Fixture-Transfer-Password');
        $sender->refresh();
        $source = $this->workspace->path($sender, $sender->result['member']);
        $database = $this->directory.'/receiver.sqlite';
        touch($database);
        config(['database.connections.transfer_receiver' => array_replace(config('database.connections.sqlite'), ['database' => $database])]);
        Artisan::call('migrate', ['--database' => 'transfer_receiver', '--force' => true]);
        DB::setDefaultConnection('transfer_receiver');
        $this->setKey('base64:'.base64_encode(str_repeat('b', 32)));
        $active = Project::create($this->projectAttributes('Receiver active'));
        Setting::create(['key' => 'active_project', 'value' => $active->id]);
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $source, 'Fixture-Transfer-Password');
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertTrue($preview['can_apply']);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $imported = Project::findOrFail(collect($result['objects'])->firstWhere('type', 'project')['id']);
        $this->assertSame($saved, $imported->station_setup);
        $this->assertNotSame($sourceCiphertext, $imported->getRawOriginal('station_setup'));
        $this->assertSame($active->id, Project::getActiveId());
        $this->assertSame($result, app(TransferImporter::class)->apply($operation->fresh(), $preview['fingerprint']));
    }

    public function test_replacing_an_unused_script_preserves_its_project_relationship(): void
    {
        $script = Script::create(array_replace($this->manifest()['objects'][0]['data'], ['script' => 'old']));
        $project = Project::create($this->projectAttributes('Inactive'));
        $project->scripts()->attach($script);
        [$operation, $preview] = $this->prepared($this->manifest(), ['objects' => ['script-1' => ['action' => 'replace', 'target' => $script->id]]]);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertSame($script->id, $result['objects']['script-1']['id']);
        $this->assertSame(1, $result['counts']['replaced']);
        $this->assertSame([$script->id], $project->scripts()->pluck('scripts.id')->all());
        $this->assertSame($this->manifest()['objects'][0]['data']['script'], $script->fresh()->script);
        $this->assertNull(Project::getActiveId());
    }

    public function test_network_and_root_owned_display_changes_remain_explicit_owned_followups(): void
    {
        $this->mock(\App\Services\NetworkProfiles::class)->shouldReceive('ethernetDevices')->andReturn(['eth0']);
        $properties = array_fill_keys(\App\Services\NetworkProfiles::PROPERTIES, '');
        $manifest = $this->manifest();
        $manifest['roots'] = ['network-1', 'settings-1'];
        $manifest['objects'] = [
            ['ref' => 'network-1', 'type' => 'network', 'omitted_secrets' => [], 'data' => [
                'name' => 'Management', 'device' => 'source0.15', 'type' => 'vlan', 'vlan_id' => 15, 'parent' => 'source0', 'draft' => true,
                'ipv4' => array_replace($properties, ['method' => 'manual', 'addresses' => '192.0.2.2/24']),
                'ipv6' => array_replace($properties, ['method' => 'disabled']),
            ]],
            ['ref' => 'settings-1', 'type' => 'settings', 'omitted_secrets' => [], 'data' => ['group' => 'display', 'values' => [
                'fpms' => ['theme' => 'classic', 'ports_map' => 'whole', 'color_mode' => 'light'],
                'wallboard' => ['mode' => 'auto', 'palette' => 'warm-light', 'view' => 'flow', 'rotation' => 15],
                'fpms_service' => ['enabled' => true, 'open_on_start' => true], 'hdmi_auto' => true,
            ]]],
        ];
        $this->mock(\App\Services\Wallboard::class)->shouldNotReceive('save');
        $this->mock(\App\Services\Fpms::class)->shouldNotReceive('save');
        $this->mock(\App\Services\NetworkChanges::class)->shouldNotReceive('begin');
        [$operation, $preview] = $this->prepared($manifest, ['interfaces' => ['network-1' => ['device' => 'eth0.15', 'parent' => 'eth0']]]);
        $this->assertTrue($preview['can_apply']);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertTrue($result['followups']['network']);
        $this->assertTrue($result['followups']['display']['hdmi_auto']);
        $this->assertSame('light', Setting::find('fpms_color_mode')->value);
        $this->assertSame('eth0', $operation->fresh()->options['drafts']['network-1']['interface']['parent']);
        $this->assertStringNotContainsString('192.0.2.2', json_encode($operation->fresh()));
    }

    public function test_expiry_removes_crashed_unregistered_files_but_keeps_committed_assets(): void
    {
        [$operation, $preview] = $this->prepared($this->imageManifest(), [], true);
        $plans = $operation->fresh()->options['preview']['objects'];
        $staged = app(\App\Services\Configuration\TransferAssets::class)->stage($operation->refresh(), $plans);
        $path = \App\Services\Configuration\TransferAssets::path('image', $staged['image-1']['filename']);
        $this->assertFileExists($path);
        $operation->update(['expires_at' => now()->subMinute()]);
        $this->workspace->prune();
        $this->assertFileDoesNotExist($path);

        [$operation, $preview] = $this->prepared($this->imageManifest(), [], true);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $path = Image::firstOrFail()->imagepath();
        $operation->update(['expires_at' => now()->subMinute()]);
        $this->workspace->prune();
        $this->assertFileExists($path);
    }

    public function test_project_import_connects_new_image_package_and_firmware_without_activation(): void
    {
        $packageRoot = $this->directory.'/package';
        mkdir($packageRoot.'/DEBIAN', 0755, true);
        file_put_contents($packageRoot.'/DEBIAN/control', "Package: sensor-tools\nVersion: 1.0\nArchitecture: all\nMaintainer: Test <test@example.test>\nDescription: Disposable acceptance package\n");
        $process = new \Symfony\Component\Process\Process(['dpkg-deb', '--build', $packageRoot, $this->directory.'/sensor.deb']);
        $process->mustRun();
        $packageBytes = file_get_contents($this->directory.'/sensor.deb');
        $firmwareBytes = 'disposable eeprom bytes';
        $manifest = $this->imageManifest();
        $manifest['objects'][] = ['ref' => 'package-1', 'type' => 'package', 'omitted_secrets' => [],
            'data' => ['filename' => 'sensor.deb', 'deb_package' => 'sensor-tools', 'deb_version' => '1.0', 'deb_architecture' => 'all'],
            'file' => ['member' => 'files/package-1.deb', 'size' => strlen($packageBytes), 'sha256' => hash('sha256', $packageBytes), 'included' => true]];
        $manifest['objects'][] = ['ref' => 'firmware-1', 'type' => 'firmware', 'omitted_secrets' => [],
            'data' => ['channel' => 'default', 'name' => 'pieeprom-2026-10-01.bin'],
            'file' => ['member' => 'files/firmware-1.bin', 'size' => strlen($firmwareBytes), 'sha256' => hash('sha256', $firmwareBytes), 'included' => true]];
        $project = $this->projectObject();
        $project['data'] = array_replace($project['data'], ['image' => 'image-1', 'packages' => ['package-1'],
            'scripts' => [], 'firmware' => 'firmware-1', 'eeprom_settings' => "[all]\nBOOT_ORDER=0xf12\n"]);
        $manifest['objects'][] = $project;
        $manifest['roots'] = ['project-1'];
        [$operation, $preview] = $this->prepared($manifest, [], true, [
            'files/package-1.deb' => $packageBytes, 'files/firmware-1.bin' => $firmwareBytes,
        ]);
        $this->assertTrue($preview['can_apply']);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $imported = Project::findOrFail($result['objects']['project-1']['id']);
        $this->assertSame($result['objects']['image-1']['id'], $imported->image_id);
        $this->assertSame([$result['objects']['package-1']['id']], $imported->packages()->pluck('packages.id')->all());
        $this->assertSame(hash('sha256', $packageBytes), hash_file('sha256', $imported->packages->first()->path()));
        $this->assertSame($firmwareBytes, file_get_contents(\App\Models\Firmware::basedir().'/'.$imported->eeprom_firmware));
        $this->assertNull(Project::getActiveId());
    }

    public function test_replacing_an_image_retires_its_original_and_split_files_only_after_commit(): void
    {
        [$image, $original, $split] = $this->retirementImage();
        [$operation, $preview] = $this->prepared($this->imageManifest(), ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        $this->assertTrue($preview['can_apply']);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertFileExists($image->fresh()->imagepath());
        $this->assertFileDoesNotExist($original);
        $this->assertFileDoesNotExist($split);
    }

    public function test_replacement_rollback_preserves_the_original_and_split_files(): void
    {
        [$image, $original, $split] = $this->retirementImage();
        $manifest = $this->imageManifest();
        $manifest['roots'][] = 'script-1';
        $manifest['objects'][] = $this->manifest()['objects'][0];
        [$operation, $preview] = $this->prepared($manifest, ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        Event::listen('eloquent.creating: '.Script::class, function () { throw new RuntimeException('rollback fixture'); });
        try {
            app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
            $this->fail('The injected transaction error must roll back the replacement.');
        } catch (RuntimeException $error) {
            $this->assertSame($original, $image->fresh()->imagepath());
            $this->assertSame(basename($split), $image->fresh()->split_filename);
            $this->assertFileExists($original);
            $this->assertFileExists($split);
        }
    }

    public function test_replacement_keeps_files_referenced_by_another_image_as_original_or_split(): void
    {
        [$image, $original, $split] = $this->retirementImage();
        Image::forceCreate(['filename' => 'other.img.gz', 'filename_on_server' => basename($split), 'filename_extension' => 'gz',
            'sha256' => hash_file('sha256', $split), 'uncompressed_sha256' => hash('sha256', 'image'), 'uncompressed_size' => 5,
            'split_filename' => basename($original)]);
        [$operation, $preview] = $this->prepared($this->imageManifest(), ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertFileExists($original);
        $this->assertFileExists($split);
    }

    public function test_replacing_a_package_retires_its_old_file(): void
    {
        $path = public_path('uploads/packages/transfer-source-'.bin2hex(random_bytes(6)).'.deb');
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'disposable verified receiver package');
        $this->publicFiles[] = $path;
        $data = ['filename' => 'sensor.deb', 'deb_package' => 'sensor-tools', 'deb_version' => '1.0', 'deb_architecture' => 'all'];
        $package = Package::create($data + ['filename_on_server' => basename($path), 'sha256' => hash_file('sha256', $path), 'size' => filesize($path)]);
        $manifest = $this->manifest();
        $manifest['roots'] = ['package-1'];
        $manifest['objects'] = [['ref' => 'package-1', 'type' => 'package', 'data' => $data, 'omitted_secrets' => [],
            'file' => ['member' => 'files/package-1.deb', 'size' => filesize($path), 'sha256' => hash_file('sha256', $path), 'included' => false]]];
        [$operation, $preview] = $this->prepared($manifest, ['objects' => ['package-1' => ['action' => 'replace', 'target' => $package->id]]]);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertFileExists($package->fresh()->path());
        $this->assertFileDoesNotExist($path);
    }

    public function test_an_image_with_pending_split_work_cannot_be_replaced_but_can_be_copied(): void
    {
        $image = $this->sourceImage();
        $image->forceFill(['split_queued_at' => now()])->save();
        [$operation, $preview] = $this->prepared($this->imageManifest(), ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        $this->assertFalse($preview['can_apply']);
        $copy = app(TransferImporter::class)->preview($operation, ['objects' => ['image-1' => ['action' => 'copy']]]);
        $this->assertTrue($copy['can_apply']);
    }

    public function test_failed_retirement_stays_durable_and_an_applied_retry_keeps_the_same_mapping(): void
    {
        [$image, $original, $split] = $this->retirementImage();
        $this->app->instance(\App\Services\Configuration\TransferRetirement::class,
            new \App\Services\Configuration\TransferRetirement(fn ($path) => false));
        [$operation, $preview] = $this->prepared($this->imageManifest(), ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        $result = app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $this->assertSame('applied', $operation->fresh()->status);
        $this->assertNotEmpty($operation->fresh()->options['retired']);
        $this->assertFileExists($original);
        $this->app->forgetInstance(\App\Services\Configuration\TransferRetirement::class);
        $this->assertSame($result, app(TransferImporter::class)->apply($operation->fresh(), $preview['fingerprint']));
        $this->assertEmpty($operation->fresh()->options['retired'] ?? []);
        $this->assertFileDoesNotExist($original);
        $this->assertFileDoesNotExist($split);
    }

    public function test_expiry_can_retry_retirement_without_the_private_operation_directory(): void
    {
        [$image, $original, $split] = $this->retirementImage();
        $this->app->instance(\App\Services\Configuration\TransferRetirement::class,
            new \App\Services\Configuration\TransferRetirement(fn ($path) => false));
        [$operation, $preview] = $this->prepared($this->imageManifest(), ['objects' => ['image-1' => ['action' => 'replace', 'target' => $image->id]]], true);
        app(TransferImporter::class)->apply($operation, $preview['fingerprint']);
        $directory = dirname($this->workspace->path($operation, 'operation.lock'));
        File::deleteDirectory($directory);
        $operation->update(['expires_at' => now()->subSecond()]);
        $this->workspace->prune();
        $this->assertNotNull($operation->fresh(), 'The pending retirement ledger must survive an unavailable file store.');
        $this->app->forgetInstance(\App\Services\Configuration\TransferRetirement::class);
        $this->workspace->prune();
        $this->assertFileDoesNotExist($original);
        $this->assertFileDoesNotExist($split);
        $this->assertNull($operation->fresh());
    }

    private function retirementImage(): array
    {
        $image = $this->sourceImage();
        $split = public_path('uploads/transfer-old-split-'.bin2hex(random_bytes(6)).'.gz');
        file_put_contents($split, gzencode('image'));
        $this->publicFiles[] = $split;
        $image->forceFill(['split_filename' => basename($split), 'split_offsets' => '[0,1,2]', 'split_piece_mb' => 4])->save();
        return [$image, $image->imagepath(), $split];
    }

    private function setKey(string $key): void
    {
        config(['app.key' => $key]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    }

    private function operation()
    {
        return $this->workspace->create(User::factory()->create()->id, 'browser-session', 'import');
    }

    private function prepared(array $manifest, array $choices = [], bool $archive = false, array $members = []): array
    {
        $source = $this->directory.'/source-'.(++$this->sequence).($archive ? '.zip' : '.json');
        if ($archive) {
            $zip = new ZipArchive;
            $zip->open($source, ZipArchive::CREATE | ZipArchive::EXCL);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            foreach ($manifest['objects'] as $object) if (($object['file']['included'] ?? false)) $zip->addFromString($object['file']['member'], $members[$object['file']['member']] ?? gzencode('image'));
            $zip->close();
        } else file_put_contents($source, json_encode($manifest, JSON_THROW_ON_ERROR));
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $source, null);
        return [$operation, app(TransferImporter::class)->preview($operation, $choices)];
    }

    private function manifest(): array
    {
        return ['format' => 'cmprovision-configuration', 'schema' => 1, 'source' => '2.24.0', 'created_at' => now()->toIso8601String(),
            'roots' => ['script-1'], 'objects' => [['ref' => 'script-1', 'type' => 'script', 'omitted_secrets' => [],
                'data' => ['name' => 'Setup', 'script_type' => 'postinstall', 'priority' => 100, 'bg' => false, 'script' => "#!/bin/sh\ntrue\n"]]]];
    }

    private function projectAttributes(string $name): array
    {
        return ['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => null, 'label_id' => null,
            'label_moment' => 'never', 'eeprom_firmware' => null, 'eeprom_settings' => null, 'verify' => true,
            'os_check' => true, 'split_image' => true, 'reprovision' => false, 'mark_boots' => 5];
    }

    private function projectObject(): array
    {
        $data = $this->projectAttributes('Imported project');
        unset($data['image_id'], $data['label_id'], $data['eeprom_firmware']);
        return ['ref' => 'project-1', 'type' => 'project', 'omitted_secrets' => [], 'data' => $data + [
            'image' => null, 'label' => null, 'firmware' => null, 'station_setup' => null, 'scripts' => ['script-1'], 'packages' => [],
        ]];
    }

    private function imageManifest(): array
    {
        $manifest = $this->manifest();
        $bytes = gzencode('image');
        $manifest['roots'] = ['image-1'];
        $manifest['objects'] = [['ref' => 'image-1', 'type' => 'image', 'omitted_secrets' => [],
            'data' => ['filename' => 'sensor.img.gz', 'filename_extension' => 'gz', 'uncompressed_sha256' => null, 'uncompressed_size' => null],
            'file' => ['member' => 'files/image-1.gz', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'included' => true]]];
        return $manifest;
    }

    private function sourceImage(): Image
    {
        $path = public_path('uploads/transfer-source-'.bin2hex(random_bytes(6)).'.gz');
        file_put_contents($path, gzencode('image'));
        $this->publicFiles[] = $path;
        return Image::forceCreate(['filename' => 'sensor.img.gz', 'filename_on_server' => basename($path), 'filename_extension' => 'gz',
            'sha256' => hash_file('sha256', $path), 'uncompressed_sha256' => hash('sha256', 'image'), 'uncompressed_size' => 5]);
    }
}
