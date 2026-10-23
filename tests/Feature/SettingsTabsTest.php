<?php

namespace Tests\Feature;

use App\Http\Livewire\NotificationSettings;
use App\Http\Livewire\ReprovisionAccounts;
use App\Http\Livewire\ReprovisionKey;
use App\Http\Livewire\Settings;
use App\Http\Livewire\SwitchSettings;
use App\Http\Livewire\UtilityOsSettings;
use App\Http\Livewire\WallboardSettings;
use App\Http\Livewire\WifiSettings;
use App\Models\User;
use App\Services\ServiceState;
use App\Services\WifiNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeServiceState;
use Tests\Support\FakeWifiNetworks;
use Tests\TestCase;

/** Settings (2.9): four tabs by purpose, each at its own address, and a strip with the state of the services on all but the first */
class SettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    const ALL_UNITS = ['cmprovision-dnsmasq', 'cmprovision-rpiboot', 'cmprovision-usb-diagnostics', 'cmprovision-queue',
        'cmprovision-queue-images', 'cmprovision-uplink.timer'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->services(self::ALL_UNITS);
        $this->app->instance(WifiNetworks::class, new FakeWifiNetworks);   // NetworkManager is there, with no networks yet
    }

    protected function services(array $active): void
    {
        $this->app->instance(ServiceState::class, new FakeServiceState($active));
    }

    protected function tab(?string $tab)
    {
        return Livewire::withQueryParams($tab === null ? [] : ['tab' => $tab])->test(Settings::class);
    }

    public function test_the_page_opens_on_status_with_the_services_and_nothing_of_the_other_tabs()
    {
        $this->tab(null)
            ->assertSet('tab', 'status')
            ->assertSee('dnsmasq (DHCP server)')
            ->assertSee('web interface')
            ->assertDontSeeLivewire(WifiSettings::class)
            ->assertDontSeeLivewire(SwitchSettings::class)
            ->assertDontSeeLivewire(NotificationSettings::class)
            ->assertDontSeeLivewire(UtilityOsSettings::class)
            ->assertDontSeeLivewire(WallboardSettings::class);
    }

    public function test_status_has_no_strip_because_it_shows_the_same_services_in_full()
    {
        $this->tab('status')->assertDontSeeHtml('id="settings-health"');
    }

    #[DataProvider('otherTabs')]
    public function test_every_other_tab_has_the_strip_with_a_count_in_words(string $tab)
    {
        $this->services(['cmprovision-dnsmasq', 'cmprovision-queue']);

        $this->tab($tab)
            ->assertSeeHtml('id="settings-health"')
            ->assertSeeHtml('wire:poll.30s')
            ->assertSee('3 of 7 running')   // the web interface answers, so it always runs
            ->assertSee('stopped');
    }

    public static function otherTabs(): array
    {
        return ['network' => ['network'], 'provisioning' => ['provisioning'], 'notifications' => ['notifications']];
    }

    public function test_the_strip_says_all_running_when_they_are()
    {
        $this->tab('network')->assertSee('7 of 7 running')->assertDontSee('stopped');
    }

    public function test_the_network_tab_holds_wifi_switches_and_static_addresses()
    {
        $this->tab('network')
            ->assertSeeLivewire(WifiSettings::class)
            ->assertSeeLivewire(SwitchSettings::class)
            ->assertSee('DHCP static addresses')
            ->assertSee('Add new assignment')
            ->assertDontSeeLivewire(NotificationSettings::class)
            ->assertDontSeeLivewire(UtilityOsSettings::class)
            ->assertDontSeeHtml('id="services"');   // the Services card is the Status tab's; the strip only has the short names
    }

    public function test_the_provisioning_tab_holds_the_utility_os_reprovisioning_and_the_display()
    {
        $this->tab('provisioning')
            ->assertSeeLivewire(UtilityOsSettings::class)
            ->assertSeeLivewire(\App\Http\Livewire\UsbPrepSettings::class)
            ->assertSeeLivewire(ReprovisionKey::class)
            ->assertSeeLivewire(ReprovisionAccounts::class)
            ->assertSeeLivewire(WallboardSettings::class)
            ->assertDontSeeLivewire(WifiSettings::class)
            ->assertDontSeeLivewire(NotificationSettings::class);
    }

    public function test_the_notifications_tab_holds_the_channels_only()
    {
        $this->tab('notifications')
            ->assertSeeLivewire(NotificationSettings::class)
            ->assertDontSeeLivewire(WifiSettings::class)
            ->assertDontSeeLivewire(UtilityOsSettings::class);
    }

    public function test_an_unknown_tab_is_the_status_tab()
    {
        $this->tab('nonsense')->assertSee('dnsmasq (DHCP server)')->assertDontSeeHtml('id="settings-health"');
    }

    public function test_a_tab_that_is_not_a_name_is_the_status_tab()
    {
        // ?tab[]=x and a crafted update put an array into the property
        $this->tab(null)->set('tab', ['x'])->assertSet('tab', 'status')->assertSee('dnsmasq (DHCP server)');
        $this->actingAs(User::factory()->create());
        $this->get('/settings?tab[]=x')->assertOk()->assertSee('dnsmasq (DHCP server)');
    }

    public function test_the_logs_menu_is_a_disclosure_that_gives_the_focus_back()
    {
        $html = $this->tab('network')->html();
        $this->assertStringNotContainsString('role="menu"', $html, 'no arrow-key menu is promised');
        $this->assertStringContainsString('aria-expanded', $html);
        $this->assertStringContainsString('aria-controls="settings-logs-list"', $html);
        $this->assertStringContainsString('id="settings-logs-list"', $html);
    }

    public function test_the_tab_bar_links_to_every_tab_and_marks_the_current_one()
    {
        $page = $this->tab('network');
        foreach (['status', 'network', 'provisioning', 'notifications'] as $tab) {
            $page->assertSeeHtml('href="'.route('settings', ['tab' => $tab]).'"');
        }
        $html = $page->html();
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('~<a href="[^"]*tab=network"[^>]*aria-current="page"~', $html);
    }

    public function test_the_tab_is_in_the_address_of_the_page()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings?tab=provisioning')->assertOk()->assertSeeLivewire(UtilityOsSettings::class)->assertDontSeeLivewire(WifiSettings::class);
        $this->get('/settings')->assertOk()->assertSee('dnsmasq (DHCP server)');
    }

    public function test_a_stopped_service_says_stopped_in_the_list_and_running_ones_say_running()
    {
        $this->services(['cmprovision-dnsmasq']);

        $html = $this->tab('status')->html();
        $this->assertStringContainsString('stopped', $html);
        $this->assertStringContainsString('running', $html);
    }

    public function test_the_logs_menu_of_the_strip_opens_every_log_from_any_tab()
    {
        $page = $this->tab('provisioning');
        foreach (['Dnsmasq', 'Rpiboot', 'UsbDiagnostics', 'Queue', 'QueueImages', 'UplinkSpare', 'Laravel'] as $log) {
            $page->assertSeeHtml('wire:click="viewLog'.$log.'()"');
        }
        $page->call('viewLogRpiboot')->assertSet('logModalOpen', true)->assertSet('logSource', 'rpiboot')->call('closeModal')->assertSet('logModalOpen', false);
    }

    /** the primary (orange) button of a tab, by name: every other action of the tab is secondary */
    public static function primaryButtons(): array
    {
        return [
            'status' => ['status', []],
            'network' => ['network', ['Add network']],
            'provisioning' => ['provisioning', ['Save display settings']],
            'notifications' => ['notifications', ['Add channel']],
        ];
    }

    #[DataProvider('primaryButtons')]
    public function test_a_tab_has_one_primary_button_and_it_is_the_one_the_tab_is_for(string $tab, array $expected)
    {
        $this->actingAs(User::factory()->create());

        $html = $this->get(route('settings', ['tab' => $tab]))->assertOk()->getContent();
        $primary = [];
        preg_match_all('~<(button|a)\b([^>]*)>(.*?)</\1>~s', $html, $elements, PREG_SET_ORDER);
        foreach ($elements as [, , $attributes, $inner]) {
            if (!preg_match('~\bclass="([^"]*)"~', $attributes, $class) || str_contains($attributes, 'dusk="confirm-password-button"')) {
                continue;   // the confirm button of the password dialog is on screen only inside the dialog
            }
            $classes = preg_split('~\s+~', trim($class[1]));
            if (in_array('cmp-btn', $classes, true) && !array_intersect($classes, ['cmp-btn-secondary', 'cmp-btn-danger'])) {
                $primary[] = trim(preg_replace('~\s+~', ' ', strip_tags($inner)));
            }
        }

        $this->assertSame($expected, $primary, "the primary buttons of the $tab tab");
    }

    public function test_the_web_interface_row_has_its_log_and_the_utility_os_row_none()
    {
        $this->tab('status')->assertSee('utility OS:')->assertSeeHtml('wire:click="viewLogLaravel()"');
    }
}
