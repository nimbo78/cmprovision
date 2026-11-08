<?php

namespace Tests\Unit;

use App\Services\Configuration\TransferCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigurationCipherTest extends TestCase
{
    private string $directory;
    private const PASSWORD = 'portable station master password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/configuration-cipher-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/source', '{"name":"Station","scripts":[]}');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_file_encryption_retains_the_payload_kind_and_exact_content(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');

        $kind = $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);

        $this->assertSame('json', $kind);
        $this->assertSame('{"name":"Station","scripts":[]}', file_get_contents($this->directory.'/opened'));
        $this->assertStringStartsWith("CMPROVISION\x01\x01", file_get_contents($this->directory.'/protected'));
    }

    public function test_archives_are_processed_across_multiple_stream_chunks(): void
    {
        $source = fopen($this->directory.'/source', 'wb');
        for ($index = 0; $index < 9; $index++) {
            fwrite($source, str_repeat(chr($index), 1048576));
        }
        fclose($source);
        $expectedHash = hash_file('sha256', $this->directory.'/source');
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'archive');

        $kind = $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);

        $this->assertSame('archive', $kind);
        $this->assertSame(9 * 1048576, filesize($this->directory.'/opened'));
        $this->assertSame($expectedHash, hash_file('sha256', $this->directory.'/opened'));
    }

    public function test_reusing_a_master_password_gives_each_export_its_own_salt(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/first', self::PASSWORD, 'json');
        $cipher->encrypt($this->directory.'/source', $this->directory.'/second', self::PASSWORD, 'json');

        $first = file_get_contents($this->directory.'/first');
        $second = file_get_contents($this->directory.'/second');
        $this->assertNotSame(substr($first, 13, 16), substr($second, 13, 16));
    }

    public function test_a_wrong_password_does_not_leave_an_opened_file(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');

        $this->expectException(RuntimeException::class);
        try {
            $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', 'a different master password');
        } finally {
            $this->assertFileDoesNotExist($this->directory.'/opened');
        }
    }

    public function test_changing_a_recognized_payload_kind_is_detected_by_authentication(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');
        $bytes = file_get_contents($this->directory.'/protected');
        $bytes[12] = "\x02";
        file_put_contents($this->directory.'/protected', $bytes);

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);
    }

    public function test_a_truncated_stream_requires_its_complete_final_tag(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');
        $bytes = file_get_contents($this->directory.'/protected');
        file_put_contents($this->directory.'/protected', substr($bytes, 0, -1));

        $this->expectException(RuntimeException::class);
        try {
            $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);
        } finally {
            $this->assertFileDoesNotExist($this->directory.'/opened');
        }
    }

    public function test_bytes_after_the_final_tag_are_rejected(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');
        file_put_contents($this->directory.'/protected', 'extra data', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);
    }

    public function test_an_oversized_frame_is_rejected_before_allocating_its_claimed_size(): void
    {
        $cipher = new TransferCipher;
        $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');
        $bytes = file_get_contents($this->directory.'/protected');
        file_put_contents($this->directory.'/protected', substr($bytes, 0, 53).pack('N', 0xffffffff).substr($bytes, 57));

        $this->expectException(RuntimeException::class);
        $cipher->decrypt($this->directory.'/protected', $this->directory.'/opened', self::PASSWORD);
    }

    public function test_a_destination_that_already_exists_is_preserved(): void
    {
        file_put_contents($this->directory.'/protected', 'existing file');
        $cipher = new TransferCipher;

        $this->expectException(RuntimeException::class);
        try {
            $cipher->encrypt($this->directory.'/source', $this->directory.'/protected', self::PASSWORD, 'json');
        } finally {
            $this->assertSame('existing file', file_get_contents($this->directory.'/protected'));
        }
    }

    public function test_text_exports_support_unicode_content_and_passwords(): void
    {
        $cipher = new TransferCipher;
        $json = '{"name":"Тестовая станция","script":"echo привет"}';
        $text = $cipher->encodeText($json, 'единый мастер-пароль станции');
        $envelope = json_decode($text, true, 8, JSON_THROW_ON_ERROR);

        $this->assertSame('cmprovision-configuration', $envelope['format']);
        $this->assertSame(1, $envelope['schema']);
        $this->assertSame($json, $cipher->decodeText($text, 'единый мастер-пароль станции'));
    }

    public function test_invalid_base64_in_a_text_envelope_is_rejected(): void
    {
        $cipher = new TransferCipher;
        $text = $cipher->encodeText('{}', self::PASSWORD);
        $envelope = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        $envelope['payload'] = '!!!';

        $this->expectException(RuntimeException::class);
        $cipher->decodeText(json_encode($envelope, JSON_THROW_ON_ERROR), self::PASSWORD);
    }

    public function test_plaintext_above_the_manifest_limit_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        (new TransferCipher)->encodeText(str_repeat('x', 16 * 1048576 + 1), self::PASSWORD);
    }
}
