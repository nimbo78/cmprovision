<?php

namespace App\Http\Middleware;

use App\Services\FirstAdmin;
use Closure;
use Illuminate\Http\Request;

class FirstAdminEntry
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && ($request->is('/') || $request->routeIs('login'))
            && !$request->user() && app(FirstAdmin::class)->state() === 'armed') {
            return redirect()->route('first-admin')->header('Cache-Control', 'no-store');
        }
        return $next($request);
    }
}
