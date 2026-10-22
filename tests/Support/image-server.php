<?php

/* Router of the test server of ImageFetcherTest (php -S): a file of known bytes behind the answers a real download meets. */

const TOTAL = 3145728;   // 3 MiB

function send(int $from, int $to, int $claimedTotal = TOTAL, bool $stopHalfway = false, int $delayMicros = 0): void
{
    $length = $to - $from + 1;
    header('Content-Type: application/octet-stream');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.$length);
    $sent = 0;
    $block = hash('sha512', 'image-server', true);
    while ($sent < $length) {
        $n = min(65536, $length - $sent);
        $chunk = '';
        for ($i = 0; $i < $n; $i++) {
            $chunk .= $block[($from + $sent + $i) % 64];
        }
        echo $chunk;
        flush();
        $sent += $n;
        if ($delayMicros) {
            usleep($delayMicros);
        }
        if ($stopHalfway && $sent >= $length / 2) {
            exit;   // the connection closes with bytes still owed
        }
    }
}

function byteRange(int $total): array
{
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d+)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m)) {
        $from = (int) $m[1];
        $to = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);

        return [$from, $to, true];
    }

    return [0, $total - 1, false];
}

function serve(int $claimedTotal = TOTAL, bool $honourRange = true, bool $stopHalfway = false, int $delayMicros = 0): void
{
    [$from, $to, $partial] = $honourRange ? byteRange(TOTAL) : [0, TOTAL - 1, false];
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes $from-$to/$claimedTotal");
    }
    send($from, $to, $claimedTotal, $stopHalfway, $delayMicros);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch (true) {
    case $path === '/file' || str_starts_with($path, '/img/'):
        serve();
        break;
    case $path === '/slowfile':
        serve(TOTAL, true, false, 40000);
        break;
    case $path === '/no-range':
        serve(TOTAL, false);
        break;
    case $path === '/changed':
        serve(TOTAL + 1000);
        break;
    case $path === '/truncate':
        serve(TOTAL, true, true);
        break;
    case $path === '/stall':
        header('Content-Length: 1000000');
        echo str_repeat('x', 100000);
        flush();
        sleep(4);
        break;
    case $path === '/chunked':
        header('Content-Type: application/octet-stream');
        echo str_repeat('x', 100);
        flush();
        usleep(200000);
        echo str_repeat('y', 100);
        break;
    case $path === '/loop':
        header('Location: /loop', true, 302);
        break;
    case preg_match('#^/redirect/(\d+)$#', $path, $m) === 1:
        header('Location: '.($m[1] > 0 ? '/redirect/'.($m[1] - 1) : '/file?token=secret'), true, 302);
        break;
    case $path === '/to-private':
        header('Location: http://10.0.0.5/secret.img.gz', true, 302);
        break;
    case $path === '/to-loopback-https':
        header('Location: https://127.0.0.1/x.img.gz', true, 302);
        break;
    case $path === '/relative':
        header('Location: file', true, 301);
        break;
    case $path === '/disposition':
        header('Content-Disposition: attachment; filename="named-by-server.img.xz"');
        serve();
        break;
    case preg_match('#^/status/(\d+)$#', $path, $m) === 1:
        http_response_code((int) $m[1]);
        echo 'nothing here';
        break;
    default:
        http_response_code(404);
}
