<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Services\Notifier;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\TestCertificates;
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
        Sleep::fake();   // a second between calls to one chat
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

    public function test_a_module_refused_for_no_active_project_leaves_the_batch_of_its_last_run()
    {
        // flashed yesterday in a batch with a bot thread, back on the stand while no project is active
        $project = $this->activeProject();
        $channel = $this->channel('mattermost_bot', ['server_url' => 'https://mm.example.com', 'token' => 'tok', 'channel_id' => 'chan1'], ['failed']);
        $old = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subDay(), 'last_event_at' => now()->subDay(),
                                          'closed_at' => now()->subDay()]);
        \App\Models\NotificationThread::create(['batch_id' => $old->id, 'channel_id' => $channel->id, 'external_id' => 'oldsummary']);
        Cm::create(['serial' => '1000000000000e01', 'mac' => 'e4:5f:01:00:00:01', 'phase' => 'done', 'notification_batch_id' => $old->id]);
        Setting::where('key', 'active_project')->delete();
        $this->mattermostFake();

        $this->start('1000000000000e01');

        $cm = Cm::where('serial', '1000000000000e01')->first();
        $this->assertSame('failed', $cm->phase);
        $this->assertNull($cm->notification_batch_id, 'the refusal belongs to no batch');
        $this->assertTrue($old->fresh()->last_event_at->eq(now()->subDay()), 'the old batch is not touched');
        Http::assertNotSent(function (Request $r) { return ($r['root_id'] ?? null) === 'oldsummary'; });
        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://mm.example.com/api/v4/posts' && empty($r['root_id']) && Str::contains($r['message'], 'e4:5f:01:00:00:01');
        });
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
                && Str::contains($r['message'], 'e4:5f:01:00:00:01') && Str::contains($r['message'], '✅');
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
            return $r->url() == 'https://mm.example.com/hooks/abc' && Str::contains($r['text'], 'e4:5f:01:00:00:03')
                && Str::contains($r['text'], '✅');
        });
        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://hooks.example.org/cm' && $r['event'] === 'completed'
                && $r['serial'] === '1000000000000c03' && $r['mac'] === 'e4:5f:01:00:00:03' && $r['project'] === '78';
        });
        Http::assertSentCount(2);   // "started" is not selected for these channels
    }

    public function test_the_webhook_names_the_switch_and_the_port()
    {
        $this->activeProject();
        $this->channel('webhook', ['url' => 'https://collector.line.local/x']);
        Http::fake();
        $cm = Cm::create(['serial' => '1000000000000c31', 'mac' => 'e4:5f:01:00:00:31', 'phase' => 'done',
            'provisioning_board' => 'sw02 GE0/0/3', 'switch_name' => 'sw02', 'switch_port' => 'GE0/0/3',
            'provisioning_started_at' => now()->subMinutes(5), 'provisioning_complete_at' => now()]);

        (new Notifier)->notify($cm, 'completed');

        Http::assertSent(function (Request $r) {
            return $r['board'] === 'sw02 GE0/0/3' && $r['switch'] === 'sw02' && $r['port'] === 'GE0/0/3';
        });
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

    public function test_a_channel_trusts_its_own_certificate_for_its_server_or_none_at_all()
    {
        $this->activeProject();
        $pem = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true)['cert'];
        $own = ['tls_ca' => $pem, 'tls_ca_for' => 'mm.line.local:443'];
        $this->channel('mattermost_webhook', ['url' => 'https://mm.line.local/hooks/a'] + $own);
        $this->channel('mattermost_webhook', ['url' => 'https://chat.line.local/hooks/b'] + $own);
        $this->channel('webhook', ['url' => 'https://collector.line.local/x', 'tls_insecure' => true]);
        $this->channel('mattermost_bot', ['server_url' => 'https://mm.line.local', 'token' => 'tok', 'channel_id' => 'chan1'] + $own);
        $this->channel('telegram', ['bot_token' => '123:abc', 'chat_id' => '-1001', 'tls_insecure' => true]);
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [preg_replace('#/bot[^/]+/#', '/bot…/', $request->url()), $options['verify'] ?? null, $options['curl'][CURLOPT_CAINFO_BLOB] ?? null];
            return Http::response(['id' => 'post1', 'ok' => true, 'result' => ['message_id' => 7]]);
        });

        $this->start('1000000000000c21');
        $this->complete('1000000000000c21');

        $this->assertContains(['https://mm.line.local/hooks/a', true, $pem], $seen);
        $this->assertContains(['https://chat.line.local/hooks/b', true, null], $seen, 'a certificate is only for the server it was saved with');
        $this->assertContains(['https://collector.line.local/x', false, null], $seen);
        $this->assertContains(['https://mm.line.local/api/v4/posts', true, $pem], $seen, 'the bot posts its summary and replies');
        $this->assertContains(['https://mm.line.local/api/v4/posts/post1/patch', true, $pem], $seen);
        $this->assertContains(['https://api.telegram.org/bot…/sendMessage', true, null], $seen, 'Telegram is checked as always');
    }

    public function test_a_certificate_error_is_recorded_with_what_to_do()
    {
        $this->activeProject();
        $channel = $this->channel('mattermost_webhook', ['url' => 'https://mm.line.local/hooks/abc']);
        Http::fake(['*' => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate for https://mm.line.local/hooks/abc')]);

        $this->start('1000000000000c22');
        $this->complete('1000000000000c22');

        $this->assertStringStartsWith('2026-10-09 11:00 '.\App\Services\TlsTrust::HINT_UNTRUSTED, $channel->fresh()->last_error);
    }

    public function test_a_telegram_error_shows_neither_the_bot_token_nor_advice_about_certificates()
    {
        $this->activeProject();
        $channel = $this->channel('telegram', ['bot_token' => '123:secret-token', 'chat_id' => '-1001']);
        Http::fake(['*' => Http::failedConnection('cURL error 60: SSL certificate problem: unable to get local issuer certificate (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.telegram.org/bot123:secret-token/sendMessage')]);

        $this->start('1000000000000c23');
        $this->complete('1000000000000c23');

        $error = $channel->fresh()->last_error;
        $this->assertStringContainsString('api.telegram.org/bot…/sendMessage', $error);
        $this->assertStringNotContainsString('secret-token', $error);
        $this->assertStringNotContainsString('Fetch from the server', $error);
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

    public function test_a_notification_that_cannot_be_queued_does_not_fail_the_module()
    {
        $this->activeProject();
        $this->channel('webhook', ['url' => 'https://hooks.example.org/cm']);
        Http::fake();
        $this->start('1000000000000c40');
        $this->mock(Notifier::class, function ($mock) {
            $mock->shouldReceive('notify')->andThrow(new \RuntimeException('database is locked'));
        });

        $this->complete('1000000000000c40');

        $this->assertSame('done', Cm::where('serial', '1000000000000c40')->value('phase'), 'the module flashed fine');
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
        Http::assertSentCount(1);   // one listener: Laravel 11+ discovers events too, it must not run twice
    }
}
