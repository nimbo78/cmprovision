<?php

namespace App\Http\Livewire;

use App\Services\Sensor\ProvisionerKey;
use Livewire\Component;

class ReprovisionKey extends Component
{
    public ?string $error = null;
    public function prepare(): void
    {
        abort_unless(auth()->check(), 403);
        try { app(ProvisionerKey::class)->ensure(); $this->error = null; }
        catch (\RuntimeException $e) { $this->error = $e->getMessage(); }
    }
    public function render()
    {
        $file = app(ProvisionerKey::class)->path().'.pub';
        $public = is_file($file) && !is_link($file) ? trim((string) file_get_contents($file)) : null;
        if ($public && !preg_match('/^ssh-ed25519 [A-Za-z0-9+\/=]+(?: .*)?$/D', $public)) $public = null;
        $fingerprint = null;
        try { if ($public) $fingerprint = app(ProvisionerKey::class)->fingerprint($public); }
        catch (\RuntimeException $e) { $public = null; }
        return view('livewire.reprovision-key', compact('public', 'fingerprint'));
    }
}
