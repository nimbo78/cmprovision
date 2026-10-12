<?php

namespace Tests\Feature;

use App\Http\Livewire\SwitchSettings;
use App\Services\Switch\Poe;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The switch settings' write access for PoE restarts: its own secrets, kept like the read ones, and a check */
class SwitchWriteAccessTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function saved()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')
            ->set('writeCommunity', 'rw-secret')->call('save')->assertHasNoErrors();
    }

    public function test_a_write_community_is_saved_and_never_shown()
    {
        $this->saved();
        $this->assertSame('rw-secret', SwitchPortFinder::config()['write']['community']);
        Livewire::test(SwitchSettings::class)
            ->assertDontSeeHtml('rw-secret')
            ->assertSeeHtml('leave empty to keep the stored write community');
    }

    public function test_a_kept_write_community_stays_and_another_switch_does_not_get_it()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->set('community', 'public')->call('save');
        $this->assertSame('rw-secret', SwitchPortFinder::config()['write']['community'], 'left empty: kept');
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.99')->set('community', 'public')->call('save');
        $this->assertSame('', SwitchPortFinder::config()['write']['community'], 'a new address never receives it');
    }

    public function test_a_v3_write_user_with_its_own_passwords()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->set('writeAuthPassword', 'rwauth123')
            ->set('writePrivProtocol', 'AES')->set('writePrivPassword', 'rwpriv123')->call('save')->assertHasNoErrors();
        $w = SwitchPortFinder::config()['write'];
        $this->assertSame(['cmprov-rw', 'SHA', 'rwauth123', 'AES', 'rwpriv123'],
            [$w['user'], $w['auth_protocol'], $w['auth_password'], $w['priv_protocol'], $w['priv_password']]);
    }

    public function test_a_write_user_needs_its_password()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->call('save')
            ->assertHasErrors(['writeAuthPassword']);
    }

    public function test_check_write_access_writes_a_port_back_as_it_is()
    {
        $this->stand([]);
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access works (GE0/0/1).');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.6', 'i', 1]], $this->fake->sets);
    }

    public function test_check_write_access_shows_the_switch_refusal()
    {
        $this->stand([]);
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.6'] = 'noAccess';
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access does not work: noAccess');
    }

    public function test_remove_write_access_keeps_the_read_one()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->call('forgetWrite');
        $this->assertSame('', SwitchPortFinder::config()['write']['community']);
        $this->assertSame('public', SwitchPortFinder::config()['community']);
    }
}
