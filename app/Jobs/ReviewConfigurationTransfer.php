<?php

namespace App\Jobs;

use App\Models\ConfigurationTransferOperation;
use App\Services\Configuration\TransferBusy;
use App\Services\Configuration\TransferImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ReviewConfigurationTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public $timeout = 7200;
    public $tries = 5;
    public function __construct(public string $operationId) { $this->onQueue('images'); }
    public function handle(TransferImporter $importer): void
    {
        $operation = ConfigurationTransferOperation::find($this->operationId);
        if (!$operation || !$operation->expires_at->isFuture() || $operation->status !== 'reviewing') return;
        try { $importer->preview($operation, $operation->options['review_choices'] ?? []); }
        catch (TransferBusy $busy) { $this->release(10); }
    }
    public function failed(?\Throwable $error): void
    {
        $operation = ConfigurationTransferOperation::find($this->operationId);
        if ($operation?->status !== 'reviewing') return;
        $options = $operation->options;
        unset($options['review_choices']);
        $operation->update(['status' => 'prepared', 'options' => $options, 'error' => 'Preview stopped. Check your choices and review again.']);
    }
}
