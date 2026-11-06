<?php

namespace Tests\Support;

use App\Services\ServiceState;

/** ServiceState for the settings page tests: the units that run are named by the test */
class FakeServiceState extends ServiceState
{
    /** @param string[] $active */
    public function __construct(public array $active = [], public array $disabled = [])
    {
    }

    public function isActive(string $unit): bool
    {
        return in_array($unit, $this->active, true);
    }

    protected function unitProperties(string $unit): array
    {
        return in_array($unit, $this->disabled, true)
            ? ['ActiveState=inactive', 'UnitFileState=masked'] : [];
    }
}
