<?php

namespace App\Models;

/**
 * A bootloader (EEPROM) image in the local firmware store: <basedir>/<channel>/pieeprom-*.bin.
 * "default" and "latest" are the channels Raspberry Pi publishes today; "stable", "beta" and
 * "critical" are their old names, still listed when a store downloaded years ago has them,
 * because existing projects may be pinned to those files.
 */
class Firmware
{
    const CHANNELS = ['default', 'latest', 'stable', 'beta', 'critical'];

    public $name, $channel, $path;

    public static function allOfChannel($channel)
    {
        $entries = [];
        $dir = self::basedir()."/$channel";

        if (@is_dir($dir) && @is_readable($dir))
        {
            $files = scandir($dir, SCANDIR_SORT_DESCENDING);
            foreach ($files as $f)
            {
                if (preg_match('/^pieeprom-.+\\.bin$/', $f))
                {
                    $entry = new Firmware;
                    $entry->name = $f;
                    $entry->channel = $channel;
                    $entry->path = $channel.'/'.$f;
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    public static function all()
    {
        $entries = [];

        foreach (self::CHANNELS as $channel)
        {
            $entries = array_merge($entries, self::allOfChannel($channel));
        }

        return $entries;
    }

    /* Channels that currently offer at least one image, in display order */
    public static function channels()
    {
        return array_values(array_filter(self::CHANNELS, function ($channel) {
            return count(self::allOfChannel($channel)) > 0;
        }));
    }

    public static function basedir()
    {
        return config('cmprovision.firmware_dir');
    }
}
