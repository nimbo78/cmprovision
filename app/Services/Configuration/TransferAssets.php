<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use App\Models\Firmware;
use App\Models\Image;
use App\Models\Package;
use RuntimeException;

/** Copy and verify large files before taking SQLite's write lock. Names are always server-generated. */
class TransferAssets
{
    public function __construct(private TransferWorkspace $workspace) {}

    public function stage(ConfigurationTransferOperation $operation, array $plans): array
    {
        $this->cleanup($operation);
        $staged = [];
        foreach ($plans as $ref => $plan) {
            if (!$plan['selected'] || !in_array($plan['action'], ['create', 'copy', 'replace'], true)
                || !in_array($plan['type'], ['image', 'package', 'firmware'], true)) continue;
            $type = $plan['type'];
            $facts = $plan['file'];
            if ($facts !== null) $source = $this->workspace->path($operation, $facts['member']);
            else {
                $id = $plan['file_source'];
                if ($type === 'image') {
                    $model = Image::findOrFail($id);
                    $source = $model->imagepath();
                    $facts = ['sha256' => $model->sha256, 'size' => filesize($source),
                        'uncompressed_sha256' => $model->uncompressed_sha256, 'uncompressed_size' => $model->uncompressed_size];
                } elseif ($type === 'package') {
                    $model = Package::findOrFail($id);
                    $source = $model->path();
                    $facts = ['sha256' => $model->sha256, 'size' => $model->size];
                } else {
                    $source = Firmware::basedir().'/'.$id;
                    $facts = $plan['file_facts'];
                }
            }
            $extension = match ($type) { 'image' => $plan['data']['filename_extension'], 'package' => 'deb', default => 'bin' };
            $name = 'transfer-'.$operation->id.'-'.bin2hex(random_bytes(6)).'.'.$extension;
            if ($type === 'firmware') $name = $plan['data']['channel'].'/pieeprom-'.$name;
            $path = self::path($type, $name);
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('The file store is unavailable.');
            $free = disk_free_space($directory);
            if ($free === false || $free < $facts['size'] + 64 * 1024 * 1024) throw new RuntimeException('There is not enough free space to import the selected files.');
            // The ledger is saved first: a killed worker's unregistered files can be removed on retry/expiry.
            $staged[$ref] = ['type' => $type, 'filename' => $name, 'facts' => $facts];
            $operation->update(['options' => array_replace($operation->options, ['staged' => $staged])]);
            $this->copy($source, $path, $facts);
        }
        return $staged;
    }

    public function cleanup(ConfigurationTransferOperation $operation): void
    {
        foreach ($operation->options['staged'] ?? [] as $file) {
            $path = self::path($file['type'], $file['filename']);
            $registered = match ($file['type']) {
                'image' => Image::where('filename_on_server', $file['filename'])->exists(),
                'package' => Package::where('filename_on_server', $file['filename'])->exists(),
                // Firmware has no row; the operation result is its durable registration.
                'firmware' => $operation->status === 'applied',
            };
            if (!$registered && !is_link($path) && is_file($path)) @unlink($path);
        }
    }

    public static function path(string $type, string $filename): string
    {
        $pattern = '/^transfer-[0-9a-f-]{36}-[0-9a-f]{12}\.(gz|xz|bz2|deb)$/D';
        if ($type === 'firmware') {
            if (!preg_match('#^(default|latest|stable|beta|critical)/pieeprom-transfer-[0-9a-f-]{36}-[0-9a-f]{12}\.bin$#D', $filename)) throw new RuntimeException('Invalid staged firmware name.');
            return Firmware::basedir().'/'.$filename;
        }
        if (!in_array($type, ['image', 'package'], true) || !preg_match($pattern, $filename)) throw new RuntimeException('Invalid staged file name.');
        return public_path('uploads/'.($type === 'package' ? 'packages/' : '').$filename);
    }

    private function copy(string $source, string $target, array $facts): void
    {
        if (is_link($source) || !is_file($source) || filesize($source) !== $facts['size']) throw new RuntimeException('A prepared file changed. Prepare this import again.');
        $input = fopen($source, 'rb');
        $output = fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            throw new RuntimeException('An imported file could not be staged.');
        }
        try {
            chmod($target, 0644);
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($input)) {
                $bytes = fread($input, 1024 * 1024);
                if ($bytes === false || ($bytes === '' && !feof($input))) throw new RuntimeException('An imported file could not be read.');
                $size += strlen($bytes);
                if ($size > $facts['size']) throw new RuntimeException('A prepared file changed. Prepare this import again.');
                hash_update($hash, $bytes);
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($output, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('An imported file could not be written.');
                    $offset += $written;
                }
            }
            if ($size !== $facts['size'] || !hash_equals($facts['sha256'], hash_final($hash))) throw new RuntimeException('A prepared file changed. Prepare this import again.');
            if (!fflush($output) || !fsync($output)) throw new RuntimeException('An imported file could not be finished.');
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
