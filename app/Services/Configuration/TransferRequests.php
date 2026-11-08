<?php

namespace App\Services\Configuration;

use App\Jobs\PrepareConfigurationTransfer;
use App\Jobs\PruneConfigurationTransfers;
use App\Models\ConfigurationTransferOperation;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Browser entry points keep passwords in encrypted operation state, never in queue arguments. */
class TransferRequests
{
    public function __construct(private TransferWorkspace $workspace) {}

    public function export(int $user, string $session, array $selection, string $format, bool $secrets,
        bool $protect, #[\SensitiveParameter] ?string $password): ConfigurationTransferOperation
    {
        Validator::make(['format' => $format], ['format' => 'required|in:json,archive'])->validate();
        // File hashing and dependency closure belong in the worker, not the browser request.
        $rules = [];
        foreach (['projects', 'scripts', 'labels'] as $kind) {
            $rules[$kind] = 'sometimes|array|max:2048';
            $rules[$kind.'.*'] = 'integer|min:1';
        }
        $rules += ['groups' => 'sometimes|array', 'groups.*' => 'in:'.implode(',', array_keys(TransferCatalog::GROUPS)),
            'binaries' => 'sometimes|array', 'binaries.*' => 'in:images,packages,firmware'];
        $selection = Validator::make($selection, $rules)->validate();
        if (!array_filter(array_intersect_key($selection, array_flip(['projects', 'scripts', 'labels', 'groups'])))) throw new RuntimeException('Select at least one object or settings group.');
        if ($protect || $secrets) {
            $password = $password ?: app(TransferPassword::class)->read();
            if ($password === null) throw new RuntimeException('Enter a transfer password or save a master password first.');
            TransferPassword::validate($password);
        } else $password = null;
        $operation = $this->create($user, $session, 'export');
        $operation->update(['status' => 'queued', 'options' => ['selection' => $selection, 'format' => $format, 'include_secrets' => $secrets],
            'secrets' => $password === null ? null : ['password' => $password]]);
        PrepareConfigurationTransfer::dispatch($operation->id);
        return $operation;
    }

    public function text(int $user, string $session, string $text, #[\SensitiveParameter] ?string $password): ConfigurationTransferOperation
    {
        if ($text === '' || strlen($text) > 2 * TransferCatalog::MAX_JSON_BYTES) throw new RuntimeException('Paste configuration text of at most 32 MiB.');
        $operation = $this->create($user, $session, 'import');
        $path = $this->workspace->path($operation, 'source');
        $mask = umask(0077);
        try {
            if (file_put_contents($path, $text, LOCK_EX) !== strlen($text) || !chmod($path, 0600)) throw new RuntimeException('The private import could not be saved.');
        } finally { umask($mask); }
        return $this->queueImport($operation, $password);
    }

    public function file(int $user, string $session, string $path, #[\SensitiveParameter] ?string $password): ConfigurationTransferOperation
    {
        if (is_link($path) || !is_file($path) || filesize($path) < 1 || filesize($path) > TransferCatalog::MAX_ARCHIVE_BYTES + 2 * 1024 * 1024) {
            throw new RuntimeException('Choose a configuration JSON, ZIP or protected CMP file within the transfer limit.');
        }
        $operation = $this->create($user, $session, 'import');
        $target = $this->workspace->path($operation, 'source');
        $free = disk_free_space(dirname($target));
        if ($free === false || $free < filesize($path) + 64 * 1024 * 1024) throw new RuntimeException('There is not enough space to receive this file.');
        $mask = umask(0077);
        try {
            if (!copy($path, $target) || !chmod($target, 0600)) throw new RuntimeException('The private import could not be saved.');
        } finally { umask($mask); }
        return $this->queueImport($operation, $password);
    }

    private function queueImport(ConfigurationTransferOperation $operation, #[\SensitiveParameter] ?string $password): ConfigurationTransferOperation
    {
        if ($password !== null && strlen($password) > 4096) throw new RuntimeException('The transfer password is too long.');
        $operation->update(['status' => 'queued', 'secrets' => $password ? ['password' => $password] : null]);
        PrepareConfigurationTransfer::dispatch($operation->id);
        return $operation;
    }

    private function create(int $user, string $session, string $direction): ConfigurationTransferOperation
    {
        $operation = $this->workspace->create($user, $session, $direction);
        PruneConfigurationTransfers::dispatch()->delay($operation->expires_at->copy()->addMinute());
        return $operation;
    }
}
