<?php

namespace App\Support;

/**
 * The server fetches addresses an operator names, from inside the stand's network: only https to public addresses. The name
 * is resolved here, every address it gives must be public, and the connection is pinned to the one chosen (the fetcher
 * connects to that address, so the name cannot point somewhere else a moment later). The same check runs on every redirect.
 */
class ImageUrlGuard
{
    /* what is not the public internet: this network, private, shared (carrier-grade NAT), loopback, link-local, documentation,
       benchmarking, multicast and reserved; IPv6 loopback, unspecified, unique local, link-local, multicast, documentation, and
       the forms that carry an IPv4 address (mapped, NAT64, 6to4, Teredo) */
    const REFUSED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001::/32', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    protected $resolver;

    /** @param callable(string): string[]|null $resolver the addresses of a name (tests give their own) */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'dns'];
    }

    /** @return string[] */
    public static function dns(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = $record['ip'] ?? $record['ipv6'] ?? null;
        }
        if (!$ips) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_filter($ips));
    }

    /**
     * @return array{url: string, scheme: string, host: string, port: int, ip: string}
     * @throws ImageSourceException (fatal) for an address that is not to be fetched
     */
    public function check(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new ImageSourceException('This address is not allowed: it is not a full https address.', true);
        }
        $scheme = strtolower($parts['scheme']);
        $host = trim(strtolower($parts['host']), '[]');
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageSourceException('This address is not allowed: it carries a user name or password.', true);
        }
        if (!in_array($scheme, ['https', 'http'], true)) {
            throw new ImageSourceException('This address is not allowed: only https is accepted.', true);
        }
        // 2130706433, 0x7f000001, 127.1: numbers some resolvers read as addresses
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^(0x[0-9a-f]+|[0-9]+)(\.(0x[0-9a-f]+|[0-9]+))*$/', $host)) {
            throw new ImageSourceException('This address is not allowed: its host is a number, not a name.', true);
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_values(call_user_func($this->resolver, $host));
        if (!$ips) {
            throw new ImageSourceException('The name '.$host.' does not resolve: check the address and the internet access of the server.');
        }
        foreach ($ips as $ip) {
            if (!self::allowedAddress($ip) && !$this->testLoopback($scheme, $ip)) {
                throw new ImageSourceException('This address is not allowed: it points into a private or local network.', true);
            }
        }
        if ($scheme === 'http' && !$this->testLoopback($scheme, $ips[0])) {
            throw new ImageSourceException('This address is not allowed: only https is accepted.', true);
        }

        return ['url' => trim($url), 'scheme' => $scheme, 'host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), 'ip' => $ips[0]];
    }

    /* the tests' own server: plain http to loopback, and only when the tests switch it on */
    protected function testLoopback(string $scheme, string $ip): bool
    {
        return config('cmprovision.image_test_loopback') && $scheme === 'http' && ($ip === '127.0.0.1' || $ip === '::1');
    }

    public static function allowedAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach (self::REFUSED as $range) {
            [$net, $bits] = explode('/', $range);
            $netPacked = inet_pton($net);
            if (strlen($netPacked) !== strlen($packed)) {
                continue;
            }
            $whole = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($packed, 0, $whole) !== substr($netPacked, 0, $whole)) {
                continue;
            }
            if ($rest === 0 || ((ord($packed[$whole]) ^ ord($netPacked[$whole])) >> (8 - $rest)) === 0) {
                return false;
            }
        }

        return true;
    }

    /* the address as it may be shown, logged and put in a message: no user name, password, query or fragment (they may be a token) */
    public static function redact(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            return strtok($url, '?#') ?: '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    /* the kind of image file a name says: gz, xz, bz2 or zip; null for anything else */
    public static function extensionOf(string $name): ?string
    {
        return preg_match('/\.(gz|xz|bz2|zip)$/i', $name, $m) ? strtolower($m[1]) : null;
    }
}
