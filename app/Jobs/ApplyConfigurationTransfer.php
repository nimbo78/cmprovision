<?php

namespace App\Jobs;

use App\Models\ConfigurationTransferOperation;
use App\Services\Configuration\TransferBusy;
use App\Services\Configuration\TransferImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ApplyConfigurationTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public $timeout = 7200;
    public $tries = 5;
    public function __construct(public string $operationId) { $this->onQueue('images'); }
    public function handle(TransferImporter $importer): void
    {
        $operation = ConfigurationTransferOperation::find($this->operationId);
        if (!$operation || !$operation->expires_at->isFuture() || $operation->status !== 'applying') return;
        try { $importer->apply($operation, $operation->options['apply_fingerprint']); }
        catch (TransferBusy $busy) { $this->release(10); }
    }
    public function failed(?\Throwable $error): void
    {
        ConfigurationTransferOperation::whereKey($this->operationId)->where('status', 'applying')
            ->update(['status' => 'previewed', 'error' => 'Application stopped. Refresh the preview before retrying.']);
    }
}
