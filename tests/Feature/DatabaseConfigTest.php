<?php

namespace Tests\Feature;

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
}
