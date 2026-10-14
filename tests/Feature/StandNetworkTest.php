<?php

namespace Tests\Feature;

use App\Http\Middleware\StandNetworkOnly;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /scriptexecute takes no login and the provisioner is reachable from the office over Wi-Fi, so the endpoint answers
 * only the stand network 172.20.0.0/16 and this server itself
 * (docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md).
 */
class StandNetworkTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const OFFICE = '192.168.68.50';

    protected function fromAddress(string $address)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }

    /* the active project, without an image: a module still gets its script */
    protected function activeProject(): Project
    {
        $project = Project::create(['name' => 'stage1-test', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function module(): Cm
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }

    public static function addresses(): array
    {
        return [
            'a module' => ['172.20.5.5', true],
            'the first lease' => ['172.20.0.2', true],
            'the last lease' => ['172.20.255.254', true],
            'a module on an IPv6 socket' => ['::ffff:172.20.5.5', true],
            'the mapped prefix in capitals' => ['::FFFF:172.20.5.5', true],
            'this server' => ['127.0.0.1', true],
            'this server by its host name' => ['127.0.1.1', true],
            'this server over IPv6' => ['::1', true],
            'this server on an IPv6 socket' => ['::ffff:127.0.0.1', true],
            'the office Wi-Fi' => ['192.168.68.50', false],
            'the office on an IPv6 socket' => ['::ffff:192.168.68.50', false],
            'the network after the stand' => ['172.21.0.1', false],
            'the network before the stand' => ['172.19.255.255', false],
            'IPv6 link-local' => ['fe80::1', false],
            'IPv6 link-local with its zone' => ['fe80::1%usb0', false],
            'a global IPv6 address' => ['2001:db8::1', false],
            'no address' => ['', false],
            'no address at all' => [null, false],
            'a name, not an address' => ['localhost', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_the_stand_network_and_this_server_get_through($address, $allowed)
    {
        $this->assertSame($allowed, StandNetworkOnly::allows($address));
    }

    public function test_a_refusal_names_the_stand_network_and_where_the_request_came_from()
    {
        $request = Request::create('/scriptexecute', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.68.50']);
        $response = (new StandNetworkOnly)->handle($request, function () {
            $this->fail('a refused request never reaches the controller');
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame("cmprovision answers /scriptexecute only for the stand network 172.20.0.0/16 and for this server itself;"
                          ." this request came from 192.168.68.50.\n", $response->getContent());
    }

    public function test_a_refusal_without_an_address_says_so()
    {
        $request = Request::create('/scriptexecute', 'GET', [], [], [], ['REMOTE_ADDR' => '']);
        $response = (new StandNetworkOnly)->handle($request, function () {
            $this->fail('a refused request never reaches the controller');
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringEndsWith("this request came from an unknown address.\n", $response->getContent());
    }

    public function test_a_module_on_the_stand_network_gets_its_script()
    {
        $this->activeProject();
        $this->fromAddress('172.20.5.5')->get(self::START)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('export SERIAL="'.self::SERIAL.'"', false);
        $this->assertSame(1, Cm::where('serial', self::SERIAL)->count());
    }

    public function test_this_server_itself_is_answered()
    {
        $this->activeProject();
        $this->fromAddress('127.0.0.1')->get(self::START)->assertOk();
        $this->fromAddress('::1')->get(self::START)->assertOk();
    }

    public static function refusedAddresses(): array
    {
        return [
            'the office Wi-Fi' => [self::OFFICE],
            'the network after the stand' => ['172.21.0.1'],
            'the network before the stand' => ['172.19.255.255'],
            'IPv6 link-local' => ['fe80::1'],
            'a global IPv6 address' => ['2001:db8::1'],
            'the office on an IPv6 socket' => ['::ffff:192.168.68.50'],
            'no address' => [''],
        ];
    }

    #[DataProvider('refusedAddresses')]
    public function test_another_network_gets_the_reason_and_nothing_is_stored($address)
    {
        $this->activeProject();
        $this->fromAddress($address)->get(self::START)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('only for the stand network 172.20.0.0/16', false);
        $this->assertSame(0, Cm::count());
        $this->assertSame(0, Cmlog::count());
    }

    public function test_a_forwarded_header_does_not_open_the_door()
    {
        $this->activeProject();
        $this->fromAddress(self::OFFICE)->withHeaders(['X-Forwarded-For' => '172.20.5.5'])->get(self::START)->assertForbidden();
        $this->assertSame(0, Cm::count());
    }

    public function test_reports_from_another_network_leave_the_module_alone()
    {
        $this->activeProject();
        $this->fromAddress('172.20.5.5')->get(self::START)->assertOk();
        $before = $this->module()->toArray();
        $logs = Cmlog::count();

        $this->fromAddress(self::OFFICE)->post('/scriptexecute?serial='.self::SERIAL.'&retcode=2&phase=postinstall',
            ['log' => UploadedFile::fake()->createWithContent('post.log', "Verification failed\n")])->assertForbidden();
        $this->fromAddress(self::OFFICE)->post('/scriptexecute?serial='.self::SERIAL,
            ['eeprom_version' => UploadedFile::fake()->createWithContent('eeprom_version', "2021/02/16 13:19:00\n")])->assertForbidden();

        $this->assertSame($before, $this->module()->toArray());
        $this->assertSame($logs, Cmlog::count());
    }

    public static function moduleQueries(): array
    {
        return [
            'progress' => ['&progress=write&sectors=2048'],
            'identify poll' => ['&identify=poll'],
            'completion' => ['&alldone=1&temp=48.7%27C&verify=0'],
        ];
    }

    #[DataProvider('moduleQueries')]
    public function test_no_kind_of_module_request_is_answered_to_another_network($query)
    {
        $this->activeProject();
        $this->fromAddress('172.20.5.5')->get(self::START)->assertOk();
        $before = $this->module()->toArray();
        $logs = Cmlog::count();

        $this->fromAddress(self::OFFICE)->get('/scriptexecute?serial='.self::SERIAL.$query)->assertForbidden();

        $this->assertSame($before, $this->module()->toArray());
        $this->assertSame($logs, Cmlog::count());
    }

    public static function methods(): array
    {
        return [['HEAD'], ['OPTIONS'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('methods')]
    public function test_every_method_is_refused_to_another_network($method)
    {
        $this->activeProject();
        $this->fromAddress(self::OFFICE)->call($method, self::START)->assertForbidden();
        $this->assertSame(0, Cm::count());
    }

    public function test_a_trailing_slash_is_the_same_endpoint()
    {
        $this->activeProject();
        $this->fromAddress(self::OFFICE)->get('/scriptexecute/?serial='.self::SERIAL)->assertForbidden();
        $this->assertSame(0, Cm::count());
        $this->fromAddress('172.20.5.5')->get('/scriptexecute/?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3')
            ->assertOk();
    }

    public function test_the_module_endpoint_runs_without_the_web_group()
    {
        $router = app('router');
        $route = $router->getRoutes()->match(Request::create('/scriptexecute', 'GET'));
        $middleware = $router->gatherRouteMiddleware($route);

        $this->assertContains(StandNetworkOnly::class, $middleware);
        // a module has no session, cookies or CSRF token: each of its requests used to write a row into sessions
        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(VerifyCsrfToken::class, $middleware);
    }

    public static function serverAddresses(): array
    {
        return [
            'the stand address' => ['172.20.5.5', 'http://172.20.0.1', '172.20.0.1'],
            'this server over IPv6' => ['::1', 'http://[::1]', '[::1]'],
        ];
    }

    #[DataProvider('serverAddresses')]
    public function test_the_script_names_the_server_as_the_module_reached_it($from, $base, $server)
    {
        $this->activeProject();
        $script = $this->fromAddress($from)->get($base.self::START)->assertOk()->getContent();

        $this->assertStringContainsString('export SERVER="'.$server.'"', $script);
        $this->assertStringContainsString('-G "http://'.$server.'/scriptexecute"', $script);
        // CM3 over USB is gone (2.5.1): nothing rewrites the server into a link-local address of usb0
        $this->assertStringNotContainsString('%usb0', $script);
    }
}
