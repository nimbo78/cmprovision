<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
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
}
