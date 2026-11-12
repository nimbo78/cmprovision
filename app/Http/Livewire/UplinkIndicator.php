<?php

namespace App\Http\Livewire;

use App\Services\NetworkStatus;
use App\Support\UplinkSummary;
use Livewire\Component;

/**
 * The icon in the top bar showing how the provisioner reaches the network, details in its tooltip.
 * It refreshes on its own every NetworkStatus::POLL_SECONDS and reads fresh data when pointed at.
 */
class UplinkIndicator extends Component
{
    /** the NetworkStatus snapshot shown, null where the network cannot be read */
    public $status;

    public function mount()
    {
        $this->status = app(NetworkStatus::class)->current(NetworkStatus::PASSIVE_MAX_AGE);
    }

    /** wire:poll */
    public function refreshPassive()
    {
        $this->status = app(NetworkStatus::class)->current(NetworkStatus::PASSIVE_MAX_AGE);
    }

    /** the operator points at the icon or taps it */
    public function refreshNow()
    {
        $this->status = app(NetworkStatus::class)->current(NetworkStatus::FRESH_MAX_AGE);
    }

    public function render()
    {
        $status = $this->status;
        if ($status !== null) $status['rescue_wifi'] = app(\App\Services\RescueWifi::class)->status();
        return view('livewire.uplink-indicator', [
            'v' => $status === null ? null : UplinkSummary::from($status),
            'poll' => NetworkStatus::POLL_SECONDS,
        ]);
    }
}
