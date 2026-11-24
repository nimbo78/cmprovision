<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use App\Models\User;
use App\Services\SerialConsoleBroker;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::define('use-serial-console', static fn (User $user) => (bool) $user->serial_console_access);
        Event::listen(Logout::class, static function (Logout $event) {
            $request = request();
            if ($event->guard === 'web' && $event->user && $request->hasSession()) {
                app(SerialConsoleBroker::class)->request(['op' => 'close_owner',
                    'owner' => SerialConsoleBroker::owner($request, $event->user->getAuthIdentifier())]);
            }
        });
    }
}
