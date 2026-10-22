<?php

namespace App\Support;

/** Verified front panels, seen from the front (sources and supported models: docs/switch-front-panels.md).
 * A profile is used only when every reported port maps to exactly one expected connector. Unknown models,
 * incomplete polls, stacks and extra interfaces retain the automatic layout; no port is invented or hidden.
 */
class SwitchFrontPanel
{
    public static function resolve(?string $model, array $tiles): ?array
    {
        $model = strtoupper(trim((string) $model));
        if (preg_match('/^(?:WS-C|WS-)?2960CX-8(?:PC|TC)-L$/', $model))
        {
            $ports = self::index($tiles, '/^(?:Gi|GigabitEthernet)0\/(\d+)$/i');
            if ($ports === null || !self::complete($ports, 12))
                return null;
            $blocks = [
                self::bank($ports, [1, 2, 3, 4], 2, false, 'copper', 'RJ45'),
                self::bank($ports, [5, 6, 7, 8], 2, false, 'copper', 'RJ45'),
                self::bank($ports, [9, 10], 1, true, 'copper', 'RJ45 uplinks'),
                // The case labels the SFP slots 1 and 2; IOS names them Gi0/11 and Gi0/12.
                self::bank($ports, [11, 12], 1, true, 'sfp', 'SFP', [1, 2]),
            ];
            return ['model' => $model, 'blocks' => $blocks];
        }

        if (preg_match('/^C9200L-(24|48)[PT]-4([GX])(?:-[AE])?$/', $model, $m))
        {
            $count = (int) $m[1];
            $main = $uplinks = [];
            $member = null;
            foreach ($tiles as $tile)
            {
                if (!preg_match('/^(Gi|GigabitEthernet|Te|TenGigabitEthernet)(\d+)\/([01])\/(\d+)$/i', $tile['name'], $p))
                    return null;
                if ($member !== null && $member !== $p[2])
                    return null;
                $member = $p[2];
                $number = (int) $p[4];
                $gigabit = in_array(strtolower($p[1]), ['gi', 'gigabitethernet'], true);
                if ($p[3] === '0')
                {
                    if (!$gigabit || isset($main[$number]))
                        return null;
                    $main[$number] = $tile;
                }
                else
                {
                    if (($m[2] === 'G' && !$gigabit) || isset($uplinks[$number]))
                        return null;
                    $uplinks[$number] = $tile;
                }
            }
            if (!self::complete($main, $count) || !self::complete($uplinks, 4))
                return null;
            $blocks = [];
            foreach (array_chunk(range(1, $count), 12) as $numbers)
                $blocks[] = self::bank($main, $numbers, 2, false, 'copper', 'RJ45');
            $blocks[] = self::bank($uplinks, [1, 2, 3, 4], 1, true, 'sfp', $m[2] === 'X' ? 'SFP+' : 'SFP');
            return ['model' => $model, 'blocks' => $blocks];
        }
        if (preg_match('/^(?:ROUTEROS )?CRS309(?:-1G-8S\+(?:IN)?)?$/', $model))
        {
            $fiber = [];
            $ethernet = null;
            foreach ($tiles as $tile)
            {
                if (strcasecmp($tile['name'], 'ether1') === 0 && $ethernet === null)
                    $ethernet = $tile;
                elseif (preg_match('/^sfp-sfpplus([1-8])$/i', $tile['name'], $p) && !isset($fiber[(int) $p[1]]))
                    $fiber[(int) $p[1]] = $tile;
                else
                    return null;
            }
            if ($ethernet === null || !self::complete($fiber, 8))
                return null;
            // All nine interfaces are switched ports. PoE in on ether1 does not mean it supplies PoE.
            return ['model' => $model, 'blocks' => [
                self::bank($fiber, range(1, 8), 1, false, 'sfp', 'SFP+'),
                self::bank([1 => $ethernet], [1], 1, false, 'copper', 'PoE in'),
            ]];
        }
        return null;
    }

    protected static function index(array $tiles, string $pattern): ?array
    {
        $ports = [];
        foreach ($tiles as $tile)
        {
            if (!preg_match($pattern, $tile['name'], $m) || isset($ports[(int) $m[1]]))
                return null;
            $ports[(int) $m[1]] = $tile;
        }
        return $ports;
    }

    protected static function complete(array $ports, int $count): bool
    {
        return count($ports) === $count && !array_diff(range(1, $count), array_keys($ports));
    }

    protected static function bank(array $ports, array $numbers, int $rows, bool $uplinks, string $media, string $label, ?array $printed = null): array
    {
        $tiles = [];
        foreach ($numbers as $i => $number)
            $tiles[] = array_merge($ports[$number], ['panel_number' => $printed[$i] ?? $number]);
        return ['rows' => $rows, 'uplinks' => $uplinks, 'media' => $media, 'label' => $label, 'tiles' => $tiles];
    }
}
