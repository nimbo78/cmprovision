<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The utility OS a module started with (2.7.0): ours sends uos=<version>, the legacy one nothing; the card and the
    start log line name it */
class UtilityOsModuleTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=Raspberry+Pi+Compute+Module+4+Rev+1.1&storagesize=61071360&mac=e4:5f:01:62:9f:d3&bootmode=2';

    protected function setUp(): void
    {
        parent::setUp();
        $project = Project::create(['name' => 'no image', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
    }

    protected function startLine(): string
    {
        return (string) Cmlog::where('cm', 'e4:5f:01:62:9f:d3')->where('msg', 'like', 'Provisioning started%')->latest('id')->value('msg');
    }

    public function test_ours_names_its_version()
    {
        $this->get(self::START.'&uos=2026.10')->assertOk();

        $this->assertSame('2026.10', Cm::where('serial', self::SERIAL)->value('utility_os'));
        $this->assertStringStartsWith('Provisioning started. Utility OS: 2026.10.', $this->startLine());
    }

    public function test_the_legacy_one_sends_nothing()
    {
        $this->get(self::START)->assertOk();

        $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'));
        $this->assertStringStartsWith('Provisioning started. Utility OS: legacy.', $this->startLine());
    }

    public function test_a_start_with_the_legacy_one_forgets_the_last_version()
    {
        $this->get(self::START.'&uos=2026.10')->assertOk();
        $this->get(self::START)->assertOk();

        $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'));
    }

    public function test_only_a_plain_version_is_taken()
    {
        foreach (['<b>1</b>', str_repeat('9', 33), '2026.10 rc', ''] as $bad)
        {
            $this->get(self::START.'&uos='.rawurlencode($bad))->assertOk();
            $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'), "'$bad'");
        }
    }

    public function test_an_array_version_does_not_interrupt_provisioning()
    {
        $this->get(self::START.'&uos[]=2026.10')->assertOk();

        $this->assertNotNull(Cm::where('serial', self::SERIAL)->first());
        $this->assertNull(Cm::where('serial', self::SERIAL)->value('utility_os'));
        $this->assertStringStartsWith('Provisioning started. Utility OS: legacy.', $this->startLine());
    }

    public function test_all_plain_version_boundaries_are_preserved()
    {
        foreach (['0', '2026.10-rc1', str_repeat('a', 32)] as $version)
        {
            $this->get(self::START.'&uos='.$version)->assertOk();
            $this->assertSame($version, Cm::where('serial', self::SERIAL)->value('utility_os'));
            $this->assertStringStartsWith('Provisioning started. Utility OS: '.$version.'.', $this->startLine());
        }
    }

    public function test_the_card_names_it()
    {
        $this->actingAs(User::factory()->create());
        $this->get(self::START.'&uos=2026.10')->assertOk();
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->assertSeeInOrder(['Utility OS', '2026.10']);

        $this->get(self::START)->assertOk();
        Livewire::test(CmCard::class, ['serial' => self::SERIAL])->assertSeeInOrder(['Utility OS', 'legacy']);
    }
}
