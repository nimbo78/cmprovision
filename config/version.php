<?php

// git archive fills these in (.gitattributes: export-subst), so a package built from a tag knows its version and
// commit (App\Support\AppVersion); a working copy keeps the placeholders and the web UI says "dev"
return [
    'describe' => '$Format:%(describe)$',
    'commit' => '$Format:%h$',
];
