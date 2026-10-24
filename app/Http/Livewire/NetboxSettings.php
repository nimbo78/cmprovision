<?php

namespace App\Http\Livewire;

use App\Services\NetBoxExport;
use Illuminate\Validation\Rule;
use Livewire\Component;

/* Settings → Provisioning (2.21.0): what the files for NetBox say of every device: its site, role, manufacturer and device type,
   the status, the name and type of the interface, and which NetBox it is (where the MAC address goes) */
class NetboxSettings extends Component
{
    public $site = '', $role = '', $manufacturer = '', $device_type = '', $status = '', $interface = '', $interface_type = '', $mac_style = '';
    public $notice;

    public function mount()
    {
        foreach (NetBoxExport::settings() as $key => $value)
            $this->$key = $value;
    }

    public function save()
    {
        $text = ['required', 'string', 'max:100', 'regex:/^[^\x00-\x1f]+$/'];
        $values = $this->validate([
            'site' => $text, 'role' => $text, 'manufacturer' => $text, 'device_type' => $text,
            'status' => ['required', Rule::in(NetBoxExport::STATUSES)],
            'interface' => $text, 'interface_type' => $text,
            'mac_style' => ['required', Rule::in(array_keys(NetBoxExport::MAC_STYLES))],
        ]);
        NetBoxExport::save($values);
        $this->notice = 'Saved: the files for NetBox say this from now on.';
    }

    /* The defaults go into the form; they are kept when saved */
    public function useDefaults()
    {
        foreach (NetBoxExport::DEFAULTS as $key => $value)
            $this->$key = $value;
        $this->notice = null;
    }

    public function render()
    {
        return view('livewire.netbox-settings', ['statuses' => NetBoxExport::STATUSES, 'macStyles' => NetBoxExport::MAC_STYLES]);
    }
}
