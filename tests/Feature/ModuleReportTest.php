<?php
namespace Tests\Feature;

use App\Models\Cm;
use App\Models\UsbDiagnosticReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleReportTest extends TestCase
{
    use RefreshDatabase;
    private function module(): Cm
    {
        return Cm::create(['serial'=>'10000000e611110c','mac'=>'e4:5f:01:00:00:01',
            'hostname'=>'<script>alert("host")</script>', 'model'=>'Raspberry Pi Compute Module 4 Rev 1.1',
            'memory_in_gb'=>4, 'phase'=>'done', 'image_filename'=>'sensor.img.gz',
            'provisioning_started_at'=>now()->subMinutes(5), 'provisioning_complete_at'=>now(),
            'eeprom_before'=>'2026/09/23 12:02:14', 'firmware'=>'pieeprom-2026-09-23.bin',
            'pre_script_output'=>'credential-sentinel', 'report_token'=>'credential-sentinel',
            'eeprom_config_after'=>'PASSWORD=credential-sentinel',
            'timeline'=>[['phase'=>'write','detail'=>'credential-sentinel','at'=>now()->subMinutes(5)->timestamp],
                ['phase'=>'done','detail'=>null,'at'=>now()->timestamp]],
            'storage_diagnostics'=>['version'=>1,'context'=>'complete','facts'=>['serial'=>'10000000e611110c','kernel'=>'6.18.60','password'=>'credential-sentinel'],
                'sections'=>['kernel'=>'credential-sentinel']], 'storage_diagnostics_at'=>now()]);
    }
    public function test_guests_cannot_download_reports_and_unknown_formats_are_rejected(): void
    {
        $cm=$this->module();
        $this->get('/cms/'.$cm->mac.'/report/html')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/cms/'.$cm->mac.'/report/php')->assertNotFound();
    }
    public function test_json_is_a_bounded_whitelist_of_recorded_evidence_without_credentials(): void
    {
        $cm=$this->module();
        $response=$this->actingAs(User::factory()->create())->get('/cms/'.$cm->mac.'/report/json')->assertOk();
        $response->assertJsonPath('schema',1)->assertJsonPath('identity.serial',$cm->serial)
            ->assertJsonPath('run.result','done')->assertJsonPath('hardware.ram','4 GiB')
            ->assertJsonPath('eeprom.before','2026-09-23');
        $this->assertStringNotContainsString('credential-sentinel',$response->getContent());
        $this->assertLessThan(131072,strlen($response->getContent()));
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;',$response->headers->get('Content-Disposition'));
    }
    public function test_standalone_html_escapes_untrusted_names_and_works_without_diagnostics(): void
    {
        $cm=$this->module(); $cm->storage_diagnostics=null; $cm->save();
        $response=$this->actingAs(User::factory()->create())->get('/cms/'.$cm->mac.'/report/html')->assertOk();
        $response->assertSee('Device report')->assertSee('sensor.img.gz')->assertSee('No diagnostic snapshot');
        $this->assertStringContainsString('&lt;script&gt;',$response->getContent());
        $this->assertStringNotContainsString('<script>',$response->getContent());
        $this->assertStringNotContainsString('credential-sentinel',$response->getContent());
        $this->assertStringContainsString("default-src 'none'",$response->headers->get('Content-Security-Policy'));
    }
    public function test_usb_report_before_registration_does_not_claim_a_successful_run(): void
    {
        $row=UsbDiagnosticReport::create(['serial'=>'1000000000000001','mac'=>'e4:5f:01:00:00:02','usb_path'=>'1-2',
            'event_id'=>'12345678-1234-1234-1234-123456789012','boot_id'=>'12345678-1234-1234-1234-123456789013',
            'received_at'=>now()->subHour(), 'report'=>['version'=>1,'context'=>'discovery',
                'facts'=>['serial'=>'1000000000000001','model'=>'Raspberry Pi Compute Module 4 Rev 1.0'], 'sections'=>[]]]);
        $response=$this->actingAs(User::factory()->create())->get('/diagnostics/'.$row->id.'/report/json')->assertOk();
        $response->assertJsonPath('kind','usb-observation')->assertJsonPath('run',null)
            ->assertJsonPath('diagnostics.0.channel','usb');
        $this->assertNotNull($response->json('diagnostics.0.received_at'));
    }

    public function test_usb_preparation_on_a_registered_module_is_not_presented_as_image_provisioning(): void
    {
        $cm=$this->module(); $cm->usb_prep_at=now(); $cm->image_filename=null; $cm->save();
        $this->actingAs(User::factory()->create())->get('/cms/'.$cm->mac.'/report/json')->assertOk()
            ->assertJsonPath('run.operation','usb-prep')->assertJsonPath('run.image_provisioned',false);
        $this->get('/cms/'.$cm->mac.'/report/html')->assertOk()->assertSee('Bootloader preparation only')
            ->assertSee('Image not provisioned');
    }
}
