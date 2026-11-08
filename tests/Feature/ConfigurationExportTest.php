<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Models\Host;
use App\Models\Image;
use App\Models\Label;
use App\Models\NotificationChannel;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\Setting;
use App\Models\User;
use App\Services\Configuration\TransferCatalog;
use App\Services\Configuration\TransferCipher;
use App\Services\Configuration\TransferExporter;
use App\Services\Configuration\TransferWorkspace;
use App\Services\NetworkProfiles;
use App\Services\Sensor\ModuleMark;
use App\Services\Sensor\SavedAccounts;
use App\Support\StationSetupInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class ConfigurationExportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;
    private TransferWorkspace $workspace;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cmprov-export-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['cmprovision.firmware_dir' => $this->directory.'/firmware']);
        $this->workspace = new TransferWorkspace($this->directory.'/operations');
        $this->app->instance(TransferWorkspace::class, $this->workspace);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) @unlink($file);
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_selected_project_closes_its_graph_and_uses_export_local_references(): void
    {
        [$project, $image, $label, $script, $package] = $this->project();
        $manifest = app(TransferCatalog::class)->export(['projects' => [$project->id]], false);
        $this->assertSame('cmprovision-configuration', $manifest['format']);
        $this->assertSame(1, $manifest['schema']);
        $objects = collect($manifest['objects'])->keyBy('ref');
        $this->assertCount(6, $objects);
        $root = $objects[$manifest['roots'][0]];
        $this->assertSame('project', $root['type']);
        $this->assertSame('image', $objects[$root['data']['image']]['type']);
        $this->assertSame('label', $objects[$root['data']['label']]['type']);
        $this->assertSame('script', $objects[$root['data']['scripts'][0]]['type']);
        $this->assertSame('package', $objects[$root['data']['packages'][0]]['type']);
        $this->assertSame('firmware', $objects[$root['data']['firmware']]['type']);
        $this->assertArrayNotHasKey('image_id', $root['data']);
        $this->assertArrayNotHasKey('id', $root['data']);
        $this->assertSame('firstboot', $objects[$root['data']['scripts'][0]]['data']['script_type']);
        $this->assertFalse($objects[$root['data']['scripts'][0]]['data']['bg']);
        $this->assertSame(42, $objects[$root['data']['scripts'][0]]['data']['priority']);
        $this->assertSame($manifest, app(TransferCatalog::class)->validate($manifest));
    }

    public function test_standalone_script_and_label_do_not_export_unselected_objects(): void
    {
        [$project, $image, $label, $script] = $this->project();
        $other = Script::create(['name' => 'Unselected', 'script_type' => 'preinstall', 'bg' => false, 'priority' => 1, 'script' => 'true']);
        $manifest = app(TransferCatalog::class)->export(['scripts' => [$script->id], 'labels' => [$label->id]], false);
        $this->assertCount(2, $manifest['objects']);
        $this->assertCount(2, $manifest['roots']);
        $this->assertSame(['script', 'label'], array_column($manifest['objects'], 'type'));
        $this->assertStringNotContainsString('Unselected', json_encode($manifest));
    }

    public function test_omitted_label_and_station_secrets_are_explicit_requirements(): void
    {
        [$project, $image, $label] = $this->project();
        $label->update(['printer_type' => 'ftp', 'ftp_hostname' => '192.0.2.10', 'ftp_username' => 'printer', 'ftp_password' => 'printer-secret']);
        $project->station_setup = $this->station();
        $project->save();
        $objects = collect(app(TransferCatalog::class)->export(['projects' => [$project->id]], false)['objects'])->keyBy('type');
        $this->assertArrayNotHasKey('ftp_password', $objects['label']['data']);
        $this->assertContains('ftp_password', $objects['label']['omitted_secrets']);
        $this->assertArrayNotHasKey('password_hash', $objects['project']['data']['station_setup']);
        $this->assertArrayNotHasKey('password', $objects['project']['data']['station_setup']['wifi']);
        $this->assertContains('station_setup.password_hash', $objects['project']['omitted_secrets']);
        $this->assertContains('station_setup.wifi.password', $objects['project']['omitted_secrets']);
        $this->assertStringNotContainsString('printer-secret', json_encode($objects));
        $this->assertStringNotContainsString('wireless-secret', json_encode($objects));
    }

    public function test_selected_settings_use_a_closed_catalog_and_decrypt_source_credentials(): void
    {
        Setting::create(['key' => 'active_project', 'value' => '123']);
        Setting::create(['key' => 'usb_prep_until', 'value' => '9999999999']);
        Setting::create(['key' => 'configuration_transfer_password', 'value' => Crypt::encryptString('master-never-exported')]);
        Setting::create(['key' => 'fpms_color_mode', 'value' => 'light']);
        Setting::create(['key' => 'netbox_site', 'value' => 'Tel Aviv']);
        app(SavedAccounts::class)->save(null, 'Custom sensor', 'sensor', 'sensor-secret', false);
        app(ModuleMark::class)->set(str_repeat('ab', 32));

        $manifest = app(TransferCatalog::class)->export(['groups' => ['netbox', 'display', 'sensor_accounts', 'mark_key']], true);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Tel Aviv', $json);
        $this->assertStringContainsString('sensor-secret', $json);
        $this->assertStringContainsString(str_repeat('ab', 32), $json);
        $this->assertStringNotContainsString('master-never-exported', $json);
        $this->assertStringNotContainsString('active_project', $json);
        $this->assertStringNotContainsString('usb_prep_until', $json);
        $this->assertStringNotContainsString(Setting::find('reprovision_accounts')->value, $json);
        $display = collect($manifest['objects'])->first(fn ($object) => ($object['data']['group'] ?? '') === 'display');
        $this->assertSame('light', $display['data']['values']['fpms']['color_mode']);
        $this->assertArrayHasKey('projects', app(TransferCatalog::class)->choices());
        $this->assertArrayHasKey('sensor_accounts', app(TransferCatalog::class)->choices()['groups']);
    }

    public function test_switches_hosts_and_notifications_omit_polling_history_and_secrets(): void
    {
        EthernetSwitch::create(['host' => '192.0.2.77', 'snmp_version' => '2c', 'community' => 'read-secret',
            'write_community' => 'write-secret', 'sys_name' => 'detected-name', 'method_detected' => 'cisco',
            'base_mac' => 'aa:bb:cc:dd:ee:ff', 'own_macs' => ['aa:bb:cc:dd:ee:ff']]);
        Host::create(['mac' => 'e4:5f:01:01:02:03', 'ip' => '172.20.5.20', 'hostname' => 'sensor']);
        NotificationChannel::create(['name' => 'Webhook', 'type' => 'webhook', 'enabled' => true,
            'events' => ['failed'], 'settings' => ['url' => 'https://example.test/hook-secret', 'unknown_runtime' => 'discard'],
            'last_sent_at' => now(), 'last_error' => 'old-runtime-error']);
        $manifest = app(TransferCatalog::class)->export(['groups' => ['switches', 'hosts', 'notifications']], false);
        $objects = collect($manifest['objects'])->keyBy('type');
        $this->assertSame('192.0.2.77', $objects['switch']['data']['host']);
        $this->assertSame('172.20.5.20', $objects['host']['data']['ip']);
        $this->assertContains('community', $objects['switch']['omitted_secrets']);
        $this->assertContains('write.community', $objects['switch']['omitted_secrets']);
        $this->assertContains('settings.url', $objects['notification']['omitted_secrets']);
        $json = json_encode($manifest);
        foreach (['read-secret', 'write-secret', 'hook-secret', 'unknown_runtime', 'old-runtime-error', 'detected-name', 'own_macs', 'base_mac'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_network_profiles_remain_portable_drafts_with_routes_and_vlan_parent(): void
    {
        $ether = '00000000-0000-0000-0000-000000000001';
        $vlan = '00000000-0000-0000-0000-000000000002';
        $profiles = new NetworkProfiles(function (array $command) use ($ether, $vlan) {
            if (in_array('UUID,TYPE', $command, true)) return "$ether:802-3-ethernet\n$vlan:vlan";
            $property = $command[array_search('-g', $command, true) + 1];
            $tagged = end($command) === $vlan;
            return match ($property) {
                'connection.id' => $tagged ? 'Management' : 'Stand',
                'connection.interface-name' => $tagged ? 'eth0.15' : 'eth0',
                'vlan.id' => '15', 'vlan.parent' => $ether,
                'ipv4.method' => 'manual', 'ipv6.method' => 'auto',
                'ipv4.addresses' => $tagged ? '192.0.2.2/24' : '172.20.0.2/16',
                'ipv4.routes' => '198.51.100.0/24 192.0.2.1 20',
                default => '',
            };
        });
        $this->app->instance(NetworkProfiles::class, $profiles);
        $manifest = app(TransferCatalog::class)->export(['groups' => ['network']], false);
        $objects = collect($manifest['objects'])->where('type', 'network')->values();
        $this->assertCount(2, $objects);
        $tagged = $objects->first(fn ($object) => $object['data']['type'] === 'vlan');
        $this->assertSame(15, $tagged['data']['vlan_id']);
        $this->assertSame('eth0', $tagged['data']['parent']);
        $this->assertSame('eth0.15', $tagged['data']['device']);
        $this->assertSame('198.51.100.0/24 192.0.2.1 20', $tagged['data']['ipv4']['routes']);
        $this->assertSame('auto', $tagged['data']['ipv6']['method']);
        $this->assertTrue($tagged['data']['draft']);
        $this->assertStringNotContainsString($ether, json_encode($manifest));
        $this->assertStringNotContainsString($vlan, json_encode($manifest));
    }

    public function test_json_contains_file_descriptors_and_no_server_paths_or_implicit_downloads(): void
    {
        [$project, $image] = $this->project();
        $image->download_url = 'https://example.test/private-token';
        $image->save();
        $operation = $this->operation();
        app(TransferExporter::class)->prepare($operation, ['projects' => [$project->id]], 'json', false, null);
        $operation->refresh();
        $this->assertSame('ready', $operation->status);
        $manifest = json_decode(file_get_contents($this->workspace->path($operation, $operation->result['member'])), true, 64, JSON_THROW_ON_ERROR);
        $asset = collect($manifest['objects'])->firstWhere('type', 'image');
        $this->assertSame(filesize($image->imagepath()), $asset['file']['size']);
        $this->assertSame(hash_file('sha256', $image->imagepath()), $asset['file']['sha256']);
        $this->assertFalse($asset['file']['included']);
        $json = json_encode($manifest);
        $this->assertStringNotContainsString($image->filename_on_server, $json);
        $this->assertStringNotContainsString('private-token', $json);
        $this->assertStringNotContainsString(public_path(), $json);
        $this->assertSame(0600, fileperms($this->workspace->path($operation, $operation->result['member'])) & 0777);
    }

    public function test_archive_contains_only_selected_binary_types_and_stores_them_without_recompression(): void
    {
        [$project, $image] = $this->project();
        $operation = $this->operation();
        app(TransferExporter::class)->prepare($operation, ['projects' => [$project->id], 'binaries' => ['images', 'firmware']], 'archive', false, null);
        $operation->refresh();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->workspace->path($operation, $operation->result['member'])));
        $manifest = json_decode($zip->getFromName('manifest.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $zip->numFiles);
        foreach ($manifest['objects'] as $object) {
            if (!isset($object['file'])) continue;
            $file = $object['file'];
            if ($object['type'] === 'package') {
                $this->assertFalse($file['included']);
                $this->assertFalse($zip->locateName($file['member']));
            } else {
                $this->assertTrue($file['included']);
                $this->assertSame($file['sha256'], hash('sha256', $zip->getFromName($file['member'])));
                $this->assertSame(ZipArchive::CM_STORE, $zip->statName($file['member'])['comp_method']);
            }
        }
        $zip->close();
    }

    public function test_secrets_require_protection_and_do_not_leave_an_unprotected_artifact(): void
    {
        $operation = $this->operation();
        try {
            app(TransferExporter::class)->prepare($operation, ['groups' => ['mark_key']], 'json', true, null);
            $this->fail('Secret export must require a password.');
        } catch (\RuntimeException|ValidationException $error) {
            $this->assertFileDoesNotExist($this->workspace->path($operation, 'export.json'));
            $this->assertStringNotContainsString('master-password', json_encode($operation->fresh()->toArray()));
        }
    }

    public function test_protected_text_can_be_opened_after_changing_the_application_key(): void
    {
        [$project, $image, $label] = $this->project();
        $label->update(['printer_type' => 'ftp', 'ftp_hostname' => '192.0.2.10', 'ftp_username' => 'printer', 'ftp_password' => 'portable-printer-secret']);
        $setup = $this->station();
        $project->station_setup = $setup;
        $project->save();
        $operation = $this->operation();
        app(TransferExporter::class)->prepare($operation, ['projects' => [$project->id]], 'json', true, 'shared-master-password');
        $operation->refresh();
        $text = file_get_contents($this->workspace->path($operation, $operation->result['member']));
        $this->assertStringNotContainsString('portable-printer-secret', $text);
        $this->assertStringNotContainsString('wireless-secret', $text);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->app->forgetInstance('encrypter');
        $manifest = json_decode(app(TransferCipher::class)->decodeText($text, 'shared-master-password'), true, 64, JSON_THROW_ON_ERROR);
        $objects = collect($manifest['objects'])->keyBy('type');
        $this->assertSame('portable-printer-secret', $objects['label']['data']['ftp_password']);
        $this->assertSame($setup['password_hash'], $objects['project']['data']['station_setup']['password_hash']);
        $this->assertSame('wireless-secret', $objects['project']['data']['station_setup']['wifi']['password']);
    }

    public function test_generic_account_selection_and_sudo_policy_remain_when_password_is_omitted(): void
    {
        [$project] = $this->project();
        $setup = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'profile' => 'raspios', 'hostname' => '', 'username' => 'operator', 'source_username' => 'wlanpi',
            'password' => 'Fixture-Portable-Password', 'sudo_passwordless' => true]));
        $project->station_setup = $setup;
        $project->save();
        $manifest = app(TransferCatalog::class)->export(['projects' => [$project->id]], false);
        $object = collect($manifest['objects'])->firstWhere('type', 'project');
        $this->assertSame('raspios', $object['data']['station_setup']['profile']);
        $this->assertSame('wlanpi', $object['data']['station_setup']['source_username']);
        $this->assertTrue($object['data']['station_setup']['sudo_passwordless']);
        $this->assertArrayNotHasKey('password_hash', $object['data']['station_setup']);
        $this->assertContains('station_setup.password_hash', $object['omitted_secrets']);
        $this->assertStringNotContainsString($setup['password_hash'], json_encode($manifest));
    }

    public function test_pending_images_and_files_outside_the_store_are_refused(): void
    {
        [$project, $image] = $this->project();
        $image->download_state = 'checking';
        $image->save();
        $this->expectException(\RuntimeException::class);
        app(TransferCatalog::class)->export(['projects' => [$project->id]], false);
    }

    public function test_symlinked_assets_are_refused(): void
    {
        [$project, $image] = $this->project();
        unlink($image->imagepath());
        symlink('/etc/passwd', $image->imagepath());
        $this->expectException(\RuntimeException::class);
        app(TransferCatalog::class)->export(['projects' => [$project->id]], false);
    }

    public function test_unknown_settings_group_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        app(TransferCatalog::class)->export(['groups' => ['active_project']], false);
    }

    private function operation()
    {
        return $this->workspace->create(User::factory()->create()->id, 'browser-session', 'export');
    }

    private function station(): array
    {
        return StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'username' => 'provision', 'password' => 'linux-password', 'wifi' => true,
            'ssid' => 'Workshop', 'wifi_password' => 'wireless-secret', 'country' => 'IL',
        ]));
    }

    private function project(): array
    {
        $token = bin2hex(random_bytes(5));
        $imageFile = public_path('uploads/transfer-'.$token.'.gz');
        $packageFile = public_path('uploads/packages/transfer-'.$token.'.deb');
        foreach ([$imageFile, $packageFile] as $file) {
            if (!is_dir(dirname($file))) mkdir(dirname($file), 0700, true);
            $this->files[] = $file;
        }
        file_put_contents($imageFile, gzencode('disposable image'));
        file_put_contents($packageFile, 'disposable package');
        mkdir($this->directory.'/firmware/default', 0700, true);
        file_put_contents($this->directory.'/firmware/default/pieeprom-2026-10-01.bin', 'disposable eeprom');
        $image = Image::forceCreate(['filename' => 'station.img.gz', 'filename_on_server' => basename($imageFile),
            'filename_extension' => 'gz', 'sha256' => hash_file('sha256', $imageFile),
            'uncompressed_sha256' => hash('sha256', 'disposable image'), 'uncompressed_size' => strlen('disposable image')]);
        $label = Label::create(['name' => 'Sensor label', 'printer_type' => 'command', 'print_command' => 'lpr $file',
            'ftp_hostname' => '', 'ftp_username' => '', 'ftp_password' => '', 'template' => 'Serial $serial', 'file_extension' => 'txt']);
        $script = Script::create(['name' => 'Set sensor', 'script_type' => 'firstboot', 'bg' => false, 'priority' => 42, 'script' => "#!/bin/sh\ntrue\n"]);
        $package = Package::create(['filename' => 'sensor.deb', 'filename_on_server' => basename($packageFile),
            'size' => filesize($packageFile), 'sha256' => hash_file('sha256', $packageFile),
            'deb_package' => 'sensor-tools', 'deb_version' => '1.0', 'deb_architecture' => 'all']);
        $project = Project::create(['name' => 'Portable station', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_id' => $label->id, 'label_moment' => 'postinstall',
            'eeprom_firmware' => 'default/pieeprom-2026-10-01.bin', 'eeprom_settings' => "[all]\nBOOT_ORDER=0xf12\n",
            'verify' => true, 'os_check' => true, 'split_image' => true, 'reprovision' => false, 'mark_boots' => 5]);
        $project->scripts()->attach($script);
        $project->packages()->attach($package);
        return [$project, $image, $label, $script, $package];
    }
}
