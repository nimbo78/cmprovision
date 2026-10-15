<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Http\Livewire\Scripts;
use App\Models\Package;
use App\Models\Project;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** The settings of the OS check (2.6.0): first-boot scripts, the project's switch and packages, the label moment after it */
class OsCheckSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function package()
    {
        return Package::create(['filename' => 'wlanpi-extra_1.2.3_arm64.deb', 'filename_on_server' => 'x.deb', 'size' => 10,
            'sha256' => str_repeat('c', 64), 'deb_package' => 'wlanpi-extra', 'deb_version' => '1.2.3', 'deb_architecture' => 'arm64']);
    }

    protected function firstBootScript()
    {
        return Script::create(['name' => 'services', 'script_type' => 'firstboot', 'bg' => false, 'priority' => 10,
            'script' => "#!/bin/sh\nsystemctl is-active ssh\n"]);
    }

    public function test_a_first_boot_script_is_saved_without_background()
    {
        Livewire::test(Scripts::class)->call('create')->set('name', 'services')->set('script_type', 'firstboot')
            ->assertDontSee('Run in background')->assertSee('Runs in the written system on its first boot')
            ->set('bg', true)->set('script', "#!/bin/sh\nsystemctl is-active ssh\n")->call('store')->assertHasNoErrors();

        $script = Script::where('name', 'services')->firstOrFail();
        $this->assertSame(['firstboot', false], [$script->script_type, $script->bg]);
    }

    public function test_the_script_list_names_the_first_boot_type()
    {
        $this->firstBootScript();
        // the type cell: the Packages section speaks of the first boot too
        Livewire::test(Scripts::class)->assertSeeHtml('<td>first boot</td>');
    }

    public function test_the_project_keeps_its_os_check_and_packages()
    {
        $package = $this->package();
        $script = $this->firstBootScript();

        Livewire::test(Projects::class)->call('create')->set('name', '78')->set('active', false)
            ->set('os_check', true)->assertSee('wlanpi-extra 1.2.3')
            ->set('selectedPackages', [(string) $package->id])->set('selectedScripts', [(string) $script->id])
            ->set('label_moment', 'firstboot')->call('store')->assertHasNoErrors();

        $project = Project::where('name', '78')->firstOrFail();
        $this->assertSame([true, 'firstboot'], [$project->os_check, $project->label_moment]);
        $this->assertSame([$package->id], $project->packages->pluck('id')->all());

        Livewire::test(Projects::class)->call('edit', $project->id)
            ->assertSet('os_check', true)->assertSet('selectedPackages', [(string) $package->id]);
    }

    public function test_printing_after_the_os_check_is_offered_with_the_check_only()
    {
        Livewire::test(Projects::class)->call('create')
            ->assertDontSee('After the OS check')->assertSee('After writing and post-install')
            ->set('os_check', true)->assertSee('After the OS check');
    }

    public function test_what_belongs_to_the_os_check_needs_it()
    {
        $package = $this->package();
        $script = $this->firstBootScript();

        Livewire::test(Projects::class)->call('create')->set('name', '78')->set('active', false)->set('os_check', false)
            ->set('label_moment', 'firstboot')->set('selectedPackages', [(string) $package->id])->set('selectedScripts', [(string) $script->id])
            ->call('store')->assertHasErrors(['label_moment', 'selectedPackages', 'selectedScripts']);

        $this->assertSame(0, Project::count());
    }

    public function test_unticking_the_check_drops_what_only_it_uses()
    {
        $package = $this->package();
        $firstBoot = $this->firstBootScript();
        $post = Script::create(['name' => 'hostname', 'script_type' => 'postinstall', 'bg' => false, 'priority' => 10, 'script' => 'true']);

        Livewire::test(Projects::class)->call('create')->set('name', '78')->set('active', false)
            ->set('os_check', true)->set('label_moment', 'firstboot')->set('selectedPackages', [(string) $package->id])
            ->set('selectedScripts', [(string) $firstBoot->id, (string) $post->id])
            ->set('os_check', false)
            ->assertSet('label_moment', 'postinstall')->assertSet('selectedPackages', [])->assertSet('selectedScripts', [(string) $post->id])
            ->call('store')->assertHasNoErrors();

        $this->assertSame([false, 'postinstall'], [Project::firstOrFail()->os_check, Project::firstOrFail()->label_moment]);
    }

    public function test_the_api_attaches_first_boot_scripts_only_with_the_check()
    {
        Sanctum::actingAs(User::factory()->create(), ['update']);
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false]);
        $script = $this->firstBootScript();

        $this->patchJson('/api/projects/'.$project->id, ['scripts' => [$script->id]])->assertStatus(422);
        $this->assertSame(0, $project->scripts()->count());
        $this->patchJson('/api/projects/'.$project->id, ['os_check' => true, 'scripts' => [$script->id]])->assertOk();
        $this->assertSame([$script->id], $project->scripts()->pluck('scripts.id')->all());
    }

    public function test_the_api_takes_the_os_check_and_its_label_moment()
    {
        Sanctum::actingAs(User::factory()->create(), ['create', 'update']);
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false]);

        $this->patchJson('/api/projects/'.$project->id, ['label_moment' => 'firstboot'])->assertStatus(422);
        $this->patchJson('/api/projects/'.$project->id, ['os_check' => true, 'label_moment' => 'firstboot'])->assertOk();
        $this->assertSame(['firstboot', true], [$project->fresh()->label_moment, $project->fresh()->os_check]);

        $this->postJson('/api/scripts', ['name' => 'services', 'script_type' => 'firstboot', 'script' => 'true'])->assertCreated();
    }
}
