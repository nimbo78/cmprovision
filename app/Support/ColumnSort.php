<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Sorting of a list by the header of a column (2.15.0). A column is a key with the value of a row (a number, a text or
 * null) and the direction of the first click: a time starts with the latest, a text with A to Z. A click on the same header
 * turns the direction round, the third click gives the default order of the list back. Rows with no value are always last,
 * and rows that tie keep the default order.
 *
 * $columns = ['key' => ['value' => fn ($row) => int|float|string|null, 'first' => 'asc'|'desc']]
 */
class ColumnSort
{
    /** [sort, dir] after a click on $key */
    public static function next(array $columns, string $sort, string $dir, string $key): array
    {
        if (!isset($columns[$key]))
            return [$sort, $dir];
        $first = $columns[$key]['first'] ?? 'asc';
        if ($sort !== $key)
            return [$key, $first];
        if ($dir === $first)
            return [$key, $first === 'asc' ? 'desc' : 'asc'];
        return ['', ''];
    }

    /** $default: the comparator of the list when no column is chosen, and the tie breaker */
    public static function apply(Collection $rows, array $columns, string $sort, string $dir, \Closure $default): Collection
    {
        if (!isset($columns[$sort]))
            return $rows->sort($default)->values();
        $value = $columns[$sort]['value'];
        $sign = $dir === 'desc' ? -1 : 1;
        // the value of every row once, not at every comparison
        $pairs = $rows->map(fn ($row) => [$row, $value($row)])->values()->all();
        usort($pairs, function ($x, $y) use ($sign, $default) {
            [$a, $va] = $x;
            [$b, $vb] = $y;
            if ($va === null || $vb === null) {
                if ($va !== $vb)
                    return $va === null ? 1 : -1;   // no value: last, whatever the direction
            } else {
                $c = (is_int($va) || is_float($va)) && (is_int($vb) || is_float($vb)) ? $va <=> $vb : strnatcasecmp((string) $va, (string) $vb);
                if ($c !== 0)
                    return $c * $sign;
            }
            return $default($a, $b);
        });
        return collect(array_column($pairs, 0));
    }
}
