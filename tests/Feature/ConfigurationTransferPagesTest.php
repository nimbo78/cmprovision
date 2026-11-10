<?php

namespace Tests\Feature;

use App\Http\Livewire\ConfigurationTransfer;
use App\Jobs\PrepareConfigurationTransfer;
use App\Models\ConfigurationTransferOperation;
use App\Models\Script;
use App\Models\User;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferPassword;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class ConfigurationTransferPagesTest extends TestCase
{
    use RefreshDatabase;
    private string $directory;
    private TransferWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        $this->directory = sys_get_temp_dir().'/cmprov-pages-'.bin2hex(random_bytes(6));
        $this->workspace = new TransferWorkspace($this->directory);
        $this->app->instance(TransferWorkspace::class, $this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_guests_cannot_open_transfer_or_downloads(): void
    {
        $this->get('/settings/transfer')->assertRedirect('/login');
        $this->get('/settings/transfer/00000000-0000-0000-0000-000000000001/download')->assertRedirect('/login');
    }

    public function test_only_the_settings_backup_card_links_to_configuration_transfer(): void
    {
        $this->app->instance(\App\Services\ServiceState::class, new \Tests\Support\FakeServiceState(['cmprovision-queue']));
        $this->actingAs(User::factory()->create());
        $this->get('/settings/transfer')->assertOk()->assertSee('Configuration transfer')->assertSee('Master password');
        $this->get('/settings?tab=system')->assertOk()->assertSee('Selective import / export')
            ->assertSeeHtml('href="'.route('settings.transfer').'"');
        $this->script();
        \App\Models\Label::create(['name' => 'Portable label', 'printer_type' => 'command', 'print_command' => 'cat $file',
            'template' => '$serial', 'file_extension' => 'txt']);
        \App\Models\Project::create(['name' => 'Portable project', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never']);
        foreach (['/projects', '/scripts', '/labels'] as $page) {
            $this->get($page)->assertOk()->assertDontSee('href="'.route('settings.transfer'), false)
                ->assertSee('Create with wizard');
        }
    }

    public function test_saved_master_password_changes_require_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ConfigurationTransfer::class)->call('saveMasterPassword', 'portable-master-password')->assertForbidden();
        $this->assertFalse(app(TransferPassword::class)->configured());
    }

    public function test_password_is_an_action_argument_and_never_in_the_component_snapshot(): void
    {
        $this->confirmed();
        $component = Livewire::test(ConfigurationTransfer::class)->call('saveMasterPassword', 'portable-master-password')->assertHasNoErrors();
        $this->assertSame('portable-master-password', app(TransferPassword::class)->read());
        $this->assertStringNotContainsString('portable-master-password', json_encode($component->snapshot));
        $component->call('forgetMasterPassword');
        $this->assertFalse(app(TransferPassword::class)->configured());
    }

    public function test_selected_export_is_queued_without_serializing_its_password(): void
    {
        $this->confirmed();
        $script = $this->script();
        $component = Livewire::test(ConfigurationTransfer::class)
            ->set('selection.scripts', [$script->id])->set('protectExport', true)
            ->call('startExport', 'portable-master-password')->assertHasNoErrors();
        $operation = ConfigurationTransferOperation::findOrFail($component->get('operationId'));
        $this->assertSame('queued', $operation->status);
        $component->assertSeeHtml('aria-label="Transfer preparation"');
        $html = $component->html();
        $this->assertLessThan(strpos($html, '>Master password</h2>'), strpos($html, '>Current transfer</h2>'), 'The active transfer must be above the password and selection forms.');
        $this->assertSame('portable-master-password', $operation->secrets['password']);
        Bus::assertDispatched(PrepareConfigurationTransfer::class, fn ($job) => $job->operationId === $operation->id && !str_contains(serialize($job), 'portable-master-password'));
    }

    public function test_text_import_prepares_previews_and_applies_only_after_confirmation(): void
    {
        $this->confirmed();
        $component = Livewire::test(ConfigurationTransfer::class)->call('startTextImport', $this->text(), null)->assertHasNoErrors();
        $operation = ConfigurationTransferOperation::findOrFail($component->get('operationId'));
        $component->assertDispatched('transfer-opened', id: $operation->id);
        $this->runPreparation($operation);
        $component->call('refreshOperation')->assertSet('status', 'previewed')->assertSee('Portable script');
        $this->assertSame(0, Script::count());
        $component->call('applyImport')->assertHasNoErrors()->assertSet('status', 'applying');
        (new \App\Jobs\ApplyConfigurationTransfer($operation->id))->handle(app(TransferImporter::class));
        $component->call('refreshOperation')->assertSet('status', 'applied')->assertSee('Import complete');
        $this->assertSame(1, Script::count());
        $component->call('applyImport');
        $this->assertSame(1, Script::count());
    }

    public function test_file_import_uses_the_same_preparation_queue(): void
    {
        $this->confirmed();
        $file = UploadedFile::fake()->createWithContent('portable.json', $this->text());
        $response = $this->post('/settings/transfer/import', ['configuration' => $file]);
        $operation = ConfigurationTransferOperation::firstOrFail();
        $response->assertRedirect(route('settings.transfer', ['operation' => $operation->id]));
        $this->assertSame($this->text(), file_get_contents($this->workspace->path($operation, 'source')));
        Bus::assertDispatched(PrepareConfigurationTransfer::class);
    }

    public function test_another_session_cannot_preview_or_download_an_operation(): void
    {
        $this->confirmed();
        $operation = $this->workspace->create(auth()->id(), 'another-browser-session', 'import');
        Livewire::test(ConfigurationTransfer::class)->call('openOperation', $operation->id)->assertForbidden();
        $this->get(route('settings.transfer.download', $operation->id))->assertForbidden();
    }

    public function test_ready_download_is_private_and_expires_with_its_operation(): void
    {
        $this->confirmed();
        $operation = $this->workspace->create(auth()->id(), session()->getId(), 'export');
        file_put_contents($this->workspace->path($operation, 'export.json'), '{"safe":true}');
        $operation->update(['status' => 'ready', 'format' => 'json',
            'result' => ['member' => 'export.json', 'filename' => 'cmprovision-configuration.json', 'bytes' => 13]]);
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('settings.transfer.download', $operation->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('{"safe":true}');
        $operation->update(['expires_at' => now()->subSecond()]);
        $this->get(route('settings.transfer.download', $operation->id))->assertForbidden();
    }

    public function test_preparation_and_application_are_not_get_routes(): void
    {
        $this->confirmed();
        $this->get('/settings/transfer/import')->assertStatus(405);
        $this->assertSame(0, Script::count());
        // The route is in the web group: normal requests retain CSRF protection.
        $route = app('router')->getRoutes()->getByName('settings.transfer.import');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_changed_choices_need_another_preview_and_review_passwords_stay_private(): void
    {
        $this->confirmed();
        $component = Livewire::test(ConfigurationTransfer::class)->call('startTextImport', $this->text());
        $operation = ConfigurationTransferOperation::findOrFail($component->get('operationId'));
        $this->runPreparation($operation);
        $component->call('refreshOperation')->set('choices.objects.script-1.name', 'Renamed')
            ->call('applyImport')->assertHasErrors('transfer');
        $this->assertSame(0, Script::count());
        $component->call('review')->assertSet('status', 'reviewing');
        (new \App\Jobs\ReviewConfigurationTransfer($operation->id))->handle(app(TransferImporter::class));
        $component->call('refreshOperation')->assertSet('previewDirty', false)->call('applyImport');
        (new \App\Jobs\ApplyConfigurationTransfer($operation->id))->handle(app(TransferImporter::class));
        $this->assertSame('Renamed', Script::firstOrFail()->name);
    }

    public function test_display_followup_uses_server_saved_preferences_after_separate_confirmation(): void
    {
        $this->confirmed();
        $desired = ['wallboard' => ['mode' => 'auto', 'palette' => 'warm-light', 'view' => 'flow', 'rotation' => 15],
            'fpms_service' => ['enabled' => true, 'open_on_start' => true], 'hdmi_auto' => true];
        $this->mock(\App\Services\Wallboard::class)->shouldReceive('save')->once()->with($desired['wallboard']);
        $this->mock(\App\Services\Fpms::class)->shouldReceive('save')->once()->with($desired['fpms_service']);
        $row = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        $row->update(['status' => 'applied', 'result' => ['counts' => ['created' => 0, 'replaced' => 0, 'reused' => 0, 'skipped' => 0],
            'objects' => [], 'followups' => ['display' => $desired]]]);
        Livewire::test(ConfigurationTransfer::class)->call('openOperation', $row->id)->call('applyDisplayPreferences')->assertHasNoErrors();
        $this->assertSame('auto', \App\Models\Setting::find('hdmi_color_mode')->value);
        $this->assertArrayNotHasKey('display', $row->fresh()->result['followups']);
    }

    public function test_network_draft_prefills_the_normal_editor_without_applying_it(): void
    {
        $this->confirmed();
        $base = array_fill_keys(\App\Services\NetworkProfiles::PROPERTIES, '');
        $draft = ['data' => ['name' => 'Management', 'device' => 'source.15', 'type' => 'vlan', 'vlan_id' => 15, 'parent' => 'source',
            'ipv4' => array_replace($base, ['method' => 'manual', 'addresses' => '192.0.2.2/24', 'routes' => '198.51.100.0/24 192.0.2.1 20']),
            'ipv6' => array_replace($base, ['method' => 'disabled'])],
            'interface' => ['device' => 'eth0.15', 'parent' => 'eth0']];
        $row = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        $row->update(['status' => 'applied', 'options' => ['drafts' => ['network-1' => $draft]]]);
        session()->put('configuration_transfer_draft', ['operation' => $row->id, 'ref' => 'network-1']);
        $uuid = '8cce0781-f5bf-4442-8fde-8621a14ca422';
        $reader = $this->mock(\App\Services\NetworkProfiles::class);
        $reader->shouldReceive('active')->andReturn([]);
        $reader->shouldReceive('stand')->andReturn(null);
        $reader->shouldReceive('saved')->andReturn([['uuid' => $uuid, 'name' => 'Local management', 'type' => 'vlan', 'device' => 'eth0.15']]);
        $reader->shouldReceive('portable')->andReturn([array_replace($draft['data'], ['device' => 'eth0.15', 'parent' => 'eth0'])]);
        $reader->shouldReceive('ethernetDevices')->andReturn(['eth0']);
        $changes = $this->mock(\App\Services\NetworkChanges::class);
        $changes->shouldReceive('available')->andReturn(true);
        $changes->shouldReceive('status')->andReturn(['ok' => true, 'result' => ['pending' => false]]);
        $changes->shouldNotReceive('begin');
        $changes->shouldNotReceive('connection');
        Livewire::test(\App\Http\Livewire\NetworkSettings::class)->call('loadImportedDraft', $uuid)
            ->assertSet('uuid', $uuid)->assertSet('editingSaved', true)
            ->assertSet('ipv4.addresses', '192.0.2.2/24')->assertSet('ipv4.routes', '198.51.100.0/24 192.0.2.1 20')
            ->assertSee('Apply and connect temporarily');
    }

    public function test_dhcp_followup_generates_normal_reservations_and_preserves_other_options(): void
    {
        $this->confirmed();
        $row = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        $row->update(['status' => 'applied', 'result' => ['counts' => ['created' => 1, 'replaced' => 0, 'reused' => 0, 'skipped' => 0],
            'objects' => [], 'followups' => ['dhcp' => true]]]);
        $path = $this->directory.'/dnsmasq.conf';
        file_put_contents($path, "interface=eth0\ndhcp-range=172.20.0.11,172.20.255.254,24h\ndhcp-host=old\n");
        config(['cmprovision.dnsmasq_conf' => $path, 'cmprovision.dnsmasq_restart' => 'true']);
        \App\Models\Host::create(['mac' => 'e4:5f:01:01:02:03', 'ip' => '172.20.1.23', 'hostname' => 'sensor']);
        Livewire::test(ConfigurationTransfer::class)->call('openOperation', $row->id)->call('applyDhcpReservations')->assertHasNoErrors();
        $this->assertStringContainsString("interface=eth0\ndhcp-range=", file_get_contents($path));
        $this->assertStringContainsString('dhcp-host=e4:5f:01:01:02:03,set:client_is_a_pi,172.20.1.23,sensor', file_get_contents($path));
        $this->assertStringNotContainsString('dhcp-host=old', file_get_contents($path));
        $this->assertArrayNotHasKey('dhcp', $row->fresh()->result['followups']);
    }

    public function test_pending_file_cleanup_is_visible_retryable_and_keeps_the_applied_result(): void
    {
        $this->confirmed();
        $filename = 'transfer-retired-'.bin2hex(random_bytes(6)).'.gz';
        $path = public_path('uploads/'.$filename);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'superseded file');
        $row = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        $result = ['counts' => ['created' => 0, 'replaced' => 1, 'reused' => 0, 'skipped' => 0],
            'objects' => [], 'followups' => []];
        $row->update(['status' => 'applied', 'result' => $result,
            'options' => ['retired' => [['type' => 'image', 'filename' => $filename]]]]);
        try {
            Livewire::test(ConfigurationTransfer::class)->call('openOperation', $row->id)
                ->assertSee('Retry file cleanup')->call('retryFileCleanup')->assertHasNoErrors()
                ->assertSee('Superseded file cleanup completed.')->assertDontSee('Retry file cleanup');
            $this->assertFileDoesNotExist($path);
            $this->assertArrayNotHasKey('retired', $row->fresh()->options);
            $this->assertSame($result, $row->fresh()->result);
        } finally { @unlink($path); }
    }

    public static function unavailableDrafts(): array { return [['expired'], ['deleted']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('unavailableDrafts')]
    public function test_unavailable_import_draft_does_not_block_normal_network_settings(string $state): void
    {
        $this->confirmed();
        $row = $this->workspace->create(auth()->id(), session()->getId(), 'import');
        $row->update(['status' => 'applied', 'options' => ['drafts' => ['network-1' => ['data' => ['name' => 'Old draft']]]]]);
        session()->put('configuration_transfer_draft', ['operation' => $row->id, 'ref' => 'network-1']);
        if ($state === 'expired') $row->update(['expires_at' => now()->subSecond()]);
        else $row->delete();
        $this->mock(\App\Services\NetworkChanges::class)->shouldReceive('available')->andReturn(false);
        Livewire::test(\App\Http\Livewire\NetworkSettings::class)->assertStatus(200)
            ->assertSet('importSource', null)->assertSee('Ethernet and VLAN profiles');
        $this->assertNull(session('configuration_transfer_draft'));
    }

    private function confirmed(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function runPreparation($operation): void
    {
        (new PrepareConfigurationTransfer($operation->id))->handle($this->workspace, app(TransferImporter::class),
            app(\App\Services\Configuration\TransferExporter::class));
    }

    private function script(): Script
    {
        return Script::create(['name' => 'Portable script', 'script_type' => 'postinstall', 'bg' => false, 'priority' => 100, 'script' => "#!/bin/sh\ntrue\n"]);
    }

    private function text(): string
    {
        return json_encode(['format' => 'cmprovision-configuration', 'schema' => 1, 'source' => '2.24.0',
            'created_at' => '2026-11-07T00:00:00Z', 'roots' => ['script-1'], 'objects' => [
                ['ref' => 'script-1', 'type' => 'script', 'omitted_secrets' => [], 'data' => [
                    'name' => 'Portable script', 'script_type' => 'postinstall', 'bg' => false, 'priority' => 100, 'script' => "#!/bin/sh\ntrue\n",
                ]],
            ]], JSON_THROW_ON_ERROR);
    }
}
