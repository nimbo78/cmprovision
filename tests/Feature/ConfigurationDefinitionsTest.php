<?php

namespace Tests\Feature;

use App\Models\Script;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Http\Livewire\Labels;
use App\Services\Configuration\Definitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Livewire\Livewire;

class ConfigurationDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_firstboot_scripts_are_normalized_for_sequential_execution(): void
    {
        $data = Definitions::script([
            'name' => 'Configure sensor',
            'script_type' => 'firstboot',
            'bg' => true,
            'priority' => 100,
            'script' => "#!/bin/sh\r\nset -e\r\nprintf ready\r\n",
        ]);

        $this->assertFalse($data['bg']);
        $this->assertSame('firstboot', $data['script_type']);
        $this->assertSame("#!/bin/sh\nset -e\nprintf ready\n", $data['script']);
        $this->assertSame(100, $data['priority']);
    }

    public function test_postinstall_scripts_keep_their_background_choice(): void
    {
        $data = Definitions::script([
            'name' => 'Background task',
            'script_type' => 'postinstall',
            'bg' => true,
            'priority' => 20,
            'script' => "#!/bin/sh\ntrue\n",
        ]);

        $this->assertTrue($data['bg']);
    }

    public function test_ftp_labels_require_a_complete_credential(): void
    {
        try {
            Definitions::label([
                'name' => 'Printer label',
                'printer_type' => 'ftp',
                'ftp_hostname' => '192.0.2.10',
                'ftp_username' => 'printer',
                'ftp_password' => '',
                'print_command' => '',
                'file_extension' => 'txt',
                'template' => 'Device $serial',
            ]);
            $this->fail('An FTP label with an omitted password must require a credential.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('ftp_password', $error->errors());
        }
    }

    public function test_command_labels_preserve_their_template_and_command(): void
    {
        $data = Definitions::label([
            'name' => 'Local printer',
            'printer_type' => 'command',
            'ftp_hostname' => '',
            'ftp_username' => '',
            'ftp_password' => '',
            'print_command' => '/usr/bin/lpr -o raw $file 2>&1',
            'file_extension' => 'jscript',
            'template' => 'Device $serial on $switch $port',
        ]);

        $this->assertSame('/usr/bin/lpr -o raw $file 2>&1', $data['print_command']);
        $this->assertSame('Device $serial on $switch $port', $data['template']);
    }

    public function test_projects_require_os_check_for_firstboot_scripts(): void
    {
        $script = Script::create([
            'name' => 'First boot setup',
            'script_type' => 'firstboot',
            'bg' => false,
            'priority' => 100,
            'script' => "#!/bin/sh\ntrue\n",
        ]);

        try {
            Definitions::project($this->projectInput(['selectedScripts' => [$script->id]]));
            $this->fail('A firstboot script must require the OS check.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('selectedScripts', $error->errors());
        }
    }

    public function test_station_setup_requires_verified_image_storage(): void
    {
        try {
            Definitions::project($this->projectInput(['stationEnabled' => true]));
            $this->fail('Station setup must require a verified image and supported storage.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('stationEnabled', $error->errors());
        }
    }

    public function test_a_plain_project_has_normalized_attributes_and_empty_relations(): void
    {
        $data = Definitions::project($this->projectInput());

        $this->assertSame('Portable project', $data['attributes']['name']);
        $this->assertSame('/dev/mmcblk0', $data['attributes']['storage']);
        $this->assertNull($data['attributes']['image_id']);
        $this->assertFalse($data['attributes']['os_check']);
        $this->assertSame([], $data['script_ids']);
        $this->assertSame([], $data['package_ids']);
        $this->assertNull($data['station_setup']);
    }

    public function test_an_existing_project_can_keep_its_pending_image(): void
    {
        $image = $this->pendingImage('own.img.gz');
        $project = Project::create([
            'name' => 'Existing', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never',
        ]);

        $data = Definitions::project($this->projectInput(['image_id' => $image->id]), $project);

        $this->assertSame($image->id, $data['attributes']['image_id']);
    }

    public function test_a_project_cannot_select_a_different_pending_image(): void
    {
        $own = $this->pendingImage('own.img.gz');
        $other = $this->pendingImage('other.img.gz');
        $project = Project::create([
            'name' => 'Existing', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $own->id, 'label_moment' => 'never',
        ]);

        try {
            Definitions::project($this->projectInput(['image_id' => $other->id]), $project);
            $this->fail('A project must not select a different image while that image is downloading.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('image_id', $error->errors());
        }
    }

    public function test_editing_a_label_through_the_full_form_retains_its_saved_password(): void
    {
        $label = Label::create([
            'name' => 'FTP printer', 'printer_type' => 'ftp',
            'ftp_hostname' => '192.0.2.10', 'ftp_username' => 'printer', 'ftp_password' => 'test-printer-password',
            'file_extension' => 'txt', 'template' => 'Device $serial',
        ]);

        Livewire::test(Labels::class)->call('edit', $label->id)
            ->set('name', 'Renamed printer')->call('store')->assertHasNoErrors();

        $this->assertSame('test-printer-password', $label->fresh()->ftp_password);
        $this->assertSame('Renamed printer', $label->fresh()->name);
    }

    private function pendingImage(string $name): Image
    {
        return Image::forceCreate([
            'filename' => $name, 'filename_on_server' => $name, 'filename_extension' => 'gz',
            'sha256' => '', 'download_state' => 'downloading',
        ]);
    }

    private function projectInput(array $changes = []): array
    {
        return array_replace([
            'name' => 'Portable project',
            'device' => 'cm4',
            'storage' => '/dev/mmcblk0',
            'image_id' => null,
            'label_id' => null,
            'firmware' => '',
            'eeprom_settings' => '',
            'label_moment' => 'never',
            'selectedScripts' => [],
            'selectedPackages' => [],
            'verify' => false,
            'os_check' => false,
            'split_image' => false,
            'reprovision' => false,
            'mark_boots' => 0,
            'stationEnabled' => false,
            'station' => [],
        ], $changes);
    }
}
