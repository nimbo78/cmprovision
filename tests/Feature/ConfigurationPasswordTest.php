<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Configuration\TransferCipher;
use App\Services\Configuration\TransferPassword;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ConfigurationPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_saved_master_password_is_encrypted_with_the_station_key(): void
    {
        $passwords = new TransferPassword;
        $this->assertFalse($passwords->configured());
        $passwords->save('one portable master password');

        $stored = Setting::findOrFail(TransferPassword::SETTING)->value;
        $this->assertStringNotContainsString('one portable master password', $stored);
        $this->assertSame('one portable master password', Crypt::decryptString($stored));
        $this->assertSame('one portable master password', $passwords->read());
        $this->assertTrue($passwords->configured());
    }

    public function test_short_master_passwords_are_rejected_before_storage(): void
    {
        $this->expectException(ValidationException::class);
        try {
            (new TransferPassword)->save('short');
        } finally {
            $this->assertDatabaseMissing('settings', ['key' => 'configuration_transfer_password']);
        }
    }

    public function test_unicode_master_passwords_are_saved_exactly_as_entered(): void
    {
        $passwords = new TransferPassword;
        $passwords->save('единый мастер-пароль');

        $this->assertSame('единый мастер-пароль', $passwords->read());
    }

    public function test_forgetting_a_master_password_removes_the_saved_value(): void
    {
        $passwords = new TransferPassword;
        $passwords->save('one portable master password');
        $passwords->forget();

        $this->assertFalse($passwords->configured());
        $this->assertNull($passwords->read());
    }

    public function test_rotation_keeps_old_exports_readable_with_their_original_password(): void
    {
        $passwords = new TransferPassword;
        $cipher = new TransferCipher;
        $passwords->save('original export master password');
        $text = $cipher->encodeText('{"name":"older export"}', $passwords->read());
        $passwords->save('replacement export master password');

        $this->assertSame('{"name":"older export"}', $cipher->decodeText($text, 'original export master password'));
        $this->expectException(RuntimeException::class);
        $cipher->decodeText($text, $passwords->read());
    }

    public function test_an_unreadable_saved_password_is_reported(): void
    {
        $passwords = new TransferPassword;
        $passwords->save('one portable master password');
        Crypt::swap(new Encrypter(random_bytes(32), 'AES-256-CBC'));

        $this->expectException(RuntimeException::class);
        $passwords->read();
    }
}
