<?php

namespace Tests\Feature;

use App\Models\PoeOutage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ports whose PoE cmprovision switched off for a restart, and which of them stayed off */
class PoeOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function outage(array $attributes = [])
    {
        return PoeOutage::create(array_merge(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'cm_serial' => '10000000e611110c',
            'user' => 'igor', 'off_at' => now()], $attributes));
    }

    protected function snapshot($takenAt, $state)
    {
        return ['taken_at' => $takenAt, 'ports' => [8 => ['poe' => ['state' => $state, 'class' => null, 'watts' => null]]]];
    }

    public function test_a_port_stayed_off_when_switching_on_failed_or_the_restart_stopped_midway()
    {
        $this->outage();                                                                         // a restart in its dark 5 s
        $failed = $this->outage(['error' => 'noAccess']);
        $stuck = $this->outage(['off_at' => now()->subMinutes(3)]);                              // php-fpm restarted midway
        $this->outage(['off_at' => now()->subMinutes(10), 'on_at' => now()->subMinutes(10)]);    // done long ago
        $this->assertSame([$stuck->id, $failed->id], PoeOutage::stayedOff()->pluck('id')->all());
    }

    public function test_a_port_the_switch_shows_with_poe_on_again_is_closed()
    {
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        $other = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute(), 'switch_host' => '192.0.2.99']);
        PoeOutage::closeSeenOn($this->snapshot(now()->getTimestamp(), 'delivering'), '192.0.2.10');
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertNull($other->fresh()->on_at, 'a row of another switch stays');
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'msg' => 'PoE on GE0/0/3 is on again (seen on the switch)']);
    }

    public function test_a_snapshot_from_before_the_restart_or_a_port_still_off_closes_nothing()
    {
        $outage = $this->outage(['error' => 'noAccess']);
        PoeOutage::closeSeenOn($this->snapshot(now()->subMinute()->getTimestamp(), 'delivering'), '192.0.2.10');
        PoeOutage::closeSeenOn($this->snapshot(now()->addSecond()->getTimestamp(), 'disabled'), '192.0.2.10');
        PoeOutage::closeSeenOn(null, '192.0.2.10');
        $this->assertNull($outage->fresh()->on_at);
    }
}
