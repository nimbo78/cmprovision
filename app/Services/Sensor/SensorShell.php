<?php

namespace App\Services\Sensor;

interface SensorShell
{
    public function key(string $address, string $operation, string $command): ShellResult;
    public function account(string $address, string $operation, string $user, string $password, string $command): ShellResult;
    public function changePassword(string $address, string $operation, string $user, string $password, string $new): ShellResult;
    public function forget(string $operation): void;
}
