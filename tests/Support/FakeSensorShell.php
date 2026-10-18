<?php

namespace Tests\Support;

use App\Services\Sensor\SensorShell;
use App\Services\Sensor\ShellResult;

class FakeSensorShell implements SensorShell
{
    public array $calls = [];
    public string $keyStatus = 'ok', $accountStatus = 'ok', $wipeStatus = 'ok';
    public array $identity = ['agent'=>'1', 'serial'=>'10000000abcdef01', 'host'=>'test-sensor', 'storage'=>'/dev/mmcblk0',
        'cid'=>'0123456789abcdef0123456789abcdef', 'sectors'=>'65536'];
    public bool $expired = false;
    public array $accountAttempts = [], $accountResults = [];
    public function key(string $address, string $operation, string $command): ShellResult
    {
        $this->calls[] = ['key', $command];
        return $this->keyStatus !== 'ok' ? new ShellResult($this->keyStatus) : $this->answer($command);
    }
    public function account(string $address, string $operation, string $user, string $password, string $command): ShellResult
    {
        $this->calls[] = ['account', $command];
        $this->accountAttempts[] = [$user, $password, $command];
        if ($this->accountResults) {
            $status = $this->accountResults[$user."\0".$password] ?? 'denied';
            if ($status !== 'ok') return new ShellResult($status);
        }
        if ($this->expired) return new ShellResult('password_expired');
        if ($this->accountStatus !== 'ok') return new ShellResult($this->accountStatus);
        if (str_starts_with($command, 'sudo -k -n true')) return new ShellResult('ok');
        return $this->answer($command);
    }
    private function answer(string $command): ShellResult
    {
        if (str_contains($command, 'wipe '.$this->identity['serial']))
            return new ShellResult($this->wipeStatus, $this->wipeStatus === 'ok' ? 'CMPROVISION_WIPED='.$this->identity['serial'] : '');
        return new ShellResult('ok', implode("\n", array_map(fn ($key) => $key.'='.$this->identity[$key], array_keys($this->identity))));
    }
    public function changePassword(string $address, string $operation, string $user, string $password, string $new): ShellResult
    {
        $this->calls[] = ['password-change', $user]; $this->expired = false; return new ShellResult('ok');
    }
    public function forget(string $operation): void {}
    public function wipes(): int { return count(array_filter($this->calls, fn ($c) => str_contains($c[1], 'wipe '.$this->identity['serial']))); }
}
