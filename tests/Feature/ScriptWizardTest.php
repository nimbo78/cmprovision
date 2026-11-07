<?php

namespace Tests\Feature;

use App\Http\Livewire\Scripts;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_defaults_and_step_validation_use_the_shared_script_definition(): void
    {
        Livewire::test(Scripts::class)->call('createWizard')->assertSet('script', "#!/bin/sh\nset -e\n\n")
            ->call('nextWizardStep')->assertHasErrors('name')->set('name', 'Guided script')
            ->call('nextWizardStep')->assertSet('wizardStep', 2)->set('script_type', 'invalid')
            ->call('nextWizardStep')->assertHasErrors('script_type')->set('script_type', 'postinstall')
            ->call('nextWizardStep')->assertSet('wizardStep', 3)->set('priority', 'fast')
            ->call('nextWizardStep')->assertHasErrors('priority')->set('priority', 10)
            ->call('nextWizardStep')->assertSet('wizardStep', 4)->set('script', '')
            ->call('nextWizardStep')->assertHasErrors('script');
    }

    public function test_firstboot_is_sequential_after_changing_phase_and_at_save(): void
    {
        $component = Livewire::test(Scripts::class)->call('createWizard')->set('name', 'First boot')
            ->call('nextWizardStep')->set('bg', true)->set('script_type', 'firstboot')->assertSet('bg', false)
            ->call('nextWizardStep')->assertSee('Sequential execution');
        while ($component->get('wizardStep') < 5) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->set('bg', true)->call('store')->assertHasNoErrors();
        $this->assertFalse((bool) Script::firstOrFail()->bg);
    }

    public function test_back_preserves_content_and_the_final_save_normalizes_line_endings(): void
    {
        $component = Livewire::test(Scripts::class)->call('createWizard')->set('name', 'Retained body');
        while ($component->get('wizardStep') < 4) $component->call('nextWizardStep');
        $component->set('script', "#!/bin/sh\r\necho hello\r\n")->call('nextWizardStep')
            ->call('previousWizardStep')->assertSet('script', "#!/bin/sh\r\necho hello\r\n")
            ->call('nextWizardStep')->call('store')->assertHasNoErrors();
        $this->assertSame("#!/bin/sh\necho hello\n", Script::firstOrFail()->script);
    }

    public function test_copy_creates_a_distinct_record_with_the_original_content(): void
    {
        $source = Script::create(['name' => 'Source', 'script_type' => 'preinstall', 'bg' => true, 'priority' => 42, 'script' => 'echo source']);
        $component = Livewire::test(Scripts::class)->call('createWizard', $source->id)->assertSet('scriptid', '')
            ->assertSet('name', 'Source (copy)')->assertSet('script', 'echo source');
        while ($component->get('wizardStep') < 5) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->call('store')->assertHasNoErrors();
        $this->assertSame(2, Script::count());
        $this->assertSame('Source', $source->fresh()->name);
    }

    public function test_incomplete_save_is_blocked_and_cancel_discards_the_draft(): void
    {
        $component = Livewire::test(Scripts::class)->call('createWizard')->call('store')->assertHasErrors('wizard');
        $token = $component->get('wizardToken');
        $component->call('cancel')->assertSet('isOpen', false)->assertSet('wizardMode', false);
        $this->assertNull(session('configuration_wizards.'.$token));
        $this->assertSame(0, Script::count());
    }

    public function test_the_complete_form_remains_available(): void
    {
        Livewire::test(Scripts::class)->call('create')->assertSet('wizardMode', false)->set('name', 'Full form')
            ->set('script', 'true')->call('store')->assertHasNoErrors();
        $this->assertSame('Full form', Script::firstOrFail()->name);
    }
}
