<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TransferImporter
{
    public function __construct(private TransferWorkspace $workspace, private TransferReader $reader,
        private TransferCatalog $catalog)
    {
    }

    public function prepare(ConfigurationTransferOperation $operation, string $source, #[\SensitiveParameter] ?string $password): void
    {
        $this->workspace->locked($operation, function () use ($operation, $source, $password) {
            if ($operation->direction !== 'import') throw new RuntimeException('This operation is not an import.');
            if (in_array($operation->status, ['prepared', 'previewed', 'applied'], true)) return;
            $mask = umask(0077);
            try {
                $this->clearPreparedFiles($operation);
                $operation->update(['status' => 'preparing', 'error' => null, 'progress_bytes' => 0, 'total_bytes' => null]);
                $prepared = $this->reader->read($operation, $source, $password);
                $json = json_encode($prepared['manifest'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $path = $this->workspace->path($operation, 'manifest.json');
                $this->write($path, $json);
                $operation->update(['status' => 'prepared', 'format' => $prepared['format'],
                    'options' => ['manifest_sha256' => hash('sha256', $json), 'files' => $prepared['files'],
                        'protected' => $prepared['protected']], 'secrets' => null]);
            } catch (Throwable $error) {
                $this->clearPreparedFiles($operation);
                $message = $error instanceof ValidationException ? 'The configuration manifest contains unsupported or inconsistent data.'
                    : (get_class($error) === RuntimeException::class ? $error->getMessage() : 'The import could not be prepared. Check the file, password and available space.');
                $operation->update(['status' => 'failed', 'options' => null, 'secrets' => null, 'error' => $message]);
                throw new RuntimeException($message);
            } finally {
                umask($mask);
                if (is_string($password) && $password !== '') sodium_memzero($password);
            }
        });
    }

    public function preview(ConfigurationTransferOperation $operation, #[\SensitiveParameter] array $choices = []): array
    {
        return $this->workspace->locked($operation, function () use ($operation, $choices) {
            if ($operation->direction !== 'import' || !in_array($operation->status, ['prepared', 'previewed'], true)) {
                throw new RuntimeException('Prepare this import before reviewing it.');
            }
            $options = $operation->options;
            $path = $this->workspace->path($operation, 'manifest.json');
            if (!is_file($path) || filesize($path) > TransferCatalog::MAX_JSON_BYTES
                || !hash_equals($options['manifest_sha256'], hash_file('sha256', $path))) {
                throw new RuntimeException('The prepared manifest changed. Start a new import.');
            }
            $manifest = $this->catalog->validate(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR));
            $resolved = app(TransferPreview::class)->resolve($operation, $manifest, $choices);
            $operation->update(['status' => 'previewed', 'options' => array_replace($options, ['preview' => $resolved['private']])]);
            return $resolved['public'];
        });
    }

    public function apply(ConfigurationTransferOperation $operation, string $previewFingerprint): array
    {
        return $this->workspace->locked($operation, function () use ($operation, $previewFingerprint) {
            if ($operation->direction !== 'import') throw new RuntimeException('This operation is not an import.');
            if ($operation->status === 'applied') return $operation->result;
            $saved = $operation->options['preview'] ?? null;
            if ($operation->status !== 'previewed' || !is_array($saved)
                || !hash_equals($saved['fingerprint'], $previewFingerprint)) {
                throw new RuntimeException('Review the current import preview before applying it.');
            }
            $manifest = $this->manifest($operation);
            $resolved = app(TransferPreview::class)->resolve($operation, $manifest, []);
            if (!$resolved['public']['can_apply'] || !hash_equals($previewFingerprint, $resolved['public']['fingerprint'])) {
                throw new RuntimeException('The preview is incomplete or receiving configuration changed. Review the import again.');
            }
            $assets = app(TransferAssets::class);
            try {
                $staged = $assets->stage($operation, $resolved['private']['objects']);
                $result = DB::transaction(function () use ($operation, $resolved, $staged) {
                    $current = ConfigurationTransferOperation::findOrFail($operation->id);
                    if ($current->status === 'applied') return $current->result;
                    if (!$current->expires_at->isFuture() || $current->status !== 'previewed'
                        || !hash_equals($resolved['private']['revision'], TransferState::revision())) {
                        throw new RuntimeException('The receiving configuration changed. Review the import again.');
                    }
                    $written = app(TransferWriter::class)->apply($resolved['private']['objects'], $staged);
                    $current->update(['status' => 'applied', 'result' => $written['result'], 'secrets' => null, 'error' => null,
                        'options' => ['drafts' => $written['drafts']]]);
                    return $written['result'];
                });
            } catch (Throwable $error) {
                // A callback/connection can fail after COMMIT. The durable result decides whether files belong to rows.
                try { $current = ConfigurationTransferOperation::find($operation->id); }
                catch (Throwable $unavailable) {
                    // Do not guess whether COMMIT succeeded while the database is unreachable.
                    throw new RuntimeException('The import outcome is temporarily unavailable. Reopen this transfer before trying again.');
                }
                if ($current?->status === 'applied') {
                    $operation->setRawAttributes($current->getAttributes(), true);
                    $this->clearPreparedFiles($operation);
                    $source = $this->workspace->path($operation, 'source');
                    if (is_file($source)) @unlink($source);
                    return $current->result;
                }
                $assets->cleanup($operation);
                throw new RuntimeException('The import was not applied. Review the preview and available space, then try again.');
            }
            $operation->refresh();
            $this->clearPreparedFiles($operation);
            $source = $this->workspace->path($operation, 'source');
            if (is_file($source)) @unlink($source);
            return $result;
        });
    }

    private function manifest(ConfigurationTransferOperation $operation): array
    {
        $path = $this->workspace->path($operation, 'manifest.json');
        if (!is_file($path) || filesize($path) > TransferCatalog::MAX_JSON_BYTES
            || !hash_equals($operation->options['manifest_sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('The prepared manifest changed. Start a new import.');
        }
        return $this->catalog->validate(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR));
    }

    private function clearPreparedFiles(ConfigurationTransferOperation $operation): void
    {
        $directory = dirname($this->workspace->path($operation, 'manifest.json'));
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isLink()) continue;
            if ($entry->isFile() && (in_array($entry->getFilename(), ['manifest.json', 'decoded'], true)
                || preg_match('/^asset-[0-9a-f]{24}\.(gz|xz|bz2|deb|bin)$/D', $entry->getFilename()))) {
                unlink($entry->getPathname());
            }
        }
    }

    private function write(string $path, string $json): void
    {
        $stream = fopen($path, 'x+b');
        if ($stream === false) throw new RuntimeException('The private manifest could not be created.');
        try {
            if (!chmod($path, 0600)) throw new RuntimeException('The private manifest could not be protected.');
            $offset = 0;
            while ($offset < strlen($json)) {
                $written = fwrite($stream, substr($json, $offset, 1024 * 1024));
                if ($written === false || $written === 0) throw new RuntimeException('The private manifest could not be saved.');
                $offset += $written;
            }
            if (!fflush($stream) || !fsync($stream)) throw new RuntimeException('The private manifest could not be finished.');
        } finally {
            fclose($stream);
        }
    }
}
