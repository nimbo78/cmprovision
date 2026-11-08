<?php
// Inspect the actual production payload, independently of the package source tests.
[$script, $app, $version, $source] = $argv;
$metadata = require "$app/config/version.php";
if ($metadata['describe'] !== "v$version" || $metadata['commit'] !== substr($source, 0, strlen($metadata['commit']))
    || !preg_match('/^[0-9a-f]{7,40}$/', $metadata['commit'])) {
    throw new RuntimeException('Packaged version does not match the selected source tag');
}
$installed = json_decode(file_get_contents("$app/vendor/composer/installed.json"), true, flags: JSON_THROW_ON_ERROR);
if (($installed['dev'] ?? true) !== false || ($installed['dev-package-names'] ?? []) !== []) {
    throw new RuntimeException('Development dependencies in the production package');
}
$paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($paths as $path) {
    $name = $path->getFilename();
    if ($name === '.env' || $name === '.git' || str_ends_with($name, '.bat')
        || ($path->isFile() && str_ends_with($name, '.sqlite') && $path->getSize() > 0)) {
        throw new RuntimeException('Unexpected private or development payload: '.$path->getPathname());
    }
}
echo "Production package source and dependency audit passed.\n";
