<?php

namespace Tests\Feature;

use App\Jobs\PrepareConfigurationTransfer;
use App\Models\Image;
use App\Models\Label;
use App\Models\Script;
use App\Models\User;
use App\Models\Setting;
use App\Models\Project;
use App\Models\Host;
use App\Services\NetBoxExport;
use App\Services\NetworkProfiles;
use App\Support\StationSetupInput;
use App\Services\Configuration\TransferCipher;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class ConfigurationImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private TransferWorkspace $workspace;
    private int $sequence = 0;
    private array $publicFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cmprov-import-preview-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->workspace = new TransferWorkspace($this->directory.'/operations');
        $this->app->instance(TransferWorkspace::class, $this->workspace);
    }

    protected function tearDown(): void
    {
        foreach ($this->publicFiles as $file) @unlink($file);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_plain_text_is_prepared_without_creating_configuration_rows(): void
    {
        $operation = $this->prepare($this->manifest());
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame('create', $preview['objects'][0]['action']);
        $this->assertSame('Setup', $preview['objects'][0]['name']);
        $this->assertSame(0, Script::count());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $preview['fingerprint']);
        $this->assertSame($preview['fingerprint'], app(TransferImporter::class)->preview($operation)['fingerprint']);
    }

    #[DataProvider('invalidManifests')]
    public function test_unsupported_or_inconsistent_manifest_is_refused(string $case): void
    {
        $manifest = $this->manifest();
        if ($case === 'version') $manifest['schema'] = 2;
        if ($case === 'duplicate') $manifest['objects'][] = $manifest['objects'][0];
        if ($case === 'dangling') $manifest['roots'][] = 'script-2';
        if ($case === 'unknown-field') $manifest['objects'][0]['data']['is_admin'] = true;
        if ($case === 'malformed-reference') $manifest['objects'][0]['ref'] = '../../outside';
        if ($case === 'unknown-type') $manifest['objects'][0]['type'] = 'user';
        $this->expectException(RuntimeException::class);
        $this->prepare($manifest);
    }

    public static function invalidManifests(): array
    {
        return array_map(fn ($case) => [$case], ['version', 'duplicate', 'dangling', 'unknown-field', 'malformed-reference', 'unknown-type']);
    }

    public function test_an_oversized_text_manifest_is_refused(): void
    {
        $source = $this->directory.'/oversized.json';
        file_put_contents($source, str_repeat(' ', 16 * 1024 * 1024).json_encode($this->manifest()));
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->prepare($this->operation(), $source, null);
    }

    #[DataProvider('unsafeMembers')]
    public function test_archive_does_not_extract_unrecognized_or_unsafe_members(string $member): void
    {
        $source = $this->zip($this->manifest(), [$member => 'must not be written']);
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->prepare($this->operation(), $source, null);
    }

    public static function unsafeMembers(): array
    {
        return [['../outside'], ['/tmp/outside'], ['files/../../outside'], ['undeclared.txt'], ['files/script-1.php']];
    }

    public function test_archive_links_are_refused_even_for_a_declared_member(): void
    {
        $bytes = gzencode('image');
        $manifest = $this->assetManifest($bytes);
        $source = $this->zip($manifest, ['files/image-1.gz' => $bytes]);
        $zip = new ZipArchive;
        $zip->open($source);
        $zip->setExternalAttributesName('files/image-1.gz', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->prepare($this->operation(), $source, null);
    }

    public function test_duplicate_zip_member_names_are_refused(): void
    {
        $source = $this->directory.'/duplicates.zip';
        $process = new \Symfony\Component\Process\Process(['python3', '-c',
            'import sys,zipfile; z=zipfile.ZipFile(sys.argv[1],"w"); data=sys.stdin.read(); z.writestr("manifest.json",data); z.writestr("manifest.json",data); z.close()', $source]);
        $process->setInput(json_encode($this->manifest()));
        $process->mustRun();
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->prepare($this->operation(), $source, null);
    }

    #[DataProvider('invalidAssets')]
    public function test_inconsistent_or_unsupported_asset_is_refused(string $case): void
    {
        $bytes = gzencode('image');
        $manifest = $this->assetManifest($bytes);
        if ($case === 'checksum') $manifest['objects'][0]['file']['sha256'] = str_repeat('a', 64);
        if ($case === 'size') $manifest['objects'][0]['file']['size']++;
        if ($case === 'unsupported') $manifest['objects'][0]['data']['filename_extension'] = 'exe';
        if ($case === 'truncated-gzip') {
            $bytes = substr($bytes, 0, -5);
            $manifest = $this->assetManifest($bytes);
        }
        $source = $this->zip($manifest, ['files/image-1.gz' => $bytes]);
        $this->expectException(RuntimeException::class);
        app(TransferImporter::class)->prepare($this->operation(), $source, null);
    }

    public static function invalidAssets(): array
    {
        return [['checksum'], ['size'], ['unsupported'], ['truncated-gzip']];
    }

    public function test_a_staged_image_is_verified_before_it_can_be_registered(): void
    {
        $bytes = gzencode('image');
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $this->zip($this->assetManifest($bytes), ['files/image-1.gz' => $bytes]), null);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame('verified', $preview['objects'][0]['file_status']);
        $this->assertSame(0, Image::count());
        $verified = $operation->fresh()->options['files']['image-1'];
        $this->assertSame(hash('sha256', 'image'), $verified['uncompressed_sha256']);
        $this->assertSame(5, $verified['uncompressed_size']);
        $this->assertSame(0600, fileperms($this->workspace->path($operation, $verified['member'])) & 0777);
    }

    public function test_a_json_asset_missing_on_the_receiver_is_an_explicit_requirement(): void
    {
        $manifest = $this->assetManifest(gzencode('image'));
        $manifest['objects'][0]['file']['included'] = false;
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertFalse($preview['can_apply']);
        $this->assertSame('missing', $preview['objects'][0]['file_status']);
        $this->assertContains('file', array_column($preview['requirements'], 'field'));
    }

    public function test_same_content_is_reused_and_same_name_different_content_defaults_to_copy(): void
    {
        $data = $this->manifest()['objects'][0]['data'];
        $same = Script::create($data);
        $operation = $this->prepare($this->manifest());
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertSame('reuse', $preview['objects'][0]['action']);
        $this->assertSame($same->id, $preview['objects'][0]['target']);
        $same->update(['script' => 'different']);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertSame('copy', $preview['objects'][0]['action']);
        $this->assertNotEmpty($preview['objects'][0]['conflicts']);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame(1, Script::count());
    }

    public function test_a_receiver_file_changed_since_its_recorded_verification_is_not_reused(): void
    {
        $bytes = gzencode('image');
        $name = 'transfer-preview-'.bin2hex(random_bytes(6)).'.gz';
        $path = public_path('uploads/'.$name);
        $this->publicFiles[] = $path;
        file_put_contents($path, $bytes);
        Image::forceCreate(['filename' => 'sensor.img.gz', 'filename_on_server' => $name, 'filename_extension' => 'gz',
            'sha256' => str_repeat('a', 64), 'uncompressed_sha256' => hash('sha256', 'old image'), 'uncompressed_size' => 9]);
        $manifest = $this->assetManifest($bytes);
        $manifest['objects'][0]['file']['included'] = false;
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest));
        $this->assertFalse($preview['can_apply']);
        $this->assertSame('missing', $preview['objects'][0]['file_status']);
    }

    public function test_project_dependencies_and_os_check_are_validated_before_confirmation(): void
    {
        $manifest = $this->manifest();
        $manifest['objects'][0]['data']['script_type'] = 'firstboot';
        $manifest['objects'][] = $this->projectObject();
        $manifest['roots'] = ['project-1'];
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest));
        $this->assertTrue($preview['can_apply']);
        $manifest['objects'][1]['data']['os_check'] = false;
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest));
        $this->assertFalse($preview['can_apply']);
        $this->assertContains('selectedScripts', array_column($preview['requirements'], 'field'));
    }

    public static function mappedScriptPhases(): array
    {
        return [['postinstall', 'firstboot', false], ['firstboot', 'postinstall', true]];
    }

    #[DataProvider('mappedScriptPhases')]
    public function test_project_checks_follow_the_effective_mapped_script_phase(string $source, string $receiver, bool $allowed): void
    {
        $manifest = $this->manifest();
        $manifest['objects'][0]['data']['script_type'] = $source;
        $existing = Script::create(array_replace($manifest['objects'][0]['data'], ['name' => 'Receiver script', 'script_type' => $receiver]));
        $project = $this->projectObject();
        $project['data']['os_check'] = false;
        $manifest['objects'][] = $project;
        $manifest['roots'] = ['project-1'];
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest),
            ['objects' => ['script-1' => ['action' => 'reuse', 'target' => $existing->id]]]);
        $this->assertSame($allowed, $preview['can_apply']);
        if (!$allowed) $this->assertContains('selectedScripts', array_column($preview['requirements'], 'field'));
    }

    public function test_singleton_settings_conflict_requires_explicit_replacement(): void
    {
        Setting::create(['key' => 'netbox_site', 'value' => 'Existing site']);
        $manifest = $this->manifest();
        $manifest['roots'] = ['settings-1'];
        $manifest['objects'] = [['ref' => 'settings-1', 'type' => 'settings', 'omitted_secrets' => [],
            'data' => ['group' => 'netbox', 'values' => array_replace(NetBoxExport::DEFAULTS, ['site' => 'Tel Aviv'])]]];
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertFalse($preview['can_apply']);
        $preview = app(TransferImporter::class)->preview($operation, ['objects' => ['settings-1' => ['action' => 'replace', 'target' => 'netbox']]]);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame('Existing site', Setting::find('netbox_site')->value);
        $preview = app(TransferImporter::class)->preview($operation, ['objects' => ['settings-1' => ['action' => 'create']]]);
        $this->assertFalse($preview['can_apply'], 'Creating a singleton must not silently overwrite its existing value.');
    }

    public function test_host_conflicts_follow_mac_and_ip_even_when_hostnames_differ(): void
    {
        Host::create(['mac' => 'e4:5f:01:01:02:03', 'ip' => '172.20.5.10', 'hostname' => 'Existing']);
        $manifest = $this->manifest();
        $manifest['roots'] = ['host-1'];
        $manifest['objects'] = [['ref' => 'host-1', 'type' => 'host', 'omitted_secrets' => [],
            'data' => ['mac' => 'e4:5f:01:01:02:03', 'ip' => '172.20.5.11', 'hostname' => 'Imported']]];
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest));
        $this->assertFalse($preview['can_apply']);
        $this->assertNotEmpty($preview['objects'][0]['conflicts']);
    }

    public function test_reusing_a_project_does_not_import_its_unneeded_source_dependencies(): void
    {
        $existing = Project::create(['name' => 'Existing project', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'label_moment' => 'never', 'verify' => true, 'os_check' => true, 'split_image' => true, 'reprovision' => false, 'mark_boots' => 5]);
        $manifest = $this->manifest();
        $manifest['objects'][] = $this->projectObject();
        $manifest['roots'] = ['project-1'];
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation, ['objects' => ['project-1' => ['action' => 'reuse', 'target' => $existing->id]]]);
        $this->assertTrue($preview['can_apply']);
        $script = collect($preview['objects'])->firstWhere('ref', 'script-1');
        $this->assertSame('skip', $script['action']);
        $this->assertFalse($script['selected']);
    }

    public function test_reusing_an_incomplete_project_requires_its_receiver_image_to_be_ready(): void
    {
        $image = Image::forceCreate(['filename' => 'pending.img.gz', 'filename_on_server' => 'pending.gz',
            'filename_extension' => 'gz', 'sha256' => '', 'download_state' => 'checking']);
        $existing = Project::create(['name' => 'Incomplete project', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => true, 'os_check' => true, 'split_image' => true, 'reprovision' => false, 'mark_boots' => 5]);
        $manifest = $this->manifest();
        $manifest['objects'][] = $this->projectObject();
        $manifest['roots'] = ['project-1'];
        $preview = app(TransferImporter::class)->preview($this->prepare($manifest), ['objects' => ['project-1' => ['action' => 'reuse', 'target' => $existing->id]]]);
        $this->assertFalse($preview['can_apply']);
    }

    public function test_station_hash_is_portable_and_omitted_passwords_are_entered_as_passwords(): void
    {
        $bytes = gzencode('image');
        $manifest = $this->assetManifest($bytes);
        $project = $this->projectObject();
        $project['data']['scripts'] = [];
        $project['data']['image'] = 'image-1';
        $project['data']['station_setup'] = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'username' => 'provision', 'password' => 'original-linux-password', 'wifi' => true,
            'ssid' => 'Workshop', 'wifi_password' => 'original-wifi-password', 'country' => 'IL',
        ]));
        $manifest['objects'][] = $project;
        $manifest['roots'] = ['project-1'];
        $source = $this->zip($manifest, ['files/image-1.gz' => $bytes]);
        $protected = $this->directory.'/station.cmp';
        app(TransferCipher::class)->encrypt($source, $protected, 'shared-master-password', 'archive');
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $protected, 'shared-master-password');
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame($project['data']['station_setup']['password_hash'], $operation->fresh()->options['preview']['objects']['project-1']['data']['station_setup']['password_hash']);

        unset($manifest['objects'][1]['data']['station_setup']['password_hash'], $manifest['objects'][1]['data']['station_setup']['wifi']['password']);
        $manifest['objects'][1]['omitted_secrets'] = ['station_setup.password_hash', 'station_setup.wifi.password'];
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $this->zip($manifest, ['files/image-1.gz' => $bytes]), null);
        $preview = app(TransferImporter::class)->preview($operation, ['secrets' => ['project-1' => [
            'station_setup.password_hash' => 'replacement-linux-password', 'station_setup.wifi.password' => 'replacement-wifi-password',
        ]]]);
        $this->assertTrue($preview['can_apply']);
        $saved = $operation->fresh()->options['preview']['objects']['project-1']['data']['station_setup'];
        $this->assertSame($saved['password_hash'], crypt('replacement-linux-password', $saved['password_hash']));
        $this->assertStringNotContainsString('replacement-linux-password', json_encode($operation->fresh()->toArray()));
        $this->assertSame($preview['fingerprint'], app(TransferImporter::class)->preview($operation)['fingerprint']);
    }

    public function test_vlan_draft_requires_a_receiver_interface_mapping(): void
    {
        $this->app->instance(NetworkProfiles::class, new class extends NetworkProfiles {
            public function ethernetDevices(): ?array { return ['eth1']; }
        });
        $family = array_fill_keys(NetworkProfiles::PROPERTIES, '');
        $manifest = $this->manifest();
        $manifest['roots'] = ['network-1'];
        $manifest['objects'] = [['ref' => 'network-1', 'type' => 'network', 'omitted_secrets' => [], 'data' => [
            'name' => 'Management', 'device' => 'eth0.15', 'type' => 'vlan', 'vlan_id' => 15, 'parent' => 'eth0', 'draft' => true,
            'ipv4' => array_replace($family, ['method' => 'manual', 'addresses' => '192.0.2.2/24']),
            'ipv6' => array_replace($family, ['method' => 'auto']),
        ]]];
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertFalse($preview['can_apply']);
        $this->assertContains('interface', array_column($preview['requirements'], 'field'));
        $preview = app(TransferImporter::class)->preview($operation, ['interfaces' => ['network-1' => ['device' => 'eth1.15', 'parent' => 'eth1']]]);
        $this->assertTrue($preview['can_apply']);
    }

    public function test_notification_credential_is_validated_without_contacting_its_destination(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $manifest = $this->manifest();
        $manifest['roots'] = ['notification-1'];
        $manifest['objects'] = [['ref' => 'notification-1', 'type' => 'notification', 'omitted_secrets' => ['settings.url'],
            'data' => ['name' => 'Webhook', 'type' => 'webhook', 'enabled' => true, 'events' => ['failed'], 'settings' => []]]];
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation, ['secrets' => ['notification-1' => ['settings.url' => 'https://example.test/private-hook']]]);
        $this->assertTrue($preview['can_apply']);
        $this->assertStringNotContainsString('private-hook', json_encode($preview));
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_missing_secrets_can_be_supplied_without_becoming_public_preview_state(): void
    {
        $manifest = $this->labelManifest();
        $operation = $this->prepare($manifest);
        $preview = app(TransferImporter::class)->preview($operation);
        $this->assertFalse($preview['can_apply']);
        $this->assertContains('ftp_password', array_column($preview['requirements'], 'field'));
        $preview = app(TransferImporter::class)->preview($operation, ['secrets' => ['label-1' => ['ftp_password' => 'private-printer-password']]]);
        $this->assertTrue($preview['can_apply']);
        $this->assertStringNotContainsString('private-printer-password', json_encode($preview));
        $this->assertStringNotContainsString('private-printer-password', json_encode($operation->fresh()->toArray()));
    }

    public function test_omitted_credential_can_be_resolved_by_an_explicit_existing_object_mapping(): void
    {
        $existing = Label::create($this->labelManifest()['objects'][0]['data'] + ['ftp_password' => 'retained-secret']);
        $operation = $this->prepare($this->labelManifest());
        $preview = app(TransferImporter::class)->preview($operation, ['objects' => ['label-1' => ['action' => 'reuse', 'target' => $existing->id]]]);
        $this->assertTrue($preview['can_apply']);
        $this->assertSame($existing->id, $preview['objects'][0]['target']);
        $this->assertStringNotContainsString('retained-secret', json_encode($preview));
    }

    public function test_protected_text_and_binary_archives_use_the_same_preparation_path(): void
    {
        $manifest = $this->labelManifest();
        $manifest['objects'][0]['data']['ftp_password'] = 'protected-printer-password';
        $manifest['objects'][0]['omitted_secrets'] = [];
        $json = json_encode($manifest);
        $text = $this->directory.'/protected.json';
        file_put_contents($text, app(TransferCipher::class)->encodeText($json, 'shared-master-password'));
        $textOperation = $this->operation();
        app(TransferImporter::class)->prepare($textOperation, $text, 'shared-master-password');
        $zip = $this->zip($manifest);
        $encrypted = $this->directory.'/protected.cmp';
        app(TransferCipher::class)->encrypt($zip, $encrypted, 'shared-master-password', 'archive');
        $archiveOperation = $this->operation();
        app(TransferImporter::class)->prepare($archiveOperation, $encrypted, 'shared-master-password');
        foreach ([$textOperation, $archiveOperation] as $operation) {
            $preview = app(TransferImporter::class)->preview($operation);
            $this->assertTrue($preview['can_apply']);
            $this->assertStringNotContainsString('protected-printer-password', json_encode($preview));
            $this->assertNull($operation->fresh()->secrets);
        }
    }

    public function test_wrong_password_does_not_leave_partially_prepared_data(): void
    {
        $source = $this->directory.'/protected.json';
        file_put_contents($source, app(TransferCipher::class)->encodeText(json_encode($this->manifest()), 'right-master-password'));
        $operation = $this->operation();
        try {
            app(TransferImporter::class)->prepare($operation, $source, 'wrong-master-password');
            $this->fail('A wrong password must be refused.');
        } catch (RuntimeException $error) {
            $this->assertSame('failed', $operation->fresh()->status);
            $this->assertFileDoesNotExist($this->workspace->path($operation, 'manifest.json'));
            $this->assertNull($operation->fresh()->secrets);
        }
    }

    public function test_preparation_job_serializes_only_the_operation_identity(): void
    {
        $operation = $this->operation();
        $operation->update(['secrets' => ['password' => 'never-in-queue-payload']]);
        $job = new PrepareConfigurationTransfer($operation->id);
        $payload = serialize($job);
        $this->assertStringContainsString($operation->id, $payload);
        $this->assertStringNotContainsString('never-in-queue-payload', $payload);
        $this->assertSame('images', $job->queue);
    }

    private function operation()
    {
        return $this->workspace->create(User::factory()->create()->id, 'browser-session', 'import');
    }

    private function prepare(array $manifest)
    {
        $source = $this->directory.'/source-'.(++$this->sequence).'.json';
        file_put_contents($source, json_encode($manifest, JSON_THROW_ON_ERROR));
        $operation = $this->operation();
        app(TransferImporter::class)->prepare($operation, $source, null);
        return $operation;
    }

    private function zip(array $manifest, array $members = []): string
    {
        $source = $this->directory.'/source-'.(++$this->sequence).'.zip';
        $zip = new ZipArchive;
        $zip->open($source, ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        foreach ($members as $name => $content) $zip->addFromString($name, $content);
        $zip->close();
        return $source;
    }

    private function manifest(): array
    {
        return ['format' => 'cmprovision-configuration', 'schema' => 1, 'source' => '2.24.0', 'created_at' => now()->toIso8601String(),
            'roots' => ['script-1'], 'objects' => [['ref' => 'script-1', 'type' => 'script', 'omitted_secrets' => [],
                'data' => ['name' => 'Setup', 'script_type' => 'postinstall', 'priority' => 100, 'bg' => false, 'script' => "#!/bin/sh\ntrue\n"]]]];
    }

    private function labelManifest(): array
    {
        $manifest = $this->manifest();
        $manifest['roots'] = ['label-1'];
        $manifest['objects'] = [['ref' => 'label-1', 'type' => 'label', 'omitted_secrets' => ['ftp_password'],
            'data' => ['name' => 'Printer', 'printer_type' => 'ftp', 'ftp_hostname' => '192.0.2.10', 'ftp_username' => 'printer',
                'print_command' => '', 'template' => 'Serial $serial', 'file_extension' => 'txt']]];
        return $manifest;
    }

    private function projectObject(): array
    {
        return ['ref' => 'project-1', 'type' => 'project', 'omitted_secrets' => [], 'data' => [
            'name' => 'Sensor project', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never',
            'eeprom_settings' => '', 'verify' => true, 'os_check' => true, 'split_image' => true, 'reprovision' => false,
            'mark_boots' => 5, 'image' => null, 'label' => null, 'firmware' => null, 'station_setup' => null,
            'scripts' => ['script-1'], 'packages' => [],
        ]];
    }

    private function assetManifest(string $bytes): array
    {
        $manifest = $this->manifest();
        $manifest['roots'] = ['image-1'];
        $manifest['objects'] = [['ref' => 'image-1', 'type' => 'image', 'omitted_secrets' => [],
            'data' => ['filename' => 'sensor.img.gz', 'filename_extension' => 'gz', 'uncompressed_sha256' => null, 'uncompressed_size' => null],
            'file' => ['member' => 'files/image-1.gz', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'included' => true]]];
        return $manifest;
    }
}
