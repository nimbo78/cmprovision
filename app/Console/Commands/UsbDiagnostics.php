<?php

namespace App\Console\Commands;

use App\Services\UsbDiagnostics as Receiver;
use Illuminate\Console\Command;
use Throwable;

class UsbDiagnostics extends Command
{
    protected $signature = 'diagnostics:usb';
    protected $description = 'Local bounded JSON interface for the USB diagnostic receiver (stdin)';

    public function handle(Receiver $receiver): int
    {
        try {
            $data = json_decode(stream_get_contents(STDIN, 70001), true, 20, JSON_THROW_ON_ERROR);
            if (!is_array($data) || !in_array($data['operation'] ?? '', ['status', 'accept'], true))
                throw new \InvalidArgumentException('Invalid diagnostic request.');
            $reply = $data['operation'] === 'status' ? $receiver->status($data['status'] ?? []) : $receiver->accept($data);
            $this->line(json_encode($reply, JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $e) {
            // Never print the input, a run token, arbitrary USB bytes or a database exception into the journal.
            $this->line(json_encode(['action' => 'error', 'reason' => 'USB diagnostic request rejected.']));
            return 1;
        }
    }
}
