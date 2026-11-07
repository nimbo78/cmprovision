<?php

namespace Tests\Feature;

use App\Http\Livewire\Labels;
use App\Http\Livewire\Projects;
use App\Http\Livewire\Scripts;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use App\Services\Configuration\TransferExporter;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreationWizardTransferTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;
    private TransferWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $this->directory = sys_get_temp_dir().'/wizard-transfer-'.bin2hex(random_bytes(6));
        $this->workspace = new TransferWorkspace($this->directory);
        $this->app->instance(TransferWorkspace::class, $this->workspace);
    }

    protected function tearDown(): void
    {
        foreach (Image::all() as $image) @unlink($image->imagepath());
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public static function formats(): array { return [['json'], ['archive']]; }

    #[DataProvider('formats')]
    public function test_wizard_created_station_label_and_script_round_trip_as_distinct_copies(string $format): void
    {
        $scriptWizard = Livewire::test(Scripts::class)->call('createWizard')->set('name', 'First-boot setup')
            ->set('script_type', 'firstboot')->set('script', "#!/bin/sh\r\necho hello\r\n");
        while ($scriptWizard->get('wizardStep') < 5) $scriptWizard->call('nextWizardStep')->assertHasNoErrors();
        $scriptWizard->call('store')->assertHasNoErrors();
        $script = Script::firstOrFail();

        $labelWizard = Livewire::test(Labels::class)->call('createWizard')->set('name', 'Printer label')
            ->set('printer_type', 'ftp')->call('nextWizardStep')->set('ftp_hostname', '192.0.2.10')->set('ftp_username', 'printer')
            ->call('nextWizardStep', ['ftp_password' => 'portable-printer-secret'])->call('nextWizardStep')->call('store')->assertHasNoErrors();
        $label = Label::firstOrFail();

        $path = public_path('uploads/wizard-transfer-'.bin2hex(random_bytes(6)).'.gz');
        $bytes = 'disposable station image';
        file_put_contents($path, gzencode($bytes));
        $image = Image::forceCreate(['filename' => 'station.img.gz', 'filename_extension' => 'gz', 'filename_on_server' => basename($path),
            'sha256' => hash_file('sha256', $path), 'uncompressed_sha256' => hash('sha256', $bytes), 'uncompressed_size' => strlen($bytes)]);
        $projectWizard = Livewire::test(Projects::class)->call('createWizard')->set('name', 'Guided station')
            ->set('stationEnabled', true)->set('station.username', 'provision')->set('station.wifi', true)
            ->set('station.ssid', 'Workshop')->set('station.country', 'RU')->set('label_id', $label->id)->set('label_moment', 'postinstall')
            ->set('selectedScripts', [(string) $script->id]);
        while ($projectWizard->get('wizardStep') < 6) $projectWizard->call('nextWizardStep')->assertHasNoErrors();
        $projectWizard->call('nextWizardStep', ['password' => 'portable-linux-password', 'wifi_password' => 'portable-wifi-password'])
            ->call('store')->assertHasNoErrors();
        $project = Project::firstOrFail();

        $export = $this->workspace->create(auth()->id(), session()->getId(), 'export');
        app(TransferExporter::class)->prepare($export, ['projects' => [$project->id], 'binaries' => ['images']], $format, true, 'portable-master-password');
        $export->refresh();
        $import = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        app(TransferImporter::class)->prepare($import, $this->workspace->path($export, $export->result['member']), 'portable-master-password');
        $preview = app(TransferImporter::class)->preview($import, ['objects' => [
            'image-1' => ['action' => 'copy'], 'script-1' => ['action' => 'copy'],
            'label-1' => ['action' => 'copy'], 'project-1' => ['action' => 'copy'],
        ]]);
        $this->assertTrue($preview['can_apply']);
        $result = app(TransferImporter::class)->apply($import, $preview['fingerprint']);
        $copy = Project::findOrFail($result['objects']['project-1']['id']);
        $this->assertNotSame($project->id, $copy->id);
        $this->assertNotSame($image->id, $copy->image_id);
        $this->assertNotSame($script->id, $copy->scripts->first()->id);
        $this->assertNotSame($label->id, $copy->label_id);
        $this->assertSame("#!/bin/sh\necho hello\n", $copy->scripts->first()->script);
        $this->assertFalse((bool) $copy->scripts->first()->bg);
        $this->assertSame($label->template, $copy->label->template);
        $this->assertSame('portable-printer-secret', $copy->label->ftp_password);
        $this->assertSame('portable-wifi-password', $copy->station_setup['wifi']['password']);
        $this->assertSame('RU', $copy->station_setup['wifi']['country']);
        $this->assertSame($image->sha256, hash_file('sha256', $copy->image->imagepath()));
        $this->assertNull(Project::getActiveId());
        $this->assertSame($result, app(TransferImporter::class)->apply($import->fresh(), $preview['fingerprint']));
    }
}
