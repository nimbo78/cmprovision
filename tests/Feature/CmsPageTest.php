<?php

namespace Tests\Feature;

use App\Http\Livewire\Cms;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The list of CMs: newest first, searchable by serial or MAC, with the project, the board and the last result */
class CmsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function project($name)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        return Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
    }

    protected function cms()
    {
        $p78 = $this->project('78');
        $test = $this->project('stage1-test');
        Cm::create(['serial' => '10000000aaaa0001', 'mac' => 'e4:5f:01:aa:00:01', 'model' => 'CM4', 'project_id' => $p78->id,
                    'provisioning_board' => 'GE0/0/1', 'phase' => 'done', 'provisioning_complete_at' => now()]);
        Cm::create(['serial' => '10000000aaaa0002', 'mac' => 'e4:5f:01:aa:00:02', 'model' => 'CM4', 'project_id' => $p78->id,
                    'provisioning_board' => 'GE0/0/2', 'phase' => 'failed']);
        Cm::create(['serial' => '10000000bbbb0003', 'mac' => 'E4:5F:01:BB:00:03', 'model' => 'CM4', 'project_id' => $test->id, 'phase' => 'write',
                    'provisioning_started_at' => now()->subMinutes(3)]);
        return [$p78, $test];
    }

    public function test_newest_first_with_project_board_and_result()
    {
        $this->cms();
        Livewire::test(Cms::class)
            ->set('projectId', 0)
            ->assertSeeInOrder(['10000000bbbb0003', '10000000aaaa0002', '10000000aaaa0001'])
            ->assertSee('stage1-test')
            ->assertSee('GE0/0/2')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-fail">failed</span>')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-run">running</span>')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-ok">done</span>');
    }

    public function test_search_finds_a_part_of_the_serial_or_the_mac_in_any_case()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->set('search', 'bb:00')
            ->assertSee('10000000bbbb0003')->assertDontSee('10000000aaaa0001');
        Livewire::test(Cms::class)->set('projectId', 0)->set('search', 'AAAA0002')
            ->assertSee('10000000aaaa0002')->assertDontSee('10000000bbbb0003');
    }

    public function test_the_project_filter_still_works()
    {
        [$p78] = $this->cms();
        Livewire::test(Cms::class)->set('projectId', $p78->id)
            ->assertSee('10000000aaaa0001')->assertDontSee('10000000bbbb0003');
    }

    public function test_a_module_stuck_in_a_phase_since_yesterday_is_not_running()
    {
        // unplugged while it wrote: the dashboard stopped counting it after ACTIVE_HOURS, the list does too
        $this->cms();
        Cm::create(['serial' => '10000000cccc0004', 'mac' => 'e4:5f:01:cc:00:04', 'model' => 'CM4', 'phase' => 'write',
                    'provisioning_started_at' => now()->subDay()]);
        Livewire::test(Cms::class)->set('projectId', 0)->set('search', 'cccc0004')
            ->assertSee('10000000cccc0004')
            ->assertDontSeeHtml('cmp-chip-run');
    }

    public function test_a_narrow_screen_scrolls_the_table_instead_of_cutting_it()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->assertSeeHtml('<div class="cmp-card cmp-card-scroll">');
    }

    public function test_the_header_counts_the_listed_modules_of_all()
    {
        [$p78] = $this->cms();
        Livewire::test(Cms::class)->set('projectId', $p78->id)->assertSee('2 of 3 modules');
        Livewire::test(Cms::class)->set('projectId', 0)->assertSee('3 modules')->assertDontSee('of 3 modules');
    }

    public function test_the_delete_icon_is_readable()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->assertSeeHtml('class="text-fg-muted hover:text-neg" title="Delete"');
    }

    public function test_delete_asks_first()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->assertSeeHtml('wire:confirm="Delete CM 10000000aaaa0001?"');
    }
}
