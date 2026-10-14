<?php
// Bench only: a stand-in for the Telegram Bot API (php -S 127.0.0.1:8099 telegram-sink.php). Every call goes to
// /tmp/sink.log as "unix-time method text"; every tenth (SINK_429_EVERY) answers 429 with retry_after 3.
$log = '/tmp/sink.log';
$every = (int) (getenv('SINK_429_EVERY') ?: 10);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$method = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$n = (int) @file_get_contents('/tmp/sink.count') + 1;
file_put_contents('/tmp/sink.count', $n);
header('Content-Type: application/json');
if ($n % $every === 0)
{
    http_response_code(429);
    echo json_encode(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 3', 'parameters' => ['retry_after' => 3]]);
    file_put_contents($log, time()." 429 $method\n", FILE_APPEND);
    return;
}
file_put_contents($log, time()." $method ".json_encode($body['text'] ?? '', JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
echo json_encode(['ok' => true, 'result' => ['message_id' => $n]]);
