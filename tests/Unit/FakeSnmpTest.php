<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/** The test switch keeps what is written, reads it back, records every write and refuses on demand */
class FakeSnmpTest extends TestCase
{
    const OID = '.1.3.6.1.2.1.105.1.1.1.3.1.4';

    public function test_a_write_is_kept_read_back_and_recorded()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->set(self::OID, 'i', 2);
        $this->assertSame(2, $snmp->get(self::OID));
        $this->assertSame([[self::OID, 'i', 2]], $snmp->sets);
    }

    public function test_a_refused_write_throws_and_changes_nothing()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->refuse[self::OID.'=1'] = 'noAccess';
        $snmp->set(self::OID, 'i', 2);
        try
        {
            $snmp->set(self::OID, 'i', 1);
            $this->fail('switching on should be refused');
        }
        catch (SnmpError $e)
        {
            $this->assertSame('noAccess', $e->getMessage());
            $this->assertFalse($e->timeout);
        }
        $this->assertSame(2, $snmp->get(self::OID));
        $this->assertCount(2, $snmp->sets, 'a refused write is still an attempt');
    }

    public function test_a_timeout_and_a_missing_object()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->refuse[self::OID] = 'timeout';
        try
        {
            $snmp->set(self::OID, 'i', 2);
            $this->fail('the write should time out');
        }
        catch (SnmpError $e)
        {
            $this->assertTrue($e->timeout);
        }
        $this->expectException(SnmpError::class);
        $snmp->get('.1.3.6.1.2.1.1.5.0');
    }
}
