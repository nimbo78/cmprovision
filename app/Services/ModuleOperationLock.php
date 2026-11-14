<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ModuleOperationLock
{
    /** MAC sorts before every hardware serial, including a serial learned while holding the MAC lock. */
    public static function targetKeys(?string $serial, ?string $mac): array
    {
        $keys = [];
        if ($mac && \App\Models\Cm::isMac($mac)) $keys[] = '!mac:'.strtolower($mac);
        if ($serial) $keys[] = $serial;
        return $keys;
    }

    public static function run(iterable $serials, callable $action, int $seconds = 120): mixed
    {
        $keys = [];
        foreach ($serials as $serial) $keys[] = (string) $serial;
        $keys = array_unique($keys); sort($keys, SORT_STRING);
        $held = [];
        try {
            foreach ($keys as $serial) {
                $lock = Cache::lock('module-operation:'.hash('sha256', $serial), $seconds);
                if (!$lock->get()) throw new ModuleOperationBusy('Another operation is running on this module.');
                $held[] = $lock;
            }
            return $action();
        } finally {
            foreach (array_reverse($held) as $lock) $lock->release();
        }
    }
}
