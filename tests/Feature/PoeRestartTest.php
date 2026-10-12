<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\Stand;
use App\Services\Switch\SwitchSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** A PoE restart: off, five dark seconds, on again and read back; one at a time; never a port with other devices */
class PoeRestartTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    const OID8 = Poe::OID_HW_ENABLE.'.8';
    const OID7 = Poe::OID_HW_ENABLE.'.7';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_a_module_is_switched_off_and_back_on()
    {
        $cm = $this->stand();
        $result = PoeRestart::modules([$cm], 'igor');

        $this->assertSame(['GE0/0/3' => '10000000e611110c'], $result['restarted']);
        $this->assertSame([[self::OID8, 'i', PoeControl::OFF], [self::OID8, 'i', PoeControl::ON]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(PoeRestart::OFF_SECONDS)->seconds()]);
        $outage = PoeOutage::sole();
        $this->assertSame(['192.0.2.10', 8, 'GE0/0/3', self::OID8, 1, '10000000e611110c', 'igor'],
            [$outage->switch_host, $outage->if_index, $outage->port, $outage->oid, $outage->on_value, $outage->cm_serial, $outage->user]);
        $this->assertNotNull($outage->on_at);
        $this->assertDatabaseHas('cmlogs', ['cm' => '10000000e611110c', 'board' => 'GE0/0/3', 'loglevel' => 'info',
                                            'msg' => 'PoE restart of GE0/0/3 by igor: off for 5 s']);
        $this->assertSame(['text' => 'Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.', 'error' => false],
            PoeRestart::message($result));
    }

    public function test_without_write_access_nothing_is_written()
    {
        $cm = $this->stand([]);
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'No write access to the switch: set it under Settings → Ethernet switch.', 'error' => true],
            PoeRestart::message($result));
    }

    public function test_a_port_shared_with_another_device_is_left_alone()
    {
        $cm = $this->stand();
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.85.10.0'] = 8;   // 00:11:22:33:44:55 next to the module
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['10000000e611110c' => 'other devices on GE0/0/3'], $result['skipped']);
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'Not restarted: 10000000e611110c (other devices on GE0/0/3).', 'error' => true],
            PoeRestart::message($result));
    }

    public function test_a_refused_switch_off_changes_nothing()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=2'] = 'noAccess';
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['failed']);
        $this->assertSame(0, PoeOutage::count());
        Sleep::assertNeverSlept();
        $this->assertDatabaseHas('cmlogs', ['loglevel' => 'error',
            'msg' => 'PoE restart of GE0/0/3 by igor failed: the switch did not switch it off: noAccess']);
        $this->assertSame('The switch did not switch off GE0/0/3: noAccess.', PoeRestart::message($result)['text']);
    }

    public function test_a_port_that_does_not_come_back_on_stays_listed()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=1'] = 'noAccess';
        $result = PoeRestart::modules([$cm], 'igor');

        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['stayed_off']);
        $this->assertCount(4, $this->fake->sets, 'off once, on three times');
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(1)->second(), Sleep::for(1)->second()]);
        $this->assertSame('noAccess', PoeOutage::sole()->error);
        $this->assertSame(1, PoeOutage::stayedOff()->count());
        $this->assertDatabaseHas('cmlogs', ['loglevel' => 'error', 'msg' => 'PoE on GE0/0/3 stays OFF after a restart by igor: noAccess']);
        $this->assertSame(['text' => 'PoE on GE0/0/3 stays OFF: noAccess. Use Power on.', 'error' => true], PoeRestart::message($result));
    }

    public function test_one_restart_at_a_time()
    {
        $cm = $this->stand();
        Cache::lock('poe-restart', PoeRestart::LOCK_SECONDS)->get();
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertTrue($result['busy']);
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'A restart is already running.', 'error' => true], PoeRestart::message($result));
    }

    public function test_several_modules_go_off_together_and_come_back_one_by_one()
    {
        $cm = $this->stand();
        $second = $this->secondModule();
        $result = PoeRestart::modules([$cm, $second], 'igor');

        $this->assertSame(['GE0/0/3' => '10000000e611110c', 'GE0/0/2' => '10000000e611110d'], $result['restarted']);
        $this->assertSame([[self::OID8, 'i', 2], [self::OID7, 'i', 2], [self::OID8, 'i', 1], [self::OID7, 'i', 1]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(500)->milliseconds()]);
        $this->assertSame('Restarted 2 ports: GE0/0/3, GE0/0/2.', PoeRestart::message($result)['text']);
    }

    public function test_one_port_refusing_does_not_stop_the_others()
    {
        $cm = $this->stand();
        $second = $this->secondModule();
        $this->fake->refuse[self::OID8.'=2'] = 'noAccess';
        $result = PoeRestart::modules([$cm, $second], 'igor');

        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['failed']);
        $this->assertSame(['GE0/0/2' => '10000000e611110d'], $result['restarted']);
        $this->assertSame(['text' => 'Restarted GE0/0/2 (10000000e611110d): PoE was off for 5 s. The switch did not switch off GE0/0/3: noAccess.',
                           'error' => true], PoeRestart::message($result));
    }

    public function test_a_moved_module_is_restarted_where_it_is_now()
    {
        $cm = $this->stand();
        $cm->update(['provisioning_board' => 'GE0/0/1']);   // where it was provisioned; the switch sees it on GE0/0/3 now
        $this->assertSame(['GE0/0/3' => '10000000e611110c'], PoeRestart::modules([$cm], 'igor')['restarted']);
    }

    public function test_a_module_the_switch_does_not_show_is_not_restarted()
    {
        $this->stand();
        $away = Cm::create(['serial' => '10000000e611ffff', 'mac' => 'e4:5f:01:00:ff:ff', 'phase' => 'failed']);
        $this->assertSame(['10000000e611ffff' => 'not seen on the switch'], PoeRestart::modules([$away], 'igor')['skipped']);
    }

    public function test_a_silent_switch_restarts_nothing()
    {
        $cm = $this->stand();
        $this->fake->timeout = true;
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        $this->assertStringStartsWith('The switch does not answer', PoeRestart::message($result)['text']);
    }

    public function test_the_ports_page_restarts_by_port()
    {
        $this->stand();
        $this->assertSame(['GE0/0/3' => '10000000e611110c'], PoeRestart::ports(['p8'], 'igor')['restarted']);
        $this->assertSame(['GE0/0/1' => 'other devices on GE0/0/1'], PoeRestart::ports(['p6'], 'igor')['skipped'],
            'a link without any address: nothing tells it is ours');
    }

    public function test_what_the_operator_is_asked()
    {
        $cm = $this->stand();
        $this->assertSame('Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again.',
            PoeRestart::confirm($cm, 'GE0/0/3'));
        $cm->phase = 'done';
        $this->assertSame('Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots the system it was flashed with.',
            PoeRestart::confirm($cm, 'GE0/0/3'));
        $cm->forceFill(['phase' => 'write', 'provisioning_started_at' => now(), 'progress_updated_at' => now()]);
        $this->assertSame('Restart 10000000e611110c? PoE goes off for 5 s; its run stops and starts over.', PoeRestart::confirm($cm, null));
        $this->assertSame('Restart the Raspberry Pi on GE0/0/7? PoE goes off for 5 s.', PoeRestart::confirm(null, 'GE0/0/7'));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(3));
        $this->assertSame('Restart 1 module (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(1));
    }

    public function test_a_booting_raspberry_pi_is_restarted_by_its_port()
    {
        $this->stand();
        // d8:3a:dd:00:00:07 on GE0/0/2, its link up for 20 s: a Pi that has not asked the server yet, so no serial
        $this->fake->tables[''] += [MacTable::OID_HUAWEI_FDB_PORT.'.216.58.221.0.0.7.10.0' => 7,
            SwitchSnapshot::OID_SYSUPTIME.'.0' => 100000, SwitchSnapshot::OID_IFLASTCHANGE.'.7' => 98000];
        $result = PoeRestart::ports(['p7'], 'igor');

        $this->assertSame(['GE0/0/2' => null], $result['restarted']);
        $this->assertSame([[self::OID7, 'i', PoeControl::OFF], [self::OID7, 'i', PoeControl::ON]], $this->fake->sets);
        $this->assertDatabaseHas('cmlogs', ['cm' => '', 'board' => 'GE0/0/2', 'msg' => 'PoE restart of GE0/0/2 by igor: off for 5 s']);
        $this->assertSame('Restarted GE0/0/2: PoE was off for 5 s.', PoeRestart::message($result)['text']);
    }

    public function test_a_switch_off_without_an_answer_is_switched_on_again_after_the_pause()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=2'] = 'timeout';   // no answer is not a refusal: the switch may have done it
        $result = PoeRestart::modules([$cm], 'igor');

        $this->assertSame([], $result['failed']);
        $this->assertSame([[self::OID8, 'i', 2], [self::OID8, 'i', 1]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
        $this->assertNotNull(PoeOutage::sole()->on_at);
        $this->assertDatabaseHas('cmlogs', ['loglevel' => 'warning',
            'msg' => 'PoE restart of GE0/0/3 by igor: the switch did not confirm switching it off (No response from 192.0.2.1); PoE is on']);
        $this->assertSame(['text' => 'The switch did not confirm switching off GE0/0/3 (No response from 192.0.2.1); PoE is on now.', 'error' => true],
            PoeRestart::message($result));
    }

    public function test_a_pulled_module_whose_mac_lingers_next_to_a_running_one_is_not_restarted()
    {
        $pulled = $this->stand();   // failed, no longer asks the server; the switch still lists its MAC on GE0/0/3
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 8;   // e4:5f:01:62:9f:d4, put in its place
        Cm::create(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'write', 'provisioning_board' => 'GE0/0/3',
            'provisioning_started_at' => now()->subMinute(), 'phase_started_at' => now()->subMinute(), 'progress_updated_at' => now()]);
        $result = PoeRestart::modules([$pulled], 'igor');

        $this->assertSame(['10000000e611110c' => 'not seen on the switch'], $result['skipped']);
        $this->assertSame([], $this->fake->sets, 'the running module keeps its power');
    }

    public function test_a_moved_module_is_found_where_its_link_is_not_where_its_mac_lingers()
    {
        $cm = $this->stand();
        // moved from GE0/0/1, whose link is down now, to GE0/0/3: the switch lists it on both
        $this->fake->tables[''][SwitchSnapshot::OID_IFOPERSTATUS.'.6'] = 2;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.20.0'] = 6;
        $this->assertSame(['GE0/0/3' => '10000000e611110c'], PoeRestart::modules([$cm], 'igor')['restarted']);
    }

    public function test_a_port_that_fails_unexpectedly_does_not_strand_the_others()
    {
        $cm = $this->stand();
        $second = $this->secondModule();
        $this->fake->refuse[self::OID8.'=1'] = 'warning';   // php-snmp warns: an exception, not an SNMP answer
        $result = PoeRestart::modules([$cm, $second], 'igor');

        $this->assertSame(['GE0/0/2' => '10000000e611110d'], $result['restarted'], 'the port after it comes back on');
        $this->assertSame(['GE0/0/3' => 'snmp2_set(): Could not open snmp connection'], $result['stayed_off']);
        $this->assertSame(1, PoeOutage::stayedOff()->count());
    }

    public function test_a_switch_off_that_fails_unexpectedly_is_switched_on_again()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=2'] = 'warning';
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['GE0/0/3' => 'snmp2_set(): Could not open snmp connection'], $result['unconfirmed']);
        $this->assertSame([[self::OID8, 'i', 2], [self::OID8, 'i', 1]], $this->fake->sets);
    }

    public function test_a_switch_that_stops_answering_after_the_snapshot_restarts_nothing()
    {
        $cm = $this->stand();
        Stand::current();                 // the snapshot all pages share, fresh for a few seconds
        $this->fake->timeout = true;      // then the switch goes silent
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'The switch does not answer: No response from 192.0.2.1.', 'error' => true], PoeRestart::message($result));
    }

    public function test_a_switch_that_takes_the_switch_off_without_doing_it_is_told()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=2'] = 'ignored';   // taken, and the port still reads on
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['GE0/0/3' => 'it took the write but the port still reads on'], $result['failed']);
        $this->assertSame(0, PoeOutage::count());
        Sleep::assertNeverSlept();
    }

    public function test_an_old_view_of_the_switch_is_not_restarted_on()
    {
        $cm = $this->stand();
        Stand::current();                                            // the stand as it was an hour ago
        $this->travel(1)->hours();
        Sleep::fake(syncWithCarbon: true);                           // the wait for the snapshot lock passes in test time
        Cache::lock('switch-snapshot-lock:192.0.2.10', 60)->get();   // another page is asking the switch right now
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        // an hour, and the wait for the lock, which may carry it into the next second
        $this->assertMatchesRegularExpression('/^The switch was last read 360[01] s ago: try again\.$/', PoeRestart::message($result)['text']);
        $this->assertTrue(PoeRestart::message($result)['error']);
    }

    public function test_the_lock_outlasts_a_restart_of_a_whole_stand_whose_switch_stops_answering()
    {
        // 48 ports, every SNMP request times out after 2 s (PhpSnmpClient: 1 s and one retry): each switch-off once,
        // then each switch-on three times a second apart, the ports half a second apart
        $timeout = 2;
        $worst = 48 * $timeout + PoeRestart::OFF_SECONDS
            + 48 * (PoeControl::TRIES * $timeout + (PoeControl::TRIES - 1) + PoeRestart::STAGGER_MS / 1000);
        $this->assertGreaterThan($worst, PoeRestart::LOCK_SECONDS);
    }
}
