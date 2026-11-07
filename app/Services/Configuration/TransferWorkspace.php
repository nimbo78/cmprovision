<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationTransferOperation;
use FilesystemIterator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/** Private, expiring operation files with a lock shared by preparation, application and cleanup. */
class TransferWorkspace
{
    public const LIFETIME_HOURS = 24;
    private const LOCK_FILE = 'operation.lock';
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? storage_path('framework/configuration-transfer'), '/');
    }

    public function create(int $userId, #[\SensitiveParameter] string $sessionId, string $direction): ConfigurationTransferOperation
    {
        if ($userId < 1 || $sessionId === '' || !in_array($direction, ['import', 'export'], true)) {
            throw new RuntimeException('A signed-in browser session and a valid transfer direction are required.');
        }
        $root = $this->rootDirectory();
        $this->prune();
        $operation = ConfigurationTransferOperation::create([
            'id' => (string) Str::uuid(), 'user_id' => $userId,
            'session_hash' => $this->sessionHash($sessionId), 'direction' => $direction,
            'status' => 'created', 'expires_at' => now()->addHours(self::LIFETIME_HOURS),
        ]);
        if (!mkdir($root.'/'.$operation->id, 0700)) {
            $operation->delete();
            throw new RuntimeException('Could not create a private transfer directory.');
        }

        return $operation;
    }

    public function owned(string $id, int $userId, #[\SensitiveParameter] string $sessionId): ConfigurationTransferOperation
    {
        $operation = Str::isUuid($id) ? ConfigurationTransferOperation::find($id) : null;
        if ($operation === null || $operation->user_id !== $userId || !$operation->expires_at->isFuture()
            || !hash_equals($operation->session_hash, $this->sessionHash($sessionId))) {
            throw new AuthorizationException('This transfer is unavailable or has expired.');
        }

        return $operation;
    }

    public function path(ConfigurationTransferOperation $operation, string $member): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,127}$/D', $member)) {
            throw new RuntimeException('Invalid transfer member name.');
        }
        $path = $this->operationDirectory($operation).'/'.$member;
        if (is_link($path)) {
            throw new RuntimeException('A transfer member must be a regular file.');
        }

        return $path;
    }

    public function locked(ConfigurationTransferOperation $operation, callable $work): mixed
    {
        $lock = $this->openLock($this->operationDirectory($operation));
        if ($lock === false) {
            throw new TransferBusy('Another task is working on this transfer. Try again when it finishes.');
        }
        try {
            $current = ConfigurationTransferOperation::find($operation->id);
            if ($current === null || !$current->expires_at->isFuture()) {
                throw new AuthorizationException('This transfer is unavailable or has expired.');
            }
            $operation->setRawAttributes($current->getAttributes(), true);

            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function prune(): void
    {
        if (!is_dir($this->root) && !is_link($this->root)) {
            return;
        }
        $root = $this->rootDirectory();
        foreach (ConfigurationTransferOperation::where('expires_at', '<=', now())->orderBy('expires_at')->limit(100)->get() as $operation) {
            if (!Str::isUuid($operation->id)) {
                $operation->delete();
                continue;
            }
            $directory = $root.'/'.$operation->id;
            if (!file_exists($directory) && !is_link($directory)) {
                $operation->delete();
                continue;
            }
            if (is_link($directory) || !is_dir($directory)) {
                if (@unlink($directory)) {
                    $operation->delete();
                }
                continue;
            }
            $lock = $this->openLock($directory);
            if ($lock === false) {
                continue;
            }
            try {
                $current = ConfigurationTransferOperation::find($operation->id);
                if ($current !== null && $current->expires_at->isFuture()) {
                    continue;
                }
                if ($current !== null) app(TransferAssets::class)->cleanup($current);
                if (File::deleteDirectory($directory)) {
                    $current?->delete();
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        $this->pruneOrphans($root);
    }

    private function pruneOrphans(string $root): void
    {
        $cutoff = now()->subHours(self::LIFETIME_HOURS)->getTimestamp();
        $scanned = 0;
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (++$scanned > 1000) {
                break;
            }
            if ($entry->isLink() || !$entry->isDir() || !Str::isUuid($entry->getFilename()) || $entry->getMTime() > $cutoff
                || ConfigurationTransferOperation::whereKey($entry->getFilename())->exists()) {
                continue;
            }
            $lock = $this->openLock($entry->getPathname());
            if ($lock === false) {
                continue;
            }
            try {
                if (!ConfigurationTransferOperation::whereKey($entry->getFilename())->exists()) {
                    File::deleteDirectory($entry->getPathname());
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function operationDirectory(ConfigurationTransferOperation $operation): string
    {
        if (!Str::isUuid($operation->id)) {
            throw new RuntimeException('Invalid transfer operation identifier.');
        }
        $directory = $this->rootDirectory().'/'.$operation->id;
        if (is_link($directory) || !is_dir($directory)) {
            throw new RuntimeException('The private transfer directory is unavailable.');
        }

        return $directory;
    }

    private function rootDirectory(): string
    {
        if ($this->root === '' || $this->root[0] !== '/' || is_link($this->root)) {
            throw new RuntimeException('The private transfer directory must be an absolute directory without a link.');
        }
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Could not create the private transfer directory.');
        }
        if (is_link($this->root) || !@chmod($this->root, 0700)) {
            throw new RuntimeException('Could not protect the private transfer directory.');
        }
        $root = realpath($this->root);
        if ($root === false) {
            throw new RuntimeException('The private transfer directory is unavailable.');
        }

        return $root;
    }

    private function openLock(string $directory)
    {
        $path = $directory.'/'.self::LOCK_FILE;
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            return false;
        }
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            return false;
        }
        if (!@chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return false;
        }

        return $lock;
    }

    private function sessionHash(#[\SensitiveParameter] string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }
}
