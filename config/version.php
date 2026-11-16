<?php

// git archive supplies ancestry and commit (.gitattributes: export-subst). debian/rules stamps the
// installed package version; an ordinary working copy keeps placeholders and says "dev".
return [
    'package' => null, // stamped by debian/rules with the actual .deb version
    'describe' => '$Format:%(describe)$',
    'commit' => '$Format:%h$',
];
