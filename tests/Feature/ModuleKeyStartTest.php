<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The start of a module finds it by its MAC; the card is at the address of its MAC and still at the old one of its serial */
class ModuleKeyStartTest extends TestCase
{
    use RefreshDatabase;

    const MAC = 'e4:5f:01:62:9f:d3';
    const SERIAL = '10000000d3629fab';

    protected function start(string $serial, ?string $mac)
    {
        return $this->get('/scriptexecute?serial='.$serial.'&model=CM4&storagesize=62500000'.($mac === null ? '' : '&mac='.$mac))->assertOk();
    }

    public function test_a_module_met_for_the_first_time_is_stored_under_its_mac_written_in_the_one_way()
    {
        $this->start(self::SERIAL, 'E4:5F:01:62:9F:D3');

        $cm = Cm::sole();
        $this->assertSame([self::MAC, self::SERIAL], [$cm->mac, $cm->serial]);
        $this->assertSame(self::MAC, Cmlog::first()->cm, 'its log is under the MAC');
    }

    public function test_the_same_mac_is_the_same_module_even_when_the_board_says_another_serial()
    {
        $this->start(self::SERIAL, self::MAC);
        $id = Cm::sole()->id;

        $this->start('10000000aaaa0001', self::MAC);

        $cm = Cm::sole();
        $this->assertSame([$id, '10000000aaaa0001', self::MAC], [$cm->id, $cm->serial, $cm->mac]);
    }

    public function test_a_serial_that_another_module_has_stays_with_it_and_the_module_found_by_its_mac_keeps_its_own()
    {
        $this->start(self::SERIAL, self::MAC);
        $this->start('10000000aaaa0002', 'e4:5f:01:00:00:02');

        $this->start('10000000aaaa0002', self::MAC);   // the first module's MAC, the second one's serial

        $this->assertSame(2, Cm::count());
        $this->assertSame(self::SERIAL, Cm::where('mac', self::MAC)->value('serial'));
        $this->assertSame('10000000aaaa0002', Cm::where('mac', 'e4:5f:01:00:00:02')->value('serial'));
        $this->assertTrue(Cmlog::where('cm', self::MAC)->where('loglevel', 'warning')->where('msg', 'like', '%which another module has%')->exists());
    }

    public function test_a_board_that_comes_with_a_new_mac_keeps_its_row_and_its_log_goes_with_it()
    {
        $this->start(self::SERIAL, self::MAC);
        $id = Cm::sole()->id;

        $this->start(self::SERIAL, 'e4:5f:01:ff:ff:ff');

        $cm = Cm::sole();
        $this->assertSame([$id, 'e4:5f:01:ff:ff:ff'], [$cm->id, $cm->mac]);
        $this->assertSame(0, Cmlog::where('cm', self::MAC)->count(), 'nothing is left under the old MAC');
        $this->assertGreaterThanOrEqual(2, Cmlog::where('cm', 'e4:5f:01:ff:ff:ff')->count());
        $this->assertTrue(Cmlog::where('cm', 'e4:5f:01:ff:ff:ff')->where('msg', 'like', '%new MAC address: it was '.self::MAC.'%')->exists());
    }

    public function test_a_start_without_a_mac_keeps_the_mac_of_the_row_and_a_new_module_gets_one_made_of_its_serial()
    {
        $this->start(self::SERIAL, self::MAC);
        $this->start(self::SERIAL, null);
        $this->start('1000000012345678', null);

        $this->assertSame(self::MAC, Cm::where('serial', self::SERIAL)->value('mac'));
        $this->assertSame('02:00:12:34:56:78', Cm::where('serial', '1000000012345678')->value('mac'));
    }

    public function test_the_card_is_at_the_address_of_the_mac_and_the_old_address_of_the_serial_still_works()
    {
        $this->start(self::SERIAL, self::MAC);
        $this->actingAs(User::factory()->create());

        $this->get('/cms/'.self::MAC)->assertOk()->assertSee('<title>'.self::MAC, false);
        $this->get('/cms/E4-5F-01-62-9F-D3')->assertOk();
        $this->get('/cms/'.self::SERIAL)->assertOk()->assertSee('<title>'.self::MAC, false);
        $this->get('/cms/e4:5f:01:00:00:00')->assertNotFound();
        $this->assertSame(url('/cms/'.self::MAC), route('cm', self::MAC), 'the colons are in the address as they are');
    }

    public function test_the_history_on_the_card_has_the_lines_under_the_mac_and_the_older_ones_under_the_serial()
    {
        $this->start(self::SERIAL, self::MAC);
        Cmlog::create(['cm' => self::SERIAL, 'loglevel' => 'info', 'msg' => 'a line from before 2.11']);
        $this->actingAs(User::factory()->create());

        $this->get('/cms/'.self::MAC)->assertOk()->assertSee('a line from before 2.11');
    }
}
