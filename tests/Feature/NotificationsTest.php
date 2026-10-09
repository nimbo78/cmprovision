<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Provisioning events go to Mattermost, Telegram or any webhook. Bots keep one thread per batch:
 * a summary message that is edited as modules finish, and a reply per module under it.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-09 08:00:00');
        config(['app.display_timezone' => 'Europe/Moscow']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function activeProject($name = '78')
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();

        $project = Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                                    'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function channel($type, array $settings, array $events = ['completed', 'failed'], $enabled = true)
    {
        return NotificationChannel::create(['name' => $type, 'type' => $type, 'enabled' => $enabled,
                                            'settings' => $settings, 'events' => $events]);
    }

    protected function start($serial)
    {
        return $this->get("/scriptexecute?serial=$serial&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:".substr($serial, -2))->assertOk();
    }

    protected function complete($serial)
    {
        return $this->get("/scriptexecute?serial=$serial&alldone=1&temp=50C&verify=0")->assertOk();
    }

    protected function failWrite($serial)
    {
        $log = UploadedFile::fake()->createWithContent('dd.log', "curl exit code 18\n");
        return $this->post("/scriptexecute?serial=$serial&retcode=1&phase=dd", ['log' => $log])->assertOk();
    }

    protected function mattermostFake()
    {
        $n = 0;
        Http::fake([
            'mm.example.com/api/v4/posts/*/patch' => Http::response(['id' => 'summary1'], 200),
            'mm.example.com/api/v4/posts' => function () use (&$n) {
                $n++;
                return Http::response(['id' => $n == 1 ? 'summary1' : "reply$n"], 201);
            },
        ]);
    }

    public function test_mattermost_bot_keeps_a_batch_thread_with_an_updated_summary()
    {
        $this->activeProject();
        $this->channel('mattermost_bot', ['server_url' => 'https://mm.example.com', 'token' => 'tok', 'channel_id' => 'chan1']);
        $this->mattermostFake();

        $this->start('1000000000000c01');
        $this->complete('1000000000000c01');

        $posts = collect(Http::recorded())->map(function ($pair) { return $pair[0]; });
        $summary = $posts->first(function (Request $r) { return $r->url() == 'https://mm.example.com/api/v4/posts' && empty($r['root_id']); });
        $this->assertNotNull($summary, 'the batch summary is posted when the first module starts');
        $this->assertSame('chan1', $summary['channel_id']);
        $this->assertStringContainsString('78', $summary['message']);
        $this->assertSame('Bearer tok', $summary->header('Authorization')[0]);

        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://mm.example.com/api/v4/posts' && ($r['root_id'] ?? null) === 'summary1'
                && Str::contains($r['message'], '1000000000000c01') && Str::contains($r['message'], '✅');
        });
        Http::assertSent(function (Request $r) {
            return $r->method() == 'PUT' && $r->url() == 'https://mm.example.com/api/v4/posts/summary1/patch'
                && Str::contains($r['message'], '1 done');
        });
    }

    public function test_telegram_bot_replies_under_the_batch_summary_and_edits_it()
    {
        $this->activeProject();
        $this->channel('telegram', ['bot_token' => '123:abc', 'chat_id' => '-100555', 'topic_id' => '']);
        Http::fake([
            'api.telegram.org/bot123:abc/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 42]])
                ->push(['ok' => true, 'result' => ['message_id' => 43]]),
            'api.telegram.org/bot123:abc/editMessageText' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->start('1000000000000c02');
        $this->failWrite('1000000000000c02');

        Http::assertSent(function (Request $r) {
            return Str::endsWith($r->url(), '/sendMessage') && $r['chat_id'] === '-100555'
                && ($r['reply_parameters']['message_id'] ?? null) === 42
                && Str::contains($r['text'], '❌') && Str::contains($r['text'], 'connection to the provisioning server was closed');
        });
        Http::assertSent(function (Request $r) {
            return Str::endsWith($r->url(), '/editMessageText') && $r['message_id'] === 42 && Str::contains($r['text'], '1 failed');
        });
    }

    public function test_webhooks_get_one_message_per_event()
    {
        $this->activeProject();
        $this->channel('mattermost_webhook', ['url' => 'https://mm.example.com/hooks/abc']);
        $this->channel('webhook', ['url' => 'https://hooks.example.org/cm']);
        Http::fake();

        $this->start('1000000000000c03');
        $this->complete('1000000000000c03');

        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://mm.example.com/hooks/abc' && Str::contains($r['text'], '1000000000000c03')
                && Str::contains($r['text'], '✅');
        });
        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://hooks.example.org/cm' && $r['event'] === 'completed'
                && $r['serial'] === '1000000000000c03' && $r['mac'] === 'e4:5f:01:00:00:03' && $r['project'] === '78';
        });
        Http::assertSentCount(2);   // "started" is not selected for these channels
    }

    public function test_modules_within_the_idle_gap_share_a_batch_and_a_new_batch_starts_after_it()
    {
        $this->activeProject();
        $this->mattermostFake();
        $this->channel('mattermost_bot', ['server_url' => 'https://mm.example.com', 'token' => 'tok', 'channel_id' => 'chan1']);

        $this->start('1000000000000c04');
        Carbon::setTestNow(now()->addHour());
        $this->start('1000000000000c05');
        $this->assertSame(1, NotificationBatch::count());
        $this->assertEquals(Cm::where('serial', '1000000000000c04')->value('notification_batch_id'),
                            Cm::where('serial', '1000000000000c05')->value('notification_batch_id'));

        Carbon::setTestNow(now()->addHours(NotificationBatch::IDLE_HOURS + 1));
        $this->start('1000000000000c06');
        $this->assertSame(2, NotificationBatch::count());
    }

    public function test_a_new_batch_can_be_started_by_hand()
    {
        $this->activeProject();
        Http::fake();
        $this->start('1000000000000c07');
        $first = NotificationBatch::latest('id')->first();

        NotificationBatch::startNew();
        $this->start('1000000000000c08');

        $this->assertNotNull($first->fresh()->closed_at);
        $this->assertSame(2, NotificationBatch::count());
    }

    public function test_a_failing_channel_is_recorded_and_does_not_disturb_provisioning()
    {
        $this->activeProject();
        $channel = $this->channel('mattermost_webhook', ['url' => 'https://mm.example.com/hooks/abc']);
        Http::fake(['*' => Http::response('channel not found', 404)]);

        $this->start('1000000000000c09');
        $this->complete('1000000000000c09')->assertSee('', false);

        $channel->refresh();
        $this->assertStringContainsString('404', $channel->last_error);
        $this->assertSame('done', Cm::where('serial', '1000000000000c09')->value('phase'));
    }

    public function test_disabled_channels_and_unselected_events_send_nothing()
    {
        $this->activeProject();
        $this->channel('webhook', ['url' => 'https://hooks.example.org/a'], ['completed'], false);
        $this->channel('webhook', ['url' => 'https://hooks.example.org/b'], ['failed']);
        Http::fake();

        $this->start('1000000000000c10');
        $this->complete('1000000000000c10');

        Http::assertNothingSent();
    }

    public function test_started_events_are_sent_when_selected()
    {
        $this->activeProject();
        $this->channel('webhook', ['url' => 'https://hooks.example.org/s'], ['started']);
        Http::fake();

        $this->start('1000000000000c11');

        Http::assertSent(function (Request $r) {
            return $r['event'] === 'started' && $r['serial'] === '1000000000000c11';
        });
    }
}
