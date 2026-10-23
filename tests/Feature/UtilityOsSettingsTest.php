<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use App\Http\Livewire\UtilityOsSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\UtilityOsTree;
use Tests\TestCase;

/** Settings → Utility OS (2.7.0): both OSes of the package, the one modules get, the switch and who made it */
class UtilityOsSettingsTest extends TestCase
{
    use RefreshDatabase, UtilityOsTree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeTree();
        $this->actingAs(User::factory()->create(['name' => 'igor']));
    }

    public function test_the_section_lists_both_and_marks_the_one_modules_get()
    {
        Livewire::test(UtilityOsSettings::class)
            ->assertSeeInOrder(['2026.10', '64-bit', 'Linux 6.18.50', '2026-10-17', 'Serve this one',
                                'legacy', '32-bit', 'Linux 5.4', '2022-11', 'modules get this one'])
            ->assertDontSee('dnsmasq serves');
    }

    public function test_serving_the_other_one_switches_the_link_and_logs_who_did_it()
    {
        Log::shouldReceive('info')->once()
            ->with('Utility OS: modules now get 2026.10 (64-bit) (scriptexecute64, before: scriptexecute) by igor');

        Livewire::test(UtilityOsSettings::class)
            ->call('serve', 'scriptexecute64')
            ->assertSee('Modules that boot from now on get 2026.10 (64-bit).')
            ->assertSeeInOrder(['2026.10', 'modules get this one', 'legacy', 'Serve this one']);

        $this->assertSame('../scriptexecute64', readlink($this->tree.'/etc/boot'));
    }

    public function test_a_directory_outside_the_package_is_refused()
    {
        Livewire::test(UtilityOsSettings::class)
            ->call('serve', '../../etc')
            ->assertSee('Could not switch: ../../etc is not a utility OS of the package');

        $this->assertSame('../scriptexecute', readlink($this->tree.'/etc/boot'));
    }

    public function test_a_guest_cannot_change_the_utility_os()
    {
        auth()->logout();

        Livewire::test(UtilityOsSettings::class)
            ->call('serve', 'scriptexecute64')
            ->assertForbidden();

        $this->assertSame('../scriptexecute', readlink($this->tree.'/etc/boot'));
    }

    public function test_the_section_warns_when_dnsmasq_does_not_follow_the_link()
    {
        file_put_contents($this->tree.'/etc/dnsmasq.conf', "enable-tftp\ntftp-root={$this->tree}/scriptexecute\n");

        Livewire::test(UtilityOsSettings::class)
            ->assertSee("dnsmasq serves {$this->tree}/scriptexecute (tftp-root in etc/dnsmasq.conf), so this switch does not change what modules get over the network.")
            ->assertSee("Set tftp-root={$this->tree}/etc/boot there and restart dnsmasq.");
    }

    public function test_no_warning_when_dnsmasq_conf_cannot_be_read_and_none_served_is_said()
    {
        unlink($this->tree.'/etc/dnsmasq.conf');
        unlink($this->tree.'/etc/boot');

        Livewire::test(UtilityOsSettings::class)
            ->assertDontSee('dnsmasq serves')
            ->assertSee("The link {$this->tree}/etc/boot points at no utility OS of the package: modules get nothing. Choose one.");
    }

    public function test_the_settings_page_has_the_section_and_the_service_status_row()
    {
        $this->get(route('settings', ['tab' => 'provisioning']))->assertOk()
            ->assertSee('Utility OS')
            ->assertSeeLivewire('utility-os-settings');
        Livewire::test(Settings::class)->assertSee('utility OS: legacy (32-bit)');
    }
}
