<?php

namespace App\Http\Livewire;

use App\Services\VlanInventory;
use App\Services\VlanNetworks;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Component;

class VlanStatus extends Component
{
    use ConfirmsPasswords;

    public $parent = '', $vlanId = '', $address = '';
    public $form = false, $notice = null, $noticeIsError = false;

    public function render()
    {
        $helper = app(VlanNetworks::class);
        $available = $helper->available();
        $result = $available ? $helper->list() : null;
        return view('livewire.vlan-status', [
            'vlans' => app(VlanInventory::class)->read(),
            'available' => $available,
            'managed' => ($result['ok'] ?? false) ? ($result['result'] ?? []) : [],
            'listError' => $result !== null && !($result['ok'] ?? false) ? ($result['error'] ?? 'Could not list VLANs.') : null,
        ]);
    }

    public function add()
    {
        $this->ensurePasswordIsConfirmed();
        Validator::make(['parent' => $this->parent, 'vlanId' => $this->vlanId, 'address' => $this->address], [
            'parent' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{1,15}$/'],
            'vlanId' => ['required', 'integer', 'between:1,4094'],
            'address' => ['nullable', 'string', 'max:32'],
        ])->validate();
        $result = app(VlanNetworks::class)->add($this->parent, (int) $this->vlanId, (string) $this->address);
        $this->noticeIsError = !($result['ok'] ?? false);
        $this->notice = $this->noticeIsError ? ($result['error'] ?? 'Could not add VLAN.') : 'VLAN added.';
        if (!$this->noticeIsError) {
            $this->form = false;
            $this->reset('parent', 'vlanId', 'address');
        }
    }

    public function remove(string $uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $result = app(VlanNetworks::class)->remove($uuid);
        $this->noticeIsError = !($result['ok'] ?? false);
        $this->notice = $this->noticeIsError ? ($result['error'] ?? 'Could not remove VLAN.') : 'VLAN removed.';
    }
}
