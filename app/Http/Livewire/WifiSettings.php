<?php

namespace App\Http\Livewire;

use App\Services\WifiNetworks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

/* Settings page section: the provisioner's Wi-Fi networks (2.5.3), each one NetworkManager profile for both cards,
   changed by the root helper tools/wifi-networks. Every change asks for the account password first. The network
   password is no property: the field lives in Alpine and comes as a parameter of save, so no snapshot ever holds it */
class WifiSettings extends Component
{
    use ConfirmsPasswords;

    public $ssid = '', $security = 'mixed', $hidden = false;
    /** the form is open; editingUuid: the network in it, null for a new one */
    #[Locked]
    public $form = false;
    #[Locked]
    public $editingUuid = null;
    /** the last Check now of each network on this page: uuid => [at, text, ok] */
    #[Locked]
    public $checks = [];
    /** Find networks: the networks in reach, null before a search */
    #[Locked]
    public $found = null;
    public $notice = null, $noticeIsError = false;
    public $logModalOpen = false, $logTitle = '', $logOutput = '';

    public function render()
    {
        $wifi = app(WifiNetworks::class);
        $available = $wifi->available();
        $list = $available ? $wifi->list() : ['networks' => [], 'others' => []];
        return view('livewire.wifi-settings', [
            'available' => $available,
            'networks' => $list['networks'],
            'others' => $list['others'],
            'blocker' => $available ? $wifi->checkBlocker() : null,
            'securities' => WifiNetworks::SECURITY,
        ]);
    }

    public function add()
    {
        $this->open(null);
    }

    public function edit($uuid)
    {
        $network = $this->network($uuid);
        if ($network)
            $this->open($network);
        else
            $this->gone();
    }

    public function cancel()
    {
        $this->form = false;
        $this->editingUuid = null;
        $this->found = null;
        $this->resetErrorBag();
    }

    public function save($password = '')
    {
        $this->ensurePasswordIsConfirmed();
        $this->store((string) $password);
    }

    public function saveAndCheck($password = '')
    {
        $this->ensurePasswordIsConfirmed();
        $uuid = $this->store((string) $password);
        if ($uuid !== null)
            $this->runCheck($uuid);
    }

    public function checkNow($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->runCheck($uuid);
    }

    public function delete($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $network = $this->network($uuid);
        if (!$network)
        {
            $this->gone();
            return;
        }
        $error = app(WifiNetworks::class)->delete($network['uuid'], $this->by());
        if ($error)
        {
            $this->flash($error, true);
            return;
        }
        if ($this->editingUuid === $network['uuid'])
            $this->cancel();
        $this->flash('Removed '.$network['ssid'].'.');
    }

    public function moveUp($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->move($uuid, -1);
    }

    public function moveDown($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->move($uuid, 1);
    }

    /* Find networks: what both radios see, for the form */
    public function findNetworks()
    {
        $scan = app(WifiNetworks::class)->scan();
        $this->found = $scan['networks'];
        if ($scan['error'])
            $this->flash($scan['error'], true);
        elseif (!$this->found)
            $this->flash('No networks found: is the Wi-Fi switched on?', true);
    }

    public function pick($index)
    {
        $network = $this->found[$index] ?? null;
        if (!$network)
            return;
        $this->ssid = $network['ssid'];
        if (isset(WifiNetworks::SECURITY[$network['security']]))
            $this->security = $network['security'];
        $this->found = null;
    }

    public function showLog()
    {
        $this->logTitle = 'Wi-Fi changes';
        $this->logOutput = app(WifiNetworks::class)->log();
        $this->logModalOpen = true;
    }

    public function closeModal()
    {
        $this->logModalOpen = false;
    }

    /* the form and the password to the helper; the saved profile's UUID, null when nothing was saved */
    protected function store(string $password): ?string
    {
        $this->notice = null;
        if (!$this->form)
            return null;
        $new = $this->editingUuid === null;
        Validator::make(['ssid' => $this->ssid, 'security' => $this->security, 'password' => $password], [
            'ssid' => ['required', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) > 32 || preg_match('/[\x00-\x1F\x7F]/', $value) || !mb_check_encoding($value, 'UTF-8'))
                    $fail('The network name must be at most 32 bytes of text without control characters.');
            }],
            'security' => 'required|in:'.implode(',', array_keys(WifiNetworks::SECURITY)),
            // the helper's rule: 8-63 printable ASCII characters, or 64 hexadecimal digits but for WPA3; empty keeps the stored one
            'password' => [$new ? 'required' : 'nullable', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) === 64 && ctype_xdigit($value) && $this->security !== 'wpa3')
                    return;
                if (strlen($value) < 8 || strlen($value) > 63 || !preg_match('/^[\x20-\x7E]+$/', $value))
                    $fail($this->security === 'wpa3' ? 'The password must be 8 to 63 printable ASCII characters.'
                        : 'The password must be 8 to 63 printable ASCII characters or 64 hexadecimal digits.');
            }],
        ])->validate();
        $inUse = !$new && ($this->network($this->editingUuid)['devices'] ?? []) !== [];
        $ssid = $this->ssid;
        $result = app(WifiNetworks::class)->save(['uuid' => $this->editingUuid, 'ssid' => $ssid, 'security' => $this->security,
                                                 'hidden' => (bool) $this->hidden, 'password' => $password], $this->by());
        if ($result['error'])
        {
            $this->flash($result['error'], true);
            return null;
        }
        $this->cancel();
        $this->flash($inUse ? 'Saved '.$ssid.': the network in use takes it at its next connection. Check now tests it on the built-in Wi-Fi.'
                            : 'Saved '.$ssid.'.');
        return $result['uuid'];
    }

    protected function runCheck(string $uuid): void
    {
        $network = $this->network($uuid);
        if (!$network)
        {
            $this->gone();
            return;
        }
        $r = app(WifiNetworks::class)->check($network['uuid'], $this->by());
        $address = $r['address'] !== '' ? ', '.explode('/', $r['address'])[0] : '';
        $text = [
            'connected' => 'connected'.$address,
            'wrong-password' => 'wrong password',
            'not-found' => 'not found by the built-in Wi-Fi',
            'no-address' => 'connected, but no address (DHCP)',
            'timeout' => 'no answer in 30 s',
            'busy' => 'not now: '.$r['detail'],
        ][$r['result']] ?? ($r['detail'] !== '' ? $r['result'].': '.$r['detail'] : $r['result']);
        $this->checks[$network['uuid']] = ['at' => Carbon::now()->local()->format('H:i'), 'text' => $text, 'ok' => $r['result'] === 'connected'];
    }

    /* one place up or down: the helper gets the whole order, the preferred first */
    protected function move($uuid, int $step): void
    {
        $uuids = array_column(app(WifiNetworks::class)->list()['networks'], 'uuid');
        $at = array_search($uuid, $uuids, true);
        if ($at === false)
        {
            $this->gone();
            return;
        }
        if (!isset($uuids[$at + $step]))
            return;
        [$uuids[$at], $uuids[$at + $step]] = [$uuids[$at + $step], $uuids[$at]];
        $error = app(WifiNetworks::class)->order($uuids, $this->by());
        $this->flash($error ?? 'Order saved: the provisioner tries the networks in this order when it connects.', $error !== null);
    }

    /* a network of the list by the UUID of any of its profiles */
    protected function network($uuid): ?array
    {
        foreach (app(WifiNetworks::class)->list()['networks'] as $network)
            if (in_array($uuid, $network['uuids'], true))
                return $network;
        return null;
    }

    protected function open(?array $network): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        $this->found = null;
        $this->form = true;
        $this->editingUuid = $network['uuid'] ?? null;
        $this->ssid = $network['ssid'] ?? '';
        $this->security = $network['security'] ?? 'mixed';
        $this->hidden = $network['hidden'] ?? false;
    }

    /* a network of the page that is no longer there: removed in another tab, or nmcli did not answer in time */
    protected function gone()
    {
        $this->flash('This network is no longer in the list: reload the page.', true);
    }

    protected function by(): string
    {
        return (string) (Auth::user()->name ?? 'unknown');
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
