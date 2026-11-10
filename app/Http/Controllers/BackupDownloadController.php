<?php

namespace App\Http\Controllers;

use App\Services\BackupFailed;
use App\Services\DataBackup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/* The copy of the data to keep away from this server (Settings → Status → Backup). The route asks for the password again
   (password.confirm): the file holds the application key and every password the server keeps. */
class BackupDownloadController extends Controller
{
    public function show()
    {
        return response()->view('settings.backup', ['downloadId' => bin2hex(random_bytes(16))])
            ->header('Cache-Control', 'no-store');
    }

    public function __invoke(Request $request, DataBackup $backup)
    {
        $input = $request->validate(['download_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/D']]);
        @set_time_limit(900);
        ignore_user_abort(true);   // the folder is removed even when the browser gives up
        try
        {
            $copy = $backup->make();
        }
        catch (BackupFailed $e)
        {
            session()->flash('backupError', $e->getMessage());
            return redirect()->route('settings', ['tab' => 'system'])->withFragment('backup');
        }
        Log::notice('Backup of the data downloaded', ['by' => auth()->user()?->email, 'file' => $copy['name'], 'bytes' => $copy['bytes']]);
        register_shutdown_function(function () use ($backup, $copy) { $backup->forget($copy['folder']); });
        $response = response()->download($copy['path'], $copy['name'], ['Content-Type' => 'application/x-tar', 'Cache-Control' => 'no-store'])
            ->deleteFileAfterSend(true);
        if (!empty($input['download_id'])) {
            // No secrets or authorization here: JavaScript only learns that native download headers arrived.
            $response->headers->setCookie(cookie('cmprovision_download_'.$input['download_id'], '1', 1, '/', null, $request->isSecure(), false, false, 'strict'));
        }
        return $response;
    }
}
