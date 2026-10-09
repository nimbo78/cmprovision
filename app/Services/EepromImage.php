<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Reads and replaces the bootloader configuration (bootconf.txt) inside a BCM2711 EEPROM image.
 *
 * The image is a chain of sections: 4-byte magic, 4-byte length, payload, padded to 8 bytes.
 * Modifiable files carry FILE_MAGIC, a 12-byte name and 4 reserved bytes before their content.
 * This is the same layout rpi-eeprom-config works with; the output was verified byte-identical
 * to that tool for images from 2021 to 2026.
 */
class EepromImage
{
    const MAGIC = 0x55aaf00f;
    const MAGIC_MASK = 0xfffff00f;
    const FILE_MAGIC = 0x55aaf11f;
    const FILE_HDR_LEN = 20;
    const FILENAME_LEN = 12;
    const MAX_BOOTCONF_SIZE = 2024;
    const BOOTCONF = "bootconf.txt\0";

    /* Offset and section length of bootconf.txt, or false when the image is not what we expect */
    protected static function findConfig($data)
    {
        $offset = 0;

        while ($offset + 8 < strlen($data))
        {
            list($magic, $len) = array_values(unpack("Nmagic/Nlen", $data, $offset));
            if (($magic & self::MAGIC_MASK) != self::MAGIC)
                return false;   // not a section header: image corrupt or truncated

            if ($magic == self::FILE_MAGIC && Str::startsWith(substr($data, $offset + 8, self::FILE_HDR_LEN), self::BOOTCONF))
                return [$offset, $len];

            $offset += 8 + $len;
            $offset = ($offset + 7) & ~7;
        }

        return false;
    }

    /* The configuration text stored in the image, or false */
    public static function getConfig($data)
    {
        $found = self::findConfig($data);
        if (!$found)
            return false;

        list($offset, $len) = $found;
        $datalen = $len - self::FILENAME_LEN - 4;
        if ($datalen < 0)
            return false;

        return substr($data, $offset + 4 + self::FILE_HDR_LEN, $datalen);
    }

    /* Settings the way rpi-eeprom-config stores them: LF only, one newline at the end (none when
       empty). They may arrive with CRLF from a browser or trimmed by the API. */
    public static function normalizeConfig($settings)
    {
        $settings = rtrim(str_replace("\r", "", (string) $settings));
        return $settings === '' ? '' : $settings."\n";
    }

    /* Replaces the configuration text in the image (in place); false when the image is not usable */
    public static function setConfig(&$data, $settings)
    {
        $found = self::findConfig($data);
        if (!$found)
            return false;

        list($offset) = $found;
        $newlen = strlen($settings) + self::FILENAME_LEN + 4;
        $data = substr($data, 0, $offset + 4).pack("N", $newlen).substr($data, $offset + 8);
        $padded = str_pad($settings, self::MAX_BOOTCONF_SIZE, "\xff");
        $data = substr($data, 0, $offset + 4 + self::FILE_HDR_LEN).$padded
              .substr($data, $offset + 4 + self::FILE_HDR_LEN + self::MAX_BOOTCONF_SIZE);

        return true;
    }
}
