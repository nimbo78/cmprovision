<?php

namespace App\Http\Livewire;

use App\Services\StationTime;
use App\Services\StationTimeControl;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationTimeSettings extends Component
{
    use ConfirmsPasswords;
    #[Locked] public array $status = [];
    public $timezone = 'UTC', $ntpEnabled = true, $ntpServers = '';
    public ?string $error = null, $notice = null;

    public function mount(): void
    {
        $this->refreshStatus();
        $this->timezone = $this->status['timezone'];
        $this->ntpEnabled = $this->status['ntp_enabled'];
        $this->ntpServers = implode("\n", $this->status['managed_servers']);
    }
    public function refreshStatus(): void
    {
        abort_unless(auth()->check(), 403);
        $this->status = app(StationTimeControl::class)->status();
    }
    public function saveTimezone(): void
    {
        abort_unless(auth()->check(), 403);
        $this->ensurePasswordIsConfirmed();
        $this->error = $this->notice = null;
        try {
            $this->status = app(StationTime::class)->timezone($this->timezone);
            $this->notice = 'Time zone saved. Reload open pages to refresh their timestamps and Auto theme.';
        } catch (\RuntimeException $error) { $this->error = $error->getMessage(); }
    }
    public function saveNtp(): void
    {
        abort_unless(auth()->check(), 403);
        $this->ensurePasswordIsConfirmed();
        $this->error = $this->notice = null;
        try {
            $this->status = app(StationTime::class)->ntp($this->ntpEnabled, $this->ntpServers);
            $this->notice = 'NTP settings saved. Synchronization status updates after the time service contacts a server.';
        } catch (\RuntimeException $error) { $this->error = $error->getMessage(); }
    }
    public function render()
    {
        return view('livewire.station-time-settings');
    }
}
