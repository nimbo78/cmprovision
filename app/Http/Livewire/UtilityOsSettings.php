<?php

namespace App\Http\Livewire;

use App\Services\UtilityOs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/* Settings page section (2.7.0): the utility OSes of the package and the one modules get. Serving the other one replaces
   the link etc/boot, which dnsmasq and rpiboot follow (App\Services\UtilityOs) */
class UtilityOsSettings extends Component
{
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $os = app(UtilityOs::class);
        return view('livewire.utility-os-settings', [
            'systems' => $os->systems(),
            'current' => $os->current(),
            'link' => $os->link(),
            'follows' => $os->tftpRootFollowsLink(),
            'tftpRoot' => $os->tftpRoot(),
        ]);
    }

    public function serve(string $dir)
    {
        abort_unless(Auth::check(), 403);

        $os = app(UtilityOs::class);
        $before = $os->current();
        try
        {
            $os->switchTo($dir);
        }
        catch (\Throwable $e)
        {
            $this->notice = 'Could not switch: '.$e->getMessage();
            $this->noticeIsError = true;
            return;
        }
        $label = UtilityOs::label($os->manifest($dir));
        Log::info('Utility OS: modules now get '.$label.' ('.$dir.', before: '.($before ?? 'none').') by '.(Auth::user()->name ?? 'unknown'));
        $this->notice = 'Modules that boot from now on get '.$label.'.';
        $this->noticeIsError = false;
    }
}
