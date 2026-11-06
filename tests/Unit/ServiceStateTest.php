<?php

namespace Tests\Unit;

use App\Services\ServiceState;
use PHPUnit\Framework\TestCase;

class ServiceStateTest extends TestCase
{
    public function test_only_inactive_masked_boot_services_are_disabled(): void
    {
        $state = new class extends ServiceState {
            public bool $active = false;
            public array $properties = [];

            public function isActive(string $unit): bool
            {
                return $this->active;
            }

            protected function unitProperties(string $unit): array
            {
                return $this->properties;
            }
        };
        foreach (['masked', 'masked-runtime'] as $mask) {
            $state->properties = ['ActiveState=inactive', 'UnitFileState='.$mask];
            $this->assertSame('disabled', $state->status('cmprovision-dnsmasq'));
            $this->assertSame('disabled', $state->status('cmprovision-rpiboot'));
            $this->assertSame('stopped', $state->status('cmprovision-queue'));
        }
        foreach ([[], ['ActiveState=failed', 'UnitFileState=masked'],
            ['ActiveState=inactive', 'UnitFileState=disabled'], ['ActiveState=inactive', 'UnitFileState=enabled']] as $properties) {
            $state->properties = $properties;
            $this->assertSame('stopped', $state->status('cmprovision-dnsmasq'));
        }
        $state->active = true;
        $this->assertSame('running', $state->status('cmprovision-dnsmasq'));
    }
}
