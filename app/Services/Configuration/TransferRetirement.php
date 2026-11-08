<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use App\Models\Image;
use App\Models\Package;
use Illuminate\Support\Facades\DB;

/** Old files belong to the replacement until COMMIT is known. Failed retirement stays durably retryable. */
class TransferRetirement
{
    private $remove;

    public function __construct(?callable $remove = null)
    {
        $this->remove = $remove ?? fn (string $path) => @unlink($path);
    }

    public function finish(ConfigurationTransferOperation $operation): bool
    {
        if ($operation->status !== 'applied') return true;
        try {
            return DB::transaction(function () use ($operation) {
                $current = ConfigurationTransferOperation::findOrFail($operation->id);
                $options = $current->options ?? [];
                $pending = [];
                foreach ($options['retired'] ?? [] as $file) {
                    $type = $file['type'];
                    $name = $file['filename'];
                    if ($this->referenced($type, $name)) continue;
                    $root = $type === 'image' ? public_path('uploads') : public_path('uploads/packages');
                    if (!in_array($type, ['image', 'package'], true) || !is_string($name) || $name === ''
                        || strlen($name) > 255 || $name === '.' || $name === '..' || strpbrk($name, "/\\\0") !== false) {
                        $pending[] = $file;
                        continue;
                    }
                    $path = $root.'/'.$name;
                    if (is_link($path)) { $pending[] = $file; continue; }
                    clearstatcache(true, $path);
                    if (file_exists($path)) {
                        $actual = realpath($path);
                        $store = realpath($root);
                        if (!is_file($path) || $actual === false || $store === false || !str_starts_with($actual, $store.'/')
                            || !($this->remove)($path)) {
                            $pending[] = $file;
                            continue;
                        }
                    }
                    // Flush only the Linux store directory, never a global sync (especially on WSL).
                    $directory = @fopen($root, 'r');
                    if ($directory === false) { $pending[] = $file; continue; }
                    try { if (!@fsync($directory)) $pending[] = $file; }
                    finally { fclose($directory); }
                }
                if ($pending) $options['retired'] = $pending;
                else unset($options['retired']);
                if ($options !== ($current->options ?? [])) $current->update(['options' => $options]);
                $operation->setRawAttributes($current->getAttributes(), true);
                return !$pending;
            });
        } catch (\Throwable $unavailable) {
            // The applied mapping remains successful; the ledger is kept if cleanup or its commit fails.
            return false;
        }
    }

    private function referenced(string $type, string $name): bool
    {
        return match ($type) {
            'image' => Image::where('filename_on_server', $name)->orWhere('split_filename', $name)->exists(),
            'package' => Package::where('filename_on_server', $name)->exists(),
            default => true,
        };
    }
}
