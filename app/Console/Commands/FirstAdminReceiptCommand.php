<?php

namespace App\Console\Commands;

use App\Services\FirstAdminReceipt;
use Illuminate\Console\Command;

class FirstAdminReceiptCommand extends Command
{
    protected $signature = 'auth:first-admin-receipt';
    protected $description = 'Process the local station bootstrap transaction';
    protected $hidden = true;

    public function handle(FirstAdminReceipt $receipt): int
    {
        try {
            $raw = stream_get_contents(STDIN, 8193);
            if (strlen($raw) > 8192) return self::FAILURE;
            $payload = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
            $this->line(json_encode($receipt->handle($payload), JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        } catch (\Throwable) {
            return self::FAILURE;
        }
    }
}
