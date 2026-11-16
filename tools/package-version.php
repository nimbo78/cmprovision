<?php
// Stamp the staged package, keeping the commit supplied by git archive.
if ($argc !== 3 || !is_file($argv[1]) || !preg_match('/\A[0-9][A-Za-z0-9.+:~_-]{0,127}\z/D', $argv[2])) {
    fwrite(STDERR, "usage: php package-version.php <staged-config.php> <Debian-version>\n");
    exit(2);
}
$version = require $argv[1];
if (!is_array($version)) throw new RuntimeException('Invalid staged version configuration.');
$version['package'] = $argv[2];
$contents = "<?php\nreturn ".var_export($version, true).";\n";
if (file_put_contents($argv[1], $contents) !== strlen($contents)) throw new RuntimeException('Could not stamp package version.');
