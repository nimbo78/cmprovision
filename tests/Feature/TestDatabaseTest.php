<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Tests refresh the database: they must never reach the one .env points at. */
class TestDatabaseTest extends TestCase
{
    public function test_tests_run_on_an_in_memory_database()
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
