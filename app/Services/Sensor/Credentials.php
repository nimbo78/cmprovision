<?php

namespace App\Services\Sensor;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Credentials
{
    public function put(string $operation, array $account): void
    {
        Cache::put('reprovision-secret:'.$operation, Crypt::encryptString(json_encode($account, JSON_THROW_ON_ERROR)), 120);
    }
    public function get(string $operation): ?array
    {
        $value = Cache::get('reprovision-secret:'.$operation);
        if (!$value) return null;
        try { return json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable $e) { $this->forget($operation); return null; }
    }
    public function forget(string $operation): void { Cache::forget('reprovision-secret:'.$operation); }
}
