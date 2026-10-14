<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Support\StandMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * PoE restart of modules (docs/superpowers/specs/2026-10-12-poe-restart-design.md). One at a time on each switch of
 * the stand, only on ports with nothing but our modules or a booting Pi (StandMap::restartBlocker()), each switch
 * checked on its fresh snapshot. Each port gets a poe_outages row before its PoE goes off, then OFF_SECONDS of
 * darkness for all of them, then each switch's ports are switched on one after the other (STAGGER_MS apart, three
 * tries each, read back). What happened goes to cmlogs and back to the operator.
 */
class PoeRestart
{
    const OFF_SECONDS = 5;
    const STAGGER_MS = 500;
    /* The lock of switch N is LOCK.':'.N; Check write access takes it too */
    const LOCK = 'poe-restart';
    // a whole 48-port stand whose switch stops answering: about 8.5 minutes of 2 s timeouts (PoeRestartTest)
    const LOCK_SECONDS = 600;
    const SNAPSHOT_SECONDS = 30;   // a restart decides on a snapshot at most this old

    protected $control, $switch, $user;

    public function __construct(PoeControl $control, EthernetSwitch $switch, ?string $user)
    {
        $this->control = $control;
        $this->switch = $switch;
        $this->user = $user;
    }

    /** Restart the ports these modules are on now */
    public static function modules(iterable $cms, ?string $user): array
    {
        return self::start($user, function (array $stand) use ($cms) {
            $tiles = $skipped = [];
            foreach ($cms as $cm)
            {
                $tile = Stand::tileOf($stand['map'], $cm);
                $why = $tile ? StandMap::restartBlocker($tile) : Stand::notSeen($stand);
                if ($why !== null)
                    $skipped[$cm->serial] = $why;
                else
                    $tiles[$tile['key']] = $tile + ['serial' => $cm->serial];
            }
            return [$tiles, $skipped];
        });
    }

    /** Restart these ports (StandMap tile keys such as "s1p8") */
    public static function ports(array $keys, ?string $user): array
    {
        return self::start($user, function (array $stand) use ($keys) {
            $tiles = $skipped = [];
            foreach ($keys as $key)
            {
                $tile = $stand['map']['tiles'][$key] ?? null;
                $why = $tile ? StandMap::restartBlocker($tile) : 'no such port';
                if ($why !== null)
                    self::skip($skipped, $tile['label'] ?? $key, $why, $tile ? $stand['switches'][$tile['switch_id']]['config']['host'] : null);
                else
                    $tiles[$key] = $tile + ['serial' => $tile['modules'] ? $tile['modules'][0]->serial : null];
            }
            return [$tiles, $skipped];
        });
    }

    /** What the operator reads after a restart: ['text', 'error' => something did not work] */
    public static function message(array $r): array
    {
        if ($r['busy'])
            return ['text' => 'A restart is already running on '.$r['busy'].'.', 'error' => true];
        if ($r['error'])
            return ['text' => $r['error'].'.', 'error' => true];
        $parts = [];
        if (count($r['restarted']) === 1)
        {
            $port = reset($r['restarted']);
            $parts[] = 'Restarted '.$port['label'].($port['serial'] ? ' ('.$port['serial'].')' : '').': PoE was off for '.self::OFF_SECONDS.' s.';
        }
        elseif ($r['restarted'])
            $parts[] = 'Restarted '.count($r['restarted']).' ports: '.implode(', ', array_column($r['restarted'], 'label')).'.';
        foreach ($r['unconfirmed'] ?? [] as $port)
            $parts[] = 'The switch did not confirm switching off '.$port['label'].' ('.$port['why'].'); PoE is on now.';
        foreach ($r['stayed_off'] as $port)
            $parts[] = 'PoE on '.$port['label'].' stays OFF: '.$port['why'].'. Use Power on.';
        foreach ($r['failed'] as $port)
            $parts[] = 'The switch did not switch off '.$port['label'].': '.$port['why'].'.';
        foreach ($r['skipped'] as $what => $why)
            $parts[] = 'Not restarted: '.$what.' ('.$why.').';
        return ['text' => $parts ? implode(' ', $parts) : 'Nothing to restart.',
                'error' => $r['stayed_off'] || $r['failed'] || !$r['restarted']];
    }

    /** The question before restarting one module, or the Raspberry Pi on a port when $cm is null */
    public static function confirm(?Cm $cm, ?string $port): string
    {
        $on = $port ? ' on '.$port : '';
        if (!$cm)
            return 'Restart the Raspberry Pi'.$on.'? PoE goes off for '.self::OFF_SECONDS.' s.';
        if ($cm->phase === 'done')
            $then = 'the module boots the system it was flashed with.';
        elseif ($cm->isRunning() && $cm->silentFor() === null)
            $then = 'its run stops and starts over.';
        else
            $then = 'the module boots from the network and is provisioned again.';
        return 'Restart '.$cm->serial.$on.'? PoE goes off for '.self::OFF_SECONDS.' s; '.$then;
    }

    public static function confirmFailed(int $count): string
    {
        return 'Restart '.$count.' '.($count === 1 ? 'module' : 'modules').' (failed or without a report)? PoE goes off for '
            .self::OFF_SECONDS.' s on each port.';
    }

    /** Why no port of this switch (Stand::current()'s state of it) is restarted now; null when they may be */
    public static function switchBlocker(?array $state): ?string
    {
        if (!$state)
            return 'the switch is gone';
        $name = $state['switch']->hostname();
        if (!PoeControl::writable($state['config']))
            return 'no write access on '.$name;
        if (!Stand::answering($state))
            return $name.' does not answer';
        // while another page asks the switch, the stand comes as it was last seen, which may be long ago
        $age = now()->getTimestamp() - (int) $state['checked_at'];
        return $age > self::SNAPSHOT_SECONDS ? $name.' was last read '.$age.' s ago: try again' : null;
    }

    protected static function start(?string $user, callable $choose): array
    {
        $result = ['restarted' => [], 'unconfirmed' => [], 'skipped' => [], 'failed' => [], 'stayed_off' => [], 'busy' => false, 'error' => null];
        $switches = EthernetSwitch::orderBy('id')->get();
        if ($switches->isEmpty())
            return ['error' => 'No switch is set up: add one under Settings → Ethernet switches'] + $result;
        if (!$switches->contains(function ($switch) { return PoeControl::writable($switch->config()); }))
            return ['error' => 'No write access to the switches: set it under Settings → Ethernet switches'] + $result;

        $stand = Stand::current();
        if (!$stand || !$stand['map'])
            return ['error' => 'No switch answers'.self::errors($stand)] + $result;
        [$tiles, $result['skipped']] = $choose($stand);
        $groups = [];
        foreach ($tiles as $key => $tile)
        {
            $why = self::switchBlocker($stand['switches'][$tile['switch_id']] ?? null);
            if ($why !== null)
                self::skip($result['skipped'], $tile['serial'] ?? $tile['label'], $why, $stand['switches'][$tile['switch_id']]['config']['host'] ?? null);
            else
                $groups[$tile['switch_id']][$key] = $tile;
        }
        if (!$groups)
            return $result;

        ksort($groups);   // locks are always taken in the same order
        $locks = [];
        foreach (array_keys($groups) as $id)
        {
            $lock = Cache::lock(self::LOCK.':'.$id, self::LOCK_SECONDS);
            if (!$lock->get())
            {
                foreach ($locks as $held)
                    $held->release();
                return ['busy' => $stand['switches'][$id]['switch']->hostname()] + $result;
            }
            $locks[] = $lock;
        }
        try
        {
            @set_time_limit(self::LOCK_SECONDS);
            $runs = [];
            foreach ($groups as $id => $group)
            {
                $state = $stand['switches'][$id];
                $names = array_map(function ($port) { return $port['name']; }, $state['snapshot']['ports']);
                try
                {
                    $admin = (new Poe(app(SnmpClient::class, ['config' => $state['config']])))->admin($names);
                }
                catch (\Throwable $e)
                {
                    foreach ($group as $tile)
                        self::skip($result['skipped'], $tile['serial'] ?? $tile['label'], $state['switch']->hostname().' does not answer: '.$e->getMessage(),
                            $state['config']['host']);
                    continue;
                }
                $runs[] = [new self(PoeControl::forConfig($state['config']), $state['switch'], $user), $group, $admin];
            }
            return self::runAll($runs, $result);
        }
        finally
        {
            foreach ($locks as $held)
                $held->release();
        }
    }

    /* " (sw01: No response from …; …)" for the switches that did not answer */
    protected static function errors(?array $stand): string
    {
        $errors = [];
        foreach ($stand['switches'] ?? [] as $state)
            if ($state['error'])
                $errors[] = $state['switch']->hostname().': '.$state['error']['message'];
        return $errors ? ' ('.implode('; ', $errors).')' : '';
    }

    /* Every switch's ports go off first, then one pause, then each switch switches its ports on one after the other */
    protected static function runAll(array $runs, array $result): array
    {
        $off = [];
        foreach ($runs as $n => [$run, $tiles, $admin])
            $off[$n] = $run->switchAllOff($tiles, $admin, $result);
        if (!array_filter($off))
            return $result;

        Sleep::for(self::OFF_SECONDS)->seconds();
        foreach ($runs as $n => [$run])
            foreach ($off[$n] as $i => [$outage, $tile])
            {
                if ($i > 0)
                    Sleep::for(self::STAGGER_MS)->milliseconds();
                try
                {
                    $run->switchOn($outage, $tile, $result);
                }
                catch (\Throwable $e)
                {
                    report($e);   // nothing may keep the next ports off
                    $result['stayed_off'][$tile['key']] = self::entry($tile, $e->getMessage());
                }
            }
        return $result;
    }

    /* Each port's row, then its PoE off: [[outage, tile]] of the ports that may be off now */
    protected function switchAllOff(array $tiles, array $admin, array &$result): array
    {
        $off = [];
        foreach ($tiles as $key => $tile)
        {
            $oid = $admin[$tile['ifIndex']] ?? null;
            if ($oid === null)
            {
                self::skip($result['skipped'], $tile['label'], 'no PoE object to write on '.$tile['label'], $this->switch->host);
                continue;
            }
            // the snapshot may be half a minute old and the ports go off one by one: a module that began writing its
            // bootloader since then would be left with one that no longer boots from the network
            if (self::eepromWriter($tile))
            {
                self::skip($result['skipped'], $tile['serial'] ?? $tile['label'],
                    'the module on '.$tile['label'].' is writing its bootloader EEPROM', $this->switch->host);
                continue;
            }
            $outage = PoeOutage::create(['switch_id' => $this->switch->id, 'switch_host' => $this->switch->host,
                'if_index' => $tile['ifIndex'], 'port' => $tile['label'], 'oid' => $oid, 'on_value' => PoeControl::ON,
                'cm_serial' => $tile['serial'], 'user' => $this->user, 'off_at' => now()]);
            [$state, $why] = $this->switchOff($oid);
            if ($state === 'refused')
            {
                $outage->delete();
                $result['failed'][$key] = self::entry($tile, $why);
                $this->log($tile, 'error', 'PoE restart of '.$tile['label'].' by '.$this->who().' failed: the switch did not switch it off: '.$why);
                continue;
            }
            // without an answer the port may be off as well: it is switched on with the others after the pause
            $off[] = [$outage, $state === 'off' ? $tile : $tile + ['unconfirmed' => $why]];
        }
        return $off;
    }

    /* A module behind this port that writes its bootloader EEPROM now, read from the database at the last moment: by
       the MACs the snapshot saw on the port and by the port its board lookup recorded */
    protected static function eepromWriter(array $tile): ?Cm
    {
        $candidates = Cm::where('phase', 'preinstall')->where(function ($q) use ($tile) {
            $q->whereIn(DB::raw('lower(mac)'), $tile['macs'])
              ->orWhere(function ($q) use ($tile) {
                  $q->where('switch_id', $tile['switch_id'])->whereIn('switch_port', [$tile['board_port'], $tile['name']]);
              });
        })->get();
        foreach ($candidates as $cm)
            if (StandMap::writingEeprom($cm) && $cm->isActive() && $cm->silentFor() === null)
                return $cm;
        return null;
    }

    /* [state, why]: 'off'; 'unknown' when the switch did not answer or php-snmp failed, so the port may be off;
       'refused' when nothing changed: the switch refused, or took the write and the port still reads on */
    protected function switchOff(string $oid): array
    {
        try
        {
            $this->control->switchOff($oid);
        }
        catch (SnmpError $e)
        {
            return [$e->timeout ? 'unknown' : 'refused', $e->getMessage()];
        }
        catch (\Throwable $e)
        {
            return ['unknown', $e->getMessage()];   // a php-snmp warning: whether the write went out is not known
        }
        try
        {
            if ($this->control->value($oid) === PoeControl::ON)
                return ['refused', 'it took the write but the port still reads on'];
        }
        catch (\Throwable $e)
        {
            // the switch took the write: off, as far as it said; switched on after the pause in any case
        }
        return ['off', null];
    }

    protected function switchOn(PoeOutage $outage, array $tile, array &$result): void
    {
        $error = $this->control->switchOnChecked($outage->oid, $outage->on_value);
        if ($error === null)
        {
            $outage->update(['on_at' => now()]);
            if (isset($tile['unconfirmed']))
            {
                $result['unconfirmed'][$tile['key']] = self::entry($tile, $tile['unconfirmed']);
                $this->log($tile, 'warning', 'PoE restart of '.$tile['label'].' by '.$this->who().': the switch did not confirm switching it off ('
                    .$tile['unconfirmed'].'); PoE is on');
            }
            else
            {
                $result['restarted'][$tile['key']] = self::entry($tile, null);
                $this->log($tile, 'info', 'PoE restart of '.$tile['label'].' by '.$this->who().': off for '.self::OFF_SECONDS.' s');
            }
        }
        else
        {
            $outage->update(['error' => $error]);
            $result['stayed_off'][$tile['key']] = self::entry($tile, $error);
            $this->log($tile, 'error', 'PoE on '.$tile['label'].' stays OFF after a restart by '.$this->who().': '.$error);
        }
    }

    /* Why a module or port is not restarted; a second port with the same label (one name for two switches) is told
       apart by its switch's address */
    protected static function skip(array &$skipped, string $what, string $why, ?string $host = null): void
    {
        if (isset($skipped[$what]) && $host !== null)
            $what .= ' ('.$host.')';
        $skipped[$what] = $why;
    }

    /* A port in the result: what the operator reads about it */
    protected static function entry(array $tile, ?string $why): array
    {
        return ['label' => $tile['label'], 'serial' => $tile['serial'] ?? null, 'why' => $why];
    }

    /* A line of the module log; a booting Raspberry Pi has no serial yet */
    protected function log(array $tile, string $level, string $message): void
    {
        Cmlog::create(['cm' => $tile['serial'] ?? '', 'board' => $tile['label'], 'loglevel' => $level, 'msg' => $message]);
    }

    protected function who(): string
    {
        return $this->user ?: 'someone';
    }
}
