<?php

namespace App\Services;

/** Coordinates the start of a module run with a privileged network change. */
class NetworkChangeGate
{
    const HELPER = '/usr/lib/cmprovision/network-transaction';
    const DIRECTORY = '/run/cmprovision-network';

    private $enabled;
    private $directory;
    private $verifyRoot;

    public function __construct(?bool $enabled = null, string $directory = self::DIRECTORY, bool $verifyRoot = true)
    {
        $this->enabled = $enabled ?? is_file(self::HELPER);
        $this->directory = rtrim($directory, '/');
        $this->verifyRoot = $verifyRoot;
    }

    /** A module retries HTTP 503 while a change is in progress; no run has started yet. */
    public function start(callable $begin)
    {
        if (app(StationUpdatesControl::class)->pending()) return $this->updateRetry();
        if (app(StationBackupsControl::class)->pending()) return $this->backupRetry();
        if (app(StationPowerControl::class)->pending()) return $this->powerRetry();
        if (!$this->enabled) {
            return $begin();
        }
        $directory = @lstat($this->directory);
        if (!$directory || ($directory['mode'] & 0170000) !== 0040000 ||
            ($this->verifyRoot && ($directory['uid'] !== 0 || ($directory['mode'] & 0022)))) {
            return $this->retry();
        }
        $path = $this->directory.'/provisioning.lock';
        if (is_link($path) || !($file = @fopen($path, 'r'))) {
            return $this->retry();
        }
        try {
            $stat = fstat($file);
            if (!$stat || ($stat['mode'] & 0170000) !== 0100000 ||
                ($this->verifyRoot && ($stat['uid'] !== 0 || ($stat['mode'] & 0022))) ||
                !flock($file, LOCK_EX | LOCK_NB)) {
                return $this->retry();
            }
            try {
                if (file_exists($this->directory.'/pending')) return $this->retry();
                if (app(StationUpdatesControl::class)->pending()) return $this->updateRetry();
                if (app(StationBackupsControl::class)->pending()) return $this->backupRetry();
                return app(StationPowerControl::class)->pending() ? $this->powerRetry() : $begin();
            } finally {
                flock($file, LOCK_UN);
            }
        } finally {
            fclose($file);
        }
    }

    private function retry()
    {
        return response('The provisioner network is being changed; retry shortly.', 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '10');
    }

    private function backupRetry()
    {
        return response('A station backup is being created; retry shortly.', 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '5');
    }

    private function powerRetry()
    {
        return response('The provisioner is restarting or shutting down; retry after it is online.', 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '10');
    }

    private function updateRetry()
    {
        return response('The station is being updated; retry after maintenance.', 503)
            ->header('Content-Type', 'text/plain')->header('Retry-After', '10');
    }
}
