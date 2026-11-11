<?php

// Standalone stream codec: no Composer/Laravel bootstrap and no database access.
// A descriptor NUMBER may be passed in argv; password bytes never are.
$codec = __DIR__.'/TransferCipher.php';
if (!is_file($codec)) $codec = dirname(__DIR__).'/app/Services/Configuration/TransferCipher.php';
require $codec;

try {
    if ($argc !== 3 || !in_array($argv[1], ['encrypt', 'decrypt'], true)
        || !preg_match('/\A[0-9]{1,4}\z/D', $argv[2]) || (int) $argv[2] < 3) {
        throw new RuntimeException('Invalid codec invocation.');
    }
    $secret = @fopen('php://fd/'.$argv[2], 'rb');
    if (!$secret) throw new RuntimeException('Missing password descriptor.');
    $raw = stream_get_contents($secret, 16385);
    fclose($secret);
    if ($raw === false || strlen($raw) > 16384) throw new RuntimeException('Invalid password input.');
    $input = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($input) || array_keys($input) !== ['password'] || !is_string($input['password'])) {
        throw new RuntimeException('Invalid password input.');
    }
    sodium_memzero($raw);
    $cipher = new \App\Services\Configuration\TransferCipher;
    if ($argv[1] === 'encrypt') {
        $cipher->encryptStream(STDIN, STDOUT, $input['password'], 'backup');
    } elseif ($cipher->decryptStream(STDIN, STDOUT, $input['password']) !== 'backup') {
        throw new RuntimeException('Unexpected backup kind.');
    }
    sodium_memzero($input['password']);
    if (!fflush(STDOUT)) throw new RuntimeException('Could not flush output.');
} catch (\Throwable $error) {
    // Do not echo invalid JSON, password bytes, exception messages or traces.
    fwrite(STDERR, "Full backup stream encryption/decryption failed.\n");
    exit(1);
}
