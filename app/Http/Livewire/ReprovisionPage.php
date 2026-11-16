<?php
namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Re-provision')]
class ReprovisionPage extends Component
{
    public function render()
    {
        $mac = request()->query('mac'); $address = request()->query('address');
        abort_if(($mac !== null && (!is_string($mac) || strlen($mac) > 17))
            || ($address !== null && (!is_string($address) || strlen($address) > 15)),422);
        return view('livewire.reprovision-page',['mac'=>$mac,'address'=>$address]);
    }
}
