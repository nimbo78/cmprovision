<?php

namespace App\Support;

/**
 * The server's time zone as the operating system has it (timedatectl, raspi-config), for showing
 * times to the operator. PHP cannot be asked: Debian 11's PHP guesses the system zone, Debian 13's
 * PHP 8.4 reports UTC whatever the system is set to (and php.ini's date.timezone defaults to "UTC").
 */
class SystemTimezone
{
    /** Re-resolve in long-lived display publishers after an operator changes the system zone. */
    public function current(): string
    {
        return self::detect(config('app.display_timezone_override'));
    }
    /**
     * @param string|null $preferred an explicit choice (APP_DISPLAY_TIMEZONE), used when it is valid
     * @param string      $etc       directory holding timezone and localtime
     */
    public static function detect(?string $preferred = null, string $etc = '/etc'): string
    {
        clearstatcache(true, $etc.'/localtime');
        $candidates = [$preferred, self::fromLink($etc.'/localtime'), self::fromFile($etc.'/timezone')];
        foreach ($candidates as $zone) {
            if ($zone !== null && $zone !== '' && self::isValid($zone)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    /** /etc/timezone: "Europe/Moscow" (Debian keeps it next to the localtime link) */
    private static function fromFile(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : trim($content);
    }

    /** /etc/localtime -> /usr/share/zoneinfo/Europe/Moscow (absolute or relative link) */
    private static function fromLink(string $path): ?string
    {
        if (!is_link($path)) {
            return null;
        }
        $target = @readlink($path);
        if ($target === false || !preg_match('#zoneinfo/(?:posix/|right/)?([^/].*)$#', $target, $m)) {
            return null;
        }

        return $m[1];
    }

    public static function isValid(string $zone): bool
    {
        try {
            new \DateTimeZone($zone);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
