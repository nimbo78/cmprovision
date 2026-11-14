<?php
namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/** Root measures fixed directories; DB-backed image decisions remain under www-data. */
class StationStorage
{
    const CACHE_KEY = 'station-storage-status-v1';
    const CATEGORIES = ['application'=>'Application and data (without uploads)', 'uploads'=>'Image files, pieces and partial downloads',
        'package_backups'=>'OS and package backups (/var/backups)', 'update_recovery'=>'Updater recovery snapshot',
        'update_downloads'=>'Updater download cache', 'full_backups'=>'Full backups and backup working files'];

    public function status(bool $refresh = false): array
    {
        if ($refresh) Cache::forget(self::CACHE_KEY);
        return Cache::remember(self::CACHE_KEY, 60, function () {
            $helper = '/usr/lib/cmprovision/station-storage';
            if (!is_file($helper)) throw new \RuntimeException('Storage measurements require the updated cmprovision4 package.');
            $process = new Process(['sudo', '-n', $helper]);
            $process->setTimeout(18);
            try { $process->mustRun(); }
            catch (\Throwable $error) { throw new \RuntimeException('Storage measurements are unavailable. Try refreshing shortly.'); }
            $data = strlen($process->getOutput()) <= 16384 ? json_decode($process->getOutput(), true) : null;
            if (!is_array($data) || ($data['schema'] ?? null) !== 1 || !is_int($data['measured_at'] ?? null)
                || !is_array($data['categories'] ?? null) || !is_array($data['snapshot'] ?? null))
                throw new \RuntimeException('The storage collector returned an invalid measurement.');
            foreach (array_keys(self::CATEGORIES) as $key) {
                $row = $data['categories'][$key] ?? null;
                if (!is_array($row) || !in_array($row['state'] ?? null, ['measured', 'missing', 'unknown'], true)
                    || !(($row['bytes'] ?? null) === null || (is_int($row['bytes']) && $row['bytes'] >= 0)))
                    throw new \RuntimeException('The storage collector returned an invalid category.');
            }
            return $data;
        });
    }
}
