<?php

namespace App\Http\Controllers;

use App\Services\Configuration\TransferCatalog;
use App\Services\Configuration\TransferRequests;
use App\Services\Configuration\TransferWorkspace;
use Illuminate\Http\Request;

class ConfigurationTransferController extends Controller
{
    public function store(Request $request, TransferRequests $transfers)
    {
        $request->validate(['configuration' => 'required|file|max:67110912', 'password' => 'nullable|string|max:4096']);
        try {
            $operation = $transfers->file($request->user()->id, $request->session()->getId(),
                $request->file('configuration')->getRealPath(), $request->input('password'));
            return redirect()->route('settings.transfer', ['operation' => $operation->id]);
        } catch (\RuntimeException $error) {
            return redirect()->route('settings.transfer')->with('transferError', $error->getMessage());
        }
    }

    public function download(Request $request, string $operation, TransferWorkspace $workspace)
    {
        $row = $workspace->owned($operation, $request->user()->id, $request->session()->getId());
        abort_unless($row->direction === 'export' && $row->status === 'ready', 409, 'The export is not ready.');
        $result = $row->result;
        return response()->streamDownload(function () use ($workspace, $row, $result) {
            $workspace->locked($row, function () use ($workspace, $row, $result) {
                $stream = fopen($workspace->path($row, $result['member']), 'rb');
                if ($stream === false) throw new \RuntimeException('The prepared export is unavailable.');
                try { fpassthru($stream); } finally { fclose($stream); }
            });
        }, $result['filename'], ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
