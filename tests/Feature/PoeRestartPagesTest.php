<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Http\Livewire\PoeOutages;
use App\Http\Livewire\ProvisioningStatus;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Switch\Poe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The Restart buttons of the module card and the dashboard */
class PoeRestartPagesTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_the_card_asks_the_switch_once_it_shows_then_restarts_the_module()
    {
        Sleep::fake();
        $cm = $this->stand();
        $card = Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('Power (PoE)')
            ->assertSee('asking the switch…')
            ->assertSeeHtml('wire:init="loadPower"');
        $this->assertSame([], $this->fake->walks, 'a slow switch does not hold the card back');
        $card->call('loadPower')
            ->assertSee('GE0/0/3 · delivering 6.9 W')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again."')
            ->call('restart')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }

    public function test_the_card_says_why_there_is_no_restart()
    {
        $cm = $this->stand([]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->call('loadPower')
            ->assertSee('no restart: no write access (Settings → Ethernet switch)');
        Setting::create(['key' => 'ethernetswitch_snmp_write_community', 'value' => 'private']);
        $away = Cm::create(['serial' => '10000000e611ffff', 'mac' => 'e4:5f:01:00:ff:ff', 'phase' => 'failed']);
        Livewire::test(CmCard::class, ['serial' => $away->serial])->call('loadPower')->assertSee('no restart: not seen on the switch');
    }

    public function test_a_card_without_a_switch_has_no_power_row()
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'done']);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertDontSee('Power (PoE)')->assertDontSeeHtml('wire:init="loadPower"');
    }

    /* An active project and its open batch with the module */
    protected function batchWith(Cm $cm)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subMinutes(30), 'last_event_at' => now()]);
        $cm->update(['notification_batch_id' => $batch->id, 'project_id' => $project->id]);
        return $batch;
    }

    public function test_the_dashboard_restarts_the_failed_modules_of_the_batch()
    {
        Sleep::fake();
        $cm = $this->stand();
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Restart failed (1)')
            ->assertSeeHtml('<span wire:loading wire:target="restart('.$cm->id.')">Restarting…</span>')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again."')
            ->call('restartFailed')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.')
            ->assertDispatched('poe-outages-changed');
    }

    public function test_the_card_shows_a_port_left_off_with_power_on_right_after_the_restart()
    {
        Sleep::fake();
        $cm = $this->stand();
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.8=1'] = 'noAccess';
        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->call('restart')
            ->assertSee('PoE on GE0/0/3 stays OFF: noAccess. Use Power on.')
            ->assertDispatched('poe-outages-changed');
        $html = $this->actingAs(User::factory()->create())->get(route('cm', $cm->serial))->assertOk()
            ->assertSeeLivewire(PoeOutages::class)->getContent();
        $this->assertStringContainsString('PoE on GE0/0/3 stayed off after a restart', $html);
        $this->assertStringContainsString('poe-outages-changed', $html, 'the banner listens for restarts on the page');
    }

    public function test_without_write_access_the_dashboard_has_no_restart()
    {
        $cm = $this->stand([]);
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertDontSee('Restart failed')
            ->assertDontSeeHtml('wire:click="restart(');
    }

    public function test_a_row_restart_finds_the_module_where_it_is_now()
    {
        Sleep::fake();
        $cm = $this->stand();
        $cm->update(['provisioning_board' => 'GE0/0/1']);
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/1? PoE goes off for 5 s;')
            ->call('restart', $cm->id)
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }
}
