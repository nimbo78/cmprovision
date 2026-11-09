<?php

namespace App\Services;

/** Coordinate DatabaseQueue::pop with the updater's idle check and maintenance publication. */
class StationUpdateQueueGate
{
    private $handle = null;
    public function __construct(private StationUpdatesControl $control,
        private string $path = '/run/cmprovision-network/provisioning.lock', private bool $verifyRoot = true) {}
    public function reserve(): bool
    {
        $this->release();
        if (!$this->control->available()) return true;
        if ($this->control->pending() || is_link($this->path)) return false;
        $handle = @fopen($this->path, 'r');
        if (!$handle) return false;
        $info = fstat($handle);
        if (!$info || ($info['mode'] & 0170000) !== 0100000 ||
            ($this->verifyRoot && ($info['uid'] !== 0 || ($info['mode'] & 0022))) || !flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        if ($this->control->pending()) { $this->release(); return false; }
        // The caller releases only after DatabaseQueue::pop has committed its reservation.
        return true;
    }
    public function release(): void
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
        $this->handle = null;
    }
    public function __destruct() { $this->release(); }
}
