<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use Livewire\Component;

/* Dashboard panel: modules being provisioned now, and those that finished or failed recently */
class ProvisioningStatus extends Component
{
    const RECENT_HOURS = 2;      // finished and failed modules stay visible this long
    const ACTIVE_HOURS = 12;     // a module still "in progress" after this long was unplugged
    const MAX_ROWS = 50;

    public function render()
    {
        $recent = now()->subHours(self::RECENT_HOURS);
        $modules = Cm::whereNotNull('phase')
            ->where(function ($q) use ($recent) {
                $q->where(function ($q) {
                    $q->whereIn('phase', Cm::ACTIVE_PHASES)
                      ->where('provisioning_started_at', '>=', now()->subHours(self::ACTIVE_HOURS));
                })->orWhere(function ($q) use ($recent) {
                    $q->whereIn('phase', ['done', 'failed'])->where('phase_started_at', '>=', $recent);
                });
            })
            ->orderByDesc('provisioning_started_at')
            ->limit(self::MAX_ROWS)
            ->get();

        return view('livewire.provisioning-status', [
            'modules' => $modules,
            'activeCount' => $modules->filter->isActive()->count(),
            'doneCount' => $modules->where('phase', 'done')->count(),
            'failedCount' => $modules->where('phase', 'failed')->count(),
        ]);
    }

    public static function bytes($bytes)
    {
        $mib = $bytes / 1048576;
        return $mib < 1024 ? sprintf('%d MB', $mib) : sprintf('%.1f GB', $mib / 1024);
    }

    public static function speed($bytesPerSecond)
    {
        return sprintf('%.1f MB/s', $bytesPerSecond / 1048576);
    }

    public static function duration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds >= 3600)
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
