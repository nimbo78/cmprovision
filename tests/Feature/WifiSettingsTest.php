<?php

namespace Tests\Feature;

use App\Http\Livewire\WifiSettings;
use App\Models\User;
use App\Services\WifiNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeWifiNetworks;
use Tests\TestCase;

/** The Wi-Fi networks section of the settings page: every change asks for the account password; a network password goes to the server once and never comes back. */
class WifiSettingsTest extends TestCase
{
    use RefreshDatabase;

    const M2 = 'ab18b939-1ff4-4500-99e6-d306e02194af';
    const ONBOARD = '74a0d3da-084a-49ef-9e8b-7a6bc7919cc5';
    const PHONE = '0b1c2d3e-0000-4000-8000-000000000001';
    const SECRET = 'S3cret-pass-1';

    protected $wifi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wifi = new FakeWifiNetworks;
        $this->wifi->networks = [
            ['uuid' => self::M2, 'uuids' => [self::M2, self::ONBOARD], 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false,
             'priority' => 20, 'devices' => ['wlan1' => 'M.2 card (wlan1)']],
            ['uuid' => self::PHONE, 'uuids' => [self::PHONE], 'ssid' => 'phone', 'security' => 'wpa3', 'hidden' => true,
             'priority' => 9, 'devices' => []],
        ];
        $this->wifi->others = [['name' => 'netplan-wlan0-guest', 'ssid' => 'guest', 'why' => 'netplan']];
        $this->app->instance(WifiNetworks::class, $this->wifi);
        $this->actingAs(User::factory()->create(['name' => 'anna']));
    }

    protected function confirmed(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    /** the network password is nowhere in what the component sends back: snapshot and HTML */
    protected function assertPasswordNeverSent($component, string $password): void
    {
        $this->assertStringNotContainsString($password, json_encode($component->snapshot));
        $this->assertStringNotContainsString($password, $component->html());
    }

    public function test_the_settings_page_has_the_section()
    {
        $this->get('/settings?tab=network')->assertOk()->assertSee('Wi-Fi networks');
    }

    public function test_networks_show_in_order_with_their_state_and_foreign_profiles_greyed()
    {
        Livewire::test(WifiSettings::class)
            ->assertSeeInOrder(['78', 'phone', 'guest'])
            ->assertSee('in use · M.2 card (wlan1)')
            ->assertSee('WPA3-Personal')
            ->assertSee('managed outside cmprovision (netplan)')
            ->assertSeeHtml('78 is in use: connect another network first');
    }

    public static function changes(): array
    {
        return [
            'save' => ['save', [self::SECRET]],
            'save and check' => ['saveAndCheck', [self::SECRET]],
            'delete' => ['delete', [self::PHONE]],
            'check now' => ['checkNow', [self::M2]],
            'move up' => ['moveUp', [self::PHONE]],
            'move down' => ['moveDown', [self::M2]],
        ];
    }

    #[DataProvider('changes')]
    public function test_nothing_changes_without_the_account_password($method, $arguments)
    {
        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa3')
            ->call($method, ...$arguments)
            ->assertForbidden();

        $this->assertSame([], $this->wifi->calls);
    }

    public function test_a_new_network_goes_to_the_helper_once_and_its_password_never_comes_back()
    {
        $this->confirmed();

        $component = Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa3')->set('hidden', true)
            ->call('save', self::SECRET)
            ->assertHasNoErrors()
            ->assertSet('form', false)
            ->assertSee('Saved lab.');

        $this->assertSame([['save', ['uuid' => null, 'ssid' => 'lab', 'security' => 'wpa3', 'hidden' => true, 'password' => self::SECRET], 'anna']],
            $this->wifi->calls);
        $this->assertPasswordNeverSent($component, self::SECRET);
    }

    public function test_after_an_error_the_password_is_not_sent_back_either()
    {
        $this->confirmed();
        $this->wifi->answers['save'] = ['uuid' => null, 'error' => 'NetworkManager did not add the network: refused'];

        $component = Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa2')
            ->call('save', self::SECRET)
            ->assertSee('NetworkManager did not add the network: refused')
            ->assertSet('form', true);

        $this->assertPasswordNeverSent($component, self::SECRET);
    }

    public function test_editing_keeps_an_empty_password_and_tells_when_the_network_is_in_use()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('edit', self::ONBOARD)
            ->assertSet('ssid', '78')->assertSet('security', 'wpa2')
            ->call('save', '')
            ->assertHasNoErrors()
            ->assertSee('Saved 78: the network in use takes it at its next connection.');

        $this->assertSame(['uuid' => self::M2, 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false, 'password' => ''],
            $this->wifi->calls[0][1]);
    }

    public static function badInput(): array
    {
        return [
            'a password of 7' => ['wpa2', 'office', '1234567', 'password'],
            'a 64-digit key for WPA3' => ['wpa3', 'office', str_repeat('ab', 32), 'password'],
            'a password outside ASCII' => ['wpa2', 'office', 'пароль-длинный', 'password'],
            'no password for a new network' => ['wpa2', 'office', '', 'password'],
            'an SSID of 33 bytes' => ['wpa2', str_repeat('a', 33), 'office-pass', 'ssid'],
            'an SSID with a tab' => ['wpa2', "off\tice", 'office-pass', 'ssid'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_the_form_checks_what_the_helper_checks($security, $ssid, $password, $field)
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', $ssid)->set('security', $security)
            ->call('save', $password)
            ->assertHasErrors([$field]);

        $this->assertSame([], $this->wifi->calls);
    }

    public function test_a_64_digit_key_is_fine_for_wpa2()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'office')->set('security', 'wpa2')
            ->call('save', str_repeat('ab', 32))
            ->assertHasNoErrors();

        $this->assertCount(1, $this->wifi->calls);
    }

    public function test_check_now_shows_the_result_in_the_row()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('checkNow', self::ONBOARD)
            ->assertSee('connected, 192.168.68.236');

        $this->assertSame([['check', self::M2, 'anna']], $this->wifi->calls);
    }

    public function test_save_and_check_checks_the_saved_network()
    {
        $this->confirmed();
        $this->wifi->answers['save'] = ['uuid' => self::PHONE, 'error' => null];   // the list of the fake does not grow

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'mixed')
            ->call('saveAndCheck', self::SECRET);

        $this->assertSame(['save', 'check'], array_column($this->wifi->calls, 0));
    }

    public function test_check_now_waits_for_an_idle_built_in_wifi()
    {
        $this->wifi->blocker = 'the built-in Wi-Fi carries the traffic';

        Livewire::test(WifiSettings::class)
            ->assertSee('Check now is not available: the built-in Wi-Fi carries the traffic.')
            ->assertSeeHtml('disabled title="Check now: the built-in Wi-Fi carries the traffic"');
    }

    public function test_delete_and_order_go_to_the_helper()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('delete', self::PHONE)
            ->assertSee('Removed phone.')
            ->call('moveUp', self::PHONE)
            ->assertSee('Order saved');

        $this->assertSame([['delete', self::PHONE, 'anna'], ['order', [self::PHONE, self::M2], 'anna']], $this->wifi->calls);
    }

    public function test_find_networks_fills_the_name_and_the_security()
    {
        $this->wifi->scanned = [['ssid' => 'Service PSK', 'signal' => 80, 'security' => 'mixed', 'bands' => ['2.4 GHz']],
                                ['ssid' => 'T-NET', 'signal' => 92, 'security' => 'enterprise', 'bands' => ['5 GHz']]];

        Livewire::test(WifiSettings::class)
            ->call('add')
            ->call('findNetworks')
            ->assertSee('Service PSK')
            ->assertSee('Enterprise (802.1X): not supported')
            ->call('pick', 0)
            ->assertSet('ssid', 'Service PSK')
            ->assertSet('security', 'mixed');
    }

    public function test_where_networkmanager_does_not_run_the_wifi_the_section_says_so()
    {
        $this->wifi->available = false;

        Livewire::test(WifiSettings::class)
            ->assertSee('Wi-Fi is not managed by NetworkManager here')
            ->assertDontSee('Add network');
    }

    public function test_the_changes_log_opens()
    {
        Livewire::test(WifiSettings::class)
            ->call('showLog')
            ->assertSee("anna saved '78' (WPA2)");
    }

    public function test_a_network_gone_from_the_list_is_said_so()
    {
        // removed in another tab, or nmcli did not answer in time: the page says so instead of doing nothing
        $this->confirmed();

        foreach (['edit', 'delete', 'checkNow', 'moveUp'] as $method)
            Livewire::test(WifiSettings::class)
                ->call($method, '0b1c2d3e-0000-4000-8000-00000000dead')
                ->assertSee('This network is no longer in the list: reload the page.');

        $this->assertSame([], $this->wifi->calls);
    }

    public function test_find_networks_says_why_it_found_nothing()
    {
        $this->wifi->scanError = 'another Wi-Fi change is running: try again';

        Livewire::test(WifiSettings::class)
            ->call('add')
            ->call('findNetworks')
            ->assertSee('another Wi-Fi change is running: try again');
    }
}
