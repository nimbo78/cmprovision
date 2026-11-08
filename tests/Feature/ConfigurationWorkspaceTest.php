<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class ConfigurationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/configuration-workspace-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_operations_and_files_are_bound_to_the_requesting_user_and_session(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'export');
        $source = $workspace->path($operation, 'source');
        file_put_contents($source, 'private source');

        $this->assertTrue($operation->is($workspace->owned($operation->id, $user->id, 'session-a')));
        $this->assertSame('export', $operation->direction);
        $this->assertSame(0700, fileperms(dirname($source)) & 0777);
        $this->assertSame('private source', file_get_contents($source));
    }

    public function test_another_user_cannot_open_an_operation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($owner->id, 'session-a', 'import');

        $this->expectException(AuthorizationException::class);
        $workspace->owned($operation->id, $other->id, 'session-a');
    }

    public function test_another_session_cannot_open_an_operation(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'import');

        $this->expectException(AuthorizationException::class);
        $workspace->owned($operation->id, $user->id, 'session-b');
    }

    public function test_an_expired_operation_cannot_be_opened(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'import');
        $this->travel(25)->hours();

        $this->expectException(AuthorizationException::class);
        $workspace->owned($operation->id, $user->id, 'session-a');
    }

    public function test_member_paths_cannot_escape_the_private_directory(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'import');

        $this->expectException(RuntimeException::class);
        $workspace->path($operation, '../outside');
    }

    public function test_a_symlink_as_the_working_root_is_rejected(): void
    {
        mkdir($this->directory.'/outside', 0700);
        symlink($this->directory.'/outside', $this->directory.'/work');
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->workspace()->create($user->id, 'session-a', 'import');
    }

    public function test_private_operation_secrets_are_encrypted_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'export');
        $operation->secrets = ['password' => 'private operation master password'];
        $operation->save();

        $this->assertStringNotContainsString('private operation master password', $operation->getRawOriginal('secrets'));
        $this->assertStringNotContainsString('private operation master password', $operation->toJson());
        $this->assertArrayNotHasKey('secrets', $operation->toArray());
        $this->assertArrayNotHasKey('session_hash', $operation->toArray());
        $this->assertSame('private operation master password', $operation->fresh()->secrets['password']);
    }

    public function test_pruning_expired_operations_removes_files_without_following_links(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'import');
        $source = $workspace->path($operation, 'source');
        file_put_contents($source, 'expired data');
        file_put_contents($this->directory.'/outside.txt', 'keep this file');
        symlink($this->directory.'/outside.txt', $workspace->path($operation, 'linked'));
        $this->travel(25)->hours();

        $workspace->prune();

        $this->assertFileDoesNotExist($source);
        $this->assertDatabaseMissing('configuration_transfer_operations', ['id' => $operation->id]);
        $this->assertSame('keep this file', file_get_contents($this->directory.'/outside.txt'));
    }

    public function test_pruning_waits_for_an_active_operation_lock(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspace();
        $operation = $workspace->create($user->id, 'session-a', 'import');

        $workspace->locked($operation, function () use ($workspace, $operation) {
            $this->travel(25)->hours();
            $workspace->prune();
            $this->assertDatabaseHas('configuration_transfer_operations', ['id' => $operation->id]);
        });
        $workspace->prune();

        $this->assertDatabaseMissing('configuration_transfer_operations', ['id' => $operation->id]);
    }

    private function workspace(): TransferWorkspace
    {
        return new TransferWorkspace($this->directory.'/work');
    }
}
