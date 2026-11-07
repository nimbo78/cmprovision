<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use App\Services\DebPackage;
use App\Services\ImageVerification;
use Illuminate\Support\Arr;
use RuntimeException;
use ZipArchive;

/** Read untrusted transfer bytes only into a private operation, without registering application objects. */
class TransferReader
{
    public function __construct(private TransferWorkspace $workspace, private TransferCatalog $catalog,
        private TransferCipher $cipher, private TransferPassword $masterPassword, private ImageVerification $images,
        private DebPackage $packages)
    {
    }

    public function read(ConfigurationTransferOperation $operation, string $source, #[\SensitiveParameter] ?string $password): array
    {
        $input = $this->workspace->path($operation, 'source');
        if (!is_file($source) || is_link($source)) throw new RuntimeException('Choose a regular configuration file.');
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > TransferCatalog::MAX_ARCHIVE_BYTES + 2 * 1024 * 1024) {
            throw new RuntimeException('The source exceeds the 64 GiB transfer limit or is empty.');
        }
        if (realpath($source) !== realpath($input)) {
            $this->space($operation, $size);
            if (is_file($input)) unlink($input);
            $stream = fopen($source, 'rb');
            if ($stream === false) throw new RuntimeException('The configuration file could not be read.');
            try {
                $this->copy($operation, $stream, $input, $size);
            } finally {
                fclose($stream);
            }
        }
        $stream = fopen($input, 'rb');
        if ($stream === false) throw new RuntimeException('The private source file is unavailable.');
        $prefix = fread($stream, 16);
        fclose($stream);
        $protected = false;
        $format = 'json';
        $decoded = null;
        try {
            if (str_starts_with($prefix, 'CMPROVISION')) {
                $password ??= $this->masterPassword->read();
                if ($password === null) throw new RuntimeException('Enter the password for this protected export.');
                $decoded = $this->workspace->path($operation, 'decoded');
                $this->space($operation, $size);
                $kind = $this->cipher->decrypt($input, $decoded, $password);
                if ($kind !== 'archive' || filesize($decoded) > TransferCatalog::MAX_ARCHIVE_BYTES) {
                    throw new RuntimeException('The protected file must contain a supported archive.');
                }
                $protected = true;
                $format = 'archive';
                [$manifest, $files] = $this->archive($operation, $decoded);
            } elseif (str_starts_with($prefix, 'PK')) {
                $format = 'archive';
                [$manifest, $files] = $this->archive($operation, $input);
            } else {
                $text = $this->text($input, 32 * 1024 * 1024);
                $envelope = $this->json($text);
                if (array_key_exists('protection', $envelope)) {
                    $password ??= $this->masterPassword->read();
                    if ($password === null) throw new RuntimeException('Enter the password for this protected export.');
                    $text = $this->cipher->decodeText($text, $password);
                    $protected = true;
                }
                if (strlen($text) > TransferCatalog::MAX_JSON_BYTES) throw new RuntimeException('The configuration manifest exceeds 16 MiB.');
                $manifest = $this->catalog->validate($this->json($text));
                $files = [];
                foreach ($manifest['objects'] as $object) {
                    if (isset($object['file'])) {
                        $this->extension($object);
                        if ($object['file']['included']) throw new RuntimeException('A JSON transfer cannot contain binary files.');
                    }
                }
            }
            if (!$protected) {
                foreach ($manifest['objects'] as $object) {
                    foreach (TransferCatalog::secretPaths($object['type'], $object['data']) as $path) {
                        $value = Arr::get($object['data'], $path);
                        if ($value !== null && $value !== '') throw new RuntimeException('This transfer contains secrets. Use a password-protected export.');
                    }
                }
            }
            return ['manifest' => $manifest, 'files' => $files, 'protected' => $protected, 'format' => $format];
        } finally {
            if ($decoded !== null && is_file($decoded)) unlink($decoded);
            if (is_string($password) && $password !== '') sodium_memzero($password);
        }
    }

    private function archive(ConfigurationTransferOperation $operation, string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) throw new RuntimeException('The archive is invalid or damaged.');
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > TransferCatalog::MAX_ENTRIES) throw new RuntimeException('The archive has too many entries.');
            $members = [];
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || isset($members[$stat['name']]) || !is_string($stat['name'])
                    || !preg_match('#^(manifest\.json|files/(image|package|firmware)-[1-9][0-9]{0,4}\.(gz|xz|bz2|deb|bin))$#D', $stat['name'])
                    || !in_array($stat['comp_method'], [ZipArchive::CM_STORE, ZipArchive::CM_DEFLATE], true)
                    || ($stat['encryption_method'] ?? 0) !== 0 || $stat['size'] < 1) {
                    throw new RuntimeException('The archive has a duplicate, unsafe or unsupported member.');
                }
                $system = $attributes = 0;
                if (!$zip->getExternalAttributesIndex($index, $system, $attributes)) throw new RuntimeException('The archive member metadata is unreadable.');
                $mode = ($attributes >> 16) & 0170000;
                if ($system === ZipArchive::OPSYS_UNIX && !in_array($mode, [0, 0100000], true)) {
                    throw new RuntimeException('Archive links and special files are not supported.');
                }
                $expanded += $stat['size'];
                if ($expanded > TransferCatalog::MAX_ARCHIVE_BYTES) throw new RuntimeException('The unpacked archive exceeds 64 GiB.');
                $members[$stat['name']] = $stat;
            }
            if (!isset($members['manifest.json']) || $members['manifest.json']['size'] > TransferCatalog::MAX_JSON_BYTES) {
                throw new RuntimeException('The archive manifest is missing or exceeds 16 MiB.');
            }
            $manifestText = $zip->getFromName('manifest.json', TransferCatalog::MAX_JSON_BYTES + 1);
            if ($manifestText === false) throw new RuntimeException('The archive manifest cannot be read.');
            $manifest = $this->catalog->validate($this->json($manifestText));
            $expected = ['manifest.json'];
            foreach ($manifest['objects'] as $object) {
                if (!isset($object['file'])) continue;
                $this->extension($object);
                if ($object['file']['included']) {
                    $file = $object['file'];
                    if (!isset($members[$file['member']]) || $members[$file['member']]['size'] !== $file['size']) {
                        throw new RuntimeException('An archived file is missing or has the wrong size.');
                    }
                    $expected[] = $file['member'];
                }
            }
            if (array_diff(array_keys($members), $expected) || count($expected) !== count($members)) {
                throw new RuntimeException('The archive contains undeclared files.');
            }
            $this->space($operation, $expanded);
            $operation->update(['total_bytes' => $expanded - $members['manifest.json']['size'], 'progress_bytes' => 0]);
            $files = [];
            foreach ($manifest['objects'] as $object) {
                if (!($object['file']['included'] ?? false)) continue;
                $file = $object['file'];
                $extension = $this->extension($object);
                $member = 'asset-'.bin2hex(random_bytes(12)).'.'.$extension;
                $target = $this->workspace->path($operation, $member);
                $stream = $zip->getStream($file['member']);
                if ($stream === false) throw new RuntimeException('An archive member could not be opened.');
                try {
                    $sha = $this->copy($operation, $stream, $target, $file['size']);
                } finally {
                    fclose($stream);
                }
                if (!hash_equals($file['sha256'], $sha)) throw new RuntimeException('An archived file has the wrong SHA-256.');
                $verified = ['member' => $member, 'size' => $file['size'], 'sha256' => $sha];
                if ($object['type'] === 'image') {
                    $limit = $object['data']['uncompressed_size'] ?? ImageVerification::MAX_BYTES;
                    if (!is_int($limit) || $limit < 1 || $limit > ImageVerification::MAX_BYTES) throw new RuntimeException('The declared unpacked image size is unsupported.');
                    $image = $this->images->uncompressed($target, $extension, $limit);
                    if (!empty($object['data']['uncompressed_sha256']) && !hash_equals($object['data']['uncompressed_sha256'], $image['sha256'])
                        || isset($object['data']['uncompressed_size']) && $object['data']['uncompressed_size'] !== $image['size']) {
                        throw new RuntimeException('The unpacked image does not match its declared hash or size.');
                    }
                    $verified += ['uncompressed_sha256' => $image['sha256'], 'uncompressed_size' => $image['size']];
                } elseif ($object['type'] === 'package') {
                    $fields = $this->packages->fields($target);
                    if ($fields === null) throw new RuntimeException('The archived package is not a readable Debian package.');
                    foreach (['deb_package' => 'package', 'deb_version' => 'version', 'deb_architecture' => 'architecture'] as $dataKey => $key) {
                        if (($object['data'][$dataKey] ?? null) !== $fields[$key]) throw new RuntimeException('The package metadata differs from its manifest.');
                    }
                }
                $files[$object['ref']] = $verified;
            }
            return [$manifest, $files];
        } finally {
            $zip->close();
        }
    }

    private function extension(array $object): string
    {
        $extension = match ($object['type']) {
            'image' => $object['data']['filename_extension'] ?? '', 'package' => 'deb', 'firmware' => 'bin',
            default => '',
        };
        if (!in_array($extension, ['gz', 'xz', 'bz2', 'deb', 'bin'], true)
            || ($object['file']['member'] ?? '') !== 'files/'.$object['ref'].'.'.$extension) {
            throw new RuntimeException('The transfer contains an unsupported file type.');
        }
        return $extension;
    }

    private function copy(ConfigurationTransferOperation $operation, $source, string $path, int $size): string
    {
        $target = fopen($path, 'x+b');
        if ($target === false) throw new RuntimeException('A private transfer file could not be created.');
        $hash = hash_init('sha256');
        $count = 0;
        $reported = 0;
        $lastUpdate = microtime(true);
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('A private transfer file could not be protected.');
            while (!feof($source)) {
                $bytes = fread($source, min(1024 * 1024, $size - $count + 1));
                if ($bytes === false) throw new RuntimeException('A transfer file could not be read.');
                $count += strlen($bytes);
                if ($count > $size) throw new RuntimeException('A transfer file exceeds its declared size.');
                hash_update($hash, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($target, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('A transfer file could not be written.');
                    $offset += $written;
                }
                if (microtime(true) - $lastUpdate > 1) {
                    $operation->increment('progress_bytes', $count - $reported);
                    $reported = $count;
                    $lastUpdate = microtime(true);
                    $operation->refresh();
                }
            }
            if ($count !== $size || !fflush($target) || !fsync($target)) throw new RuntimeException('A transfer file is incomplete or could not be saved.');
            if ($count > $reported) $operation->increment('progress_bytes', $count - $reported);
        } finally {
            fclose($target);
        }
        return hash_final($hash);
    }

    private function text(string $path, int $limit): string
    {
        if (filesize($path) > $limit) throw new RuntimeException('The configuration text exceeds its size limit.');
        $text = file_get_contents($path, false, null, 0, $limit + 1);
        if ($text === false || strlen($text) > $limit) throw new RuntimeException('The configuration text cannot be read.');
        return $text;
    }

    private function json(#[\SensitiveParameter] string $text): array
    {
        try {
            $data = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('The transfer is not valid configuration JSON.');
        }
        if (!is_array($data)) throw new RuntimeException('The transfer must contain a configuration document.');
        return $data;
    }

    private function space(ConfigurationTransferOperation $operation, int $bytes): void
    {
        $free = disk_free_space(dirname($this->workspace->path($operation, 'source')));
        if ($free === false || $free < $bytes + 64 * 1024 * 1024) throw new RuntimeException('There is not enough free space to prepare the import.');
    }
}
