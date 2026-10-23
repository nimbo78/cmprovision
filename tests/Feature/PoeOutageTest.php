<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** Ports whose PoE cmprovision switched off for a restart, and which of them stayed off */
class PoeOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function outage(array $attributes = [])
    {
        return PoeOutage::create(array_merge(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'cm_mac' => 'e4:5f:01:62:9f:d3',
            'user' => 'igor', 'off_at' => now()], $attributes));
    }

    protected function switch(): EthernetSwitch
    {
        return EthernetSwitch::firstOrCreate(['host' => '192.0.2.10'], ['community' => 'public']);
    }

    public function test_a_port_stayed_off_when_switching_on_failed_or_the_restart_stopped_midway()
    {
        $this->outage();                                                                         // a restart in its dark 5 s
        $failed = $this->outage(['error' => 'noAccess']);
        $stuck = $this->outage(['off_at' => now()->subMinutes(3)]);                              // php-fpm restarted midway
        $this->outage(['off_at' => now()->subMinutes(10), 'on_at' => now()->subMinutes(10)]);    // done long ago
        $this->assertSame([$stuck->id, $failed->id], PoeOutage::stayedOff()->pluck('id')->all());
    }

    public function test_a_port_the_switch_shows_switched_on_again_is_closed()
    {
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        $other = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute(), 'switch_host' => '192.0.2.99']);
        PoeOutage::closeSeenOn(new FakeSnmp(['' => [$outage->oid => 1]]), $this->switch());   // a row from before 2.4: by address
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertNull($other->fresh()->on_at, 'a row of another switch stays');
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'msg' => 'PoE on GE0/0/3 is on again (seen on the switch)']);
    }

    public function test_a_port_still_off_a_restart_in_its_dark_seconds_or_a_silent_switch_closes_nothing()
    {
        $off = $this->outage(['error' => 'noAccess']);
        // still in its 5 s, the off not written yet: not read at all
        $dark = $this->outage(['if_index' => 7, 'port' => 'GE0/0/2', 'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.7']);
        PoeOutage::closeSeenOn(new FakeSnmp(['' => [$off->oid => 2, $dark->oid => 1]]), $this->switch());
        $silent = new FakeSnmp(['' => [$off->oid => 1]]);
        $silent->timeout = true;
        PoeOutage::closeSeenOn($silent, $this->switch());
        $this->assertNull($off->fresh()->on_at);
        $this->assertNull($dark->fresh()->on_at);
    }
}
