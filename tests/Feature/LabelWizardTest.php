<?php

namespace Tests\Feature;

use App\Http\Livewire\Labels;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LabelWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_command_path_checks_each_step_and_renders_an_escaped_sample(): void
    {
        $component = Livewire::test(Labels::class)->call('createWizard')->call('nextWizardStep')->assertHasErrors('name')
            ->set('name', 'Serial label')->call('nextWizardStep')->assertSet('wizardStep', 2)
            ->set('print_command', '')->call('nextWizardStep')->assertHasErrors('print_command')
            ->set('print_command', 'cat $file')->call('nextWizardStep')->assertSet('wizardStep', 3)
            ->set('template', '')->call('nextWizardStep')->assertHasErrors('template')
            ->set('template', '<b>$serial</b>|$switch|$port')->call('nextWizardStep')->assertSet('wizardStep', 4)
            ->assertSeeHtml('&lt;b&gt;1000000012345678&lt;/b&gt;|sw01|Gi0/3');
        $component->call('previousWizardStep')->assertSet('template', '<b>$serial</b>|$switch|$port')
            ->call('nextWizardStep')->call('store')->assertHasNoErrors();
        $this->assertSame(1, Label::count());
    }

    public function test_ftp_password_is_private_and_is_not_reused_for_another_destination(): void
    {
        $component = Livewire::test(Labels::class)->call('createWizard')->set('name', 'FTP label')->set('printer_type', 'ftp')
            ->call('nextWizardStep')->set('ftp_hostname', '192.0.2.10')->set('ftp_username', 'printer')
            ->call('nextWizardStep', ['ftp_password' => 'private-printer-secret'])->assertSet('wizardStep', 3);
        $this->assertStringNotContainsString('private-printer-secret', json_encode($component->snapshot));
        $component->call('previousWizardStep')->set('ftp_hostname', '192.0.2.11')
            ->call('nextWizardStep')->assertHasErrors('ftp_password')->assertSet('wizardStep', 2)
            ->call('nextWizardStep', ['ftp_password' => 'new-destination-secret'])->assertHasNoErrors()
            ->call('nextWizardStep')->call('store')->assertHasNoErrors();
        $this->assertSame('new-destination-secret', Label::firstOrFail()->ftp_password);
    }

    public function test_copy_keeps_same_destination_credentials_private_and_creates_a_distinct_label(): void
    {
        $source = Label::create(['name' => 'Source', 'printer_type' => 'ftp', 'ftp_hostname' => '192.0.2.10',
            'ftp_username' => 'printer', 'ftp_password' => 'source-printer-secret', 'template' => '$serial', 'file_extension' => 'txt']);
        $component = Livewire::test(Labels::class)->call('createWizard', $source->id)
            ->assertSet('labelid', '')->assertSet('ftp_password', '')->assertSet('name', 'Source (copy)');
        $this->assertStringNotContainsString('source-printer-secret', json_encode($component->snapshot));
        for ($i = 0; $i < 3; $i++) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->call('store')->assertHasNoErrors();
        $this->assertSame(2, Label::count());
        $this->assertSame('source-printer-secret', Label::where('id', '!=', $source->id)->firstOrFail()->ftp_password);
        $this->assertSame('Source', $source->fresh()->name);
    }

    public function test_save_does_not_print_but_the_separate_test_action_does(): void
    {
        $path = sys_get_temp_dir().'/wizard-print-'.bin2hex(random_bytes(6));
        try {
            $component = Livewire::test(Labels::class)->call('createWizard')->set('name', 'Command label')
                ->set('print_command', 'cat $file > '.escapeshellarg($path));
            for ($i = 0; $i < 3; $i++) $component->call('nextWizardStep')->assertHasNoErrors();
            $component->call('printTestLabel')->assertHasNoErrors();
            $this->assertFileExists($path);
            unlink($path);
            $component->call('store')->assertHasNoErrors();
            $this->assertFileDoesNotExist($path);
        } finally { @unlink($path); }
    }

    public function test_incomplete_wizard_cannot_save_or_print_and_cancel_clears_its_state(): void
    {
        $component = Livewire::test(Labels::class)->call('createWizard')->call('store')->assertHasErrors('wizard')
            ->call('printTestLabel')->assertHasErrors('wizard');
        $token = $component->get('wizardToken');
        $component->call('cancel')->assertSet('wizardMode', false)->assertSet('isOpen', false);
        $this->assertSame(0, Label::count());
        $this->assertNull(session('configuration_wizards.'.$token));
    }

    public function test_switching_to_command_uses_command_requirements(): void
    {
        Livewire::test(Labels::class)->call('createWizard')->set('name', 'Command')->set('printer_type', 'ftp')
            ->call('nextWizardStep')->set('printer_type', 'command')->set('print_command', '')
            ->call('nextWizardStep')->assertHasErrors('print_command')->set('print_command', 'cat $file')
            ->call('nextWizardStep')->assertHasNoErrors()->assertSet('wizardStep', 3);
    }

    public function test_client_credentials_are_cleared_only_after_the_connection_step_consumes_them(): void
    {
        Livewire::test(Labels::class)->call('createWizard')->set('name', 'FTP')->set('printer_type', 'ftp')
            ->call('nextWizardStep', ['ftp_password' => 'new-private-password'])->assertNotDispatched('wizard-credentials-stored')
            ->set('ftp_hostname', '192.0.2.10')->set('ftp_username', 'printer')
            ->call('nextWizardStep', ['ftp_password' => 'new-private-password'])->assertDispatched('wizard-credentials-stored');
    }
}
