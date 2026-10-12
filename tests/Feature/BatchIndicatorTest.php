<?php

namespace Tests\Feature;

use App\Http\Livewire\BatchIndicator;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The open batch in the top bar of every page, polled even in a background tab, and what the tab title and icon get */
class BatchIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function openBatch()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subMinutes(38), 'last_event_at' => now()]);
        foreach (['done', 'done', 'failed', 'write'] as $n => $phase)
            Cm::create(['serial' => sprintf('10000000a1f3c2%02x', $n), 'mac' => sprintf('e4:5f:01:a1:c2:%02x', $n), 'phase' => $phase,
                'notification_batch_id' => $batch->id, 'provisioning_started_at' => now()->subMinutes(5)]);
        return $batch;
    }

    public function test_the_top_bar_shows_the_open_batch_and_tells_the_tab()
    {
        $this->openBatch();
        Livewire::test(BatchIndicator::class)
            ->assertSee('78')
            ->assertSeeHtml('1<span class="hidden md:inline">&nbsp;running</span>')
            ->assertSee('✓ 2')
            ->assertSeeHtml('✕ 1<span class="hidden md:inline">&nbsp;failed</span>')
            ->assertSeeHtml('data-tab-title="1 failed · 1 running"')
            ->assertSeeHtml('data-tab-icon="fail"')
            ->assertSee(route('dashboard'), false);
    }

    public function test_a_module_without_a_report_gets_a_yellow_chip_and_the_tab_a_yellow_dot()
    {
        $this->openBatch();
        Cm::where('phase', 'failed')->update(['phase' => 'done']);
        Cm::where('phase', 'write')->update(['phase_started_at' => now()->subMinutes(4), 'progress_updated_at' => now()->subMinutes(3)]);

        Livewire::test(BatchIndicator::class)
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-stale"><span class="dot"></span>1<span class="hidden md:inline">&nbsp;no report</span></span>')
            ->assertDontSeeHtml('cmp-chip-run')
            ->assertSeeHtml('data-tab-title="1 no report · 3 done"')
            ->assertSeeHtml('data-tab-icon="stale"');
    }

    public function test_without_an_open_batch_it_is_silent()
    {
        Livewire::test(BatchIndicator::class)
            ->assertSeeHtml('data-tab-title=""')
            ->assertSeeHtml('data-tab-icon="idle"')
            ->assertDontSee('running');
    }

    public function test_every_page_has_it_polling_in_the_background_with_the_appearance_button()
    {
        $html = $this->actingAs(User::factory()->create())->get('/cms')->assertOk()->getContent();
        $this->assertStringContainsString('wire:poll.10s.keep-alive', $html);
        $this->assertStringContainsString('aria-label="Appearance"', $html);
        $this->assertStringContainsString('x-data="appearancePopover()"', $html);
        $this->assertStringContainsString('<title>CMs · cmprovision</title>', $html);
        $this->assertStringContainsString('id="cmp-icon-svg"', $html);
        $this->assertStringContainsString('<script src="'.asset('js/tab-status.js'), $html);
        $this->assertMatchesRegularExpression('#>\s*Settings\s*</a>#', $html);
        $this->assertStringNotContainsString('Other settings', $html);
    }

    public function test_a_narrow_bar_shows_the_batch_without_the_project_name()
    {
        // the spec's narrow bar: digits only; the page links need the room up to xl
        $this->openBatch();
        Livewire::test(BatchIndicator::class)->assertSeeHtml('<span class="text-fg-muted hidden xl:inline">78</span>');
    }

    public function test_the_page_links_scroll_to_the_current_page_instead_of_running_under_the_batch()
    {
        $html = $this->actingAs(User::factory()->create())->get('/firmware')->assertOk()->getContent();
        $link = fn ($route) => preg_match('#<a [^>]*href="'.preg_quote(route($route), '#').'"[^>]*>#', $html, $m) ? $m[0] : '';
        $this->assertStringContainsString('aria-current="page"', $link('firmware'));
        $this->assertStringNotContainsString('aria-current', $link('cms'));
        $this->assertStringContainsString('x-init="$el.querySelector(\'[aria-current=page]\')?.scrollIntoView({ block: \'nearest\', inline: \'nearest\' })"', $html);
    }

    public function test_the_sign_in_page_has_no_appearance_button()
    {
        $this->get('/login')->assertOk()->assertDontSee('aria-label="Appearance"', false);
    }
}
