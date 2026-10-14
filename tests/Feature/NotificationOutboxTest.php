<?php

namespace Tests\Feature;

use App\Jobs\FlushNotifications;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\NotificationOutbox;
use App\Models\NotificationThread;
use App\Models\Project;
use App\Services\Notifier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Events wait in notification_outbox and go out in packs: one message per window, the summary with fresh numbers */
class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected $batch;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 10:00:00');
        Sleep::fake();
        Queue::fake();
        $image = new Image;
        $image->filename = 'os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_moment' => 'never', 'verify' => false]);
        $this->batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now(), 'last_event_at' => now()]);
    }

    protected function channel(string $type, array $settings, array $events = ['started', 'completed', 'failed'])
    {
        return NotificationChannel::create(['name' => $type, 'type' => $type, 'enabled' => true, 'settings' => $settings, 'events' => $events]);
    }

    protected function module(int $n, string $phase, array $more = []): Cm
    {
        return Cm::create(['serial' => sprintf('1000000000000%03d', $n), 'mac' => sprintf('e4:5f:01:00:01:%02x', $n), 'phase' => $phase,
                           'provisioning_board' => 'sw01 Gi0/'.$n, 'notification_batch_id' => $this->batch->id,
                           'provisioning_started_at' => now()->subMinutes(5), 'provisioning_complete_at' => $phase === 'done' ? now() : null] + $more);
    }

    /* What the queue worker does with the job: frees its unique lock as it takes it, then runs it */
    protected function flush()
    {
        $job = new FlushNotifications;
        (new UniqueLock(app(Repository::class)))->release($job);
        $job->handle(app(Notifier::class));
    }

    protected function mattermost(array $events = ['started', 'completed', 'failed'])
    {
        $n = 0;
        Http::fake([
            'mm.example.com/api/v4/posts/*/patch' => Http::response(['id' => 'summary1'], 200),
            'mm.example.com/api/v4/posts' => function () use (&$n) {
                $n++;
                return Http::response(['id' => $n == 1 ? 'summary1' : "reply$n"], 201);
            },
        ]);
        return $this->channel('mattermost_bot', ['server_url' => 'https://mm.example.com', 'token' => 'tok', 'channel_id' => 'chan1'], $events);
    }

    protected function replies()
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => $r->url() === 'https://mm.example.com/api/v4/posts' && !empty($r['root_id']))->values();
    }

    public function test_events_within_the_window_go_out_as_one_pack()
    {
        $this->mattermost();
        foreach ([1, 2, 3] as $n)
            app(Notifier::class)->notify($this->module($n, 'done'), 'completed');
        Queue::assertPushedOn('notifications', FlushNotifications::class);

        $this->flush();

        $this->assertCount(1, $this->replies());
        $text = $this->replies()[0]['message'];
        $this->assertStringStartsWith("✅ 3 done:\n", $text);
        foreach (['1000000000000001', '1000000000000002', '1000000000000003'] as $serial)
            $this->assertStringContainsString($serial, $text);
        $this->assertSame(0, NotificationOutbox::whereNull('sent_at')->count());
    }

    public function test_a_pack_has_only_the_events_the_channel_wants_and_the_summary_is_fresh()
    {
        $channel = $this->mattermost(['failed']);
        NotificationThread::create(['batch_id' => $this->batch->id, 'channel_id' => $channel->id, 'external_id' => 'summary1']);
        app(Notifier::class)->notify($this->module(1, 'done'), 'completed');
        app(Notifier::class)->notify($this->module(2, 'failed', ['phase_detail' => 'Missing eMMC/SD card.']), 'failed');
        $this->module(3, 'write');

        $this->flush();

        $this->assertCount(1, $this->replies());
        $this->assertStringStartsWith("❌ 1 failed:\n", $this->replies()[0]['message']);
        $this->assertStringNotContainsString('1000000000000001', $this->replies()[0]['message']);
        Http::assertSent(fn (Request $r) => Str::endsWith($r->url(), '/posts/summary1/patch')
            && Str::contains($r['message'], '✅ 1 done · ❌ 1 failed · ⏳ 1 in progress'));
    }

    public function test_a_long_pack_is_split_by_lines()
    {
        $this->mattermost();
        for ($n = 1; $n <= 80; $n++)
            app(Notifier::class)->notify($this->module($n, 'failed', ['phase_detail' => str_repeat('Missing eMMC/SD card. ', 3)]), 'failed');

        $this->flush();

        $this->assertGreaterThan(1, $this->replies()->count());
        foreach ($this->replies() as $reply)
            $this->assertLessThanOrEqual(4000, mb_strlen($reply['message']));
        $this->assertStringContainsString('1000000000000080', $this->replies()->last()['message']);
    }

    public function test_a_rate_limit_waits_as_long_as_the_chat_server_asks()
    {
        Http::fake(['tg.example/*' => Http::response(['ok' => false, 'error_code' => 429,
            'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)]);
        config(['cmprovision.telegram_api' => 'https://tg.example']);
        $this->channel('telegram', ['bot_token' => '123:abc', 'chat_id' => '-100']);
        app(Notifier::class)->notify($this->module(1, 'done'), 'completed');

        $this->flush();

        Queue::assertPushed(FlushNotifications::class, fn ($job) => (int) now()->diffInSeconds($job->delay, true) === 7);
        $this->assertSame(1, NotificationOutbox::whereNull('sent_at')->count(), 'nothing lost');
        $this->flush();                                             // a run before the wait is over leaves the chat alone
        Http::assertSentCount(1);
    }

    public function test_after_five_failures_an_event_is_given_up()
    {
        Http::fake(['hooks.example.org/*' => Http::response('down', 500)]);
        $channel = $this->channel('webhook', ['url' => 'https://hooks.example.org/cm']);
        app(Notifier::class)->notify($this->module(1, 'done'), 'completed');

        for ($i = 0; $i < Notifier::ATTEMPTS; $i++)
            $this->flush();

        $row = NotificationOutbox::sole();
        $this->assertNotNull($row->sent_at);
        $this->assertSame(Notifier::ATTEMPTS, $row->attempts);
        $this->assertStringContainsString('500', $row->error);
        $this->assertStringContainsString('500', $channel->fresh()->last_error);
    }

    public function test_a_json_webhook_gets_each_event_without_a_pause()
    {
        Http::fake();
        $this->channel('webhook', ['url' => 'https://hooks.example.org/cm']);
        foreach ([1, 2, 3] as $n)
            app(Notifier::class)->notify($this->module($n, 'done'), 'completed');

        $this->flush();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r['serial'] === '1000000000000002' && $r['event'] === 'completed' && $r['board'] === 'sw01 Gi0/2');
        Sleep::assertNeverSlept();
    }

    public function test_calls_to_one_chat_are_a_second_apart()
    {
        $this->mattermost();
        app(Notifier::class)->notify($this->module(1, 'done'), 'completed');

        $this->flush();                                             // the summary, then the reply

        Sleep::assertSlept(fn ($duration) => $duration->totalMicroseconds > 900000, 1);
    }

    public function test_a_channel_turned_off_or_deleted_before_the_flush_gets_nothing()
    {
        Http::fake();
        $off = $this->channel('webhook', ['url' => 'https://hooks.example.org/a']);
        $gone = $this->channel('webhook', ['url' => 'https://hooks.example.org/b']);
        app(Notifier::class)->notify($this->module(1, 'done'), 'completed');
        $off->update(['enabled' => false]);
        $gone->delete();

        $this->flush();

        Http::assertNothingSent();
        $this->assertSame(0, NotificationOutbox::whereNull('sent_at')->count());
        $this->assertSame(1, NotificationOutbox::count(), 'the deleted channel took its rows with it');
    }

    public function test_events_of_a_module_deleted_before_the_flush_are_skipped()
    {
        $this->mattermost();
        app(Notifier::class)->notify($gone = $this->module(1, 'done'), 'completed');
        app(Notifier::class)->notify($this->module(2, 'done'), 'completed');
        $gone->delete();

        $this->flush();

        $this->assertCount(1, $this->replies());
        $this->assertStringStartsWith("✅ 1 done:\n1000000000000002", $this->replies()[0]['message']);
    }
}
