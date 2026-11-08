<?php

namespace Tests\Feature;

use App\Services\StationSetupSsh;
use App\Services\Sensor\ProvisionerKey;
use App\Support\ImageAccessFailure;
use Tests\TestCase;

class StationSetupSshTest extends TestCase
{
    private string $fixture;
    private function transport(): StationSetupSsh
    {
        $this->fixture = (string) getenv('CMP_STATION_SSH_FIXTURE');
        if ($this->fixture === '') $this->markTestSkipped('Run tests/integration/station-setup-ssh.py in disposable Linux.');
        file_put_contents($this->fixture.'/mode', 'ok');
        @unlink($this->fixture.'/received');
        $key = $this->createStub(ProvisionerKey::class);
        $key->method('ensure')->willReturn(trim(file_get_contents($this->fixture.'/client.pub')));
        $key->method('path')->willReturn($this->fixture.'/client');
        return new StationSetupSsh($key, (int) getenv('CMP_STATION_SSH_PORT'));
    }
    private function host(): string
    {
        return implode(' ', array_slice(explode(' ', trim(file_get_contents($this->fixture.'/host.pub'))), 0, 2));
    }
    private function identity(): array
    {
        return ['schema' => 1, 'serial' => '10000000e611110c', 'cid' => str_repeat('a', 32), 'run' => str_repeat('x', 40)];
    }
    public function test_real_ssh_authentication_host_pin_and_private_stdin_delivery(): void
    {
        $ssh = $this->transport();
        $settings = ['wifi_password' => 'No-HTTP-or-shell-secret'];
        $ssh->deliver('127.0.0.1', $this->host(), $this->identity(), $settings);
        $received = json_decode(file_get_contents($this->fixture.'/received'), true);
        $this->assertSame(['identity' => $this->identity(), 'settings' => $settings], $received);
    }
    public function test_wrong_identity_receives_no_credentials(): void
    {
        $ssh = $this->transport();
        try {
            $ssh->deliver('127.0.0.1', $this->host(), array_replace($this->identity(), ['serial' => '1000000000000000']), ['secret' => 'never-send']);
            $this->fail('Wrong identity accepted');
        } catch (\RuntimeException $e) {
            $this->assertFileDoesNotExist($this->fixture.'/received');
            $this->assertStringContainsString('identity', $e->getMessage());
        }
    }
    public function test_wrong_host_key_receives_no_credentials(): void
    {
        $ssh = $this->transport();
        $wrong = 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('z', 32));
        try {
            $ssh->deliver('127.0.0.1', $wrong, $this->identity(), ['secret' => 'never-send']);
            $this->fail('Wrong host key accepted');
        } catch (\RuntimeException $e) {
            $this->assertFileDoesNotExist($this->fixture.'/received');
        }
    }
    public function test_remote_error_output_is_not_exposed(): void
    {
        $ssh = $this->transport();
        file_put_contents($this->fixture.'/mode', 'fail');
        try {
            $ssh->deliver('127.0.0.1', $this->host(), $this->identity(), ['password' => 'hidden-fixture-password']);
            $this->fail('Remote failure accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('hidden-fixture-password', $e->getMessage());
        }
    }

    public function test_generic_helper_errors_cross_real_ssh_as_allowlisted_messages_without_stderr_secrets(): void
    {
        $ssh = $this->transport();
        file_put_contents($this->fixture.'/mode', 'generic:rename_references');
        try {
            $ssh->deliver('127.0.0.1', $this->host(), $this->identity(),
                ['profile' => 'raspios', 'password_hash' => 'private-fixture-hash']);
            $this->fail('Generic failure was accepted');
        } catch (ImageAccessFailure $error) {
            $this->assertSame('rename_references', $error->failureCode);
            $this->assertStringNotContainsString('private-fixture-hash', $error->getMessage());
        }
    }

    public function test_unknown_generic_stdout_and_legacy_failures_are_not_promoted_to_public_error_codes(): void
    {
        foreach ([['profile' => 'raspios'], []] as $settings) {
            $ssh = $this->transport();
            file_put_contents($this->fixture.'/mode', isset($settings['profile']) ? 'generic:private-fixture-hash' : 'generic:rename_references');
            try {
                $ssh->deliver('127.0.0.1', $this->host(), $this->identity(), $settings + ['password_hash' => 'private-fixture-hash']);
                $this->fail('Failure was accepted');
            } catch (\RuntimeException $error) {
                $this->assertNotInstanceOf(ImageAccessFailure::class, $error);
                $this->assertStringNotContainsString('private-fixture-hash', $error->getMessage());
            }
        }
    }
}
