<?php

namespace App\Http\Middleware;

use App\Services\StationUpdatesControl;
use Closure;

class StationUpdateMaintenance
{
    public function handle($request, Closure $next)
    {
        if (app(StationUpdatesControl::class)->pending()
            && !($request->isMethod('GET') && $request->is('settings/updates/status', 'updates/health', 'login', 'up'))) {
            return response('A station update is in maintenance. Retry after it finishes. If recovery is required, use SSH or the local console.', 503)
                ->header('Retry-After', '10')->header('Content-Type', 'text/plain');
        }
        return $next($request);
    }
}
