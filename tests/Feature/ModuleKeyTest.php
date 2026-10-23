<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Cmlog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2.11: the MAC address is the key of a module; the serial number stays its unique attribute and what it sends after its first request */
class ModuleKeyTest extends TestCase
{
    use RefreshDatabase;

    const MAC = 'e4:5f:01:62:9f:d3';
    const SERIAL = '10000000d3629fab';

    public function test_a_mac_is_written_in_one_way_whatever_the_module_or_the_person_typed()
    {
        $this->assertSame('e4:5f:01:aa:bb:cc', Cm::normalizeMac(' E4-5F-01-AA-BB-CC '));
        $this->assertSame('e4:5f:01:aa:bb:cc', Cm::normalizeMac('e4:5f:01:aa:bb:cc'));
        $this->assertSame('e45f01aabbcc', Cm::normalizeMac('E45F01AABBCC'), 'what is not a MAC is lowercased and trimmed, nothing more');
        $this->assertSame('', Cm::normalizeMac(null));
    }

    public function test_only_six_pairs_of_hex_digits_are_a_mac()
    {
        $this->assertTrue(Cm::isMac('e4:5f:01:62:9f:d3'));
        $this->assertFalse(Cm::isMac('E4:5F:01:62:9F:D3'), 'the written form is lowercase: normalize first');
        foreach (['', 'a/b', 'e4:5f:01:62:9f', 'e4:5f:01:62:9f:d3:00', 'e45f01629fd3', 'e4:5f:01:62:9f:zz', "e4:5f:01:62:9f:d3\n"] as $bad)
            $this->assertFalse(Cm::isMac($bad), var_export($bad, true));
    }

    public function test_the_card_goes_on_when_the_serial_of_its_module_changes_while_it_is_open()
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => self::MAC]);
        $card = \Livewire\Livewire::test(\App\Http\Livewire\CmCard::class, ['mac' => self::MAC]);

        $cm->update(['serial' => '10000000aaaa0009']);

        $card->call('identify')->assertHasNoErrors();
    }

    public function test_a_module_without_a_mac_gets_a_locally_administered_one_made_of_its_serial()
    {
        $this->assertSame('02:00:12:34:56:78', Cm::pseudoMac('1000000012345678'));
        $this->assertSame(Cm::pseudoMac('1000000012345678'), Cm::pseudoMac('1000000012345678'));
        $this->assertNotSame(Cm::pseudoMac('1000000012345678'), Cm::pseudoMac('1000000012345679'));
    }

    public function test_a_module_is_found_by_its_mac_in_any_spelling_or_by_its_serial()
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => self::MAC]);

        $this->assertSame($cm->id, Cm::findByKey(self::MAC)->id);
        $this->assertSame($cm->id, Cm::findByKey('E4-5F-01-62-9F-D3')->id);
        $this->assertSame($cm->id, Cm::findByKey(self::SERIAL)->id);
        $this->assertNull(Cm::findByKey('e4:5f:01:00:00:00'));
        $this->assertNull(Cm::findByKey(''));
    }

    public function test_two_modules_cannot_have_one_mac()
    {
        Cm::create(['serial' => self::SERIAL, 'mac' => self::MAC]);

        $this->expectException(QueryException::class);
        Cm::create(['serial' => '1000000000000002', 'mac' => self::MAC]);
    }

    public function test_a_mac_is_stored_in_the_one_way_whatever_it_came_as()
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => 'E4:5F:01:62:9F:D3']);

        $this->assertSame(self::MAC, $cm->fresh()->mac);
    }

    public function test_the_log_of_a_module_is_the_lines_under_its_mac_and_the_old_ones_under_its_serial()
    {
        $cm = Cm::create(['serial' => self::SERIAL, 'mac' => self::MAC]);
        Cmlog::create(['cm' => self::MAC, 'loglevel' => 'info', 'msg' => 'new']);
        Cmlog::create(['cm' => self::SERIAL, 'loglevel' => 'info', 'msg' => 'old']);
        Cmlog::create(['cm' => 'e4:5f:01:00:00:01', 'loglevel' => 'info', 'msg' => 'another']);

        $this->assertEqualsCanonicalizing(['new', 'old'], $cm->logLines()->pluck('msg')->all());
    }
}
