<?php

namespace Tests\Feature;

use App\Models\{Cm, Image, Project, Setting, StationSetupRun};
use App\Services\{StationSetup, StationSetupSsh};
use App\Services\Sensor\ProvisionerKey;
use App\Support\{ImageAccessFailure, StationSetupInput};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ImageAccessProvisioningTest extends TestCase
{
    use RefreshDatabase;
    private const SERIAL = '10000000e611110c';
    private const CID = '150100424a5444345203b3f2c00e7800';
    private const NONCE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function key(): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    }

    private function project(): Project
    {
        $image = Image::forceCreate(['filename' => 'raspios.img.xz', 'filename_extension' => 'xz',
            'filename_on_server' => 'access-test.xz', 'sha256' => str_repeat('a', 64),
            'uncompressed_sha256' => str_repeat('b', 64), 'uncompressed_size' => 8388608]);
        $project = Project::create(['name' => 'Generic SSH image', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
            'image_id' => $image->id, 'verify' => true, 'os_check' => true, 'label_moment' => 'never']);
        $project->station_setup = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'profile' => 'raspios', 'hostname' => '', 'username' => 'operator', 'password' => 'Fixture-Generic-Password',
            'source_username' => 'wlanpi', 'sudo_passwordless' => false]));
        $project->save();
        $key = $this->createStub(ProvisionerKey::class);
        $key->method('ensure')->willReturn($this->key());
        $this->app->instance(ProvisionerKey::class, $key);
        return $project;
    }

    private function fixtureRun(): array
    {
        $project = $this->project();
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => 'e4:5f:01:62:9f:d3', 'cid' => self::CID,
            'project_id' => $project->id, 'phase' => 'postinstall', 'diagnostics_token' => self::NONCE]);
        $run = StationSetupRun::create(['cm_id' => $cm->id, 'run' => self::NONCE, 'settings' => $project->station_setup,
            'expires_at' => now()->addHour()]);
        return [$project, $cm, $run];
    }

    private function report()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '172.20.100.25'])->post('/station-setup', [
            'serial' => self::SERIAL, 'run' => self::NONCE, 'host_key' => $this->key()]);
    }

    public function test_generic_script_stages_only_static_helper_code_without_a_preinstalled_agent(): void
    {
        [$project, $cm] = $this->fixtureRun();
        $script = app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $this->assertStringContainsString('/tools/image-access', $script);
        $this->assertStringContainsString(hash_file('sha256', public_path('tools/image-access')), $script);
        $this->assertStringContainsString('/run/cmprovision-station-setup/image-access', $script);
        $this->assertStringNotContainsString('/usr/lib/cmprovision/image-setup', $script);
        $this->assertStringNotContainsString('Fixture-Generic-Password', $script);
        $this->assertStringNotContainsString($project->station_setup['password_hash'], $script);
        $this->assertStringContainsString('image-access:error:rename_references', $script);
        if (is_executable('/bin/sh')) {
            $syntax = new Process(['/bin/sh', '-n'], null, null, $script);
            $syntax->run();
            $this->assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
        }
    }

    public function test_generic_setup_is_inserted_after_verification_and_legacy_utility_os_is_refused(): void
    {
        $project = $this->project();
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $this->get(OsCheckModuleScriptTest::START.'&cid='.self::CID)->assertOk()
            ->assertSee('Station setup requires the 64-bit utility OS');
        Cm::query()->delete();
        $script = $this->get(OsCheckModuleScriptTest::START.'&cid='.self::CID.'&uos=2026.10.2')->assertOk()->getContent();
        $this->assertStringContainsString('Configuring installed OS access', $script);
        $this->assertLessThan(strpos($script, 'sh -v /tmp/post--2.sh'), strpos($script, 'sh -v /tmp/post-0.sh'));
        $this->assertStringNotContainsString($project->station_setup['password_hash'], $script);
    }

    public function test_allowlisted_helper_failure_reaches_the_module_without_exposing_settings_and_remains_retryable(): void
    {
        [, , $run] = $this->fixtureRun();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->once())->method('deliver')->willThrowException(new ImageAccessFailure('rename_references'));
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->report()->assertStatus(502)->assertContent('image-access:error:rename_references');
        $this->assertSame('pending', $run->fresh()->state);
        $this->assertNotNull($run->fresh()->settings);
    }

    public function test_successful_generic_run_erases_its_credential_snapshot(): void
    {
        [$project, , $run] = $this->fixtureRun();
        $ssh = $this->createMock(StationSetupSsh::class);
        $ssh->expects($this->once())->method('deliver')->with('172.20.100.25', $this->key(),
            ['schema' => 1, 'serial' => self::SERIAL, 'cid' => self::CID, 'run' => self::NONCE], $project->station_setup);
        $this->app->instance(StationSetupSsh::class, $ssh);
        $this->report()->assertOk()->assertContent('applied');
        $this->assertNull($run->fresh()->settings);
        $this->assertSame('applied', $run->fresh()->state);
    }

    public function test_profile_is_bound_to_the_run_and_an_applied_run_does_not_repeat_setup(): void
    {
        [$project, $cm, $run] = $this->fixtureRun();
        $project->station_setup = StationSetupInput::normalize(array_replace(StationSetupInput::form(), [
            'username' => 'different', 'password' => 'Fixture-Changed-Password']));
        $project->save();
        $script = app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $this->assertStringContainsString('/tools/image-access', $script);
        $run->update(['state' => 'applied', 'settings' => null]);
        $repeat = app(StationSetup::class)->prepare($cm, $project, '172.20.0.1');
        $this->assertStringContainsString('already applied', $repeat);
        $this->assertStringNotContainsString('mount -t ext4', $repeat);
    }

    public function test_only_exact_known_failure_codes_can_be_mapped_into_public_errors(): void
    {
        $error = ImageAccessFailure::fromResponse('image-access:error:account_ambiguous');
        $this->assertInstanceOf(ImageAccessFailure::class, $error);
        $this->assertStringContainsString('Existing Linux user', $error->getMessage());
        foreach (['image-access:error:private-password', 'image-access:error:account_ambiguous\nsecret',
            'error:account_ambiguous', 'image-access:error:'] as $response) {
            $this->assertNull(ImageAccessFailure::fromResponse($response));
        }
    }
}
