<?php

namespace App\Providers;

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
    }
}
