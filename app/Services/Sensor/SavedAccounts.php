<?php

namespace App\Services\Sensor;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SavedAccounts
{
    const SETTING = 'reprovision_accounts';
    const MAX_ENABLED = 3;

    /** Metadata only: passwords must never be included in a Livewire snapshot. */
    public function listing(): array
    {
        return array_map(fn ($a) => ['id'=>$a['id'],'name'=>$a['name'],'user'=>$a['user'],'enabled'=>$a['enabled']], $this->read());
    }

    public function enabled(): array
    {
        $accounts = []; $seen = [];
        foreach ($this->read() as $a) {
            $key = hash('sha256', $a['user']."\0".$a['password']);
            if ($a['enabled'] && !isset($seen[$key])) {
                $accounts[] = $a; $seen[$key] = true;
            }
        }
        return $accounts;
    }

    public function save(?string $id, string $name, string $user, string $password, bool $enabled): void
    {
        $this->change(function (array $accounts) use ($id, $name, $user, $password, $enabled) {
            $index = $id === null ? count($accounts) : $this->index($accounts, $id);
            if ($id !== null && $password === '') $password = $accounts[$index]['password'];
            Validator::make(compact('name', 'user', 'password'), [
                'name' => ['required','string','max:80','not_regex:/[\r\n\x00]/'],
                'user' => ['required','regex:/^[a-z_][a-z0-9_-]{0,31}$/D'],
                'password' => ['required','string','max:1024','not_regex:/[\r\n\x00]/'],
            ])->validate();
            if (strlen($password) > 1024) throw \Illuminate\Validation\ValidationException::withMessages(['password'=>'Use a sensor password of at most 1024 bytes.']);
            if ($index >= 10) throw new RuntimeException('Keep at most 10 saved sensor accounts.');
            $accounts[$index] = ['id' => $id ?? bin2hex(random_bytes(8)), 'name' => $name, 'user' => $user,
                'password' => $password, 'enabled' => $enabled];
            return $accounts;
        });
    }

    public function remove(string $id): void
    {
        $this->change(function ($accounts) use ($id) {
            array_splice($accounts, $this->index($accounts, $id), 1); return $accounts;
        });
    }

    public function toggle(string $id): void
    {
        $this->change(function ($accounts) use ($id) {
            $i = $this->index($accounts, $id); $accounts[$i]['enabled'] = !$accounts[$i]['enabled']; return $accounts;
        });
    }

    public function move(string $id, int $direction): void
    {
        if (!in_array($direction, [-1, 1], true)) throw new RuntimeException('Invalid account order.');
        $this->change(function ($accounts) use ($id, $direction) {
            $i = $this->index($accounts, $id); $j = $i + $direction;
            if (isset($accounts[$j])) [$accounts[$i], $accounts[$j]] = [$accounts[$j], $accounts[$i]];
            return $accounts;
        });
    }

    private function index(array $accounts, string $id): int
    {
        foreach ($accounts as $i => $a) if ($a['id'] === $id) return $i;
        throw new RuntimeException('This saved sensor account no longer exists.');
    }

    private function change(callable $change): void
    {
        $lock = Cache::lock('reprovision-accounts-settings', 10);
        if (!$lock->get()) throw new RuntimeException('Another operator is updating sensor accounts. Try again.');
        try {
            $accounts = $change($this->read());
            if (count(array_filter($accounts, fn ($a) => $a['enabled'])) > self::MAX_ENABLED)
                throw new RuntimeException('Enable at most 3 accounts for automatic checking.');
            Setting::updateOrCreate(['key' => self::SETTING], ['value' => Crypt::encryptString(json_encode($accounts, JSON_THROW_ON_ERROR))]);
        } finally { $lock->release(); }
    }

    private function read(): array
    {
        $setting = Setting::find(self::SETTING);
        if (!$setting) return [
            ['id' => 'wlanpi', 'name' => 'WLAN Pi', 'user' => 'wlanpi', 'password' => 'wlanpi', 'enabled' => true],
            ['id' => 'raspberry-legacy', 'name' => 'Raspberry Pi OS (legacy)', 'user' => 'pi', 'password' => 'raspberry', 'enabled' => false],
        ];
        try {
            $accounts = json_decode(Crypt::decryptString($setting->value), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($accounts) || !array_is_list($accounts) || count($accounts) > 10) throw new RuntimeException;
            $ids = [];
            foreach ($accounts as $a) {
                if (!is_array($a) || !is_string($a['id'] ?? null) || !preg_match('/^[a-z0-9-]{1,40}$/D', $a['id'])
                    || isset($ids[$a['id']]) || !is_string($a['name'] ?? null) || $a['name'] === '' || strlen($a['name']) > 320
                    || !is_string($a['user'] ?? null) || !preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', $a['user'])
                    || !is_bool($a['enabled'] ?? null) || !is_string($a['password'] ?? null)
                    || $a['password'] === '' || strlen($a['password']) > 1024 || preg_match('/[\r\n\x00]/', $a['password']))
                    throw new RuntimeException;
                $ids[$a['id']] = true;
            }
            if (count(array_filter($accounts, fn ($a) => $a['enabled'])) > self::MAX_ENABLED) throw new RuntimeException;
            return $accounts;
        } catch (\Throwable $e) {
            throw new RuntimeException('Saved sensor accounts cannot be read. Check the provisioner APP_KEY or restore a matching backup.');
        }
    }
}
