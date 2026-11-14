<?php

namespace App\Support;

/* Package version when installed; git ancestry in archived source; dev in a working copy. */
class AppVersion
{
    /** "2.4.1 · 3f3b4bf"; "2.4.1+3 · 8d0e3ed" three commits after the tag; "dev" in a working copy */
    public static function text(): string
    {
        $describe = (string) config('version.describe');
        $commit = (string) config('version.commit');
        $package = (string) config('version.package');
        if ($package !== '') {
            if (str_starts_with($commit, '$Format')) $commit = '';
            return $package.($commit !== '' ? ' · '.$commit : '');
        }
        if ($describe === '' || str_starts_with($describe, '$Format') || str_starts_with($commit, '$Format'))
            return 'dev';
        $version = preg_replace('/-(\d+)-g[0-9a-f]+$/', '+$1', preg_replace('/^v/', '', $describe));
        return $version.($commit !== '' ? ' · '.$commit : '');
    }
}
