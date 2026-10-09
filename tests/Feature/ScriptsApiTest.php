<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Upstream #55: scripts attached to a project are visible and changeable through the API. */
class ScriptsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function project()
    {
        return Project::create(['name' => 'p-'.uniqid(), 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false]);
    }

    protected function script($name = 'resize', $type = 'postinstall')
    {
        return Script::create(['name' => $name, 'script_type' => $type, 'priority' => 50, 'bg' => false, 'script' => "#!/bin/sh\necho $name\n"]);
    }

    public function test_project_listing_includes_the_attached_scripts()
    {
        $project = $this->project();
        $project->scripts()->sync([$this->script()->id]);
        Sanctum::actingAs(User::factory()->create(), ['read']);

        $this->getJson('/api/projects')->assertOk()
            ->assertJsonPath('0.scripts.0.name', 'resize')
            ->assertJsonPath('0.scripts.0.script_type', 'postinstall');
        $this->getJson("/api/projects/{$project->id}")->assertOk()->assertJsonPath('scripts.0.name', 'resize');
    }

    public function test_patching_a_project_can_replace_its_scripts()
    {
        $project = $this->project();
        $old = $this->script('old'); $new = $this->script('new', 'preinstall');
        $project->scripts()->sync([$old->id]);
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson("/api/projects/{$project->id}", ['scripts' => [$new->id]])->assertOk()
            ->assertJsonPath('scripts.0.name', 'new');

        $this->assertEquals([$new->id], array_map('intval', $project->scripts()->pluck('scripts.id')->all()));
    }

    public function test_patching_with_an_unknown_script_id_is_rejected()
    {
        $project = $this->project();
        Sanctum::actingAs(User::factory()->create(), ['update']);

        $this->patchJson("/api/projects/{$project->id}", ['scripts' => [999]])->assertStatus(422);
    }

    public function test_scripts_can_be_created_updated_and_deleted_with_the_matching_permissions()
    {
        Sanctum::actingAs(User::factory()->create(), ['create', 'update', 'delete']);

        $id = $this->postJson('/api/scripts', [
            'name' => 'Set hostname', 'script_type' => 'postinstall', 'priority' => 60, 'bg' => false,
            'script' => "#!/bin/sh\necho sensor > /mnt/root/etc/hostname\n",
        ])->assertCreated()->assertJsonPath('name', 'Set hostname')->json('id');

        $this->patchJson("/api/scripts/$id", ['priority' => 70])->assertOk()->assertJsonPath('priority', 70);
        $this->deleteJson("/api/scripts/$id")->assertOk();
        $this->assertSame(0, Script::count());
    }

    public function test_a_script_used_by_a_project_cannot_be_deleted()
    {
        $project = $this->project();
        $script = $this->script();
        $project->scripts()->sync([$script->id]);
        Sanctum::actingAs(User::factory()->create(), ['delete']);

        $this->deleteJson("/api/scripts/{$script->id}")->assertStatus(409);
        $this->assertSame(1, Script::count());
    }

    public function test_creating_a_script_needs_the_create_permission_and_valid_fields()
    {
        Sanctum::actingAs(User::factory()->create(), ['read']);
        $this->postJson('/api/scripts', ['name' => 'x', 'script_type' => 'postinstall', 'script' => 'echo'])->assertStatus(403);

        Sanctum::actingAs(User::factory()->create(), ['create']);
        $this->postJson('/api/scripts', ['name' => 'x', 'script_type' => 'sometime', 'script' => 'echo'])->assertStatus(422);
    }
}
