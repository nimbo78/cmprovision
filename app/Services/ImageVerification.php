<?php

namespace App\Services;

use RuntimeException;

/** One streamed decompression for the hash and size; the decompressor must also finish successfully. */
class ImageVerification
{
    public const MAX_BYTES = 512 * 1024 * 1024 * 1024;

    public function uncompressed(string $path, string $extension, int $limit = self::MAX_BYTES): array
    {
        $command = ['gz' => 'gzip', 'xz' => 'xz', 'bz2' => 'bzip2'][$extension] ?? null;
        if ($command === null || $limit < 1 || $limit > self::MAX_BYTES || !is_file($path) || is_link($path)) {
            throw new RuntimeException('The image format, file or unpacked-size limit is unsupported.');
        }
        $arguments = array_merge(['timeout', '7200', $command], $extension === 'xz' ? ['--memlimit-decompress=128MiB'] : [], ['-dc', '--', $path]);
        $process = @proc_open($arguments, [
            0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start image verification.');
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($pipes[1])) {
                $bytes = fread($pipes[1], 1024 * 1024);
                if ($bytes === false) throw new RuntimeException('The unpacked image could not be read.');
                $size += strlen($bytes);
                if ($size > $limit) throw new RuntimeException('The unpacked image exceeds its verification limit.');
                hash_update($hash, $bytes);
            }
        } catch (\Throwable $error) {
            proc_terminate($process);
            throw $error;
        } finally {
            fclose($pipes[1]);
            $status = proc_close($process);
        }
        if ($status !== 0 || $size === 0) throw new RuntimeException('The compressed image is damaged, unsupported or exceeds the decoder memory limit.');
        return ['sha256' => hash_final($hash), 'size' => $size];
    }
}
