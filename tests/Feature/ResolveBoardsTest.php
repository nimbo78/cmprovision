<?php

namespace Tests\Feature;

use App\Http\Livewire\ProvisioningStatus;
use App\Jobs\PrintLabel;
use App\Jobs\ResolveBoards;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetworkStatus;
use App\Services\Switch\MacTable;
use App\Services\Switch\Stand;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** A module's board is looked up in the background: the start answers at once, one poll of the stand serves a wave */
class ResolveBoardsTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    const START = '/scriptexecute?model=CM4&storagesize=62500000&serial=';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
    }

    /* A module whose start waits for its board; the stand's own modules (PoeStand) are taken over */
    protected function pending(string $serial, string $mac): Cm
    {
        return Cm::updateOrCreate(['serial' => $serial], ['mac' => $mac, 'phase' => 'write', 'phase_detail' => null,
            'provisioning_started_at' => now(), 'provisioning_board' => null, 'switch_id' => null, 'switch_name' => null,
            'switch_port' => null, 'board_pending_since' => now()]);
    }

    protected function project(string $labelMoment = 'never'): Project
    {
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $label = Label::create(['name' => 'csv', 'printer_type' => 'command', 'print_command' => 'cat $file',
                                'template' => '$serial|$provisionboard', 'file_extension' => 'txt']);
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_id' => $label->id, 'label_moment' => $labelMoment, 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    public function test_a_start_with_switches_answers_at_once_and_leaves_the_board_to_the_queue()
    {
        Queue::fake();
        $this->stand();
        $this->project();

        $this->get(self::START.'10000000e611110c&mac=e4:5f:01:62:9f:d3')->assertOk()->assertSee('Writing image from');

        $cm = Cm::where('serial', '10000000e611110c')->first();
        $this->assertNull($cm->provisioning_board);
        $this->assertNotNull($cm->board_pending_since);
        $this->assertSame([], $this->fake->walks, 'the start asks no switch');
        Queue::assertPushedOn('boards', ResolveBoards::class);
    }

    public function test_without_switches_the_board_comes_from_the_jumpers_at_the_start()
    {
        Queue::fake();
        $this->project();

        $this->get(self::START.'10000000e611110c&mac=e4:5f:01:62:9f:d3&inversejumper=110')->assertOk();

        $cm = Cm::where('serial', '10000000e611110c')->first();
        $this->assertSame(['001 (1)', null], [$cm->provisioning_board, $cm->board_pending_since]);
        Queue::assertNotPushed(ResolveBoards::class);
    }

    public function test_one_poll_finds_the_boards_of_a_wave()
    {
        $this->stand();
        $this->secondSwitch();
        $one = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        $two = $this->pending('10000000e611110e', 'e4:5f:01:62:9f:d5');

        (new ResolveBoards)->handle();

        $one->refresh();
        $two->refresh();
        $this->assertSame(['sw01 GE0/0/3', 'sw02 GE0/0/3'], [$one->provisioning_board, $two->provisioning_board]);
        $this->assertSame([1, 'sw02', 'GE0/0/3'], [$one->switch_id, $two->switch_name, $two->switch_port]);
        $this->assertSame([null, null], [$one->board_pending_since, $two->board_pending_since]);
        $walks = count($this->fake->walks);
        Stand::current();
        $this->assertSame($walks, count($this->fake->walks), 'the snapshot the job took is the one the pages use');
    }

    public function test_a_module_not_on_the_map_yet_is_looked_up_again()
    {
        Queue::fake();
        $this->stand();
        $cm = $this->pending('10000000e611110f', 'e4:5f:01:00:00:99');

        (new ResolveBoards)->handle();

        $this->assertNotNull($cm->fresh()->board_pending_since);
        Queue::assertPushed(ResolveBoards::class,
            fn ($job) => (int) now()->diffInSeconds($job->delay, true) === ResolveBoards::RETRY_SECONDS);

        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.0.0.153.10.0'] = 7;   // e4:5f:01:00:00:99 on GE0/0/2
        Carbon::setTestNow(now()->addSeconds(ResolveBoards::RETRY_SECONDS));
        (new ResolveBoards)->handle();                                 // a newer snapshot than the run before saw

        $this->assertSame('sw01 GE0/0/2', $cm->fresh()->provisioning_board);
    }

    public function test_after_a_minute_the_module_gets_the_warning_and_no_board()
    {
        Queue::fake();
        $this->stand();
        $cm = $this->pending('10000000e611110f', 'e4:5f:01:00:00:99');
        Carbon::setTestNow(now()->addSeconds(ResolveBoards::GIVE_UP_SECONDS));

        (new ResolveBoards)->handle();

        Queue::assertNothingPushed();
        $this->assertSame([null, null], [$cm->fresh()->provisioning_board, $cm->fresh()->board_pending_since]);
        $this->assertSame(1, Cmlog::where('cm', '10000000e611110f')->where('loglevel', 'warning')
            ->where('msg', 'Switch port unknown: sw01 does not know MAC e4:5f:01:00:00:99')->count());
    }

    public function test_the_preinstall_label_is_printed_once_with_the_board()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('the print command runs in a POSIX shell');
        $this->stand();
        $project = $this->project('preinstall');
        $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3')->update(['project_id' => $project->id]);

        (new ResolveBoards)->handle();
        (new ResolveBoards)->handle();

        $this->assertSame(1, Cmlog::where('msg', 'Label command output: 10000000e611110c|sw01 GE0/0/3')->count());
    }

    public function test_a_refused_module_gets_its_board_and_no_label()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('the print command runs in a POSIX shell');
        $this->stand();
        $this->project('preinstall');

        // no storagesize: refused; the sync queue of the tests looks the board up once the refusal is recorded
        $this->get('/scriptexecute?model=CM4&serial=10000000e611110c&mac=e4:5f:01:62:9f:d3')->assertOk()
            ->assertSee('Missing eMMC/SD card.');

        $cm = Cm::where('serial', '10000000e611110c')->first();
        $this->assertSame(['failed', 'sw01 GE0/0/3'], [$cm->phase, $cm->provisioning_board]);
        $this->assertSame(0, Cmlog::where('msg', 'like', 'Label command output:%')->count());
    }

    public function test_a_module_that_started_again_keeps_its_new_lookup()
    {
        $this->stand();
        $cm = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        $read = $cm->fresh();                                       // what a run read
        Carbon::setTestNow(now()->addSeconds(30));
        $cm->update(['board_pending_since' => now()]);              // the module started again meanwhile

        $this->assertFalse(ResolveBoards::settle($read, ['switch' => null, 'port' => null, 'board' => 'old', 'warning' => null]));
        $this->assertNull($cm->fresh()->provisioning_board);
        $this->assertNotNull($cm->fresh()->board_pending_since);
    }

    public function test_a_start_during_a_run_gets_a_run_of_its_own()
    {
        config(['queue.default' => 'database']);
        $this->stand();
        $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        ResolveBoards::dispatch();                                  // queued, holding the unique lock
        $this->fake->onWalk = function () {                         // while a worker runs it and asks the switch,
            $this->fake->onWalk = null;                             // another module starts
            $this->pending('10000000e611110d', 'e4:5f:01:62:9f:d4');
            ResolveBoards::dispatch();
        };

        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'boards', '--once' => true]);

        $this->assertSame('sw01 GE0/0/3', Cm::where('serial', '10000000e611110c')->value('provisioning_board'));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'boards')->count(), 'the start during the run queued the next run');
    }

    public function test_a_module_whose_board_cannot_be_recorded_does_not_strand_the_others()
    {
        Queue::fake();
        $this->stand();
        $this->secondSwitch();
        $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        $two = $this->pending('10000000e611110e', 'e4:5f:01:62:9f:d5');
        DB::statement("create trigger refuse_one before update on cms when old.serial = '10000000e611110c' "
            ."begin select raise(abort, 'disk I/O error'); end");

        (new ResolveBoards)->handle();

        $this->assertSame('sw02 GE0/0/3', $two->fresh()->provisioning_board);
        Queue::assertPushed(ResolveBoards::class);                   // the first one is tried again
    }

    public function test_a_run_that_failed_queues_the_next_while_boards_are_pending()
    {
        Queue::fake();
        (new ResolveBoards)->failed(new \RuntimeException('database is locked'));
        Queue::assertNothingPushed();

        $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        (new ResolveBoards)->failed(new \RuntimeException('database is locked'));
        Queue::assertPushed(ResolveBoards::class,
            fn ($job) => (int) now()->diffInSeconds($job->delay, true) === ResolveBoards::RETRY_SECONDS);
    }

    public function test_a_lookup_left_for_minutes_is_dropped_without_a_board_or_a_label()
    {
        if (!is_executable('/bin/sh'))
            $this->markTestSkipped('the print command runs in a POSIX shell');
        $this->stand();
        $project = $this->project('preinstall');
        $cm = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        $cm->update(['project_id' => $project->id, 'board_pending_since' => now()->subMinutes(ResolveBoards::STALE_MINUTES + 1)]);

        (new ResolveBoards)->handle();

        $this->assertSame([null, null], [$cm->fresh()->provisioning_board, $cm->fresh()->board_pending_since]);
        $this->assertSame(0, Cmlog::where('msg', 'like', 'Label command output:%')->count());
        $this->assertSame(1, Cmlog::where('cm', '10000000e611110c')->where('loglevel', 'warning')->count());
    }

    public function test_the_boards_of_a_wave_are_written_before_any_label_is_printed()
    {
        Queue::fake();
        $this->stand();
        $this->secondSwitch();
        $project = $this->project('preinstall');
        $one = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');
        $two = $this->pending('10000000e611110e', 'e4:5f:01:62:9f:d5');
        Cm::whereKey([$one->id, $two->id])->update(['project_id' => $project->id]);

        (new ResolveBoards)->handle();

        $this->assertSame(['sw01 GE0/0/3', 'sw02 GE0/0/3'], [$one->fresh()->provisioning_board, $two->fresh()->provisioning_board]);
        Queue::assertPushed(PrintLabel::class, 2);                   // each in a job of its own, after the boards
        $this->assertSame(0, Cmlog::where('msg', 'like', 'Label command output:%')->count());
    }

    public function test_a_board_lookup_that_cannot_be_queued_does_not_fail_the_start()
    {
        $this->stand();
        $this->project();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('database is locked'));
        });

        $this->get(self::START.'10000000e611110c&mac=e4:5f:01:62:9f:d3')->assertOk()->assertSee('Writing image from');

        $this->assertSame('write', Cm::where('serial', '10000000e611110c')->value('phase'));
    }

    public function test_the_log_of_a_start_gets_its_board_once_it_is_known()
    {
        Queue::fake();
        $this->stand();
        $this->project();
        $this->get(self::START.'10000000e611110c&mac=e4:5f:01:62:9f:d3')->assertOk();
        $started = fn () => Cmlog::where('cm', '10000000e611110c')->where('msg', 'like', 'Provisioning started%')->value('board');
        $this->assertNull($started());

        (new ResolveBoards)->handle();

        $this->assertSame('sw01 GE0/0/3', $started());
    }

    public function test_a_stand_that_cannot_be_read_waits_and_then_warns()
    {
        Queue::fake();
        $this->stand();
        $this->mock(NetworkStatus::class, function ($mock) {
            $mock->shouldReceive('serverMac')->andThrow(new \RuntimeException('no route to the stand'));
            $mock->shouldReceive('current')->andReturn(null);
        });
        $cm = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');

        (new ResolveBoards)->handle();
        $this->assertNotNull($cm->fresh()->board_pending_since);
        Queue::assertPushed(ResolveBoards::class);

        Carbon::setTestNow(now()->addSeconds(ResolveBoards::GIVE_UP_SECONDS));
        (new ResolveBoards)->handle();
        $this->assertNull($cm->fresh()->board_pending_since);
        $this->assertSame(1, Cmlog::where('cm', '10000000e611110c')->where('msg', 'Switch port unknown: no route to the stand')->count());
    }

    public function test_a_module_whose_switches_are_gone_gets_no_board_at_once()
    {
        Queue::fake();
        $cm = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');   // the switches were removed after the start

        (new ResolveBoards)->handle();

        $this->assertSame([null, null], [$cm->fresh()->provisioning_board, $cm->fresh()->board_pending_since]);
        $this->assertSame(0, Cmlog::where('cm', '10000000e611110c')->count());
        Queue::assertNothingPushed();
    }

    public function test_the_dashboard_and_the_card_say_the_board_is_being_looked_up()
    {
        $this->stand();
        $cm = $this->pending('10000000e611110c', 'e4:5f:01:62:9f:d3');

        Livewire::test(ProvisioningStatus::class)->assertSeeHtml('title="looking up the switch port"');
        $this->actingAs(User::factory()->create())->get('/cms/'.$cm->serial)->assertOk()->assertSee('looking up the switch port…');
    }
}
