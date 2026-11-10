<?php

namespace App\Http\Livewire;

use App\Services\StationBackupsControl;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationBackupsPanel extends Component
{
    use ConfirmsPasswords;

    #[Locked] public string $nonce;
    public ?string $error = null;

    public function mount(): void { $this->nonce = bin2hex(random_bytes(32)); }

    private function execute(#[\SensitiveParameter] array $request): void
    {
        abort_unless(auth()->check(), 403);
        $this->ensurePasswordIsConfirmed();
        $this->error = null;
        try {
            $result = app(StationBackupsControl::class)->request($request);
            $this->dispatch('station-backup-changed', status: $result);
        } catch (\RuntimeException $error) { $this->error = $error->getMessage(); }
    }

    public function saveMasterPassword(#[\SensitiveParameter] string $password): void { $this->execute(['action' => 'password', 'password' => $password]); }
    public function saveProfile(#[\SensitiveParameter] array $profile): void { $this->execute(['action' => 'save-profile', 'profile' => $profile]); }
    public function deleteProfile(string $id): void { $this->execute(['action' => 'delete-profile', 'id' => $id]); }
    public function saveSchedule(array $schedule): void { $this->execute(['action' => 'schedule', 'schedule' => $schedule]); }
    public function create(string $target, array $options): void { $this->execute(['action' => 'create', 'nonce' => $this->nonce, 'target' => $target, 'options' => $options]); }
    public function testProfile(string $id): void { $this->execute(['action' => 'test-profile', 'nonce' => bin2hex(random_bytes(32)), 'id' => $id]); }
    public function hostKey(string $host, int $port): void { $this->execute(['action' => 'host-key', 'nonce' => bin2hex(random_bytes(32)), 'host' => $host, 'port' => $port]); }
    public function cancel(string $id): void { $this->execute(['action' => 'cancel', 'id' => $id]); }
    public function back(): void { abort_unless(auth()->check(), 403); $this->nonce = bin2hex(random_bytes(32)); $this->error = null; }
    public function render()
    {
        $control = app(StationBackupsControl::class);
        return view('livewire.station-backups-panel', ['available' => $control->available(), 'status' => $control->status()]);
    }
}
