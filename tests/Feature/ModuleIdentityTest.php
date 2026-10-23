<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Http\Livewire\Cms;
use App\Http\Livewire\ProvisioningLog;
use App\Http\Livewire\ProvisioningStatus;
use App\Http\Livewire\ReprovisionDialog;
use App\Http\Livewire\UsbDiagnostics;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\UsbDiagnosticReport;
use App\Support\ModuleDiagnostics;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** 2.10: a module is known by its MAC address and, once the new system has one, its host name; the serial number is
 *  the small print (the card, the CMs list's last column, the JSON of a webhook), never the name of a module. */
class ModuleIdentityTest extends TestCase
{
    use RefreshDatabase;

    const MAC = 'e4:5f:01:62:9f:d3';
    const SERIAL = '10000000d3629fab';

    protected function cm(array $extra = []): Cm
    {
        return Cm::create(array_merge(['serial' => self::SERIAL, 'mac' => self::MAC, 'phase' => 'write',
            'provisioning_started_at' => now(), 'phase_started_at' => now()], $extra));
    }

    public function test_a_module_is_named_by_its_host_name_or_else_by_its_mac_and_only_in_the_last_resort_by_its_serial()
    {
        $this->assertSame(self::MAC, $this->cm()->displayName());
        $this->assertSame('wlanpi-sensor1', $this->cm(['serial' => '1000000000000002', 'mac' => 'e4:5f:01:00:00:02', 'hostname' => 'wlanpi-sensor1'])->displayName());
        $this->assertSame('e4:5f:01:00:00:03', $this->cm(['serial' => '1000000000000003', 'mac' => 'e4:5f:01:00:00:03', 'hostname' => '  '])->displayName());
        $this->assertSame('1000000000000004', (new Cm(['serial' => '1000000000000004', 'mac' => '']))->displayName());
    }

    public function test_the_cms_list_leads_with_the_mac_then_the_host_name_and_keeps_the_serial_for_last()
    {
        $this->cm(['hostname' => 'wlanpi-sensor1']);

        $html = Livewire::test(Cms::class)->html();
        $this->assertMatchesRegularExpression('~<th>MAC</th>\s*<th>Host name</th>\s*<th>Serial</th>~', $html);
        $this->assertMatchesRegularExpression('~<a href="[^"]*/cms/'.self::SERIAL.'"[^>]*>'.self::MAC.'</a>~', $html, 'the MAC is the link to the card');
        $this->assertStringContainsString('Delete CM wlanpi-sensor1?', $html, 'the host name when there is one, else the MAC');
        $this->assertStringContainsString('MAC, host name or serial', $html);
    }

    public function test_the_dashboard_names_a_module_by_its_mac_with_the_host_name_under_it_and_has_no_serial_column()
    {
        $this->cm(['hostname' => 'wlanpi-sensor1']);

        $html = Livewire::test(ProvisioningStatus::class)->html();
        $this->assertStringNotContainsString('<th>Serial</th>', $html);
        $this->assertMatchesRegularExpression('~<a href="[^"]*/cms/'.self::SERIAL.'"[^>]*>'.self::MAC.'</a>~', $html);
        $this->assertStringContainsString('wlanpi-sensor1', $html);
        $this->assertStringNotContainsString('>'.self::SERIAL.'<', $html);
    }

    public function test_the_card_is_titled_by_the_name_of_the_module_and_shows_the_serial_as_a_row()
    {
        $cm = $this->cm();
        $this->actingAs(User::factory()->create());
        $this->get(route('cm', $cm->serial))->assertOk()->assertSee('<title>'.self::MAC, false);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('Serial')->assertSee(self::SERIAL);

        $cm->update(['hostname' => 'wlanpi-sensor1']);
        $this->get(route('cm', $cm->serial))->assertSee('<title>wlanpi-sensor1', false);
    }

    public function test_the_log_on_the_dashboard_shows_the_mac_of_the_module_and_keeps_the_card_link()
    {
        $this->cm();
        Cmlog::create(['cm' => self::SERIAL, 'board' => 'sw01 Gi0/1', 'loglevel' => 'info', 'msg' => 'Module started']);
        Cmlog::create(['cm' => '1000000000009999', 'board' => '', 'loglevel' => 'info', 'msg' => 'A module that was deleted']);

        $html = Livewire::test(ProvisioningLog::class)->html();
        $this->assertMatchesRegularExpression('~<a href="[^"]*/cms/'.self::SERIAL.'"[^>]*>'.self::MAC.'</a>~', $html);
        $this->assertStringContainsString('1000000000009999', $html, 'the serial of a module that is not there any more is all there is');
    }

    public function test_the_usb_report_list_leads_with_the_mac_when_the_module_reported_one()
    {
        $report = ModuleDiagnostics::parse("CMPROVISION_DIAGNOSTICS_V1\ncontext=discovery\nserial=".self::SERIAL."\ntarget=/dev/mmcblk0\nblock=missing\ncontroller=present\nthrottled=0x0\n");
        UsbDiagnosticReport::create(['serial' => self::SERIAL, 'boot_id' => 'boot', 'event_id' => 'event', 'mac' => self::MAC,
            'usb_path' => '1-1', 'report' => $report, 'received_at' => now()]);
        UsbDiagnosticReport::create(['serial' => '1000000000000005', 'boot_id' => 'boot', 'event_id' => 'event2', 'mac' => null,
            'usb_path' => '1-2', 'report' => $report, 'received_at' => now()->subMinute()]);

        $html = Livewire::test(UsbDiagnostics::class)->html();
        $this->assertMatchesRegularExpression('~<th>Ethernet MAC</th>\s*<th>Serial</th>~', $html);
        $this->assertStringContainsString('not reported', $html);
        $this->assertStringContainsString('1000000000000005', $html);
    }

    public function test_the_reprovision_dialog_names_the_module_by_host_name_or_mac()
    {
        $cm = $this->cm();
        $this->actingAs(User::factory()->create());
        $html = Livewire::test(ReprovisionDialog::class, ['serial' => $cm->serial])->call('begin')->html();
        $this->assertStringContainsString('Re-provision '.self::MAC.'</h2>', $html);
        $this->assertMatchesRegularExpression('~MAC '.self::MAC.' · Serial '.self::SERIAL.'~', $html);

        $cm->update(['hostname' => 'wlanpi-sensor1']);
        $this->assertStringContainsString('Re-provision wlanpi-sensor1</h2>', Livewire::test(ReprovisionDialog::class, ['serial' => $cm->serial])->call('begin')->html());
    }

    public function test_a_message_to_a_chat_starts_with_the_mac_or_host_name_and_the_serial_is_for_the_webhook()
    {
        $cm = $this->cm(['provisioning_board' => 'sw01 Gi0/1']);
        $notifier = app(Notifier::class);

        $this->assertSame(self::MAC.' · board sw01 Gi0/1 · started', $notifier->moduleText($cm, 'started'));
        $cm->update(['hostname' => 'wlanpi-sensor1']);
        $this->assertSame('wlanpi-sensor1 · '.self::MAC.' · board sw01 Gi0/1 · started', $notifier->moduleText($cm->fresh(), 'started'));
        $this->assertSame(self::SERIAL, (new \ReflectionMethod($notifier, 'payload'))->invoke($notifier, $cm->fresh(), 'started', null, 'x')['serial']);
    }
}
