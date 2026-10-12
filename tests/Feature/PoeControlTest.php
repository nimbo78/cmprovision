<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** Writing a port's PoE with the switch's write credentials (Settings, "Write access") */
class PoeControlTest extends TestCase
{
    use RefreshDatabase;

    const OID = Poe::OID_HW_ENABLE.'.8';

    protected function settings(array $values)
    {
        foreach ($values as $key => $value)
            Setting::updateOrCreate(['key' => 'ethernetswitch_'.$key], ['value' => $value]);
        return SwitchPortFinder::config();
    }

    public function test_write_access_needs_a_write_community_or_a_write_user()
    {
        $this->assertFalse(PoeControl::writable($this->settings(['ip' => '192.0.2.10', 'snmp_community' => 'public'])));
        $this->assertTrue(PoeControl::writable($this->settings(['snmp_write_community' => 'private'])));
        $this->assertFalse(PoeControl::writable($this->settings(['snmp_version' => '3'])), 'v3 needs a write user');
        $this->assertTrue(PoeControl::writable($this->settings(['snmp_write_user' => 'cmprov-rw'])));
        $this->assertFalse(PoeControl::writable($this->settings(['ip' => ''])), 'no switch');
    }

    public function test_the_control_talks_with_the_write_credentials()
    {
        $config = $this->settings(['ip' => '192.0.2.10', 'snmp_community' => 'public', 'snmp_write_community' => 'private']);
        $seen = null;
        $this->app->bind(SnmpClient::class, function ($app, $params) use (&$seen) {
            $seen = $params['config'];
            return new FakeSnmp(['' => []]);
        });
        $this->assertNotNull(PoeControl::forConfig($config));
        $this->assertSame(['192.0.2.10', 'private'], [$seen['host'], $seen['community']]);
        $this->assertNull(PoeControl::forConfig($this->settings(['snmp_write_community' => ''])));
    }

    public function test_off_on_and_read_back()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $control = new PoeControl($snmp);
        $control->switchOff(self::OID);
        $this->assertSame(PoeControl::OFF, $control->value(self::OID));
        $this->assertNull($control->switchOnChecked(self::OID));
        $this->assertSame([[self::OID, 'i', PoeControl::OFF], [self::OID, 'i', PoeControl::ON]], $snmp->sets);
    }

    public function test_switching_on_tries_three_times_a_second_apart()
    {
        Sleep::fake();
        $snmp = new FakeSnmp(['' => [self::OID => 2]]);
        $snmp->refuse[self::OID.'=1'] = 'noAccess';
        $this->assertSame('noAccess', (new PoeControl($snmp))->switchOnChecked(self::OID));
        $this->assertCount(3, $snmp->sets);
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(1)->second()]);
    }

    public function test_check_writes_a_value_back_unchanged()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1, Poe::OID_HW_ENABLE.'.9' => 2]]);
        $this->assertSame(8, (new PoeControl($snmp))->check([8 => self::OID, 9 => Poe::OID_HW_ENABLE.'.9']));
        $this->assertSame([[self::OID, 'i', 1]], $snmp->sets);
        $this->assertNull((new PoeControl($snmp))->check([]));
    }
}
