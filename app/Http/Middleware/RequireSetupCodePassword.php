<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;

class RequireSetupCodePassword extends RequirePassword
{
    public function handle($request, Closure $next, $redirectToRoute = null, $passwordTimeoutSeconds = null)
    {
        $timeout = $passwordTimeoutSeconds ?? config('auth.password_timeout');

        if ($request->expectsJson()) {
            return parent::handle($request, $next, $redirectToRoute, $timeout);
        }

        if ($this->shouldConfirmPassword($request, $timeout)) {
            // Keep the selected device in this tab even if a card poll saves an older session.
            return to_route('cm.setup-code.confirm', ['mac' => $request->route('mac')]);
        }

        return $next($request);
    }
}
