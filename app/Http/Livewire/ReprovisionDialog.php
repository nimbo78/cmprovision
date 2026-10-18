<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Project;
use App\Models\ReprovisionOperation;
use App\Services\Sensor\Reprovision;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReprovisionDialog extends Component
{
    #[Locked] public string $serial;
    #[Locked] public ?string $operationId = null;
    public bool $open = false;
    public string $username = 'wlanpi';
    public ?string $error = null;

    public function mount(string $serial): void { Cm::where('serial', $serial)->firstOrFail(); $this->serial = $serial; }
    public function begin(): void
    {
        $this->requireUser(); $this->open = true; $this->error = null;
    }
    public function check(string $password = ''): void
    {
        $this->perform(function (Reprovision $service) use ($password) {
            $op = $this->operation();
            if ($op && ($op->expires_at->isPast() || !in_array($op->state, ['checking','account','password_expired','checked'], true))) {
                $service->cancel($op->id, auth()->id(), session()->getId());
                $this->operationId = null;
            }
            $op = $service->check($this->serial, auth()->id(), session()->getId(), $this->operationId,
                $password === '' ? null : ['user' => $this->username, 'password' => $password]);
            $this->operationId = $op->id;
            if ($op->username) $this->username = $op->username;
        });
    }
    public function changePassword(string $password, string $repeat): void
    {
        $this->perform(fn (Reprovision $service) => $service->changePassword($this->operationId ?? '', auth()->id(), session()->getId(), $password, $repeat));
    }
    public function wipe(): void
    {
        $this->perform(fn (Reprovision $service) => $service->wipe($this->operationId ?? '', auth()->id(), session()->getId(), auth()->user()->name));
    }
    public function confirmBoot(): void
    {
        $this->perform(fn (Reprovision $service) => $service->confirmBoot($this->serial, auth()->user()->name));
    }
    public function close(): void
    {
        $this->perform(function (Reprovision $service) {
            if ($this->operationId) $service->cancel($this->operationId, auth()->id(), session()->getId());
            $this->operationId = null; $this->open = false;
        });
    }
    private function requireUser(): void { abort_unless(auth()->check(), 403); }
    private function perform(callable $action): void
    {
        $this->requireUser(); $this->error = null;
        try { $action(app(Reprovision::class)); }
        catch (\RuntimeException $e) { $this->error = $e->getMessage(); }
    }
    private function operation(): ?ReprovisionOperation
    {
        if (!$this->operationId) return null;
        return ReprovisionOperation::whereKey($this->operationId)->where('serial', $this->serial)
            ->where('user_id', auth()->id())->where('session_hash', hash('sha256', session()->getId()))->first();
    }
    public function render()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();
        return view('livewire.reprovision-dialog', ['cm' => $cm, 'operation' => $this->operation(),
            'pending' => ReprovisionOperation::pending($this->serial), 'project' => Project::getActive(),
            'blocker' => app(Reprovision::class)->blocker($cm)]);
    }
}
