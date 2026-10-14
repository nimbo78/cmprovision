<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 40-48 modules write at once: the database runs in WAL, and a writer waits its turn instead of failing */
class DatabaseConfigTest extends TestCase
{
    public function test_the_database_runs_in_wal_with_a_busy_timeout()
    {
        $file = tempnam(sys_get_temp_dir(), 'db');
        config(['database.connections.walcheck' => ['database' => $file] + config('database.connections.sqlite')]);
        try
        {
            $db = DB::connection('walcheck');
            $this->assertSame('wal', strtolower($db->selectOne('PRAGMA journal_mode')->journal_mode));
            $this->assertSame(5000, (int) $db->selectOne('PRAGMA busy_timeout')->timeout);
            $this->assertSame(1, (int) $db->selectOne('PRAGMA synchronous')->synchronous, 'NORMAL');
        }
        finally
        {
            DB::purge('walcheck');
            foreach (['', '-wal', '-shm'] as $suffix)
                @unlink($file.$suffix);
        }
    }

    public function test_a_transaction_takes_the_write_lock_when_it_begins()
    {
        // two queue workers reserving jobs: a transaction that reads and then writes fails at once with "database is
        // locked" when the other one wrote in between (busy_timeout does not cover that), unless it locks at BEGIN
        if (PHP_VERSION_ID < 80400)
            $this->markTestSkipped('Laravel sets the transaction mode on PHP 8.4 and later');
        $file = tempnam(sys_get_temp_dir(), 'db');
        $sqlite = ['database' => $file] + config('database.connections.sqlite');
        config(['database.connections.walcheck' => $sqlite, 'database.connections.walother' => ['busy_timeout' => 100] + $sqlite]);
        try
        {
            $worker = DB::connection('walcheck');
            $worker->statement('create table jobs (id integer primary key, reserved_at integer)');
            $worker->beginTransaction();
            $worker->select('select id from jobs where reserved_at is null');
            try
            {
                DB::connection('walother')->insert('insert into jobs (reserved_at) values (null)');
                $this->fail('another connection wrote inside the transaction');
            }
            catch (QueryException $e)
            {
                $this->assertStringContainsString('database is locked', $e->getMessage());
            }
            $worker->update('update jobs set reserved_at = 1');
            $worker->commit();
        }
        finally
        {
            DB::purge('walcheck');
            DB::purge('walother');
            foreach (['', '-wal', '-shm'] as $suffix)
                @unlink($file.$suffix);
        }
    }
}
