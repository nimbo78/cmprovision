<?php

namespace App\Services;

/* What a .deb says about itself (dpkg-deb --field): its package, version and architecture. A method of an instance
   from the container, so that a test can stand in for dpkg-deb. */
class DebPackage
{
    /** ['package' => ..., 'version' => ..., 'architecture' => ...], or null when dpkg-deb does not read the file */
    public function fields(string $path): ?array
    {
        $out = [];
        $code = 1;
        // several fields come back as "Field: value" lines
        @exec('dpkg-deb --field '.escapeshellarg($path).' Package Version Architecture 2>/dev/null', $out, $code);
        if ($code !== 0)
            return null;
        $fields = [];
        foreach ($out as $line)
            if (preg_match('/^(Package|Version|Architecture):\s*(\S.*)$/', $line, $m))
                $fields[strtolower($m[1])] = trim($m[2]);
        return isset($fields['package'], $fields['version'], $fields['architecture']) ? $fields : null;
    }
}
