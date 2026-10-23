<?php

namespace Tests\Feature;

use App\Models\Cm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** The migration that makes the MAC the key: rows of a database that has lived through 2.10 come out right */
class MacKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require base_path('database/migrations/2026_10_23_100000_mac_is_the_key.php');
        $this->migration->down();   // the database as 2.10 left it: no unique MAC, the PoE outages name a serial
    }

    protected function cm(string $serial, string $mac, string $updated): int
    {
        return DB::table('cms')->insertGetId(['serial' => $serial, 'mac' => $mac, 'created_at' => $updated, 'updated_at' => $updated]);
    }

    public function test_macs_are_written_in_one_way()
    {
        $this->cm('1000000000000001', 'E4-5F-01-AA-BB-CC', '2026-10-01 10:00:00');

        $this->migration->up();

        $this->assertSame('e4:5f:01:aa:bb:cc', DB::table('cms')->value('mac'));
    }

    public function test_a_mac_two_modules_share_stays_with_the_one_updated_last_and_the_other_gets_one_of_its_own()
    {
        $this->cm('1000000000000001', 'e4:5f:01:aa:bb:cc', '2026-10-01 10:00:00');
        $this->cm('1000000000000002', 'E4:5F:01:AA:BB:CC', '2026-10-02 10:00:00');
        $this->cm('1000000000000003', 'b8:27:eb:00:00:03', '2026-10-03 10:00:00');

        $this->migration->up();

        $macs = DB::table('cms')->orderBy('serial')->pluck('mac', 'serial')->all();
        $this->assertSame('02:00:00:00:00:01', $macs['1000000000000001'], 'a locally administered address made of its serial');
        $this->assertSame('e4:5f:01:aa:bb:cc', $macs['1000000000000002']);
        $this->assertSame('b8:27:eb:00:00:03', $macs['1000000000000003'], 'a MAC nobody shares is not touched');
    }

    public function test_the_mac_is_unique_afterwards()
    {
        $this->cm('1000000000000001', 'e4:5f:01:aa:bb:cc', '2026-10-01 10:00:00');
        $this->migration->up();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->cm('1000000000000002', 'e4:5f:01:aa:bb:cc', '2026-10-02 10:00:00');
    }

    public function test_the_log_of_a_module_goes_under_its_mac_and_the_log_of_a_deleted_one_stays()
    {
        $this->cm('1000000000000001', 'e4:5f:01:aa:bb:cc', '2026-10-01 10:00:00');
        DB::table('cmlogs')->insert([['cm' => '1000000000000001', 'loglevel' => 'info', 'msg' => 'a'], ['cm' => '1000000000000009', 'loglevel' => 'info', 'msg' => 'gone'],
            ['cm' => '', 'loglevel' => 'info', 'msg' => 'a booting Pi']]);

        $this->migration->up();

        $this->assertSame(['e4:5f:01:aa:bb:cc', '1000000000000009', ''], DB::table('cmlogs')->orderBy('id')->pluck('cm')->all());
    }

    public function test_the_outage_of_a_port_names_the_mac_of_its_module()
    {
        $this->cm('1000000000000001', 'e4:5f:01:aa:bb:cc', '2026-10-01 10:00:00');
        DB::table('poe_outages')->insert(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3', 'oid' => '1.2.3', 'on_value' => 1, 'cm_serial' => '1000000000000001', 'off_at' => now()]);
        DB::table('poe_outages')->insert(['switch_host' => '192.0.2.10', 'if_index' => 9, 'port' => 'GE0/0/4', 'oid' => '1.2.4', 'on_value' => 1, 'cm_serial' => null, 'off_at' => now()]);

        $this->migration->up();

        $this->assertFalse(Schema::hasColumn('poe_outages', 'cm_serial'));
        $this->assertSame(['e4:5f:01:aa:bb:cc', null], DB::table('poe_outages')->orderBy('if_index')->pluck('cm_mac')->all());
    }

    public function test_running_it_again_changes_nothing_and_it_can_be_taken_back()
    {
        $this->cm('1000000000000001', 'e4:5f:01:aa:bb:cc', '2026-10-01 10:00:00');
        DB::table('cmlogs')->insert(['cm' => '1000000000000001', 'loglevel' => 'info', 'msg' => 'a']);
        $this->migration->up();
        $this->migration->up();

        $this->assertSame('e4:5f:01:aa:bb:cc', DB::table('cmlogs')->value('cm'));

        $this->migration->down();

        $this->assertSame('1000000000000001', DB::table('cmlogs')->value('cm'), 'back to the serial');
        $this->assertTrue(Schema::hasColumn('poe_outages', 'cm_serial'));
        $this->migration->up();   // and forward again for the next test's teardown
        $this->assertSame(1, Cm::count());
    }
}
