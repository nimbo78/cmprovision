<?php

namespace Tests\Unit;

use App\Support\ArpTable;
use PHPUnit\Framework\TestCase;

/** The MAC a switch answers the provisioner from, as its ARP cache keeps it */
class ArpTableTest extends TestCase
{
    protected $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'arp');
        file_put_contents($this->file, "IP address       HW type     Flags       HW address            Mask     Device\n"
            ."172.20.233.77    0x1         0x2         00:57:D2:97:A1:C0     *        eth0\n"
            ."172.20.233.78    0x1         0x0         00:00:00:00:00:00     *        eth0\n"
            ."192.168.25.1     0x1         0x2         48:a9:8a:b1:95:74     *        wlan0\n");
        ArpTable::$path = $this->file;
    }

    protected function tearDown(): void
    {
        ArpTable::$path = '/proc/net/arp';
        unlink($this->file);
        parent::tearDown();
    }

    public function test_the_mac_of_an_address()
    {
        $this->assertSame('00:57:d2:97:a1:c0', ArpTable::macOf('172.20.233.77'));
        $this->assertSame('00:57:d2:97:a1:c0', ArpTable::macOf('172.20.233.77:161'), 'an SNMP port after the address');
    }

    public function test_no_mac_for_an_incomplete_entry_or_an_unknown_address()
    {
        $this->assertNull(ArpTable::macOf('172.20.233.78'));
        $this->assertNull(ArpTable::macOf('172.20.233.79'));
        ArpTable::$path = '/nonexistent/arp';
        $this->assertNull(ArpTable::macOf('172.20.233.77'));
    }
}
