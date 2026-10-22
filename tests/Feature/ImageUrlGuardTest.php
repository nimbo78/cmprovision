<?php

namespace Tests\Feature;

use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The server fetches what an operator names: only public https addresses, checked before every connection */
class ImageUrlGuardTest extends TestCase
{
    public static function refusedAddresses(): array
    {
        return [
            'loopback' => ['127.0.0.1'], 'loopback range' => ['127.9.9.9'], 'this network' => ['0.0.0.0'],
            'private 10' => ['10.1.2.3'], 'private 172.16' => ['172.16.0.1'], 'private 172.31' => ['172.31.255.254'],
            'the stand network' => ['172.20.0.1'], 'private 192.168' => ['192.168.68.237'],
            'link local' => ['169.254.169.254'], 'carrier grade nat' => ['100.64.0.1'], 'carrier grade nat end' => ['100.127.255.255'],
            'multicast' => ['224.0.0.251'], 'reserved' => ['240.0.0.1'], 'broadcast' => ['255.255.255.255'],
            'documentation' => ['192.0.2.7'], 'benchmark' => ['198.18.0.1'],
            'v6 loopback' => ['::1'], 'v6 unspecified' => ['::'], 'v6 unique local' => ['fd12:3456::1'], 'v6 link local' => ['fe80::1'],
            'v6 multicast' => ['ff02::1'], 'v4 mapped loopback' => ['::ffff:127.0.0.1'], 'v4 mapped private' => ['::ffff:10.0.0.1'],
            'v6 documentation' => ['2001:db8::1'], 'nat64' => ['64:ff9b::a00:1'], 'not an address' => ['example.org'],
        ];
    }

    #[DataProvider('refusedAddresses')]
    public function test_the_addresses_of_the_stand_and_the_machine_itself_are_refused(string $ip)
    {
        $this->assertFalse(ImageUrlGuard::allowedAddress($ip));
    }

    public static function publicAddresses(): array
    {
        return [['140.82.112.3'], ['185.199.108.153'], ['8.8.8.8'], ['172.15.0.1'], ['172.32.0.1'], ['100.63.255.255'], ['100.128.0.1'],
                ['2606:50c0:8000::154'], ['2a00:1450:4001::200e']];
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_allowed(string $ip)
    {
        $this->assertTrue(ImageUrlGuard::allowedAddress($ip));
    }

    protected function guard(array $ips = ['140.82.112.3']): ImageUrlGuard
    {
        return new ImageUrlGuard(fn (string $host) => $ips);
    }

    public function test_a_good_address_is_pinned_to_the_address_it_resolved_to()
    {
        $checked = $this->guard(['2606:50c0:8000::154', '140.82.112.3'])->check('https://github.com/WLAN-Pi/pi-gen/releases/download/26.10/os.img.gz?x=1');

        $this->assertEquals(['host' => 'github.com', 'port' => 443, 'scheme' => 'https', 'ip' => '2606:50c0:8000::154'],
            array_intersect_key($checked, array_flip(['host', 'port', 'scheme', 'ip'])));
        $this->assertSame('https://github.com/WLAN-Pi/pi-gen/releases/download/26.10/os.img.gz?x=1', $checked['url'], 'the address keeps its query: the fetch needs it');
        $this->assertSame(8443, $this->guard()->check('https://example.org:8443/a.img.gz')['port']);
    }

    public function test_only_https_is_accepted_and_no_user_name_or_password()
    {
        foreach (['http://example.org/a.img.gz', 'ftp://example.org/a.img.gz', 'file:///etc/passwd', '//example.org/a.img.gz', 'example.org/a.img.gz', '',
                  'https://user:pass@example.org/a.img.gz', 'https://user@example.org/a.img.gz', 'https:///a.img.gz'] as $url) {
            try {
                $this->guard()->check($url);
                $this->fail("'$url' is no address to fetch from");
            } catch (ImageSourceException $e) {
                $this->assertTrue($e->fatal, $url);
            }
        }
    }

    public function test_a_name_that_resolves_to_a_private_address_is_refused_even_among_public_ones()
    {
        foreach ([['10.0.0.5'], ['140.82.112.3', '10.0.0.5'], ['127.0.0.1'], ['::1']] as $ips) {
            try {
                $this->guard($ips)->check('https://example.org/a.img.gz');
                $this->fail('resolved to '.json_encode($ips));
            } catch (ImageSourceException $e) {
                $this->assertTrue($e->fatal);
            }
        }
    }

    public function test_a_name_that_does_not_resolve_is_an_error_worth_another_try()
    {
        try {
            $this->guard([])->check('https://example.org/a.img.gz');
            $this->fail('no address');
        } catch (ImageSourceException $e) {
            $this->assertFalse($e->fatal);
            $this->assertStringContainsString('does not resolve', $e->getMessage());
        }
    }

    public function test_an_address_given_as_a_number_is_checked_without_the_resolver()
    {
        $this->assertSame('140.82.112.3', $this->guard(['10.0.0.1'])->check('https://140.82.112.3/a.img.gz')['ip']);
        $this->assertSame('2606:50c0:8000::154', $this->guard(['10.0.0.1'])->check('https://[2606:50c0:8000::154]/a.img.gz')['ip']);
        foreach (['https://10.0.0.1/a.img.gz', 'https://127.0.0.1/a.img.gz', 'https://[::1]/a.img.gz', 'https://2130706433/a.img.gz', 'https://0x7f000001/a.img.gz'] as $url) {
            try {
                $this->guard()->check($url);
                $this->fail($url);
            } catch (ImageSourceException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_plain_http_is_allowed_only_to_loopback_and_only_when_the_tests_ask_for_it()
    {
        $this->assertFalse(config('cmprovision.image_test_loopback'), 'off by default');
        $this->expectException(ImageSourceException::class);
        $this->guard(['127.0.0.1'])->check('http://localhost:8080/a.img.gz');
    }

    public function test_with_the_test_switch_loopback_over_http_is_allowed_but_nothing_else_over_http()
    {
        config(['cmprovision.image_test_loopback' => true]);

        $this->assertSame(['http', 8080, '127.0.0.1'], array_values(array_intersect_key($this->guard(['127.0.0.1'])->check('http://localhost:8080/a.img.gz'), array_flip(['ip', 'port', 'scheme']))));
        $this->expectException(ImageSourceException::class);
        $this->guard(['140.82.112.3'])->check('http://example.org/a.img.gz');
    }

    public function test_a_message_never_carries_the_query_or_a_password()
    {
        $this->assertSame('https://example.org:8443/a/b.img.gz', ImageUrlGuard::redact('https://user:secret@example.org:8443/a/b.img.gz?token=abc#frag'));
        $this->assertSame('https://example.org/a.img.gz', ImageUrlGuard::redact('https://example.org/a.img.gz?sig=abc'));
        $this->assertSame('not an address', ImageUrlGuard::redact('not an address?token=abc'));
        try {
            $this->guard(['10.0.0.1'])->check('https://example.org/a.img.gz?token=secret');
        } catch (ImageSourceException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public static function names(): array
    {
        return [['os.img.gz', 'gz'], ['OS.IMG.XZ', 'xz'], ['os.img.bz2', 'bz2'], ['os.gz', 'gz'], ['x.zip', 'zip'], ['X.ZIP', 'zip'],
                ['os.img', null], ['os.7z', null], ['os.img.zst', null], ['os.tar.gz', 'gz'], ['gz', null], ['', null], ['os.sha256', null]];
    }

    #[DataProvider('names')]
    public function test_the_kind_of_a_file_comes_from_its_name(string $name, ?string $extension)
    {
        $this->assertSame($extension, ImageUrlGuard::extensionOf($name));
    }
}
