<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Models\Label;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_wizard_defaults_are_safe_and_the_complete_form_keeps_its_defaults(): void
    {
        Livewire::test(Projects::class)->call('createWizard')->assertSet('wizardMode', true)
            ->assertSet('wizardStep', 1)->assertSet('active', false)->assertSet('verify', true)
            ->assertSet('os_check', true)->assertSet('split_image', true)->assertSee('Purpose and name');
        Livewire::test(Projects::class)->call('create')->assertSet('wizardMode', false)->assertSet('active', true)->assertSet('verify', false);
    }

    public function test_each_step_checks_its_fields_and_back_keeps_input(): void
    {
        $component = Livewire::test(Projects::class)->call('createWizard')->call('nextWizardStep')
            ->assertHasErrors('name')->assertSet('wizardStep', 1)->set('name', 'Guided sensor')
            ->call('nextWizardStep')->assertSet('wizardStep', 2)->set('storage', '')
            ->call('nextWizardStep')->assertHasErrors('storage')->assertSet('wizardStep', 2)
            ->set('storage', '/dev/mmcblk0')->call('nextWizardStep')->assertSet('wizardStep', 3)
            ->call('previousWizardStep')->assertSet('wizardStep', 2)->assertSet('name', 'Guided sensor');
        $this->assertSame(0, Project::count());
    }

    public function test_save_before_summary_is_rejected_and_final_save_does_not_activate(): void
    {
        $component = Livewire::test(Projects::class)->call('createWizard')->set('name', 'Guided sensor')
            ->call('store')->assertHasErrors('wizard');
        $this->finish($component);
        $component->call('store')->assertHasNoErrors()->assertSet('isOpen', false);
        $this->assertSame(1, Project::count());
        $this->assertNull(Project::getActiveId());
    }

    public function test_copy_creates_a_new_project_with_its_dependencies(): void
    {
        $script = Script::create(['name' => 'Check', 'script_type' => 'postinstall', 'priority' => 100, 'bg' => false, 'script' => 'true']);
        $label = Label::create(['name' => 'Serial', 'printer_type' => 'command', 'print_command' => 'lpr $file', 'template' => '$serial', 'file_extension' => 'txt']);
        $source = Project::create(['name' => 'Source', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_id' => $label->id, 'label_moment' => 'postinstall', 'os_check' => true]);
        $source->scripts()->attach($script);
        $component = Livewire::test(Projects::class)->call('createWizard', $source->id)->assertSet('projectid', '')
            ->assertSet('active', false)->assertSet('name', 'Source (copy)');
        $this->finish($component);
        $component->call('store')->assertHasNoErrors();
        $copy = Project::where('id', '!=', $source->id)->firstOrFail();
        $this->assertSame([$script->id], $copy->scripts()->pluck('scripts.id')->all());
        $this->assertSame($label->id, $copy->label_id);
        $this->assertSame('Source', $source->fresh()->name);
    }

    public function test_activation_can_be_explicitly_selected_on_the_summary(): void
    {
        $component = Livewire::test(Projects::class)->call('createWizard')->set('name', 'Explicit activation');
        $this->finish($component);
        $component->set('active', true)->call('store')->assertHasNoErrors();
        $this->assertSame(Project::firstOrFail()->id, Project::getActiveId());
    }

    public function test_station_passwords_stay_private_across_back_and_final_save(): void
    {
        \App\Models\Image::forceCreate(['filename' => 'station.img.gz', 'filename_on_server' => 'wizard-disposable.gz', 'filename_extension' => 'gz', 'sha256' => str_repeat('a', 64)]);
        $component = Livewire::test(Projects::class)->call('createWizard')->set('name', 'Guided station')
            ->set('stationEnabled', true)->set('station.username', 'provision')
            ->set('station.wifi', true)->set('station.ssid', 'Workshop')->set('station.country', 'RU');
        while ($component->get('wizardStep') < 6) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->call('nextWizardStep', ['password' => 'private-linux-password', 'wifi_password' => 'private-wifi-password'])
            ->assertHasNoErrors()->assertSet('wizardStep', 7);
        $this->assertStringNotContainsString('private-linux-password', json_encode($component->snapshot));
        $this->assertStringNotContainsString('private-wifi-password', json_encode($component->snapshot));
        $this->assertStringNotContainsString('private-wifi-password', session('configuration_wizards.'.$component->get('wizardToken')));
        $component->call('previousWizardStep')->assertSet('wizardStep', 6)->call('nextWizardStep')->assertHasNoErrors()
            ->call('store')->assertHasNoErrors();
        $setup = Project::firstOrFail()->station_setup;
        $this->assertSame('private-wifi-password', $setup['wifi']['password']);
        $this->assertSame($setup['password_hash'], crypt('private-linux-password', $setup['password_hash']));
        $this->assertNull(Project::getActiveId());
    }

    public function test_disabling_os_check_keeps_the_existing_dependency_cleanup(): void
    {
        $script = Script::create(['name' => 'First boot', 'script_type' => 'firstboot', 'priority' => 100, 'bg' => false, 'script' => 'true']);
        Livewire::test(Projects::class)->call('createWizard')->set('selectedScripts', [(string) $script->id])
            ->set('selectedPackages', ['123'])->set('label_moment', 'firstboot')->set('os_check', false)
            ->assertSet('selectedScripts', [])->assertSet('selectedPackages', [])->assertSet('label_moment', 'postinstall');
    }

    public function test_cancel_discards_private_wizard_state(): void
    {
        $component = Livewire::test(Projects::class)->call('createWizard');
        $token = $component->get('wizardToken');
        $component->call('cancel')->assertSet('wizardMode', false)->assertSet('isOpen', false);
        $this->assertNull(session('configuration_wizards.'.$token));
    }

    public function test_script_step_rejects_firstboot_scripts_without_os_check(): void
    {
        $script = Script::create(['name' => 'First boot', 'script_type' => 'firstboot', 'priority' => 100, 'bg' => false, 'script' => 'true']);
        Livewire::test(Projects::class)->call('createWizard')->set('name', 'Sensor')
            ->call('nextWizardStep')->call('nextWizardStep')->set('os_check', false)->call('nextWizardStep')
            ->assertSet('wizardStep', 4)->set('selectedScripts', [(string) $script->id])
            ->call('nextWizardStep')->assertHasErrors('selectedScripts')->assertSet('wizardStep', 4);
    }

    public function test_name_conflict_is_reported_on_the_first_step(): void
    {
        Project::create(['name' => 'Existing', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never']);
        Livewire::test(Projects::class)->call('createWizard')->set('name', 'Existing')
            ->call('nextWizardStep')->assertHasErrors('name')->assertSet('wizardStep', 1);
    }

    private function finish($component): void
    {
        for ($i = 0; $i < 7 && $component->get('wizardStep') < 7; $i++) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->assertSet('wizardStep', 7);
    }
}
