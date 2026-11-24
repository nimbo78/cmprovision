<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SerialConsoleBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class SerialConsoleWebTest extends TestCase
{
    use RefreshDatabase;

    private const PORT = 'by-id/usb-FTDI_A1-if00-port0';
    private const GENERATION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const LEASE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function operator(): User
    {
        $user = User::factory()->create();
        $this->artisan('auth:serial-console', ['email' => $user->email, '--grant' => true])->assertSuccessful();
        return $user->refresh();
    }

    private function inventory(): array
    {
        return ['ok' => true, 'ports' => [['id' => self::PORT, 'generation' => self::GENERATION,
            'driver' => 'ftdi_sio', 'usb' => '1-2', 'busy' => true, 'hostname' => '', 'seen_at' => null]]];
    }

    public function test_guest_and_unprivileged_user_cannot_list_or_open_a_cable(): void
    {
        $broker = Mockery::mock(SerialConsoleBroker::class)->makePartial();
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $broker->shouldNotReceive('request');
        $this->getJson('/console/ports')->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->getJson('/console/ports')->assertForbidden();
        $this->postJson('/console/session/open')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('href="'.url('/console').'"', false);
    }

    public function test_revoke_takes_effect_on_the_next_request_and_api_tokens_cannot_open_a_line(): void
    {
        $user = $this->operator();
        $token = $user->createToken('inventory')->plainTextToken;
        $this->withToken($token)->getJson('/console/ports')->assertUnauthorized();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->artisan('auth:serial-console', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->actingAs($user->fresh());
        $this->postJson('/console/session/poll', ['session' => self::LEASE, 'cursor' => 0])->assertForbidden();
    }

    public function test_upgrade_only_grants_the_identifiable_station_administrator(): void
    {
        $migration = require database_path('migrations/2026_11_24_120000_add_serial_console_access.php');
        $first = User::factory()->create();
        $migration->down();
        $migration->up();
        $this->assertTrue($first->fresh()->serial_console_access);

        $admin = User::factory()->create();
        $migration->down();
        $migration->up();
        $this->assertFalse($first->fresh()->serial_console_access);
        $this->assertFalse($admin->fresh()->serial_console_access);

        DB::table('first_admin_receipts')->insert(['id' => 1, 'user_id' => $admin->id]);
        $migration->down();
        $migration->up();
        $this->assertTrue($admin->fresh()->serial_console_access);
        $this->assertFalse($first->fresh()->serial_console_access);
    }

    public function test_both_menus_follow_passive_inventory_including_a_busy_cable(): void
    {
        $broker = Mockery::mock(SerialConsoleBroker::class)->makePartial();
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $broker->shouldReceive('request')->with(['op' => 'ports'])->andReturn(['ok' => true, 'ports' => []], $this->inventory());
        $this->actingAs($this->operator());
        $link = 'href="'.url('/console').'"';
        $this->get('/dashboard')->assertOk()->assertDontSee($link, false);
        $this->travel(20)->seconds();
        $this->assertEquals(2, substr_count($this->get('/dashboard')->assertOk()->getContent(), $link));
    }

    public function test_password_confirmation_and_body_only_rpc_preserve_exact_file_text(): void
    {
        $this->actingAs($this->operator());
        $broker = Mockery::mock(SerialConsoleBroker::class);
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $this->get('/console')->assertRedirect(route('password.confirm'));
        $this->postJson('/console/session/open')->assertStatus(423);
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $owner = null;
        $broker->shouldReceive('request')->once()->withArgs(function (array $request) use (&$owner) {
            $owner = $request['owner'];
            return preg_match('/^[0-9a-f]{64}$/D', $owner)
                && $request['op'] === 'open' && $request['port'] === self::PORT
                && $request['generation'] === self::GENERATION && $request['baud'] === 'auto';
        })->andReturn(['ok' => true, 'session' => self::LEASE]);
        $this->postJson('/console/session/open', ['port' => self::PORT, 'generation' => self::GENERATION,
            'mode' => 'connect', 'baud' => 'auto', 'frame' => 'auto'])->assertOk()->assertJsonPath('session', self::LEASE);
        $file = "\r\n system-view  \r\n display clock\r\n";
        $broker->shouldReceive('request')->once()->withArgs(fn (array $r) => $r['owner'] === $owner
            && $r['op'] === 'file_preview' && $r['text'] === $file && $r['seq'] === 1 && $r['session'] === self::LEASE)
            ->andReturn(['ok' => true]);
        $this->postJson('/console/session/file_preview', ['session' => self::LEASE, 'seq' => 1,
            'filename' => 'test.cfg', 'text' => $file])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/console/session/write?session='.self::LEASE)->assertStatus(405);
    }

    public function test_wrong_shape_raw_path_or_cross_origin_never_reaches_the_broker(): void
    {
        $this->actingAs($this->operator())->withSession(['auth.password_confirmed_at' => time()]);
        $broker = Mockery::mock(SerialConsoleBroker::class);
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $broker->shouldNotReceive('request');
        $this->postJson('/console/session/open', ['port' => '/dev/tty1', 'generation' => self::GENERATION,
            'baud' => 9600, 'frame' => '8n1', 'mode' => 'connect'])->assertUnprocessable();
        $this->postJson('/console/session/file_preview', ['session' => self::LEASE, 'seq' => 1,
            'filename' => 'secret.cfg', 'text' => str_repeat('a', 1048577)])->assertUnprocessable()->assertJsonPath('seq_consumed', false);
        $this->postJson('/console/session/write', ['session' => self::LEASE, 'seq' => 1,
            'data' => base64_encode('hello')], ['Origin' => 'https://another.example'])->assertForbidden();
        $this->postJson('/console/session/write', ['session' => self::LEASE, 'seq' => '1',
            'data' => base64_encode('hello')])->assertUnprocessable()->assertJsonPath('seq_consumed', false);
        $this->postJson('/console/session/close', ['session' => self::LEASE, 'owner' => self::GENERATION])->assertUnprocessable();
    }

    public function test_labels_are_cable_specific_and_require_the_current_generation(): void
    {
        $this->actingAs($this->operator())->withSession(['auth.password_confirmed_at' => time()]);
        $broker = Mockery::mock(SerialConsoleBroker::class)->makePartial();
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $broker->shouldReceive('request')->with(['op' => 'ports'])->andReturn($this->inventory());
        $this->postJson('/console/ports/label', ['port' => self::PORT, 'generation' => self::LEASE, 'label' => 'Old'])->assertConflict();
        $this->postJson('/console/ports/label', ['port' => self::PORT, 'generation' => self::GENERATION, 'label' => 'Rack 2'])->assertOk();
        $this->getJson('/console/ports')->assertOk()->assertJsonPath('ports.0.label', 'Rack 2');
        $this->postJson('/console/ports/label', ['port' => self::PORT, 'generation' => self::GENERATION, 'label' => ''])->assertOk();
        $this->getJson('/console/ports')->assertJsonPath('ports.0.label', '');
    }

    public function test_logout_releases_that_login_and_broker_failure_does_not_prevent_sign_out(): void
    {
        $this->actingAs($this->operator());
        $broker = Mockery::mock(SerialConsoleBroker::class);
        $this->app->instance(SerialConsoleBroker::class, $broker);
        $broker->shouldReceive('request')->once()->withArgs(fn (array $r) => $r['op'] === 'close_owner'
            && preg_match('/^[0-9a-f]{64}$/D', $r['owner']))->andReturn(['ok' => false, 'error' => 'unavailable']);
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }
}
