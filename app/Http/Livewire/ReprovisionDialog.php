<?php

namespace App\Http\Livewire;

use App\Models\{Cm,Project,ReprovisionOperation};
use App\Services\Sensor\{Reprovision,ReprovisionStation};
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ReprovisionDialog extends Component
{
    #[Locked] public ?string $serial = null;
    #[Locked] public ?string $mac = null;
    #[Locked] public ?string $operationId = null;
    public bool $open = false;
    public string $addressInput = '';
    public string $username = 'wlanpi';
    public ?string $error = null;

    public function mount(?string $serial = null, ?string $mac = null, ?string $address = null, bool $autoOpen = false): void
    {
        $this->serial = $serial;
        $this->mac = $serial ? Cm::where('serial',$serial)->firstOrFail()->mac : $mac;
        $this->addressInput = $address ?? '';
        if ($autoOpen) $this->begin();
    }
    public function begin(): void { $this->requireUser(); $this->open = true; $this->error = null; }
    public function check(string $password = ''): void
    {
        $this->perform(function (Reprovision $service) use ($password) {
            $op = $this->operation();
            $mac = $op?->mac ?? $this->mac;
            if ($op && ($op->expires_at->isPast() || !$op->context || !in_array($op->state,['checking','account','password_expired','checked'],true))) {
                $service->cancel($op->id,auth()->id(),session()->getId());
                $this->operationId = null;
            }
            $op = $service->checkTarget($mac,$this->addressInput === '' ? null : $this->addressInput,
                auth()->id(),session()->getId(),$this->operationId,$password === '' ? null : ['user'=>$this->username,'password'=>$password]);
            $this->operationId = $op->id;
            if ($op->username) $this->username = $op->username;
        });
    }
    public function changePassword(string $password, string $repeat): void
    {
        $this->perform(fn (Reprovision $s) => $s->changePassword($this->operationId ?? '',auth()->id(),session()->getId(),$password,$repeat));
    }
    public function wipe(): void
    {
        $this->perform(fn (Reprovision $s) => $s->wipe($this->operationId ?? '',auth()->id(),session()->getId(),auth()->user()->name));
    }
    public function confirmBoot(): void
    {
        $this->perform(function (Reprovision $s) {
            $op = $this->operation();
            $mac = $op?->mac ?? $this->mac;
            $pending = $mac ? ReprovisionOperation::pendingTarget($mac,$this->serial) : null;
            if (!$pending?->serial) throw new \RuntimeException('No re-provision request is pending.');
            $s->confirmBoot($pending->serial,auth()->user()->name);
        });
    }
    public function back(): void
    {
        $this->perform(function (Reprovision $s) {
            if ($this->operationId) $s->cancel($this->operationId,auth()->id(),session()->getId());
            $this->operationId = null;
        });
    }
    public function close(): void { $this->back(); $this->open = false; }
    private function requireUser(): void { abort_unless(auth()->check(),403); }
    private function perform(callable $action): void
    {
        $this->requireUser(); $this->error = null;
        try { $action(app(Reprovision::class)); }
        catch (\RuntimeException $e) { $this->error = $e->getMessage(); }
    }
    private function operation(): ?ReprovisionOperation
    {
        if (!$this->operationId) return null;
        return ReprovisionOperation::whereKey($this->operationId)->where('user_id',auth()->id())
            ->where('session_hash',hash('sha256',session()->getId()))->firstOrFail();
    }
    public function render()
    {
        $op = $this->operation();
        $mac = $op?->mac ?? $this->mac;
        $cm = $this->serial ? Cm::where('serial',$this->serial)->first() : ($mac ? Cm::where('mac',$mac)->first() : null);
        $state = Cache::remember('reprovision-station-ui',5,function () {
            try { return app(ReprovisionStation::class)->current(); }
            catch (\RuntimeException $e) { return ['error'=>$e->getMessage()]; }
        });
        return view('livewire.reprovision-dialog',[
            'cm'=>$cm, 'operation'=>$op, 'targetMac'=>$mac, 'targetSerial'=>$op?->serial ?? $this->serial, 'station'=>$state,
            'secondary'=>isset($state['address']) && $state['address'] !== '172.20.0.1',
            'handoff'=>app(ReprovisionStation::class)->handoff($mac,$this->addressInput),
            'pending'=>$mac ? ReprovisionOperation::pendingTarget($mac,$this->serial) : null,
            'project'=>Project::getActive(), 'blocker'=>$cm ? app(Reprovision::class)->blocker($cm) : null,
            'dialogId'=>'reprovision-'.$this->getId(),
        ]);
    }
}
