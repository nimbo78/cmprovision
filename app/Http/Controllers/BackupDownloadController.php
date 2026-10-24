<?php

namespace App\Http\Controllers;

use App\Services\BackupFailed;
use App\Services\DataBackup;
use Illuminate\Support\Facades\Log;

/* The copy of the data to keep away from this server (Settings → Status → Backup). The route asks for the password again
   (password.confirm): the file holds the application key and every password the server keeps. */
class BackupDownloadController extends Controller
{
    public function __invoke(DataBackup $backup)
    {
        @set_time_limit(900);
        ignore_user_abort(true);   // the folder is removed even when the browser gives up
        try
        {
            $copy = $backup->make();
        }
        catch (BackupFailed $e)
        {
            session()->flash('backupError', $e->getMessage());
            return redirect()->route('settings', ['tab' => 'status'])->withFragment('backup');
        }
        Log::notice('Backup of the data downloaded', ['by' => auth()->user()?->email, 'file' => $copy['name'], 'bytes' => $copy['bytes']]);
        register_shutdown_function(function () use ($backup, $copy) { $backup->forget($copy['folder']); });
        return response()->download($copy['path'], $copy['name'], ['Content-Type' => 'application/x-tar', 'Cache-Control' => 'no-store'])
            ->deleteFileAfterSend(true);
    }
}
