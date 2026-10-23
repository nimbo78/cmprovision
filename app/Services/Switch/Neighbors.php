<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * What the devices on a switch's ports say about themselves, from the switch's LLDP (IEEE 802.1AB) and CDP (Cisco)
 * tables: name, what it is, its port, its address, what it can do. A neighbour is whatever sends the frames, so every
 * text is cleaned (no control characters, valid UTF-8, a length limit) before it reaches a page or the display.
 * Read only; a switch with LLDP and CDP off, or without the tables, has no neighbours and that is not an error.
 *
 * read() => ['ports' => [ifIndex => [neighbour, ...]], 'discovery' => ['lldp' => bool, 'cdp' => ?bool], 'at' => unix time]
 * neighbour = ['protocol' => 'lldp'|'cdp'|'lldp/cdp' (the same device in both), 'name', 'description' (first line),
 *              'platform' (CDP), 'port', 'chassis', 'mac' (?string: chassis, else port id), 'port_mac' (?string: the
 *              interface that sent the frame), 'address' (?string), 'capabilities' => [string]]
 */
class Neighbors
{
    const OID_LLDP_REM = '.1.0.8802.1.1.2.1.4.1.1';          // lldpRemTable, index timeMark.localPortNum.remIndex
    const OID_LLDP_MAN = '.1.0.8802.1.1.2.1.4.2.1.3';        // lldpRemManAddrIfSubtype: the address is in the index
    const OID_LLDP_LOC_ID = '.1.0.8802.1.1.2.1.3.7.1.3';     // lldpLocPortId, by lldpLocPortNum
    const OID_LLDP_LOC_DESC = '.1.0.8802.1.1.2.1.3.7.1.4';   // lldpLocPortDesc
    const OID_CDP_CACHE = '.1.3.6.1.4.1.9.9.23.1.2.1.1';     // cdpCacheTable, index ifIndex.deviceIndex
    const OID_CDP_RUN = '.1.3.6.1.4.1.9.9.23.1.3.1.0';       // cdpGlobalRun: 1 on, 2 off

    const PER_PORT = 4;         // a port with more neighbours (a hub, a hostile device) shows the first few
    const MAX_ROWS = 500;       // a table longer than this (a flood of fake neighbours) is not read at all
    const TTL = 20;             // seconds: neighbours change slowly and the snapshot is taken every few seconds
    const KEEP = 120;           // seconds a switch that stopped answering for these tables shows its last neighbours
    const RETRY = 60;           // seconds a failed read is not tried again: every snapshot would wait for the timeout

    /* lldpSystemCapabilities, first octet, most significant bit first */
    const LLDP_CAPABILITIES = [0x80 => 'other', 0x40 => 'repeater', 0x20 => 'bridge', 0x10 => 'wlan-ap', 0x08 => 'router',
                               0x04 => 'telephone', 0x02 => 'docsis', 0x01 => 'station'];
    /* cdpCacheCapabilities, as a number */
    const CDP_CAPABILITIES = [0x01 => 'router', 0x02 => 'bridge', 0x04 => 'bridge', 0x08 => 'bridge', 0x10 => 'station',
                              0x40 => 'repeater', 0x80 => 'telephone'];

    protected $snmp, $id;

    public function __construct(SnmpClient $snmp, string $id = 'switch')
    {
        $this->snmp = $snmp;
        $this->id = $id;
    }

    /** $ports: the switch's Ethernet ports as SwitchSnapshot builds them ([ifIndex => ['name' => ...]]) */
    public function read(array $ports): array
    {
        $key = 'switch-neighbors:'.$this->id;
        $fresh = Cache::get($key);
        if ($fresh !== null)
            return $fresh;
        try
        {
            $found = $this->fetch($ports);
        }
        catch (\Throwable $e)
        {
            // a switch that does not answer at all has its snapshot fail elsewhere; here the last look stays for a short
            // while, and the next try waits so that the timeout is not paid by every snapshot
            if (Cache::add($key.':failing', 1, 600))
                Log::warning('Switch '.$this->id.': the LLDP/CDP neighbours cannot be read: '.$e->getMessage());
            $fallback = Cache::get($key.':last') ?? ['ports' => [], 'discovery' => ['lldp' => false, 'cdp' => null], 'at' => Carbon::now()->getTimestamp()];
            Cache::put($key, $fallback, self::RETRY);
            return $fallback;
        }
        Cache::forget($key.':failing');
        $found['at'] = Carbon::now()->getTimestamp();
        Cache::put($key, $found, self::TTL);
        Cache::put($key.':last', $found, self::KEEP);
        return $found;
    }

    protected function fetch(array $ports): array
    {
        $lldp = $this->lldp($ports);
        // CDP is a Cisco table: if only it fails, what LLDP said stays, and CDP is left alone for a minute
        $cdp = ['neighbors' => [], 'on' => null];
        $down = 'switch-neighbors:'.$this->id.':cdp-down';
        if (!Cache::has($down))
            try
            {
                $cdp = $this->cdp();
            }
            catch (\Throwable $e)
            {
                Cache::put($down, 1, self::RETRY);
            }
        $found = [];
        foreach ([$lldp['neighbors'], $cdp['neighbors']] as $byPort)
            foreach ($byPort as $ifIndex => $list)
                if (isset($ports[$ifIndex]))
                    foreach ($list as $neighbour)
                        self::add($found[$ifIndex], $neighbour);
        return ['ports' => $found, 'discovery' => ['lldp' => $lldp['on'], 'cdp' => $cdp['on']]];
    }

    /* A device that talks both LLDP and CDP (a Linux box with lldpd does) is one neighbour: the same name on the same
       port. The CDP entry adds what LLDP lacks, such as the platform */
    protected static function add(?array &$list, array $neighbour): void
    {
        $list = $list ?? [];
        foreach ($list as &$have)
            if ($have['protocol'] !== $neighbour['protocol'] && $neighbour['name'] !== '' && strcasecmp($have['name'], $neighbour['name']) === 0)
            {
                $have['protocol'] = 'lldp/cdp';
                foreach (['platform', 'description'] as $field)
                    $have[$field] = $have[$field] !== '' ? $have[$field] : $neighbour[$field];
                $have['address'] = $have['address'] ?? $neighbour['address'];
                $have['capabilities'] = array_values(array_unique(array_merge($have['capabilities'], $neighbour['capabilities'])));
                return;
            }
        unset($have);
        if (count($list) < self::PER_PORT)
            $list[] = $neighbour;
    }

    /* [oid => value] below $oid; [] when the agent has nothing there; a silent agent throws */
    protected function walk(string $oid): array
    {
        try
        {
            return $this->snmp->walk($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
    }

    /* Whether a table is too long to read: one cheap column is counted first. Whoever sits on the cable can announce
       thousands of devices, and the whole table would be read into memory every few seconds */
    protected function tooLong(string $column, string $what): bool
    {
        if (count($this->walk($column)) <= self::MAX_ROWS)
            return false;
        if (Cache::add('switch-neighbors:'.$this->id.':flood:'.$what, 1, 600))
            Log::warning('Switch '.$this->id.': more than '.self::MAX_ROWS.' '.$what.' neighbours, they are not read');
        return true;
    }

    protected function lldp(array $ports): array
    {
        if ($this->tooLong(self::OID_LLDP_REM.'.4', 'LLDP'))
            return ['neighbors' => [], 'on' => true];
        $rows = [];
        foreach ($this->walk(self::OID_LLDP_REM) as $oid => $value)
        {
            $p = explode('.', substr($oid, strlen(self::OID_LLDP_REM) + 1));
            if (count($p) !== 4 || !ctype_digit(implode('', $p)))
                continue;
            [$column, , $local, $index] = array_map('intval', $p);
            $rows[$local.'.'.$index]['local'] = $local;
            $rows[$local.'.'.$index][$column] = $value;
        }
        $ids = $this->walk(self::OID_LLDP_LOC_ID);
        $on = $rows || $ids;
        if (!$rows)
            return ['neighbors' => [], 'on' => (bool) $on];

        $addresses = [];
        foreach ($this->walk(self::OID_LLDP_MAN) as $oid => $value)
        {
            $p = explode('.', substr($oid, strlen(self::OID_LLDP_MAN) + 1));
            if (count($p) < 6 || !ctype_digit(implode('', $p)))
                continue;
            $p = array_map('intval', $p);
            if (count($p) - 5 !== $p[4])
                continue;
            $address = self::address($p[3], array_slice($p, 5));
            if ($address !== null)
                $addresses[$p[1].'.'.$p[2]][] = $address;
        }

        $map = $this->localPorts(array_unique(array_column($rows, 'local')), $ports, $ids);
        $neighbors = [];
        foreach ($rows as $key => $r)
        {
            $ifIndex = $map[$r['local']] ?? null;
            if ($ifIndex === null)
                continue;
            [$chassis, $chassisMac] = self::lldpChassis((int) ($r[4] ?? 0), $r[5] ?? '');
            [$portId, $portMac] = self::lldpPort((int) ($r[6] ?? 0), $r[7] ?? '');
            $neighbors[$ifIndex][] = [
                'protocol' => 'lldp',
                'name' => self::text($r[9] ?? ''),
                'description' => self::firstLine($r[10] ?? ''),
                'platform' => '',
                'port' => self::text($r[8] ?? '') ?: $portId,
                'chassis' => $chassis,
                'mac' => $chassisMac ?? $portMac,
                'port_mac' => $portMac,
                'address' => self::preferred($addresses[$key] ?? []),
                'capabilities' => self::lldpCapabilities($r[12] ?? '', $r[11] ?? ''),
            ];
        }
        return ['neighbors' => $neighbors, 'on' => true];
    }

    /* lldpLocPortNum => ifIndex. The number is an ifIndex on some switches and a running number on others (the 2960CX
       counts 1, 2, ... while its interfaces are 10101, ...), so the port is found by the name it gives itself, then by
       its ifDescr, and only then by the number */
    protected function localPorts(array $locals, array $ports, array $ids): array
    {
        $byName = [];
        foreach ($ports as $ifIndex => $port)
            $byName[strtolower($port['name'])] = $ifIndex;
        $descs = null;
        $loc = $this->walk(self::OID_LLDP_LOC_DESC);
        $map = [];
        foreach ($locals as $local)
        {
            $names = [self::text($ids[self::OID_LLDP_LOC_ID.'.'.$local] ?? ''), self::text($loc[self::OID_LLDP_LOC_DESC.'.'.$local] ?? '')];
            foreach ($names as $name)
                if ($name !== '' && isset($byName[strtolower($name)]))
                {
                    $map[$local] = $byName[strtolower($name)];
                    continue 2;
                }
            if ($descs === null)
                $descs = $this->descriptions($ports);
            foreach ($names as $name)
                if ($name !== '' && isset($descs[strtolower($name)]))
                {
                    $map[$local] = $descs[strtolower($name)];
                    continue 2;
                }
            if (isset($ports[$local]))
                $map[$local] = $local;
        }
        return $map;
    }

    /* ifDescr => ifIndex of the Ethernet ports */
    protected function descriptions(array $ports): array
    {
        $out = [];
        foreach ($this->walk(SwitchSnapshot::OID_IFDESCR) as $oid => $value)
        {
            $ifIndex = (int) substr(strrchr($oid, '.'), 1);
            if (isset($ports[$ifIndex]))
                $out[strtolower(self::text($value))] = $ifIndex;
        }
        return $out;
    }

    protected function cdp(): array
    {
        if ($this->tooLong(self::OID_CDP_CACHE.'.6', 'CDP'))
            return ['neighbors' => [], 'on' => true];
        $rows = [];
        foreach ($this->walk(self::OID_CDP_CACHE) as $oid => $value)
        {
            $p = explode('.', substr($oid, strlen(self::OID_CDP_CACHE) + 1));
            if (count($p) !== 3 || !ctype_digit(implode('', $p)))
                continue;
            [$column, $ifIndex, $device] = array_map('intval', $p);
            $rows[$ifIndex.'.'.$device]['if'] = $ifIndex;
            $rows[$ifIndex.'.'.$device][$column] = $value;
        }
        $on = null;
        try
        {
            $on = (int) $this->snmp->get(self::OID_CDP_RUN) === 1;
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
        }
        $neighbors = [];
        foreach ($rows as $r)
        {
            $address = isset($r[4]) && is_string($r[4]) && strlen($r[4]) === 4 ? implode('.', array_values(unpack('C4', $r[4]))) : null;
            $neighbors[$r['if']][] = [
                'protocol' => 'cdp',
                'name' => self::text($r[6] ?? ''),
                'description' => self::firstLine($r[5] ?? ''),
                'platform' => self::text($r[8] ?? ''),
                'port' => self::text($r[7] ?? ''),
                'chassis' => '',
                'mac' => null,
                'port_mac' => null,
                'address' => $address,
                'capabilities' => self::cdpCapabilities($r[9] ?? ''),
            ];
        }
        return ['neighbors' => $neighbors, 'on' => $on ?? ($rows ? true : null)];
    }

    /* A text from the wire as one clean line: valid UTF-8, no control or invisible formatting characters, shortened */
    public static function text($value, int $max = 80): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        if (!mb_check_encoding($s, 'UTF-8'))
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        $s = preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }

    /* The first line of a long text (a system description runs over several) */
    protected static function firstLine($value): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        return self::text(strtok($s, "\r\n") ?: '', 160);
    }

    protected static function mac(string $octets): ?string
    {
        return strlen($octets) === 6 && trim($octets, "\0") !== '' ? implode(':', str_split(bin2hex($octets), 2)) : null;
    }

    /* [text, MAC or null] of lldpRemChassisId by its subtype: 4 a MAC address, 5 a network address, others text */
    protected static function lldpChassis(int $subtype, $raw): array
    {
        $raw = is_string($raw) ? $raw : '';
        if ($subtype === 4 && ($mac = self::mac($raw)) !== null)
            return [$mac, $mac];
        if ($subtype === 5 && strlen($raw) === 5 && ord($raw[0]) === 1)
            return [implode('.', array_values(unpack('C4', substr($raw, 1)))), null];
        return [self::text($raw), null];
    }

    /* [text, MAC or null] of lldpRemPortId by its subtype: 3 a MAC address, 4 a network address, others text */
    protected static function lldpPort(int $subtype, $raw): array
    {
        $raw = is_string($raw) ? $raw : '';
        if ($subtype === 3 && ($mac = self::mac($raw)) !== null)
            return [$mac, $mac];
        if ($subtype === 4 && strlen($raw) === 5 && ord($raw[0]) === 1)
            return [implode('.', array_values(unpack('C4', substr($raw, 1)))), null];
        return [self::text($raw), null];
    }

    /* A management address from the index of lldpRemManAddrTable: 1 is IPv4, 2 IPv6 */
    protected static function address(int $subtype, array $octets): ?string
    {
        if ($subtype === 1 && count($octets) === 4)
            return implode('.', $octets);
        if ($subtype === 2 && count($octets) === 16)
            return inet_ntop(pack('C*', ...$octets)) ?: null;
        return null;
    }

    /* The IPv4 address if there is one, else the first global IPv6 one */
    protected static function preferred(array $addresses): ?string
    {
        foreach ($addresses as $address)
            if (strpos($address, ':') === false)
                return $address;
        foreach ($addresses as $address)
            if (stripos($address, 'fe80:') !== 0)
                return $address;
        return null;
    }

    /* What the device does now (enabled), or else what it could do (supported); the first octet of each bit string */
    protected static function lldpCapabilities($enabled, $supported): array
    {
        foreach ([$enabled, $supported] as $bits)
        {
            $first = is_string($bits) && $bits !== '' ? ord($bits[0]) : 0;
            $names = [];
            foreach (self::LLDP_CAPABILITIES as $bit => $name)
                if ($first & $bit)
                    $names[] = $name;
            if ($names)
                return $names;
        }
        return [];
    }

    protected static function cdpCapabilities($raw): array
    {
        $number = is_string($raw) && $raw !== '' && strlen($raw) <= 4 ? unpack('N', str_pad($raw, 4, "\0", STR_PAD_LEFT))[1] : 0;
        $names = [];
        foreach (self::CDP_CAPABILITIES as $bit => $name)
            if (($number & $bit) && !in_array($name, $names, true))
                $names[] = $name;
        return $names;
    }
}
