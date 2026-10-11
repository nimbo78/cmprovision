<?php

namespace App\Providers;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        /* SNMP access to the switch; tests bind a fake one */
        $this->app->bind(\App\Services\Snmp\SnmpClient::class, function ($app, $params) {
            return new \App\Services\Snmp\PhpSnmpClient($params['config'] ?? []);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        /* $date->local(): the same instant in the display time zone (storage stays UTC) */
        Carbon::macro('local', function () {
            return $this->copy()->timezone(config('app.display_timezone'));
        });

        /* Laravel 11+ apps set where guests go in bootstrap/app.php (redirectGuestsTo); this one keeps the
           Laravel 8 layout. Without it a session that AuthenticateSession ends (the password changed in
           another browser) gets an empty 401 instead of the login page. JSON requests still get a 401. */
        AuthenticateSession::redirectUsing(fn () => route('login'));
        AuthenticationException::redirectUsing(fn () => route('login'));
    }
}
