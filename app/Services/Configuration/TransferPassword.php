<?php

namespace App\Services\Configuration;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TransferPassword
{
    public const SETTING = 'configuration_transfer_password';

    public function configured(): bool
    {
        $value = Setting::find(self::SETTING)?->value;

        return is_string($value) && $value !== '';
    }

    public function save(#[\SensitiveParameter] string $password): void
    {
        if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12 || mb_strlen($password, 'UTF-8') > 1024) {
            throw ValidationException::withMessages([
                'master_password' => 'Use a master password of 12 to 1024 characters.',
            ]);
        }
        Setting::updateOrCreate(['key' => self::SETTING], ['value' => Crypt::encryptString($password)]);
    }

    public function read(): ?string
    {
        $value = Setting::find(self::SETTING)?->value;
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException $error) {
            throw new RuntimeException('The saved master password could not be opened with this station key. Set it again.');
        }
    }

    public function forget(): void
    {
        Setting::destroy(self::SETTING);
    }
}
