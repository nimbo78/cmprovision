<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;

/* Several switches in one test: each answers from its own FakeSnmp, picked by the address in the client's config */
trait FakeSwitches
{
    /** @param FakeSnmp[] $fakes host => fake */
    protected function bindSwitches(array $fakes): void
    {
        $this->app->bind(SnmpClient::class, function ($app, $params) use ($fakes) {
            $host = $params['config']['host'] ?? '';
            if (!isset($fakes[$host]))
                throw new \RuntimeException('No fake switch at '.$host);
            return $fakes[$host];
        });
    }
}
