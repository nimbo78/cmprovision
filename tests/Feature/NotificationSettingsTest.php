<?php

namespace Tests\Feature;

use App\Http\Livewire\NotificationSettings;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\User;
use App\Services\TlsTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\TestCertificates;
use Tests\TestCase;

/** Notification channels are set up on the settings page, without touching config files. */
class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_page_has_a_notifications_section()
    {
        $this->actingAs(User::factory()->create())->get('/settings')->assertOk()->assertSee('Notifications');
    }

    public function test_a_webhook_channel_is_added_with_the_default_events()
    {
        Livewire::test(NotificationSettings::class)
            ->call('add')
            ->set('name', 'Line chat')
            ->set('type', 'mattermost_webhook')
            ->set('url', 'https://mm.example.com/hooks/abc')
            ->call('save')
            ->assertHasNoErrors();

        $channel = NotificationChannel::firstOrFail();
        $this->assertSame('Line chat', $channel->name);
        $this->assertSame('https://mm.example.com/hooks/abc', $channel->setting('url'));
        $this->assertSame(['completed', 'failed'], $channel->events);
        $this->assertTrue($channel->enabled);
    }

    public function test_fields_are_checked_for_the_chosen_type()
    {
        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'x')->set('type', 'webhook')->set('url', 'not a url')->call('save')
            ->assertHasErrors(['url']);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'tg')->set('type', 'telegram')->set('botToken', '')->set('chatId', 'abc')->call('save')
            ->assertHasErrors(['botToken', 'chatId']);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'x')->set('type', 'webhook')->set('url', 'https://h.example.org/')->set('events', [])->call('save')
            ->assertHasErrors(['events']);

        $this->assertSame(0, NotificationChannel::count());
    }

    public function test_a_mattermost_channel_link_is_resolved_to_its_id()
    {
        Http::fake([
            'mm.example.com/api/v4/teams/name/line/channels/name/provisioning' => Http::response(['id' => 'chan9', 'display_name' => 'Provisioning']),
        ]);

        Livewire::test(NotificationSettings::class)
            ->call('add')
            ->set('name', 'MM bot')
            ->set('type', 'mattermost_bot')
            ->set('serverUrl', 'https://mm.example.com')
            ->set('token', 'bot-token')
            ->set('channel', 'https://mm.example.com/line/channels/provisioning')
            ->call('save')
            ->assertHasNoErrors();

        $channel = NotificationChannel::firstOrFail();
        $this->assertSame('chan9', $channel->setting('channel_id'));
        $this->assertSame('bot-token', $channel->setting('token'));
        Http::assertSent(function (Request $r) {
            return $r->hasHeader('Authorization', 'Bearer bot-token');
        });
    }

    public function test_an_unknown_mattermost_channel_is_reported()
    {
        Http::fake(['*' => Http::response(['message' => 'Unable to find the existing channel.'], 404)]);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'MM bot')->set('type', 'mattermost_bot')
            ->set('serverUrl', 'https://mm.example.com')->set('token', 't')->set('channel', 'line/nope')
            ->call('save')
            ->assertHasErrors(['channel']);
    }

    public function test_editing_keeps_a_secret_left_empty()
    {
        $channel = NotificationChannel::create(['name' => 'tg', 'type' => 'telegram', 'enabled' => true,
            'settings' => ['bot_token' => '123:secret', 'chat_id' => '-1001', 'topic_id' => ''], 'events' => ['failed']]);

        Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)
            ->assertSet('botToken', '')
            ->set('chatId', '-1002')
            ->call('save')
            ->assertHasNoErrors();

        $channel->refresh();
        $this->assertSame('123:secret', $channel->setting('bot_token'));
        $this->assertSame('-1002', $channel->setting('chat_id'));
        $this->assertSame(['failed'], $channel->events);
    }

    public function test_a_stored_token_is_never_sent_to_a_new_server()
    {
        $channel = NotificationChannel::create(['name' => 'mm', 'type' => 'mattermost_bot', 'enabled' => true,
            'settings' => ['server_url' => 'https://mm.example.com', 'token' => 'stored-secret', 'channel' => 'line/prov',
                           'channel_id' => 'abcdefghijklmnopqrstuvwxyz'], 'events' => ['failed']]);
        Http::fake();

        Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)
            ->set('serverUrl', 'https://collector.example.org')
            ->set('channel', 'team/somewhere')
            ->call('save')
            ->assertHasErrors(['token']);

        Http::assertNothingSent();
        $this->assertSame('https://mm.example.com', $channel->fresh()->setting('server_url'));
    }

    public function test_the_test_button_sends_a_message_and_shows_errors()
    {
        $channel = NotificationChannel::create(['name' => 'hook', 'type' => 'mattermost_webhook', 'enabled' => true,
            'settings' => ['url' => 'https://mm.example.com/hooks/abc'], 'events' => ['completed']]);

        Http::fake(['mm.example.com/*' => Http::response('ok')]);
        Livewire::test(NotificationSettings::class)->call('test', $channel->id)->assertSee('Test message sent');
        Http::assertSent(function (Request $r) {
            return $r->url() == 'https://mm.example.com/hooks/abc' && strpos($r['text'], 'Test message') !== false;
        });

        $broken = NotificationChannel::create(['name' => 'broken', 'type' => 'mattermost_webhook', 'enabled' => true,
            'settings' => ['url' => 'https://other.example.com/hooks/x'], 'events' => ['completed']]);
        Http::fake(['other.example.com/*' => Http::response('Invalid webhook', 400)]);
        Livewire::test(NotificationSettings::class)->call('test', $broken->id)->assertSee('400');
        $this->assertStringContainsString('400', $broken->fresh()->last_error);
    }

    public function test_channels_can_be_switched_off_and_deleted()
    {
        $channel = NotificationChannel::create(['name' => 'hook', 'type' => 'webhook', 'enabled' => true,
            'settings' => ['url' => 'https://h.example.org/'], 'events' => ['completed']]);

        Livewire::test(NotificationSettings::class)->call('toggle', $channel->id);
        $this->assertFalse($channel->fresh()->enabled);

        Livewire::test(NotificationSettings::class)->call('delete', $channel->id);
        $this->assertSame(0, NotificationChannel::count());
    }

    public function test_a_new_batch_can_be_started_from_the_page()
    {
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'label_moment' => 'never', 'verify' => false]);
        $batch = NotificationBatch::current($project);

        Livewire::test(NotificationSettings::class)->assertSee('Batch #'.$batch->id)->call('newBatch');

        $this->assertNotNull($batch->fresh()->closed_at);
    }

    public function test_telegram_chats_seen_by_the_bot_are_offered()
    {
        Http::fake(['api.telegram.org/bot123:abc/getUpdates' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 1, 'my_chat_member' => ['chat' => ['id' => -1001234, 'title' => 'Provisioning line', 'type' => 'supergroup']]],
            ['update_id' => 2, 'message' => ['chat' => ['id' => 555, 'first_name' => 'Igor', 'type' => 'private'], 'text' => 'hi']],
        ]])]);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('type', 'telegram')->set('botToken', '123:abc')
            ->call('findTelegramChats')
            ->assertSee('Provisioning line')
            ->assertSee('-1001234')
            ->call('useChat', '-1001234')
            ->assertSet('chatId', '-1001234');
    }

    public function test_a_trusted_certificate_is_saved_for_its_server_and_shown_when_editing()
    {
        $made = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'Line')->set('type', 'mattermost_webhook')->set('url', 'https://mm.line.local/hooks/abc')
            ->set('tlsCa', "\n".$made['cert']."\n")
            ->call('save')
            ->assertHasNoErrors();

        $channel = NotificationChannel::firstOrFail();
        $this->assertSame([trim($made['cert'])."\n", 'mm.line.local:443', false],
                          [$channel->setting('tls_ca'), $channel->setting('tls_ca_for'), $channel->setting('tls_insecure')]);
        Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)
            ->assertSet('tlsCa', trim($made['cert'])."\n")
            ->assertSee(TlsTrust::describe($made['cert'])[0]['sha256'])
            ->assertSee('self-signed');
        Livewire::test(NotificationSettings::class)->assertSeeHtml('· own certificate</span>');
    }

    public function test_a_private_key_or_other_text_is_not_taken_for_a_certificate()
    {
        $made = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true);

        foreach (['not a certificate' => 'PEM', $made['cert'].$made['key'] => 'private key'] as $text => $why)
            Livewire::test(NotificationSettings::class)
                ->call('add')->set('name', 'x')->set('type', 'webhook')->set('url', 'https://h.line.local/')->set('tlsCa', $text)
                ->call('save')
                ->assertHasErrors(['tlsCa'])
                ->assertSee($why);

        $this->assertSame(0, NotificationChannel::count());
    }

    public function test_a_certificate_stays_with_the_server_it_was_saved_for()
    {
        $pem = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true)['cert'];
        $channel = NotificationChannel::create(['name' => 'hooks', 'type' => 'mattermost_webhook', 'enabled' => true, 'events' => ['failed'],
            'settings' => ['url' => 'https://mm.line.local/hooks/a', 'tls_ca' => $pem, 'tls_ca_for' => 'mm.line.local:443']]);

        // another hook on the same server keeps it
        Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)->set('url', 'https://mm.line.local/hooks/b')->call('save')->assertHasNoErrors();
        $this->assertSame([$pem, 'mm.line.local:443'], [$channel->fresh()->setting('tls_ca'), $channel->fresh()->setting('tls_ca_for')]);

        // another server: its own certificate, or none
        $page = Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)->set('url', 'https://chat.line.local/hooks/a')->call('save')
            ->assertHasErrors(['tlsCa'])
            ->assertSee('belongs to mm.line.local:443');
        $this->assertSame('https://mm.line.local/hooks/b', $channel->fresh()->setting('url'));
        $page->set('tlsCa', '')->call('save')->assertHasNoErrors();
        $this->assertSame(['https://chat.line.local/hooks/a', null], [$channel->fresh()->setting('url'), $channel->fresh()->setting('tls_ca')]);

        // pasted again by hand, it is for the server in the form
        Livewire::test(NotificationSettings::class)->call('edit', $channel->id)->set('tlsCa', $pem)->call('save')->assertHasNoErrors();
        $this->assertSame('chat.line.local:443', $channel->fresh()->setting('tls_ca_for'));
    }

    public function test_accepting_any_certificate_is_warned_about_and_marked_in_the_list()
    {
        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'MM')->set('type', 'mattermost_bot')
            ->set('tlsInsecure', true)
            ->assertSee('anyone on the way to the server can read the bot token');

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'Line')->set('type', 'webhook')->set('url', 'https://collector.line.local/x')
            ->set('tlsInsecure', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSeeHtml('· any certificate</span>');

        $this->assertTrue(NotificationChannel::firstOrFail()->setting('tls_insecure'));
    }

    public function test_the_channel_lookup_on_save_trusts_what_the_form_says()
    {
        $pem = TestCertificates::make('mm.line.local', ['mm.line.local'], null, true)['cert'];
        $seen = [];
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen[] = [$options['verify'] ?? null, $options['curl'][CURLOPT_CAINFO_BLOB] ?? null];
            return Http::response(['id' => 'chan9']);
        });
        $form = function () {
            return Livewire::test(NotificationSettings::class)
                ->call('add')->set('name', 'MM')->set('type', 'mattermost_bot')
                ->set('serverUrl', 'https://mm.line.local')->set('token', 'tok')->set('channel', 'line/provisioning');
        };

        $form()->set('tlsCa', $pem)->call('save')->assertHasNoErrors();
        $form()->set('tlsInsecure', true)->call('save')->assertHasNoErrors();

        $this->assertSame([[true, $pem], [false, null]], $seen);
    }

    public function test_certificate_errors_say_what_to_do()
    {
        Http::fake(['*' => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate')]);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'MM')->set('type', 'mattermost_bot')
            ->set('serverUrl', 'https://mm.line.local')->set('token', 'tok')->set('channel', 'line/provisioning')
            ->call('save')
            ->assertHasErrors(['channel'])
            ->assertSee('Fetch from the server');

        $channel = NotificationChannel::create(['name' => 'hook', 'type' => 'mattermost_webhook', 'enabled' => true,
            'settings' => ['url' => 'https://mm.line.local/hooks/abc'], 'events' => ['completed']]);
        Livewire::test(NotificationSettings::class)
            ->call('test', $channel->id)
            ->assertSet('noticeIsError', true)
            ->assertSee('Fetch from the server');
        $this->assertStringContainsString(TlsTrust::HINT_UNTRUSTED, $channel->fresh()->last_error);
    }

    public function test_fetching_a_certificate_clears_the_errors_of_the_last_save()
    {
        Http::fake(['*' => Http::failedConnection('cURL error 60: SSL certificate problem: self-signed certificate')]);

        Livewire::test(NotificationSettings::class)
            ->call('add')->set('name', 'MM')->set('type', 'mattermost_bot')
            ->set('serverUrl', 'http://mm.line.local')->set('token', 'tok')->set('channel', 'line/provisioning')
            ->call('save')
            ->assertHasErrors(['channel'])
            ->call('fetchCertificate')
            ->assertHasNoErrors(['channel'])
            ->assertHasErrors(['tlsCa']);
    }

    public function test_a_server_the_system_trusts_needs_no_certificate_of_its_own()
    {
        $pem = TestCertificates::make('mm.line.local', ['10.0.0.5'], null, true)['cert'];
        $channel = NotificationChannel::create(['name' => 'hooks', 'type' => 'mattermost_webhook', 'enabled' => true, 'events' => ['failed'],
            'settings' => ['url' => 'https://10.0.0.5/hooks/a', 'tls_ca' => $pem, 'tls_ca_for' => '10.0.0.5:443']]);
        $this->app->instance(TlsTrust::class, new class extends TlsTrust {
            public function fetch(string $url): array
            {
                return ['trusted' => true, 'chain' => []];
            }
        });

        // moved to a server with a certificate from a public CA: fetching drops the old certificate
        Livewire::test(NotificationSettings::class)
            ->call('edit', $channel->id)
            ->set('url', 'https://mm.example.com/hooks/a')
            ->call('fetchCertificate')
            ->assertSet('tlsCa', '')
            ->assertSee('This system trusts the certificate of mm.example.com:443 already')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($channel->fresh()->setting('tls_ca'));
    }

    public function test_telegram_errors_on_the_page_never_show_the_bot_token()
    {
        Http::fake(['*' => Http::failedConnection('cURL error 6: Could not resolve host: api.telegram.org (see https://curl.se/libcurl/c/libcurl-errors.html) for https://api.telegram.org/bot123:secret-token/getUpdates')]);
        $channel = NotificationChannel::create(['name' => 'tg', 'type' => 'telegram', 'enabled' => true,
            'settings' => ['bot_token' => '123:secret-token', 'chat_id' => '-1001'], 'events' => ['failed']]);

        Livewire::test(NotificationSettings::class)
            ->call('test', $channel->id)
            ->assertSet('noticeIsError', true)
            ->assertSee('api.telegram.org/bot…/getUpdates')
            ->assertDontSee('secret-token')
            ->call('edit', $channel->id)
            ->call('findTelegramChats')
            ->assertHasErrors(['botToken'])
            ->assertDontSee('secret-token');

        $this->assertStringNotContainsString('secret-token', $channel->fresh()->last_error);
    }

    public function test_fetching_a_certificate_needs_an_https_address()
    {
        Livewire::test(NotificationSettings::class)
            ->call('add')->set('type', 'webhook')->set('url', 'http://collector.line.local/x')
            ->call('fetchCertificate')
            ->assertHasErrors(['tlsCa'])
            ->assertSee('Only an https:// address');
    }
}
