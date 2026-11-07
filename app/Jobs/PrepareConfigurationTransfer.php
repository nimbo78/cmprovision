<?php

namespace App\Jobs;

use App\Models\ConfigurationTransferOperation;
use App\Services\Configuration\TransferBusy;
use App\Services\Configuration\TransferExporter;
use App\Services\Configuration\TransferImporter;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class PrepareConfigurationTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 5;
    public $timeout = 7200;

    public function __construct(public string $operationId)
    {
        $this->onQueue('images');
    }

    public function handle(TransferWorkspace $workspace, TransferImporter $importer, TransferExporter $exporter): void
    {
        $operation = ConfigurationTransferOperation::find($this->operationId);
        if ($operation === null || !$operation->expires_at->isFuture()
            || !in_array($operation->status, ['created', 'queued', 'preparing'], true)) return;
        $options = $operation->options ?? [];
        $password = $operation->secrets['password'] ?? null;
        try {
            if ($operation->direction === 'export') {
                $exporter->prepare($operation, $options['selection'] ?? [], $options['format'] ?? 'json',
                    (bool) ($options['include_secrets'] ?? false), $password);
            } else {
                $importer->prepare($operation, $workspace->path($operation, 'source'), $password);
                $importer->preview($operation);
            }
        } catch (TransferBusy $error) {
            $this->release(10);
        } finally {
            if (is_string($password) && $password !== '') sodium_memzero($password);
        }
    }

    public function failed(?\Throwable $error): void
    {
        ConfigurationTransferOperation::whereKey($this->operationId)->whereNotIn('status', ['ready', 'prepared', 'previewed', 'applied'])
            ->update(['status' => 'failed', 'secrets' => null, 'error' => 'Preparation stopped before it completed. Start or retry the transfer.']);
    }
}
