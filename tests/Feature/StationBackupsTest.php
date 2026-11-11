<?php

namespace Tests\Feature;

use App\Http\Livewire\StationBackupsPanel;
use App\Models\User;
use App\Services\StationBackupsControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StationBackupsTest extends TestCase
{
    use RefreshDatabase;

    private function control(): StationBackupsControl
    {
        $control = new class extends StationBackupsControl {
            public array $calls = [];
            public function available(): bool { return true; }
            public function pending(): bool { return false; }
            public function status(): array { return ['schema' => 1, 'profiles' => [], 'history' => [], 'password_configured' => false, 'operation' => null]; }
            public function request(array $request): array { $this->calls[] = $request; return $this->status() + ['id' => str_repeat('a', 32)]; }
        };
        $this->app->instance(StationBackupsControl::class, $control);
        return $control;
    }

    public function test_status_requires_authentication_and_is_not_cached(): void
    {
        $control = $this->control();
        $this->getJson('/settings/backups/status')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/settings/backups/status')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('status', $control->calls[0]['action']);
    }

    public function test_every_mutation_requires_recent_password_and_secrets_are_not_public_state(): void
    {
        $control = $this->control();
        $this->actingAs(User::factory()->create());
        try { Livewire::test(StationBackupsPanel::class)->call('saveMasterPassword', 'a private master password', 'a private master password'); }
        catch (\Illuminate\Auth\Access\AuthorizationException $expected) {}
        $this->assertSame([], $control->calls);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(StationBackupsPanel::class)->call('saveMasterPassword', 'a private master password', 'a private master password');
        $this->assertSame('password', $control->calls[0]['action']);
        $this->assertStringNotContainsString('a private master password', json_encode($component->instance()->all()));
    }

    public function test_master_password_mismatch_never_reaches_the_privileged_broker(): void
    {
        $control = $this->control();
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(StationBackupsPanel::class)
            ->call('saveMasterPassword', 'a private master password', 'a mistyped master password')
            ->assertSet('error', 'The master passwords do not match. Enter them again.');
        $this->assertSame([], $control->calls);
        $this->assertStringNotContainsString('a mistyped master password', json_encode($component->instance()->all()));
    }

    public function test_profile_secrets_are_action_arguments_and_create_is_idempotent_until_back(): void
    {
        $control = $this->control();
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $component = Livewire::test(StationBackupsPanel::class)
            ->call('saveProfile', ['type' => 'ftp', 'name' => 'Nightly', 'password' => 'transport-private'])
            ->call('create', 'download', ['images' => false, 'packages' => true])
            ->call('create', 'download', ['images' => false, 'packages' => true]);
        $this->assertSame($control->calls[1], $control->calls[2]);
        $this->assertStringNotContainsString('transport-private', json_encode($component->instance()->all()));
        $component->call('back')->call('create', 'download', ['images' => false, 'packages' => true]);
        $this->assertNotSame($control->calls[2]['nonce'], $control->calls[3]['nonce']);
    }

    public function test_download_requires_confirmation_and_exact_operation_identifier(): void
    {
        $this->control();
        $this->actingAs(User::factory()->create());
        $this->get('/settings/backups/'.str_repeat('a', 32).'/download')->assertRedirect(route('password.confirm'));
        $this->get('/settings/backups/invalid/download')->assertNotFound();
        $this->get('/settings/backups/confirm')->assertRedirect(route('password.confirm'));
    }

    public function test_download_stream_does_not_rewind_or_duplicate_primed_chunks(): void
    {
        $control = new class extends StationBackupsControl {
            protected function downloadProcess(string $id): \Symfony\Component\Process\Process
            {
                return new \Symfony\Component\Process\Process([PHP_BINARY, '-r', 'echo "CMPROVISION\\x01\\x03"; for ($i=0;$i<48;$i++) { echo str_repeat("x",65536); usleep(1000); }']);
            }
        };
        [$chunks, $first] = $control->download(str_repeat('a', 32));
        $bytes = $first;
        foreach ($chunks as $chunk) $bytes .= $chunk;
        $this->assertSame("CMPROVISION\x01\x03".str_repeat('x', 48*65536), $bytes);
    }
}
