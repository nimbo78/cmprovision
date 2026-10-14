<?php

namespace Tests\Support;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\Setting;
use App\Services\NetworkStatus;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\SwitchSnapshot;
use Mockery\MockInterface;

/** A Huawei stand for PoE restart tests: sw01 with PoE on GE0/0/1-3 (ifIndex 6-8), the provisioner behind XGE0/0/1;
    secondSwitch() adds sw02 */
trait PoeStand
{
    use FakeSwitches;

    protected $fake, $fake2;

    /* The provisioner's MAC on the modules' network; the top bar's indicator reads nothing */
    protected function mockNetwork(): void
    {
        $this->mock(NetworkStatus::class, function (MockInterface $m) {
            $m->shouldReceive('serverMac')->andReturn('2c:cf:67:30:7d:a2');
            $m->shouldReceive('current')->andReturn(null);
        });
    }

    /* The switch with write access (unless $settings says otherwise) and a failed module on GE0/0/3 */
    protected function stand(array $settings = ['snmp_write_community' => 'private']): Cm
    {
        $this->mockNetwork();
        SwitchRow::put(['ip' => '192.0.2.10', 'snmp_community' => 'public'] + $settings);

        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw01'];
        foreach ([6 => 'GE0/0/1', 7 => 'GE0/0/2', 8 => 'GE0/0/3', 30 => 'XGE0/0/1'] as $i => $name)
            $t += [
                SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => 1,
                SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => $i === 30 ? 10000 : 1000,
            ];
        $t += [
            MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.10.0' => 8,          // e4:5f:01:62:9f:d3, the module
            MacTable::OID_HUAWEI_FDB_PORT.'.44.207.103.48.125.162.1.0' => 30,        // 2c:cf:67:30:7d:a2, the provisioner
        ];
        foreach ([6, 7, 8] as $i)
            $t += [
                Poe::OID_HW_POWER_STATUS.'.'.$i => $i === 8 ? 'Powered' : 'Detecting',
                Poe::OID_HW_CONSUMING.'.'.$i => $i === 8 ? 6900 : 0,
                Poe::OID_HW_ENABLE.'.'.$i => 1,
            ];
        $this->fake = new FakeSnmp(['' => $t]);
        $fake = $this->fake;
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });

        return Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'GE0/0/3',
            'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }

    /* A second switch at 192.0.2.11 (sw02) with a failed module on its GE0/0/3; both answer from their own fakes */
    protected function secondSwitch(array $columns = ['write_community' => 'private']): Cm
    {
        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw02'];
        foreach ([6 => 'GE0/0/1', 8 => 'GE0/0/3'] as $i => $name)
            $t += [SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                   SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => 1,
                   SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => 1000,
                   Poe::OID_HW_POWER_STATUS.'.'.$i => $i === 8 ? 'Powered' : 'Detecting',
                   Poe::OID_HW_CONSUMING.'.'.$i => $i === 8 ? 6900 : 0, Poe::OID_HW_ENABLE.'.'.$i => 1];
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0'] = 8;   // e4:5f:01:62:9f:d5
        $this->fake2 = new FakeSnmp(['' => $t], '192.0.2.11');
        EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'public'] + $columns);
        $this->bindSwitches(['192.0.2.10' => $this->fake, '192.0.2.11' => $this->fake2]);
        return Cm::create(['serial' => '10000000e611110e', 'mac' => 'e4:5f:01:62:9f:d5', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'sw02 GE0/0/3', 'switch_name' => 'sw02',
            'switch_port' => 'GE0/0/3', 'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }

    /* A second failed module, on GE0/0/2 */
    protected function secondModule(): Cm
    {
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 7;   // e4:5f:01:62:9f:d4
        return Cm::create(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'GE0/0/2',
            'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }
}
