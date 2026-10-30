<?php

namespace Tests\Feature;

use App\Http\Livewire\Projects;
use App\Models\{Cm,Image,Project,Setting,User};
use App\Services\Sensor\ModuleMark;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** The provisioner's mark over LLDP (2.23.0): the re-provision agent leaves a service in the written system that names the
    station to the switch for the project's first boots, with a code of the module's own key; a station with the same mark
    key checks it (ModuleMark) */
class ModuleMarkTest extends TestCase
{
    use RefreshDatabase;

    const KEY = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';
    const MAC = 'e4:5f:01:62:9f:d3';

    protected function marks(): ModuleMark
    {
        return app(ModuleMark::class);
    }

    /** a neighbour (Neighbors) whose port description is the mark of boot $boot of $boots, made with $key */
    protected function says(string $key, int $boot = 3, int $boots = 20, string $mac = self::MAC, string $station = 'wlanpi-da2'): array
    {
        $module = hash_hmac('sha256', $mac, hex2bin($key));
        return ['protocol' => 'lldp', 'name' => 'wlanpi-fd3', 'description' => 'Debian GNU/Linux 13 (trixie)', 'platform' => '',
                'port' => "cmprovision mark $station $boot/$boots ".ModuleMark::code($module, $station, $boot, $boots),
                'chassis' => '', 'mac' => $mac, 'port_mac' => $mac, 'address' => '172.20.108.222', 'capabilities' => []];
    }

    public function test_a_generated_key_is_kept_encrypted_and_known_by_its_fingerprint()
    {
        $this->assertNull($this->marks()->key());
        $key = $this->marks()->generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
        $this->assertSame($key, app(ModuleMark::class)->key());
        $this->assertStringNotContainsString($key, (string) Setting::find(ModuleMark::SETTING)->value, 'encrypted with the APP_KEY');
        $this->assertSame(substr(hash('sha256', $key), 0, 12), $this->marks()->fingerprint());
        $this->assertNotSame($key, $this->marks()->generate(), 'a new key each time');
    }

    public function test_the_key_of_another_station_is_taken_only_when_it_is_one()
    {
        foreach (['', 'secret', substr(self::KEY, 1), self::KEY.'00', str_repeat('g', 64)] as $bad)
            try
            {
                $this->marks()->set($bad);
                $this->fail("took $bad");
            }
            catch (\InvalidArgumentException $e)
            {
                $this->assertNull($this->marks()->key());
            }
        $this->marks()->set(' '.strtoupper(self::KEY)."\n");
        $this->assertSame(self::KEY, $this->marks()->key());
    }

    public function test_the_module_key_is_its_mac_signed_with_the_mark_key()
    {
        $this->assertNull($this->marks()->moduleKey(self::MAC), 'no mark key, no module key');
        $this->marks()->set(self::KEY);
        $expected = hash_hmac('sha256', self::MAC, hex2bin(self::KEY));
        $this->assertSame($expected, $this->marks()->moduleKey('E4-5F-01-62-9F-D3'), 'however the MAC is written');
    }

    public function test_a_mark_is_read_only_in_its_own_form()
    {
        $this->assertSame(['station' => 'wlanpi-da2', 'boot' => 3, 'boots' => 20, 'code' => '0123456789abcdef'],
            ModuleMark::parse('cmprovision mark wlanpi-da2 3/20 0123456789abcdef'));
        foreach (['eth0', '', null, 'cmprovision mark wlanpi-da2 21/20 0123456789abcdef', 'cmprovision mark wlanpi-da2 0/20 0123456789abcdef',
                  'cmprovision mark wlanpi-da2 3/20 0123456789ABCDEF', 'cmprovision mark wlan pi 3/20 0123456789abcdef',
                  'cmprovision 2.23.0 station', 'x cmprovision mark wlanpi-da2 3/20 0123456789abcdef'] as $text)
            $this->assertNull(ModuleMark::parse($text), (string) $text);
    }

    public function test_a_station_with_the_same_key_knows_the_mark_for_genuine()
    {
        $this->marks()->set(self::KEY);
        $this->assertSame(['station' => 'wlanpi-da2', 'boot' => 3, 'boots' => 20, 'verified' => true], $this->marks()->check($this->says(self::KEY)));

        $other = str_repeat('ab', 32);
        $this->assertFalse($this->marks()->check($this->says($other))['verified'], 'another key');
        $copied = ['mac' => '2c:cf:67:00:00:01', 'port_mac' => '2c:cf:67:00:00:01'] + $this->says(self::KEY);
        $this->assertFalse($this->marks()->check($copied)['verified'], 'the text of one module on another');
        $edited = $this->says(self::KEY);
        $edited['port'] = str_replace('3/20', '3/90', $edited['port']);
        $this->assertFalse($this->marks()->check($edited)['verified'], 'more boots written in by hand');
        $this->assertNull($this->marks()->check(['port' => 'eth0', 'mac' => self::MAC]));
    }

    public function test_a_station_without_a_key_shows_the_mark_unchecked()
    {
        $this->assertNull($this->marks()->check($this->says(self::KEY))['verified']);
    }

    public function test_the_station_is_named_by_its_host_name()
    {
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', ModuleMark::station());
    }

    public function test_the_command_makes_shows_and_takes_a_key()
    {
        $this->artisan('sensor:mark-key')->expectsOutputToContain('No mark key')->assertExitCode(0);
        $this->artisan('sensor:mark-key', ['--generate' => true])->expectsOutputToContain('Fingerprint')->assertExitCode(0);
        $key = $this->marks()->key();
        $this->artisan('sensor:mark-key', ['--show' => true])->expectsOutput($key)->assertExitCode(0);

        $file = tempnam(sys_get_temp_dir(), 'mark');
        file_put_contents($file, self::KEY."\n");
        $this->artisan('sensor:mark-key', ['--from' => $file])->expectsOutputToContain(substr(hash('sha256', self::KEY), 0, 12))->assertExitCode(0);
        $this->assertSame(self::KEY, $this->marks()->key());
        file_put_contents($file, "nonsense\n");
        $this->artisan('sensor:mark-key', ['--from' => $file])->assertExitCode(1);
        $this->assertSame(self::KEY, $this->marks()->key(), 'a bad key changes nothing');
        unlink($file);
    }

    protected function project(int $boots): Project
    {
        $image = Image::forceCreate(['filename' => 'test.img.gz', 'filename_extension' => 'gz', 'filename_on_server' => 'test.img.gz',
            'sha256' => str_repeat('a', 64), 'uncompressed_size' => 8388608]);
        $project = Project::create(['name' => 'Mark test', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
            'label_moment' => 'never', 'reprovision' => true, 'mark_boots' => $boots]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    public function test_the_project_keeps_its_boots_in_the_form_and_the_api()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $project = $this->project(0);
        Livewire::test(Projects::class)->call('edit', $project->id)->set('mark_boots', 20)->call('store')->assertHasNoErrors();
        $this->assertSame(20, $project->fresh()->mark_boots);
        Livewire::test(Projects::class)->call('edit', $project->id)->set('mark_boots', 101)->call('store')->assertHasErrors('mark_boots');
        Sanctum::actingAs($user, ['update']);
        $this->patchJson('/api/projects/'.$project->id, ['mark_boots' => 5])->assertOk()->assertJsonPath('mark_boots', 5);
        $this->patchJson('/api/projects/'.$project->id, ['mark_boots' => -1])->assertStatus(422);
    }

    public function test_the_module_script_installs_the_mark_and_fetches_its_key_without_writing_it_down()
    {
        $this->project(20);
        $this->marks()->set(self::KEY);
        $script = $this->get('/scriptexecute?serial=10000000abcdef01&mac='.self::MAC.'&storagesize=65536')->assertOk()->getContent();

        $this->assertStringContainsString("printf 'STATION=%s\\nBOOTS=%s\\n' '".ModuleMark::station()."' 20 >", $script);
        $this->assertStringContainsString('/scriptexecute?serial=10000000abcdef01&markkey=1', $script);
        $this->assertStringNotContainsString($this->marks()->moduleKey(self::MAC), $script, 'sh -v copies every line into the log');
        $this->assertStringNotContainsString(self::KEY, $script);
    }

    public function test_without_a_mark_key_the_script_says_why_there_is_no_mark()
    {
        $this->project(20);
        $script = $this->get('/scriptexecute?serial=10000000abcdef01&mac='.self::MAC.'&storagesize=65536')->assertOk()->getContent();
        $this->assertStringContainsString('mark skipped: no mark key on the provisioner', $script);
        $this->assertStringNotContainsString('markkey=1', $script);
    }

    public function test_a_project_without_boots_has_no_mark()
    {
        $this->project(0);
        $this->marks()->set(self::KEY);
        $script = $this->get('/scriptexecute?serial=10000000abcdef01&mac='.self::MAC.'&storagesize=65536')->assertOk()->getContent();
        $this->assertStringContainsString('Re-provision agent', $script);
        foreach (['markkey', 'cmprovision-mark', 'mark skipped'] as $text)
            $this->assertStringNotContainsString($text, $script);
    }

    public function test_the_module_gets_its_key_only_while_its_run_installs_the_mark()
    {
        $project = $this->project(20);
        $this->marks()->set(self::KEY);
        $cm = Cm::create(['serial' => '10000000abcdef01', 'mac' => self::MAC, 'phase' => 'postinstall', 'project_id' => $project->id,
                          'provisioning_started_at' => now()]);
        $answer = $this->get('/scriptexecute?serial=10000000abcdef01&markkey=1')->assertOk();
        $this->assertSame(hash_hmac('sha256', self::MAC, hex2bin(self::KEY))."\n", $answer->getContent());
        $this->assertStringStartsWith('text/plain', $answer->headers->get('Content-Type'));

        foreach (['done', 'write', 'failed'] as $phase)
        {
            $cm->forceFill(['phase' => $phase])->save();
            $this->get('/scriptexecute?serial=10000000abcdef01&markkey=1')->assertNotFound();
        }
        $cm->forceFill(['phase' => 'postinstall'])->save();
        $project->update(['mark_boots' => 0]);
        $this->get('/scriptexecute?serial=10000000abcdef01&markkey=1')->assertNotFound();
        $this->get('/scriptexecute?serial=20000000abcdef02&markkey=1')->assertNotFound();
        $this->assertSame(0, \App\Models\Cmlog::count(), 'nothing of it in the log');
    }
}
