<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

class ModuleOperationLock
{
    public static function run(iterable $serials, callable $action, int $seconds = 120): mixed
    {
        $keys = [];
        foreach ($serials as $serial) $keys[] = (string) $serial;
        $keys = array_unique($keys); sort($keys, SORT_STRING);
        $held = [];
        try {
            foreach ($keys as $serial) {
                $lock = Cache::lock('module-operation:'.hash('sha256', $serial), $seconds);
                if (!$lock->get()) throw new RuntimeException('Another operation is running on this module.');
                $held[] = $lock;
            }
            return $action();
        } finally {
            foreach (array_reverse($held) as $lock) $lock->release();
        }
    }
}
