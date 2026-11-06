<?php

namespace Tests\Unit;

use App\Support\NetworkRoutingInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class NetworkRoutingInputTest extends TestCase
{
    public function test_accepts_saved_nm_routes_and_human_notation_without_losing_attributes(): void
    {
        $expected = [['destination' => '203.0.113.0/24', 'via' => '192.0.2.1', 'metric' => 25,
            'table' => 100, 'mtu' => 1400, 'src' => '192.0.2.10', 'onlink' => true]];
        $this->assertSame($expected, NetworkRoutingInput::routes('203.0.113.0/24 192.0.2.1 25 table=100 mtu=1400 src=192.0.2.10 onlink=true'));
        $this->assertSame($expected, NetworkRoutingInput::routes('203.0.113.0/24 via 192.0.2.1 metric 25 table=100 mtu=1400 src=192.0.2.10 onlink=true'));
        $this->assertSame([['destination' => 'fd42:5::/64', 'type' => 'blackhole', 'table' => 101]],
            NetworkRoutingInput::routes('fd42:5::/64 type=blackhole table=101'));
    }

    public function test_parses_rule_selectors_and_hexadecimal_marks(): void
    {
        $this->assertSame([
            ['priority' => 100, 'from' => '192.0.2.0/24', 'fwmark' => 16, 'fwmask' => 255, 'table' => 100],
            ['priority' => 200, 'to' => 'fd42::/64', 'iif' => 'eth0.42', 'table' => 200],
        ], NetworkRoutingInput::rules("priority 100 from 192.0.2.0/24 fwmark 0x10/0xff table 100\npriority 200 to fd42::/64 iif eth0.42 lookup 200"));
    }

    public function test_rejects_unknown_attributes_instead_of_silently_erasing_them(): void
    {
        foreach (['192.0.2.0/24 cwnd=10', '192.0.2.0/24 table=100 table=200',
            '192.0.2.0/24 metric 99999999999999999999', '192.0.2.0/24 via',
            '192.0.2.0/24 onlink=maybe', '192.0.2.0/24 10 metric 20'] as $value) {
            try {
                NetworkRoutingInput::routes($value);
                $this->fail('Invalid route was accepted: '.$value);
            } catch (InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
        foreach (['from all table 100', 'priority 10 table main', 'priority 10 table 100 table 200',
            'priority 10 fwmark 0x1/0xff/0xff table 100', 'priority 10 table 4294967296'] as $value) {
            try {
                NetworkRoutingInput::rules($value);
                $this->fail('Invalid rule was accepted: '.$value);
            } catch (InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
}
