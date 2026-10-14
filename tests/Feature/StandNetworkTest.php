<?php

namespace Tests\Feature;

use App\Http\Middleware\StandNetworkOnly;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /scriptexecute takes no login and the provisioner is reachable from the office over Wi-Fi, so the endpoint answers
 * only the stand network 172.20.0.0/16 and this server itself
 * (docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md).
 */
class StandNetworkTest extends TestCase
{
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
}
