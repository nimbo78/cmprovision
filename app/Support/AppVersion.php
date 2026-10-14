<?php

namespace App\Support;

/* The version and commit the user menu names (config/version.php, filled in by git archive) */
class AppVersion
{
    /** "2.4.1 · 3f3b4bf"; "2.4.1+3 · 8d0e3ed" three commits after the tag; "dev" in a working copy */
    public static function text(): string
    {
        $describe = (string) config('version.describe');
        $commit = (string) config('version.commit');
        if ($describe === '' || str_starts_with($describe, '$Format') || str_starts_with($commit, '$Format'))
            return 'dev';
        $version = preg_replace('/-(\d+)-g[0-9a-f]+$/', '+$1', preg_replace('/^v/', '', $describe));
        return $version.($commit !== '' ? ' · '.$commit : '');
    }
}
