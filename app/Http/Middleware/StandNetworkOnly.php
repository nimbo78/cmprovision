<?php

namespace App\Http\Middleware;

use App\Services\NetworkStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The modules' endpoint takes no login, so it answers only the stand network and this server itself
 * (docs/operations.md#network-access). The client is the connection's own address:
 * a forwarded header never counts, and the office cannot fake a TCP source, since the answer to its SYN would go to the
 * stand. The address a request came to proves nothing (Linux takes a packet for any of its addresses on any
 * interface), and IPv6 link-local is refused: the Wi-Fi uplinks have it too.
 */
class StandNetworkOnly
{
    const LOOPBACK = ['127.0.0.0/8', '::1'];

    public function handle(Request $request, Closure $next)
    {
        $address = (string) $request->server('REMOTE_ADDR');
        if (self::allows($address))
            return $next($request);

        return new Response('cmprovision answers /scriptexecute only for the stand network '.NetworkStatus::STAND_NETWORK
            .' and for this server itself; this request came from '.($address === '' ? 'an unknown address' : $address).".\n",
            403, ['Content-Type' => 'text/plain']);
    }

    /* A module of the stand, or this server; an IPv4 client on an IPv6 socket comes as ::ffff:a.b.c.d */
    public static function allows(?string $address): bool
    {
        $address = (string) $address;
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m))
            $address = $m[1];

        return $address !== '' && IpUtils::checkIp($address, array_merge([NetworkStatus::STAND_NETWORK], self::LOOPBACK));
    }
}
