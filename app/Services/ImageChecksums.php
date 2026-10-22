<?php

namespace App\Services;

/** The checksums sources publish for their images: "<hex>  <name>" lines (sha256sum), a bare hex, "SHA256 (<name>) = <hex>" (BSD) */
class ImageChecksums
{
    /* is this text a sha256 (what an operator may type instead of the address of a checksum file) */
    public static function isSum(string $text): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/i', trim($text));
    }

    public static function normalize(string $text): string
    {
        return strtolower(trim($text));
    }

    /**
     * The sha256 in a checksum file: with a name, the one of that file (the last part of the name on the line must be exactly it);
     * without one, the first. A line of nothing but a hex is the sum of the file the checksum file belongs to.
     */
    public static function parse(string $text, ?string $name = null): ?string
    {
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if (preg_match('/^([0-9a-f]{64})$/i', $line, $m)) {
                return strtolower($m[1]);
            }
            if (preg_match('/^([0-9a-f]{64})[ \t]+\*?(.+)$/i', $line, $m)) {
                $sum = strtolower($m[1]);
                $file = $m[2];
            } elseif (preg_match('/^SHA256 \((.+)\) = ([0-9a-f]{64})$/i', $line, $m)) {
                $sum = strtolower($m[2]);
                $file = $m[1];
            } else {
                continue;
            }
            if ($name === null || basename(str_replace('\\', '/', $file)) === $name) {
                return $sum;
            }
        }

        return null;
    }
}
