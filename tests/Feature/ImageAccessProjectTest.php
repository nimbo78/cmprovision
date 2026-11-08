<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Models\{Image, Project, User};
use App\Support\StationSetupInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ImageAccessProjectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->actingAs(User::factory()->create());
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function input(array $changes = []): array
    {
        return array_replace(StationSetupInput::form(), ['profile' => 'raspios', 'hostname' => '',
            'username' => 'operator', 'source_username' => 'wlanpi', 'password' => 'Fixture-Linux-Password',
            'sudo_passwordless' => false], $changes);
    }

    private function image(): Image
    {
        return Image::forceCreate(['filename' => 'raspios.img.xz', 'filename_extension' => 'xz',
            'filename_on_server' => 'image-access-fixture.xz', 'sha256' => str_repeat('a', 64)]);
    }

    private function key(): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    }

    public function test_legacy_station_definitions_remain_compatible_with_installed_schema_one_helpers(): void
    {
        $saved = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'username' => 'provision', 'password' => 'Fixture-Legacy-Password']));
        $this->assertSame(1, $saved['schema']);
        $this->assertSame(['schema', 'hostname', 'username', 'password_hash', 'keys', 'ssh', 'wifi'], array_keys($saved));
        $this->assertSame($saved, StationSetupInput::saved($saved));
        $this->assertSame($saved, StationSetupInput::normalize(StationSetupInput::form($saved), $saved));
    }

    public function test_generic_definition_retains_account_selection_and_sudo_policy_without_redisplaying_password(): void
    {
        $saved = StationSetupInput::normalize($this->input(['sudo_passwordless' => true]));
        $this->assertSame(2, $saved['schema']);
        $this->assertSame('raspios', $saved['profile']);
        $this->assertSame('wlanpi', $saved['source_username']);
        $this->assertSame('', $saved['hostname']);
        $this->assertTrue($saved['sudo_passwordless']);
        $this->assertNull($saved['wifi']);
        $this->assertSame($saved['password_hash'], crypt('Fixture-Linux-Password', $saved['password_hash']));
        $form = StationSetupInput::form($saved);
        $this->assertSame('', $form['password']);
        $this->assertSame($saved, StationSetupInput::normalize($form, $saved));
        $this->assertSame($saved, StationSetupInput::saved($saved));
    }

    public function test_generic_key_only_account_requires_passwordless_sudo(): void
    {
        $input = $this->input(['password_login' => false, 'keys' => $this->key(), 'sudo_passwordless' => false]);
        try {
            StationSetupInput::normalize($input);
            $this->fail('A key-only administrator must retain a usable sudo path.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('sudo_passwordless', $error->errors());
        }
        $saved = StationSetupInput::normalize(array_replace($input, ['sudo_passwordless' => true]));
        $this->assertNull($saved['password_hash']);
        $this->assertSame([$this->key()], $saved['keys']);
    }

    public function test_generic_profile_refuses_other_setup_profiles_and_noninteractive_account_names(): void
    {
        foreach ([['profile' => 'arbitrary'], ['source_username' => 'root'], ['source_username' => '../wlanpi'],
            ['username' => 'daemon'], ['ssh' => false], ['wifi' => true]] as $change) {
            try {
                StationSetupInput::normalize($this->input($change));
                $this->fail('Invalid generic setup was accepted: '.json_encode($change));
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
    }

    public function test_full_project_form_saves_generic_access_encrypted_and_can_edit_without_a_new_password(): void
    {
        $image = $this->image();
        $component = Livewire::test(Projects::class)->call('create')->set('name', 'SSH sensor')
            ->set('image_id', $image->id)->set('verify', true)->set('active', false)
            ->set('stationEnabled', true)->set('station', $this->input())
            ->assertSee('Existing Linux user')->assertSee('Sudo without a password')
            ->call('store')->assertHasNoErrors();
        $project = Project::firstOrFail();
        $saved = $project->station_setup;
        $this->assertSame('raspios', $saved['profile']);
        $raw = DB::table('projects')->where('id', $project->id)->value('station_setup');
        $this->assertStringNotContainsString('Fixture-Linux-Password', $raw);
        $this->assertStringNotContainsString($saved['password_hash'], $raw);
        $this->assertArrayNotHasKey('station_setup', $project->toArray());
        $component->call('edit', $project->id)->assertSet('station.profile', 'raspios')
            ->assertSet('station.password', '')->assertSet('station.source_username', 'wlanpi')
            ->set('station.sudo_passwordless', true)->call('store')->assertHasNoErrors();
        $this->assertSame($saved['password_hash'], $project->fresh()->station_setup['password_hash']);
        $this->assertTrue($project->fresh()->station_setup['sudo_passwordless']);
    }

    public function test_generic_wizard_keeps_credentials_private_and_copy_preserves_its_profile(): void
    {
        $this->image();
        $component = Livewire::test(Projects::class)->call('createWizard')->set('name', 'Guided SSH sensor')
            ->set('stationEnabled', true)->set('station', $this->input(['password' => '']));
        while ($component->get('wizardStep') < 6) $component->call('nextWizardStep')->assertHasNoErrors();
        $component->call('nextWizardStep', ['password' => 'Fixture-Wizard-Password'])->assertHasNoErrors()
            ->assertSet('wizardStep', 7);
        $this->assertStringNotContainsString('Fixture-Wizard-Password', json_encode($component->snapshot));
        $component->call('previousWizardStep')->call('nextWizardStep')->assertHasNoErrors()
            ->call('store')->assertHasNoErrors();
        $source = Project::firstOrFail();
        $this->assertSame('raspios', $source->station_setup['profile']);
        $copy = Livewire::test(Projects::class)->call('createWizard', $source->id)
            ->assertSet('station.profile', 'raspios')->assertSet('station.source_username', 'wlanpi')
            ->assertSet('station.password', '');
        while ($copy->get('wizardStep') < 7) $copy->call('nextWizardStep')->assertHasNoErrors();
        $copy->call('store')->assertHasNoErrors();
        $this->assertSame($source->station_setup, Project::orderByDesc('id')->first()->station_setup);
    }
}
