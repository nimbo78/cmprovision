<?php

namespace App\Services\Switch;

use App\Models\Cm;
use Illuminate\Support\Carbon;

/* The port of a module that has just asked for its script: the stand map's port for its MAC (Stand, the map every page
   uses), as a board "hostname port"; a warning names the switches that did not answer */
class PortLookup
{
    /* A module start waits this long for another request's poll of a switch; a page waits a second */
    const WAIT = 10;

    /** ['switch' => EthernetSwitch or null, 'port' => the port's description or name, 'board' => "hostname port",
        "[multiple ports]" or null, 'warning' => null or why the port is unknown or which switches are silent].
        $since: when the module's request came; a snapshot taken before it may not know the module yet */
    public static function find(string $serial, string $mac, int $since): array
    {
        $mac = strtolower($mac);
        $none = ['switch' => null, 'port' => null, 'board' => null, 'warning' => null];
        // the module may have no row yet: the map still has to count its MAC as a module's
        $module = new Cm(['serial' => $serial, 'mac' => $mac]);
        $stand = Stand::current(null, self::WAIT, [$module]);
        if (!$stand)
            return $none;
        // no port of its own yet (unknown, behind a link only, or a tie): the switches may have learnt it after their
        // last poll, and a slow switch's snapshot stays fresh for twice its poll time
        if (($stand['map']['owners'][$mac]['key'] ?? null) === null)
            $stand = Stand::current($since, self::WAIT, [$module]);

        $silent = [];
        foreach ($stand['switches'] as $state)
            if (!Stand::answering($state))
                $silent[] = $state['switch']->hostname().' does not answer'.($state['error'] ? ' ('.$state['error']['message'].')' : '');
        $silentText = $silent ? implode('; ', $silent) : null;
        $owner = $stand['map']['owners'][$mac] ?? null;
        $tile = $owner && $owner['key'] !== null ? $stand['map']['tiles'][$owner['key']] : null;

        // a switch that does not answer shows where the module was when it last did: it may be elsewhere by now
        $state = $tile ? $stand['switches'][$tile['switch_id']] : null;
        if ($tile && !Stand::answering($state))
            return ['warning' => 'Switch port unknown: MAC '.$mac.' last seen on '.$tile['label'].' at '
                .Carbon::createFromTimestamp($state['snapshot']['taken_at'])->local()->format('H:i:s').'; '.$silentText] + $none;
        // other addresses on its port may be a link to a silent switch nobody can tell now
        if ($tile && !($silent && $tile['others'] > 0))
            return ['switch' => $stand['switches'][$tile['switch_id']]['switch'], 'port' => $tile['board_port'],
                    'board' => $tile['board'], 'warning' => $silentText];
        if ($owner && $owner['key'] === null && !$silent && count($owner['seen']) > 1)
            return ['board' => '[multiple ports]'] + $none;
        if ($owner)
        {
            $where = implode(', ', array_map(function ($key) use ($stand) {
                $t = $stand['map']['tiles'][$key];
                return $t['label'].($t['link'] ? ' (link to '.$t['link'].')' : '');
            }, $owner['seen']));
            return ['warning' => 'Switch port unknown: MAC '.$mac.' seen behind '.$where.($silentText ? '; '.$silentText : '')] + $none;
        }
        $asked = [];
        foreach ($stand['switches'] as $state)
            if (Stand::answering($state))
                $asked[] = $state['switch']->hostname();
        return ['warning' => 'Switch port unknown: '
            .($asked ? implode(', ', $asked).' '.(count($asked) === 1 ? 'does' : 'do').' not know MAC '.$mac : 'no switch answers')
            .($silentText ? '; '.$silentText : '')] + $none;
    }
}
