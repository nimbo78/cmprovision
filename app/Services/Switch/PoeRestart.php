<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\PoeOutage;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * PoE restart of modules (docs/superpowers/specs/2026-10-12-poe-restart-design.md). One at a time for the whole
 * stand, only on ports with nothing but our modules or a booting Pi (StandMap::restartBlocker()), checked on a
 * fresh snapshot. Each port gets a poe_outages row before its PoE goes off, then OFF_SECONDS of darkness, then the
 * ports are switched on one after the other (STAGGER_MS apart, three tries each, read back). What happened goes
 * to cmlogs and back to the operator.
 */
class PoeRestart
{
    const OFF_SECONDS = 5;
    const STAGGER_MS = 500;
    const LOCK_SECONDS = 120;

    protected $control, $host, $user;

    public function __construct(PoeControl $control, string $host, ?string $user)
    {
        $this->control = $control;
        $this->host = $host;
        $this->user = $user;
    }

    /** Restart the ports these modules are on now */
    public static function modules(iterable $cms, ?string $user): array
    {
        return self::start($user, function (array $map) use ($cms) {
            $tiles = $skipped = [];
            foreach ($cms as $cm)
            {
                $tile = Stand::tileOf($map, $cm);
                $why = $tile ? StandMap::restartBlocker($tile) : 'not seen on the switch';
                if ($why !== null)
                    $skipped[$cm->serial] = $why;
                else
                    $tiles[$tile['key']] = $tile + ['serial' => $cm->serial];
            }
            return [$tiles, $skipped];
        });
    }

    /** Restart these ports (StandMap tile keys such as "p8") */
    public static function ports(array $keys, ?string $user): array
    {
        return self::start($user, function (array $map) use ($keys) {
            $tiles = $skipped = [];
            foreach ($keys as $key)
            {
                $tile = $map['tiles'][$key] ?? null;
                $why = $tile ? StandMap::restartBlocker($tile) : 'no such port';
                if ($why !== null)
                    $skipped[$tile['name'] ?? $key] = $why;
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
            return ['text' => 'A restart is already running.', 'error' => true];
        if ($r['error'])
            return ['text' => $r['error'].'.', 'error' => true];
        $parts = [];
        if (count($r['restarted']) === 1)
        {
            $port = array_key_first($r['restarted']);
            $serial = $r['restarted'][$port];
            $parts[] = 'Restarted '.$port.($serial ? ' ('.$serial.')' : '').': PoE was off for '.self::OFF_SECONDS.' s.';
        }
        elseif ($r['restarted'])
            $parts[] = 'Restarted '.count($r['restarted']).' ports: '.implode(', ', array_keys($r['restarted'])).'.';
        foreach ($r['stayed_off'] as $port => $why)
            $parts[] = 'PoE on '.$port.' stays OFF: '.$why.'. Use Power on.';
        foreach ($r['failed'] as $port => $why)
            $parts[] = 'The switch did not switch off '.$port.': '.$why.'.';
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

    protected static function start(?string $user, callable $choose): array
    {
        $result = ['restarted' => [], 'skipped' => [], 'failed' => [], 'stayed_off' => [], 'busy' => false, 'error' => null];
        $config = SwitchPortFinder::config();
        $control = PoeControl::forConfig($config);
        if (!$control)
            return ['error' => 'No write access to the switch: set it under Settings → Ethernet switch'] + $result;

        $lock = Cache::lock('poe-restart', self::LOCK_SECONDS);
        if (!$lock->get())
            return ['busy' => true] + $result;
        try
        {
            @set_time_limit(self::LOCK_SECONDS);
            $stand = Stand::current();
            if (!$stand['map'] || $stand['error'])
                return ['error' => 'The switch does not answer'.($stand['error'] ? ': '.$stand['error']['message'] : '')] + $result;
            [$tiles, $result['skipped']] = $choose($stand['map']);
            if (!$tiles)
                return $result;
            $names = array_map(function ($port) { return $port['name']; }, $stand['snapshot']['ports']);
            $admin = (new Poe(app(SnmpClient::class, ['config' => $config])))->admin($names);
            return (new self($control, $config['host'], $user))->run($tiles, $admin, $result);
        }
        finally
        {
            $lock->release();
        }
    }

    protected function run(array $tiles, array $admin, array $result): array
    {
        $off = [];
        foreach ($tiles as $tile)
        {
            $oid = $admin[$tile['ifIndex']] ?? null;
            if ($oid === null)
            {
                $result['skipped'][$tile['name']] = 'no PoE object to write on '.$tile['name'];
                continue;
            }
            $outage = PoeOutage::create(['switch_host' => $this->host, 'if_index' => $tile['ifIndex'], 'port' => $tile['name'],
                'oid' => $oid, 'on_value' => PoeControl::ON, 'cm_serial' => $tile['serial'], 'user' => $this->user, 'off_at' => now()]);
            try
            {
                $this->control->switchOff($oid);
                $off[] = [$outage, $tile];
            }
            catch (SnmpError $e)
            {
                $outage->delete();
                $result['failed'][$tile['name']] = $e->getMessage();
                $this->log($tile, 'error', 'PoE restart of '.$tile['name'].' by '.$this->who().' failed: the switch did not switch it off: '.$e->getMessage());
            }
        }
        if (!$off)
            return $result;

        Sleep::for(self::OFF_SECONDS)->seconds();
        foreach ($off as $n => [$outage, $tile])
        {
            if ($n > 0)
                Sleep::for(self::STAGGER_MS)->milliseconds();
            $error = $this->control->switchOnChecked($outage->oid, $outage->on_value);
            if ($error === null)
            {
                $outage->update(['on_at' => now()]);
                $result['restarted'][$tile['name']] = $tile['serial'];
                $this->log($tile, 'info', 'PoE restart of '.$tile['name'].' by '.$this->who().': off for '.self::OFF_SECONDS.' s');
            }
            else
            {
                $outage->update(['error' => $error]);
                $result['stayed_off'][$tile['name']] = $error;
                $this->log($tile, 'error', 'PoE on '.$tile['name'].' stays OFF after a restart by '.$this->who().': '.$error);
            }
        }
        return $result;
    }

    protected function log(array $tile, string $level, string $message): void
    {
        Cmlog::create(['cm' => $tile['serial'], 'board' => $tile['name'], 'loglevel' => $level, 'msg' => $message]);
    }

    protected function who(): string
    {
        return $this->user ?: 'someone';
    }
}
