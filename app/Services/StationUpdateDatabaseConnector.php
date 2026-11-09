<?php

namespace App\Services;

use Illuminate\Queue\Connectors\DatabaseConnector;

/** Same Laravel database connection/retry/after-commit contract with guarded pop(). */
class StationUpdateDatabaseConnector extends DatabaseConnector
{
    public function connect(array $config)
    {
        return new StationUpdateDatabaseQueue(
            $this->connections->connection($config['connection'] ?? null),
            $config['table'], $config['queue'], $config['retry_after'] ?? 60, $config['after_commit'] ?? null
        );
    }
}
