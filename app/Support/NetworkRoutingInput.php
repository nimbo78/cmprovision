<?php

namespace App\Support;

use InvalidArgumentException;

/** Parse the documented route/rule notation; the privileged controller validates IP semantics. */
class NetworkRoutingInput
{
    public static function lines(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $text)), fn ($v) => $v !== ''));
    }

    public static function routes(?string $text): array
    {
        $routes = [];
        foreach (self::lines($text) as $line) {
            $tokens = preg_split('/\s+/', $line);
            $route = ['destination' => array_shift($tokens)];
            while ($tokens) {
                $part = array_shift($tokens);
                if (str_contains($part, '=')) {
                    [$key, $value] = explode('=', $part, 2);
                    if (!in_array($key, ['table', 'mtu', 'src', 'onlink', 'type'], true) || isset($route[$key]))
                        throw new InvalidArgumentException('Unknown or repeated route attribute. Use table, mtu, src, onlink or type.');
                    if (in_array($key, ['table', 'mtu'], true)) $value = self::number($value);
                    if ($key === 'onlink') {
                        if (!in_array($value, ['true', 'false'], true)) throw new InvalidArgumentException('Use onlink=true or onlink=false.');
                        $value = $value === 'true';
                    }
                    $route[$key] = $value;
                } elseif ($part === 'via' || $part === 'metric') {
                    $key = $part === 'via' ? 'via' : 'metric';
                    if (!$tokens || isset($route[$key])) throw new InvalidArgumentException('A route has a missing or repeated gateway/metric.');
                    $value = array_shift($tokens);
                    $route[$key] = $key === 'metric' ? self::number($value) : $value;
                } elseif (ctype_digit($part)) {
                    if (isset($route['metric'])) throw new InvalidArgumentException('A route has more than one metric.');
                    $route['metric'] = self::number($part);
                } else {
                    if (isset($route['via'])) throw new InvalidArgumentException('Use: prefix, optional gateway and metric, then attribute=value.');
                    $route['via'] = $part;
                }
            }
            $routes[] = $route;
        }
        return $routes;
    }

    public static function rules(?string $text): array
    {
        $rules = [];
        foreach (self::lines($text) as $line) {
            $tokens = preg_split('/\s+/', $line);
            $rule = [];
            while ($tokens) {
                $key = array_shift($tokens);
                if ($key === 'lookup') $key = 'table';
                if (!$tokens || !in_array($key, ['priority', 'table', 'from', 'to', 'fwmark', 'iif', 'oif'], true) || isset($rule[$key]))
                    throw new InvalidArgumentException('Use priority N, optional from/to prefixes or fwmark/interface, and table N.');
                $value = array_shift($tokens);
                if ($key === 'fwmark') {
                    $parts = explode('/', $value);
                    if (count($parts) > 2) throw new InvalidArgumentException('Use fwmark NUMBER or NUMBER/MASK.');
                    $rule[$key] = self::number($parts[0], true);
                    if (isset($parts[1])) $rule['fwmask'] = self::number($parts[1], true);
                } else {
                    $rule[$key] = in_array($key, ['priority', 'table'], true) ? self::number($value) : $value;
                }
            }
            if (!isset($rule['priority'], $rule['table'])) throw new InvalidArgumentException('Each routing rule needs a numeric priority and table.');
            $rules[] = $rule;
        }
        return $rules;
    }

    private static function number(string $text, bool $hex = false): int
    {
        if ($hex && preg_match('/^0x[0-9a-f]{1,8}$/iD', $text)) return (int) hexdec(substr($text, 2));
        if (!ctype_digit($text) || strlen($text) > 10 || (int) $text > 4294967295)
            throw new InvalidArgumentException('Routing numbers must be unsigned 32-bit integers.');
        return (int) $text;
    }
}
