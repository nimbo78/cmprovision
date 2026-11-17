<?php

namespace Tests\Feature;

use App\Http\Livewire\StationSetupWizard;
use App\Http\Livewire\NetworkSettings;
use App\Models\Image;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetworkChanges;
use App\Services\NetworkProfiles;
use App\Services\NetworkStatus;
use App\Services\StationWizardState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class StationSetupWizardTest extends TestCase
{
    use RefreshDatabase;
    private string $public;
    private string $originalPublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPublic = public_path();
        $this->public = sys_get_temp_dir().'/cmp-wizard-'.bin2hex(random_bytes(8));
        mkdir($this->public.'/uploads', 0700, true);
        $this->app->usePublicPath($this->public);
    }

    protected function tearDown(): void
    {
        $this->app->usePublicPath($this->originalPublic);
        File::deleteDirectory($this->public);
        parent::tearDown();
    }

    private function network(string $role = 'secondary', bool $pending = false, bool $known = true): NetworkChanges
    {
        $control = $this->createMock(NetworkChanges::class);
        $control->method('available')->willReturn(true);
        $control->method('status')->willReturn(['ok' => $known, 'result' => ['pending' => $pending, 'token' => 'never-in-browser']]);
        $service = fn ($on) => ['active' => $on, 'enabled' => $on ? 'enabled' : 'masked', 'boot_enabled' => $on];
        $control->method('roleState')->willReturn(['ok' => $known, 'result' => ['dhcp' => $service($role === 'primary'), 'usb' => $service(false)]]);
        $this->app->instance(NetworkChanges::class, $control);
        $profiles = $this->createStub(NetworkProfiles::class);
        $stand = in_array($role, ['primary', 'secondary']) ? ['device' => 'eth0', 'uuid' => NetworkSettingsTest::UUID, 'type' => 'ethernet', 'address' => $role === 'primary' ? '172.20.0.1' : '172.20.0.2'] : null;
        $profiles->method('active')->willReturn($stand ? [$stand] : []);
        $profiles->method('stand')->willReturn($stand);
        // Explicitly distinguish a confirmed absent stand from a failed/ambiguous address read.
        $profiles->method('standState')->willReturn(['known' => true, 'stand' => $stand]);
        $this->app->instance(NetworkProfiles::class, $profiles);
        $status = $this->createStub(NetworkStatus::class);
        $status->method('read')->willReturn(['links' => []]);
        $this->app->instance(NetworkStatus::class, $status);
        return $control;
    }

    public function test_wizard_is_authenticated_and_not_a_public_registration_route(): void
    {
        $this->get('/settings/setup')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_next_and_finish_never_change_roles_or_activate_a_project(): void
    {
        $control = $this->network();
        $control->expects($this->never())->method('configureStand');
        $this->actingAs(User::factory()->create());
        Livewire::test(StationSetupWizard::class)->assertSet('step', 1)->call('go', 2)->assertSet('step', 2)
            ->call('go', 7)->call('finish')->assertRedirect(route('settings', ['tab' => 'system']));
        $this->assertNull(Project::getActiveId());
        $this->assertNotNull(Setting::find('station_wizard_completed'));
    }

    public function test_pending_or_unavailable_network_status_blocks_navigation_and_finish(): void
    {
        $this->network(pending: true);
        $this->actingAs(User::factory()->create());
        Livewire::test(StationSetupWizard::class)->call('go', 2)->assertSet('step', 1)->assertHasErrors('wizard');
        $this->network(known: false);
        $state = app(StationWizardState::class)->read();
        $this->assertSame('unknown', $state['role']);
        $this->assertNotEmpty($state['blockers']);
    }

    public function test_disabled_station_can_finish_without_a_stand_profile_or_project(): void
    {
        $this->network('disabled');
        $state = app(StationWizardState::class)->read();
        $this->assertSame('disabled', $state['role']);
        $this->assertSame([], $state['blockers']);
        $this->actingAs(User::factory()->create());
        Livewire::test(StationSetupWizard::class)->call('go', 7)->call('finish')->assertHasNoErrors()->assertRedirect();
    }

    public function test_primary_requires_an_active_project_and_actual_image_and_hash(): void
    {
        $this->network('primary');
        $this->assertNotEmpty(app(StationWizardState::class)->read()['blockers']);
        $image = new Image;
        $image->filename = $image->filename_on_server = 'fixture.img.gz'; $image->filename_extension = 'gz'; $image->sha256 = ''; $image->save();
        $project = Project::create(['name' => 'Fixture', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'verify' => true]);
        Setting::create(['key' => 'active_project', 'value' => $project->id]);
        $this->assertFalse(app(StationWizardState::class)->projectReady()['ready']);
        file_put_contents($image->imagepath(), 'compressed image fixture');
        $this->assertFalse(app(StationWizardState::class)->projectReady()['ready']);
        $image->sha256 = $image->uncompressed_sha256 = str_repeat('a', 64); $image->save();
        $this->assertTrue(app(StationWizardState::class)->projectReady()['ready']);
        mkdir($this->public.'/firmware/default', 0700, true);
        config(['cmprovision.firmware_dir' => $this->public.'/firmware']);
        $firmware = ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n");
        file_put_contents($this->public.'/firmware/default/pieeprom-2026-09-23.bin', $firmware);
        $project->eeprom_firmware = 'default/pieeprom-2026-09-23.bin'; $project->eeprom_settings = "[all]\nBOOT_UART=0\n"; $project->save();
        $this->assertFalse(app(StationWizardState::class)->projectReady()['ready']);
        file_put_contents(public_path('uploads/pieeprom.bin'), $firmware);
        Setting::create(['key' => 'active_eeprom_sha256', 'value' => hash('sha256', 'different EEPROM')]);
        $this->assertFalse(app(StationWizardState::class)->projectReady()['ready']);
        Setting::whereKey('active_eeprom_sha256')->update(['value' => hash('sha256', $firmware)]);
        $this->assertTrue(app(StationWizardState::class)->projectReady()['ready']);
        $this->assertSame([], app(StationWizardState::class)->read()['blockers']);
    }

    public function test_wizard_primary_apply_is_blocked_until_the_project_is_ready(): void
    {
        $control = $this->network('disabled');
        $control->expects($this->never())->method('configureStand');
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(NetworkSettings::class, ['section' => 'role'])->set('stationRole', 'primary')->call('configureStand')
            ->assertSee('Prepare and activate a ready project before enabling the primary station.');
    }

    public function test_an_invalid_step_and_premature_finish_do_not_complete_setup(): void
    {
        $this->network();
        $this->actingAs(User::factory()->create());
        Livewire::test(StationSetupWizard::class)->call('go', 999)->assertSet('step', 1)->call('finish')->assertHasErrors('wizard');
        $this->assertNull(Setting::find('station_wizard_completed'));
    }

    public function test_review_does_not_serialize_network_confirmation_tokens(): void
    {
        $this->network();
        $this->actingAs(User::factory()->create());
        $page = Livewire::test(StationSetupWizard::class)->call('go', 7);
        $this->assertStringNotContainsString('never-in-browser', json_encode($page->snapshot).$page->html());
    }

    public function test_failed_address_read_is_not_mistaken_for_a_disabled_station(): void
    {
        $this->network('disabled');
        $reader = new NetworkProfiles(fn ($argv) => in_array('nmcli', $argv, true) ? '' : null);
        $this->app->instance(NetworkProfiles::class, $reader);
        $this->assertSame('unknown', app(StationWizardState::class)->read()['role']);
    }
}
