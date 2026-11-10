<?php

namespace App\Services\Configuration;

use JsonException;
use RuntimeException;

/** Version-one password-protected files and copyable text use the same authenticated stream. */
class TransferCipher
{
    private const MAGIC = "CMPROVISION\x01";
    private const KINDS = ['json' => "\x01", 'archive' => "\x02", 'backup' => "\x03"];
    private const CHUNK_BYTES = 1048576;
    private const MANIFEST_BYTES = 16777216;
    private const TEXT_BYTES = 33554432;
    private const PROTECTION = 'argon2id13-secretstream-xchacha20poly1305-v1';

    public function encrypt(string $source, string $destination, #[\SensitiveParameter] string $password, string $kind): void
    {
        $this->transformFile($source, $destination, function ($input, $output) use ($password, $kind) {
            $this->encryptStream($input, $output, $password, $kind);
        });
    }

    public function decrypt(string $source, string $destination, #[\SensitiveParameter] string $password): string
    {
        return $this->transformFile($source, $destination, function ($input, $output) use ($password) {
            return $this->decryptStream($input, $output, $password);
        });
    }

    public function encodeText(#[\SensitiveParameter] string $json, #[\SensitiveParameter] string $password): string
    {
        if (strlen($json) > self::MANIFEST_BYTES) {
            throw new RuntimeException('The configuration text exceeds the 16 MiB limit.');
        }
        $input = $this->memoryStream($json);
        $output = $this->memoryStream();
        try {
            $this->encryptStream($input, $output, $password, 'json');
            rewind($output);
            $bytes = stream_get_contents($output);
            if ($bytes === false) {
                throw new RuntimeException('Could not read the protected configuration text.');
            }

            return json_encode([
                'format' => 'cmprovision-configuration',
                'schema' => 1,
                'protection' => self::PROTECTION,
                'payload' => base64_encode($bytes),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    public function decodeText(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $password): string
    {
        if (strlen($text) > self::TEXT_BYTES) {
            throw new RuntimeException('The protected text exceeds the 32 MiB limit.');
        }
        try {
            $envelope = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The protected configuration text is not valid JSON.');
        }
        if (!is_array($envelope) || ($envelope['format'] ?? null) !== 'cmprovision-configuration'
            || ($envelope['schema'] ?? null) !== 1 || ($envelope['protection'] ?? null) !== self::PROTECTION
            || !is_string($envelope['payload'] ?? null)) {
            throw new RuntimeException('This protected configuration text has an unsupported format.');
        }
        $bytes = base64_decode($envelope['payload'], true);
        if ($bytes === false) {
            throw new RuntimeException('The protected configuration payload is not valid Base64.');
        }
        $input = $this->memoryStream($bytes);
        $output = $this->memoryStream();
        try {
            if ($this->decryptStream($input, $output, $password, self::MANIFEST_BYTES) !== 'json') {
                throw new RuntimeException('The protected text must contain a configuration document.');
            }
            rewind($output);
            $json = stream_get_contents($output);
            if ($json === false) {
                throw new RuntimeException('Could not read the opened configuration text.');
            }

            return $json;
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function transformFile(string $source, string $destination, callable $transform): mixed
    {
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('The export source is not a regular file.');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('The destination already exists.');
        }
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Could not read the export source.');
        }
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Could not create the export destination.');
        }
        $complete = false;
        try {
            if (!chmod($destination, 0600)) {
                throw new RuntimeException('Could not protect the export destination.');
            }
            $result = $transform($input, $output);
            if (!fflush($output) || !fsync($output)) {
                throw new RuntimeException('Could not finish writing the export destination.');
            }
            $complete = true;

            return $result;
        } finally {
            fclose($input);
            fclose($output);
            if (!$complete) {
                @unlink($destination);
            }
        }
    }

    public function encryptStream($input, $output, #[\SensitiveParameter] string $password, string $kind): void
    {
        if (!isset(self::KINDS[$kind])) {
            throw new RuntimeException('Unsupported export payload kind.');
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = $this->key($password, $salt);
        try {
            [$state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        } finally {
            sodium_memzero($key);
        }
        $header = self::MAGIC.self::KINDS[$kind].$salt.$streamHeader;
        try {
            $this->writeAll($output, $header);
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new RuntimeException('Could not read the export source.');
                }
                if ($chunk === '') {
                    break;
                }
                $this->writeFrame($output, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state, $chunk, $header, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ));
            }
            $this->writeFrame($output, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state, '', $header, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
            ));
        } finally {
            sodium_memzero($state);
        }
    }

    public function decryptStream($input, $output, #[\SensitiveParameter] string $password, int $limit = PHP_INT_MAX): string
    {
        $header = $this->readExact($input, 53);
        if (substr($header, 0, 12) !== self::MAGIC || !in_array($header[12], self::KINDS, true)) {
            throw new RuntimeException('This export uses an unsupported protected format.');
        }
        $kind = array_search($header[12], self::KINDS, true);
        $key = $this->key($password, substr($header, 13, 16));
        try {
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($header, 29, 24), $key);
        } finally {
            sodium_memzero($key);
        }
        $total = 0;
        try {
            while (true) {
                $length = unpack('N', $this->readExact($input, 4))[1];
                if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES
                    || $length > self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                    throw new RuntimeException('The protected export contains an invalid frame size.');
                }
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->readExact($input, $length), $header);
                if ($opened === false) {
                    throw new RuntimeException('Incorrect master password or damaged export.');
                }
                [$chunk, $tag] = $opened;
                if (!in_array($tag, [SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                    SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL], true)) {
                    throw new RuntimeException('The protected export contains an unsupported frame.');
                }
                $total += strlen($chunk);
                if ($total > $limit) {
                    throw new RuntimeException('The opened configuration exceeds its size limit.');
                }
                $this->writeAll($output, $chunk);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    if (fread($input, 1) !== '') {
                        throw new RuntimeException('The protected export contains data after its final frame.');
                    }

                    return $kind;
                }
            }
        } finally {
            sodium_memzero($state);
        }
    }

    private function key(#[\SensitiveParameter] string $password, string $salt): string
    {
        if ($password === '' || strlen($password) > 4096) {
            throw new RuntimeException('Enter an export password of at most 4096 bytes.');
        }

        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            $password, $salt, 2, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private function readExact($stream, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($stream, $length - strlen($bytes));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The protected export is incomplete or damaged.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function writeFrame($stream, string $sealed): void
    {
        $this->writeAll($stream, pack('N', strlen($sealed)));
        $this->writeAll($stream, $sealed);
    }

    private function writeAll($stream, #[\SensitiveParameter] string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the configuration data.');
            }
            $offset += $written;
        }
    }

    private function memoryStream(#[\SensitiveParameter] string $bytes = '')
    {
        $stream = fopen('php://temp/maxmemory:33554432', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not allocate a configuration buffer.');
        }
        $this->writeAll($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
