<?php

namespace Tests\Feature;

use App\Http\Livewire\SwitchSettings;
use App\Models\EthernetSwitch;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeRestart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FakeSnmp;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The switch settings' write access for PoE restarts: its own secrets, kept like the read ones, and a check */
class SwitchWriteAccessTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function setUp(): void
    {
        parent::setUp();
        // saving reads the switch's name and base MAC: no real SNMP unless a test sets up the stand
        $this->app->bind(SnmpClient::class, function () { return new FakeSnmp(['' => []]); });
    }

    protected function first(): array
    {
        return EthernetSwitch::orderBy('id')->first()->config();
    }

    protected function saved()
    {
        Livewire::test(SwitchSettings::class)->call('add')->set('host', '192.0.2.10')->set('community', 'public')
            ->set('writeCommunity', 'rw-secret')->call('save')->assertHasNoErrors();
    }

    public function test_a_write_community_is_saved_and_never_shown()
    {
        $this->saved();
        $this->assertSame('rw-secret', $this->first()['write']['community']);
        Livewire::test(SwitchSettings::class)->call('edit', 1)
            ->assertDontSeeHtml('rw-secret')
            ->assertSeeHtml('leave empty to keep the stored write community');
    }

    public function test_a_kept_write_community_stays_and_another_switch_does_not_get_it()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('community', 'public')->call('save');
        $this->assertSame('rw-secret', $this->first()['write']['community'], 'left empty: kept');
        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('host', '192.0.2.99')->set('community', 'public')->call('save');
        $this->assertSame('', $this->first()['write']['community'], 'a new address never receives it');
    }

    public function test_a_v3_write_user_with_its_own_passwords()
    {
        Livewire::test(SwitchSettings::class)->call('add')->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->set('writeAuthPassword', 'rwauth123')
            ->set('writePrivProtocol', 'AES')->set('writePrivPassword', 'rwpriv123')->call('save')->assertHasNoErrors();
        $w = $this->first()['write'];
        $this->assertSame(['cmprov-rw', 'SHA', 'rwauth123', 'AES', 'rwpriv123'],
            [$w['user'], $w['auth_protocol'], $w['auth_password'], $w['priv_protocol'], $w['priv_password']]);
    }

    public function test_a_write_user_needs_its_password()
    {
        Livewire::test(SwitchSettings::class)->call('add')->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->call('save')
            ->assertHasErrors(['writeAuthPassword']);
    }

    public function test_check_write_access_writes_a_port_back_as_it_is()
    {
        $this->stand([]);
        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access works (GE0/0/1).');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.6', 'i', 1]], $this->fake->sets);
    }

    public function test_check_write_access_shows_the_switch_refusal()
    {
        $this->stand([]);
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.6'] = 'noAccess';
        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access does not work: noAccess');
    }

    public function test_check_write_access_waits_for_a_restart()
    {
        $this->stand([]);
        Cache::lock('poe-restart:1', 60)->get();   // its value written back could undo the restart's switch-on
        Livewire::test(SwitchSettings::class)->call('edit', 1)->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('A restart is running: check again in a minute.');
        $this->assertSame([], $this->fake->sets);
    }

    public function test_check_write_access_takes_its_own_switch_lock()
    {
        $this->stand();
        $this->secondSwitch();
        $lock = Cache::lock(PoeRestart::LOCK.':2', 60);   // a restart on sw02 does not hold sw01's check
        $lock->get();
        try
        {
            Livewire::test(SwitchSettings::class)->call('edit', 1)->call('checkWrite')->assertSee('Write access works');
            Livewire::test(SwitchSettings::class)->call('edit', 2)->call('checkWrite')->assertSee('A restart is running');
        }
        finally
        {
            $lock->release();
        }
    }

    public function test_check_write_access_locks_the_switch_at_the_form_s_address()
    {
        $this->stand();
        $this->secondSwitch();
        $lock = Cache::lock(PoeRestart::LOCK.':1', 60);
        $lock->get();
        try
        {
            // sw02's form with sw01's address: the check talks to sw01, whose restart is running
            Livewire::test(SwitchSettings::class)->call('edit', 2)->set('host', '192.0.2.10')->set('community', 'public')
                ->set('writeCommunity', 'private')->call('checkWrite')->assertSee('A restart is running');
        }
        finally
        {
            $lock->release();
        }
    }

    public function test_remove_write_access_keeps_the_read_one()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->call('edit', 1)->call('forgetWrite');
        $this->assertSame('', $this->first()['write']['community']);
        $this->assertSame('public', $this->first()['community']);
    }
}
