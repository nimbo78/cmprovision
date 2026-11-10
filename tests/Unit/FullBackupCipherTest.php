<?php

namespace Tests\Unit;

use App\Services\Configuration\TransferCipher;
use PHPUnit\Framework\TestCase;

class FullBackupCipherTest extends TestCase
{
    public function test_full_backup_stream_kind_round_trips_without_changing_selective_kinds(): void
    {
        $cipher = new TransferCipher;
        $input = fopen('php://temp', 'w+b');
        $sealed = fopen('php://temp', 'w+b');
        $opened = fopen('php://temp', 'w+b');
        $data = str_repeat('archive data', 200000);
        fwrite($input, $data);
        rewind($input);
        try {
            $cipher->encryptStream($input, $sealed, 'master passphrase', 'backup');
            rewind($sealed);
            $this->assertSame('backup', $cipher->decryptStream($sealed, $opened, 'master passphrase', strlen($data)));
            rewind($opened);
            $this->assertSame($data, stream_get_contents($opened));
            $this->assertSame('{"existing":true}', $cipher->decodeText($cipher->encodeText('{"existing":true}', 'old password'), 'old password'));
        } finally {
            fclose($input); fclose($sealed); fclose($opened);
        }
    }
}
