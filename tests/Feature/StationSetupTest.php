<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Models\{Cm, Image, Project, Setting, StationSetupRun, User};
use App\Services\{StationSetup, StationSetupSsh};
use App\Services\Sensor\ProvisionerKey;
use App\Support\StationSetupInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StationSetupTest extends TestCase
{
    use RefreshDatabase;
    const SERIAL = '10000000e611110c';
    const CID = '150100424a5444345203b3f2c00e7800';
    const NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function input(): array
    {
        return array_replace(StationSetupInput::form(), ['username' => 'operator', 'password' => 'Initial-test-password',
            'wifi' => true, 'ssid' => ' Test;WiFi ', 'wifi_password' => ' Test\\password ', 'country' => 'IL']);
    }

    private function key(): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    }

    private function project(): Project
    {
        $image = new Image;
        $image->forceFill(['filename' => 'station.img.gz', 'filename_extension' => 'gz', 'filename_on_server' => 'station-test.gz',
            'sha256' => str_repeat('a', 64), 'uncompressed_sha256' => str_repeat('b', 64), 'uncompressed_size' => 8388608])->save();
        $project = Project::create(['name' => 'Station', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'label_moment' => 'never', 'verify' => true, 'os_check' => true]);
        $project->station_setup = StationSetupInput::normalize($this->input());
        $project->save();
        return $project;
    }

    private function fixtureRun(): array
    {
        $project = $this->project();
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'cid' => self::CID,
            'project_id' => $project->id, 'phase' => 'postinstall', 'diagnostics_token' => self::NONCE]);
        $run = StationSetupRun::create(['cm_id' => $cm->id, 'run' => self::NONCE,
            'settings' => $project->station_setup, 'expires_at' => now()->addHour()]);
        return [$cm, $run, $project];
    }

    private function deliver(array $overrides = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '172.20.100.25'])->post('/station-setup', $overrides + [
            'serial' => self::SERIAL, 'run' => self::NONCE, 'host_key' => $this->key()]);
    }

    public function test_credentials_are_encrypted_hidden_and_never_redisplayed(): void
    {
        $project = $this->project();
        $raw = DB::table('projects')->where('id', $project->id)->value('station_setup');
        $this->assertStringNotContainsString('Test\\password', $raw);
        $this->assertStringNotContainsString('$6$', $raw);
        $this->assertArrayNotHasKey('station_setup', $project->toArray());
        $config = $project->fresh()->station_setup;
        $this->assertSame($config['password_hash'], crypt('Initial-test-password', $config['password_hash']));
        $this->assertArrayNotHasKey('password', $config);
        $form = StationSetupInput::form($config);
        $this->assertSame('', $form['password']);
        $this->assertSame('', $form['wifi_password']);
        $this->assertSame($config, StationSetupInput::normalize($form, $config));
        $html = Livewire::test(Projects::class)->call('edit', $project->id)->html();
        $this->assertStringNotContainsString('Initial-test-password', $html);
        $this->assertStringNotContainsString('Test\\password', $html);
        $this->assertStringNotContainsString($config['password_hash'], $html);
    }

    public function test_headless_code_is_private_per_run_and_only_available_after_delivery_until_expiry(): void
    {
        [$cm, $oldRun, $project] = $this->fixtureRun();
        $oldRun->delete();
        $settings = StationSetupInput::normalize(['web_setup' => true] + $this->input());
        $this->assertSame(3, $settings['schema']);
        $this->assertSame($settings, StationSetupInput::saved($settings));
        $project->station_setup = $settings;
        $project->save();
        $key = $this->createStub(ProvisionerKey::class);
        $key->method('ensure')->willReturn($this->key());
        $this->app->instance(ProvisionerKey::class, $key);
        $script = app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $run = StationSetupRun::sole();
        $code = $run->web_setup_code;
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{16}$/D', $code);
        $this->assertStringNotContainsString($code, $script);
        $this->assertNotSame($code, $run->getRawOriginal('web_setup_code'));
        $this->assertArrayNotHasKey('web_setup_code', $run->toArray());
        $url = route('cm.setup-code', $cm->mac);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($url)->assertRedirect(route('cm.setup-code.confirm', $cm->mac));
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->get($url)->assertNotFound();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->once())->method('deliver')->with($this->anything(), $this->anything(), $this->anything(),
            $this->callback(fn ($s) => $s['web_setup_code'] === $code && !isset($s['web_setup'])));
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->deliver()->assertOk()->assertDontSee($code);
        $response = $this->get($url)->assertOk()->assertSee(implode(' ', str_split($code, 4)));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $cm->update(['diagnostics_token' => str_repeat('b', 40)]);
        $this->get($url)->assertNotFound();
        app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $this->assertNotSame($code, $run->fresh()->web_setup_code);
        $run->fresh()->update(['state' => 'applied', 'expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();
    }

    public function test_key_only_setup_requires_valid_keys_and_ssh(): void
    {
        $input = array_replace($this->input(), ['password_login' => false, 'keys' => $this->key()]);
        $settings = StationSetupInput::normalize($input);
        $this->assertNull($settings['password_hash']);
        $this->assertSame([$this->key()], $settings['keys']);
        $this->expectException(ValidationException::class);
        StationSetupInput::normalize(array_replace($input, ['ssh' => false]));
    }

    public function test_password_confirmation_returns_to_this_device_code_when_the_card_poll_loses_the_intended_url(): void
    {
        [$cm, $run] = $this->fixtureRun();
        $run->update(['state' => 'applied', 'target_serial' => $cm->serial, 'target_cid' => $cm->cid,
            'web_setup_code' => 'ABCDEFGHIJKLMNOP']);
        $url = route('cm.setup-code', $cm->mac);
        $this->actingAs(User::factory()->create());
        $this->getJson($url)->assertStatus(423);
        $challenge = $this->get($url)->assertRedirect()->headers->get('Location');
        $this->get($challenge)->assertOk()->assertDontSee('ABCD EFGH IJKL MNOP')
            ->assertSeeHtml('action="'.$challenge.'"')->assertSee('Back to device');
        $this->from($challenge)->post($challenge, ['password' => 'incorrect'])
            ->assertRedirect($challenge)->assertSessionHasErrors('password');
        $this->get($url)->assertRedirect($challenge);

        // A card poll that started before the link was clicked can save its older session here.
        session()->forget('url.intended');
        session()->save();
        $this->post($challenge, ['password' => 'password'])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('ABCD EFGH IJKL MNOP');
    }

    public function test_device_confirmation_keeps_another_tabs_backup_destination(): void
    {
        [$cm] = $this->fixtureRun();
        $url = route('cm.setup-code', $cm->mac);
        $this->actingAs(User::factory()->create());
        $challenge = $this->get($url)->assertRedirect()->headers->get('Location');

        $backup = route('settings.backup');
        $this->get($backup)->assertRedirect(route('password.confirm'));
        $this->get($challenge)->assertOk()->assertDontSee('Back to Backup');
        $this->post($challenge, ['password' => 'password'])->assertRedirect($url)
            ->assertSessionHas('url.intended', $backup);
        $this->post(route('password.confirm'), ['password' => 'password'])->assertRedirect($backup);
    }

    public function test_new_wifi_network_does_not_inherit_another_network_password(): void
    {
        $saved = StationSetupInput::normalize($this->input());
        $form = StationSetupInput::form($saved);
        $form['ssid'] = 'Different';
        $this->expectException(ValidationException::class);
        StationSetupInput::normalize($form, $saved);
    }

    public function test_station_settings_need_password_confirmation_and_verification(): void
    {
        $project = $this->project();
        $this->actingAs(User::factory()->create());
        Livewire::test(Projects::class)->call('edit', $project->id)->call('store')->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(Projects::class)->call('edit', $project->id)->set('verify', false)->call('store')->assertHasErrors('stationEnabled');
        $this->assertTrue($project->fresh()->verify);
    }

    public function test_only_ssh_receives_settings_and_success_erases_the_run_copy(): void
    {
        [$cm, $run, $project] = $this->fixtureRun();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->once())->method('deliver')->with('172.20.100.25', $this->key(),
            ['schema' => 1, 'serial' => self::SERIAL, 'cid' => self::CID, 'run' => self::NONCE], $project->station_setup);
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->deliver(['host_key' => $this->key()."\n"])->assertOk()->assertContent('applied');
        $this->assertNull($run->fresh()->settings);
        $this->assertSame('applied', $run->fresh()->state);
        $this->deliver()->assertOk()->assertContent('applied'); // lost response: no second application
    }

    public function test_stale_run_wrong_phase_expiry_and_changed_host_key_are_refused(): void
    {
        [$cm, $run] = $this->fixtureRun();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->never())->method('deliver');
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->deliver(['run' => str_repeat('b', 40)])->assertStatus(409);
        $cm->update(['phase' => 'verify']);
        $this->deliver()->assertStatus(409);
        $cm->update(['phase' => 'postinstall']);
        $run->update(['expires_at' => now()->subMinute()]);
        $this->deliver()->assertStatus(409);
        $run->update(['expires_at' => now()->addHour(), 'host_key' => 'different']);
        $this->deliver()->assertStatus(409);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->post('/station-setup', [])->assertForbidden();
    }

    public function test_transport_failure_does_not_leak_its_input_and_can_be_retried(): void
    {
        [, $run] = $this->fixtureRun();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->once())->method('deliver')->willThrowException(new \RuntimeException('secret-password-from-process'));
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->deliver()->assertStatus(502)->assertDontSee('secret-password-from-process');
        $this->assertNotNull($run->fresh()->settings);
        $this->assertSame($this->key(), $run->fresh()->host_key);
    }

    public function test_script_has_no_secrets_and_setup_runs_after_verification(): void
    {
        $project = $this->project();
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $key = $this->createStub(ProvisionerKey::class);
        $key->method('ensure')->willReturn($this->key());
        $this->app->instance(ProvisionerKey::class, $key);
        $script = $this->get(OsCheckModuleScriptTest::START.'&cid='.self::CID.'&uos=2026.10.2')->assertOk()->getContent();
        $this->assertStringContainsString('Configuring the station', $script);
        $this->assertStringNotContainsString('Initial-test-password', $script);
        $this->assertStringNotContainsString('Test\\password', $script);
        $this->assertStringNotContainsString($project->station_setup['password_hash'], $script);
        $this->assertLessThan(strpos($script, 'sh -v /tmp/post--2.sh'), strpos($script, 'sh -v /tmp/post-0.sh'));
        $this->assertSame(1, StationSetupRun::count());
    }

    public function test_legacy_utility_os_is_refused_before_writing(): void
    {
        $project = $this->project();
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $this->get(OsCheckModuleScriptTest::START.'&cid='.self::CID)->assertOk()
            ->assertSee('Station setup requires the 64-bit utility OS');
        $this->assertSame('failed', Cm::where('serial', self::SERIAL)->firstOrFail()->phase);
        $this->assertSame(0, StationSetupRun::count());
    }

    public function test_settings_snapshot_survives_project_edits_and_old_expiry_jobs(): void
    {
        [$cm, $run, $project] = $this->fixtureRun();
        $snapshot = $run->settings;
        $settings = $project->station_setup;
        $settings['hostname'] = 'changed-later';
        $project->station_setup = $settings;
        $project->save();
        $key = $this->createStub(ProvisionerKey::class);
        $key->method('ensure')->willReturn($this->key());
        $this->app->instance(ProvisionerKey::class, $key);
        app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $this->assertSame($snapshot, $run->fresh()->settings);
        $expiry = new \App\Jobs\ExpireStationSetup($run->id);
        $expiry->handle();
        $this->assertNotNull($run->fresh());
        $this->travel(2)->hours();
        $expiry->handle();
        $this->assertNull($run->fresh());
    }
}
