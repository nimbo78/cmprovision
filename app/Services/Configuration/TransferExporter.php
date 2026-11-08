<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class TransferExporter
{
    public function __construct(private TransferWorkspace $workspace, private TransferCatalog $catalog,
        private TransferCipher $cipher, private TransferPassword $masterPassword)
    {
    }

    public function prepare(ConfigurationTransferOperation $operation, array $selection, string $format,
        bool $includeSecrets, #[\SensitiveParameter] ?string $password): void
    {
        $this->workspace->locked($operation, function () use ($operation, $selection, $format, $includeSecrets, $password) {
            if ($operation->direction !== 'export') throw new RuntimeException('This operation is not an export.');
            if ($operation->status === 'ready') return;
            if (!in_array($format, ['json', 'archive'], true)) throw new RuntimeException('Choose JSON or an archive.');
            $oldMask = umask(0077);
            try {
                $this->cleanup($operation);
                $operation->update(['status' => 'preparing', 'format' => $format, 'result' => null, 'error' => null,
                    'progress_bytes' => 0, 'total_bytes' => null]);
                if ($includeSecrets && $password === null) $password = $this->masterPassword->read();
                if ($includeSecrets && $password === null) throw new RuntimeException('Set a master password before exporting secrets.');
                $manifest = $this->catalog->export($selection, $includeSecrets);
                $total = 0;
                foreach ($manifest['objects'] as &$object) {
                    if (!isset($object['file'])) continue;
                    $kind = ['image' => 'images', 'package' => 'packages', 'firmware' => 'firmware'][$object['type']];
                    $object['file']['included'] = $format === 'archive' && in_array($kind, $selection['binaries'] ?? [], true);
                    if ($object['file']['included']) $total += $object['file']['size'];
                }
                unset($object);
                $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($json) > TransferCatalog::MAX_JSON_BYTES) throw new RuntimeException('Select fewer objects: the manifest exceeds 16 MiB.');
                $needed = ($total + strlen($json) + 1024 * count($manifest['objects'])) * ($password !== null ? 2 : 1);
                if ($total + strlen($json) + 1024 * count($manifest['objects']) > TransferCatalog::MAX_ARCHIVE_BYTES) {
                    throw new RuntimeException('The selected files exceed the 64 GiB transfer limit.');
                }
                $probe = $this->workspace->path($operation, 'export.json');
                $free = disk_free_space(dirname($probe));
                if ($free === false || $free < $needed + 64 * 1024 * 1024) throw new RuntimeException('There is not enough free space to prepare this export.');
                $operation->update(['total_bytes' => $total]);
                if ($format === 'json') {
                    $member = 'export.json';
                    $this->write($this->workspace->path($operation, $member), $password === null ? $json : $this->cipher->encodeText($json, $password));
                } else {
                    $plain = $this->workspace->path($operation, 'export.zip');
                    $this->archive($operation, $manifest, $json, $plain, $total);
                    $member = 'export.zip';
                    if ($password !== null) {
                        $member = 'export.cmp';
                        $this->cipher->encrypt($plain, $this->workspace->path($operation, $member), $password, 'archive');
                        unlink($plain);
                    }
                }
                $path = $this->workspace->path($operation, $member);
                $extension = pathinfo($member, PATHINFO_EXTENSION);
                $operation->update(['status' => 'ready', 'progress_bytes' => $total, 'secrets' => null,
                    'result' => ['member' => $member, 'filename' => 'cmprovision-configuration.'.$extension,
                        'protected' => $password !== null, 'objects' => count($manifest['objects']), 'bytes' => filesize($path)]]);
            } catch (Throwable $error) {
                $this->cleanup($operation);
                // Query and filesystem exceptions can contain local paths or secret bindings.
                $message = $error instanceof ValidationException ? 'The selected configuration is invalid. Check the selected objects.'
                    : (get_class($error) === RuntimeException::class ? $error->getMessage() : 'The export could not be prepared. Check its inputs and free space.');
                $operation->update(['status' => 'failed', 'secrets' => null, 'error' => $message]);
                throw new RuntimeException($message);
            } finally {
                umask($oldMask);
                if (is_string($password) && $password !== '') sodium_memzero($password);
            }
        });
    }

    private function archive(ConfigurationTransferOperation $operation, array $manifest, string $json, string $path, int $total): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) throw new RuntimeException('Could not create the export archive.');
        try {
            if (!$zip->addFromString('manifest.json', $json) || !$zip->setCompressionName('manifest.json', ZipArchive::CM_DEFLATE, 6)) {
                throw new RuntimeException('Could not write the configuration manifest.');
            }
            $sources = $this->catalog->fileSources();
            foreach ($manifest['objects'] as $object) {
                if (!($object['file']['included'] ?? false)) continue;
                if (!$zip->addFile($sources[$object['ref']], $object['file']['member'])
                    || !$zip->setCompressionName($object['file']['member'], ZipArchive::CM_STORE)) {
                    throw new RuntimeException('A selected file could not be archived.');
                }
            }
            $zip->registerProgressCallback(0.05, function (float $progress) use ($operation, $total) {
                $operation->update(['progress_bytes' => (int) floor($progress * $total)]);
            });
        } catch (Throwable $error) {
            $zip->unchangeAll();
            $zip->close();
            throw $error;
        }
        if (!$zip->close() || !chmod($path, 0600)) throw new RuntimeException('Could not finish the export archive.');
        // addFile reads during close: verify what actually reached the ZIP, including a source changed concurrently.
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('Could not verify the export archive.');
        try {
            foreach ($manifest['objects'] as $object) {
                if (!($object['file']['included'] ?? false)) continue;
                $stream = $zip->getStream($object['file']['member']);
                if ($stream === false) throw new RuntimeException('An archived file is unavailable.');
                try {
                    $hash = hash_init('sha256');
                    $size = hash_update_stream($hash, $stream, $object['file']['size'] + 1);
                    if ($size !== $object['file']['size'] || !hash_equals($object['file']['sha256'], hash_final($hash))) {
                        throw new RuntimeException('A source file changed while the archive was prepared. Start a new export.');
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function write(string $path, string $data): void
    {
        $stream = fopen($path, 'x+b');
        if ($stream === false) throw new RuntimeException('Could not create the export file.');
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('Could not protect the export file.');
            $offset = 0;
            while ($offset < strlen($data)) {
                $written = fwrite($stream, substr($data, $offset, 1024 * 1024));
                if ($written === false || $written === 0) throw new RuntimeException('Could not write the export file.');
                $offset += $written;
            }
            if (!fflush($stream) || !fsync($stream)) throw new RuntimeException('Could not finish the export file.');
        } finally {
            fclose($stream);
        }
    }

    private function cleanup(ConfigurationTransferOperation $operation): void
    {
        foreach (['export.json', 'export.zip', 'export.cmp'] as $member) {
            $path = $this->workspace->path($operation, $member);
            if (is_file($path)) unlink($path);
        }
    }
}
