<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Services\Snmp\PhpSnmpClient;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\SnapshotCache;
use App\Services\Switch\SwitchSnapshot;
use App\Services\SwitchPortFinder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/* Settings page section: the SNMP switches of the stand, which tell the port each module is plugged into. The list
   shows them all; one at a time is added or edited in the form */
class SwitchSettings extends Component
{
    public $host = '', $version = '2c', $community = '', $user = '';
    public $authProtocol = '', $authPassword = '', $privProtocol = '', $privPassword = '', $method = 'auto';
    // PoE restart: write credentials of their own; secrets never go to the browser either
    public $writeCommunity = '', $writeUser = '', $writeAuthProtocol = '', $writeAuthPassword = '', $writePrivProtocol = '', $writePrivPassword = '';
    /** the form is open; editingId: the switch in it, null for a new one */
    #[Locked]
    public $form = false;
    #[Locked]
    public $editingId = null;
    #[Locked]
    public $stored = [];          // which secrets the switch in the form has (they are never sent to the browser)
    public $result = null;
    public $notice = null, $noticeIsError = false;

    public function render()
    {
        $switches = $byMac = [];
        foreach (EthernetSwitch::orderBy('id')->get() as $switch)
        {
            $config = $switch->config();
            $last = (new SnapshotCache($switch->host, function () { return null; }))->last();
            $switches[$switch->id] = ['id' => $switch->id, 'name' => $switch->hostname(), 'host' => $switch->host,
                                      'version' => 'v'.$config['version'], 'writable' => PoeControl::writable($config),
                                      'state' => self::lastAnswer($last), 'same' => false];
            // one switch under two addresses: the base MAC each address answered with (Stand draws only the first)
            $mac = $last['snapshot']['identity']['mac'] ?? $switch->base_mac;
            if ($mac !== null)
                $byMac[$mac][] = $switch->id;
        }
        $same = [];
        foreach ($byMac as $mac => $ids)
            if (count($ids) > 1)
            {
                $same[] = implode(' and ', array_map(function ($id) use ($switches) { return $switches[$id]['name'].' ('.$switches[$id]['host'].')'; }, $ids))
                    .' are the same switch (base MAC '.$mac.'): delete one of them.';
                foreach ($ids as $id)
                    $switches[$id]['same'] = true;
            }
        return view('livewire.switch-settings', [
            'switches' => array_values($switches),
            'same' => $same,
            // two switches with one name give boards nobody can tell apart
            'duplicates' => array_keys(array_filter(array_count_values(array_column(array_filter($switches, function ($s) { return !$s['same']; }), 'name')),
                function ($n) { return $n > 1; })),
        ]);
    }

    public function add()
    {
        $this->open(null);
    }

    public function edit($id)
    {
        $switch = EthernetSwitch::find($id);
        if ($switch)
            $this->open($switch);
    }

    public function cancel()
    {
        $this->open(null);
        $this->form = false;
    }

    public function test()
    {
        $this->result = null;
        $this->notice = null;
        if (!$this->form)
            return;
        if (trim($this->host) === '')
        {
            $this->addError('host', 'Enter the switch address.');
            return;
        }

        $config = $this->formConfig();
        if ($config['version'] === '2c' && $config['community'] === '')
        {
            $this->addError('community', 'Enter the community for this switch.');
            return;
        }
        if ($config['version'] === '3' && $config['user'] === '')
        {
            $this->addError('user', 'Enter the SNMPv3 user.');
            return;
        }

        try
        {
            $scan = SwitchPortFinder::forConfig($config)->scan();
            $who = $scan['answered'] ? SwitchSnapshot::forConfig($config)->nameAndMac() : ['name' => null, 'mac' => null];
        }
        catch (\Throwable $e)
        {
            $this->flash('The switch could not be asked: '.$e->getMessage(), true);
            return;
        }

        $serials = Cm::whereIn('mac', array_keys($scan['ports']))->pluck('serial', 'mac')->all();
        $rows = [];
        foreach ($scan['ports'] as $mac => $port)
            $rows[] = ['port' => $port, 'mac' => $mac, 'vlan' => $scan['vlans'][$mac] ?? null, 'serial' => $serials[$mac] ?? null];
        usort($rows, function ($a, $b) {
            return strnatcasecmp($a['port'], $b['port']) ?: strcmp($a['mac'], $b['mac']);
        });

        $tried = [];
        foreach ($scan['tried'] as $method => $answer)
            $tried[SwitchPortFinder::METHODS[$method]] = $answer;

        $this->result = [
            'name' => $who['name'],
            'mac' => $who['mac'],
            'method' => $scan['method'] ? SwitchPortFinder::METHODS[$scan['method']] : null,
            'rows' => $rows,
            'tried' => $tried,
        ];
        $other = $this->sameAs($who['mac']);
        if (!$scan['answered'])
            $this->flash('The switch did not answer: check the address, the community or SNMPv3 user, and that SNMP is enabled for this server\'s address.', true);
        else if ($other)
            $this->flash($other, true);
        else if (!$rows)
            $this->flash('The switch answered, but no method returned its MAC address table. See the answers below and the SNMP hints.', true);
    }

    public function save()
    {
        $this->notice = null;
        if (!$this->form)
            return;
        $this->validate($this->rules());

        $switch = $this->editingId ? EthernetSwitch::find($this->editingId) : new EthernetSwitch;
        if (!$switch)
        {
            $this->cancel();
            return;
        }
        $config = $this->formConfig();
        if (EthernetSwitch::where('host', $config['host'])->when($switch->exists, function ($q) use ($switch) { $q->whereKeyNot($switch->id); })->exists())
        {
            $this->addError('host', 'This address is in the list already.');
            return;
        }
        // what the switch calls itself: the same switch under a second address would show its modules twice
        try
        {
            $who = SwitchSnapshot::forConfig($config)->nameAndMac();
        }
        catch (\Throwable $e)
        {
            $who = null;   // a silent switch is saved too; its name comes with its first answer
        }
        $other = $this->sameAs($who['mac'] ?? null);
        if ($other)
        {
            $this->addError('host', $other);
            return;
        }

        $before = $switch->exists ? $switch->config() : EthernetSwitch::emptyConfig();
        $switch->fillConfig($config);
        if ($before['host'] !== $config['host'])
            $switch->forceFill(['method_detected' => null, 'sys_name' => null, 'base_mac' => null, 'own_macs' => null, 'links' => null]);
        elseif ($before['method'] !== $this->method)
            $switch->method_detected = null;
        if ($who)
            $switch->forceFill(['sys_name' => $who['name'] ?? $switch->sys_name, 'base_mac' => $who['mac'] ?? $switch->base_mac]);
        $switch->save();

        $this->cancel();
        $this->flash('Saved '.$switch->hostname().($who ? '' : ' (it did not answer: its name comes with its first answer)')
            .'. Modules get their switch and port as board from now on.');
    }

    /* Write access without a change: the first PoE port's value is written back as it is (PoeControl::check()) */
    public function checkWrite()
    {
        $this->result = null;
        $this->notice = null;
        if (!$this->form)
            return;
        $config = $this->formConfig();
        if (!PoeControl::writable($config))
        {
            $this->flash('Enter the write community (v2c) or the write user (v3) first.', true);
            return;
        }
        // the value it reads and writes back could be a restart's "off", written after the restart switched the port on
        $id = $this->editingId ?? EthernetSwitch::where('host', $config['host'])->value('id');
        $lock = Cache::lock(PoeRestart::LOCK.':'.($id ?? 'new'), 60);
        if (!$lock->get())
        {
            $this->flash('A restart is running: check again in a minute.', true);
            return;
        }
        try
        {
            $ports = array_map(function ($port) { return $port['name']; }, SwitchSnapshot::forConfig($config)->take()['ports']);
            $admin = (new Poe(app(SnmpClient::class, ['config' => $config])))->admin($ports);
            $ifIndex = PoeControl::forConfig($config)->check($admin);
        }
        catch (\Throwable $e)
        {
            $this->flash('Write access does not work: '.$e->getMessage(), true);
            return;
        }
        finally
        {
            $lock->release();
        }
        if ($ifIndex === null)
            $this->flash('The switch reports no PoE ports.', true);
        else
            $this->flash('Write access works ('.$ports[$ifIndex].').');
    }

    /* No more PoE restarts on the switch in the form: its write credentials go, the read ones stay */
    public function forgetWrite()
    {
        $switch = $this->editingId ? EthernetSwitch::find($this->editingId) : null;
        if (!$switch)
            return;
        $switch->fill(['write_community' => null, 'write_user' => null, 'write_auth_protocol' => null, 'write_auth_password' => null,
                       'write_priv_protocol' => null, 'write_priv_password' => null])->save();
        $this->open($switch);
        $this->flash('Write access removed: no PoE restarts on '.$switch->hostname().'.');
    }

    /* A switch leaves the stand; not while a port of it stayed off after a restart: nothing could switch it on then */
    public function delete($id)
    {
        $switch = EthernetSwitch::find($id);
        if (!$switch)
            return;
        $name = $switch->hostname();
        if (PoeOutage::stayedOff()->forSwitch($switch)->exists())
        {
            $this->flash('Ports of '.$name.' stayed off after a PoE restart. Switch them on (Power on) or forget them first.', true);
            return;
        }
        $switch->delete();
        if ((int) $this->editingId === (int) $id)
            $this->cancel();
        $this->flash('Removed '.$name.'.'.(EthernetSwitch::exists() ? '' : ' Boards come from the jumpers again.'));
    }

    /* The form for this switch, or empty for a new one; secrets stay empty, $stored tells which ones it has */
    protected function open(?EthernetSwitch $switch): void
    {
        $this->resetErrorBag();
        $this->result = null;
        $this->notice = null;
        $c = $switch ? $switch->config() : EthernetSwitch::emptyConfig();
        $this->form = true;
        $this->editingId = $switch ? $switch->id : null;
        $this->host = $c['host'];
        $this->version = $c['version'] ?: '2c';
        $this->user = $c['user'];
        $this->authProtocol = $c['auth_protocol'];
        $this->privProtocol = $c['priv_protocol'];
        $this->method = $c['method'] ?: 'auto';
        $this->community = $this->authPassword = $this->privPassword = '';
        $w = $c['write'];
        $this->writeUser = $w['user'];
        $this->writeAuthProtocol = $w['auth_protocol'];
        $this->writePrivProtocol = $w['priv_protocol'];
        $this->writeCommunity = $this->writeAuthPassword = $this->writePrivPassword = '';
        $this->stored = ['community' => $c['community'] !== '', 'auth_password' => $c['auth_password'] !== '',
                         'priv_password' => $c['priv_password'] !== '', 'detected' => $c['detected'],
                         'write_community' => $w['community'] !== '', 'write_auth_password' => $w['auth_password'] !== '',
                         'write_priv_password' => $w['priv_password'] !== ''];
    }

    protected function rules()
    {
        $methods = implode(',', array_merge(['auto'], array_keys(SwitchPortFinder::METHODS)));
        $rules = [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'version' => 'required|in:2c,3',
            'method' => 'required|in:'.$methods,
        ];
        $stored = $this->storedConfig();
        $same = $this->sameSwitch($stored);
        $kept = function ($key) use ($stored, $same) {
            return $same && $stored[$key] !== '';
        };
        if ($this->version === '2c')
        {
            $rules['community'] = ($kept('community') ? 'nullable' : 'required').'|string|max:100';
        }
        else
        {
            $rules['user'] = 'required|string|max:100';
            $rules['authProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::authProtocols());
            $rules['authPassword'] = ($this->authProtocol && !$kept('auth_password') ? 'required' : 'nullable').'|string|min:8|max:100';
            $rules['privProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::PRIV_PROTOCOLS);
            $rules['privPassword'] = ($this->privProtocol && !$kept('priv_password') ? 'required' : 'nullable').'|string|min:8|max:100';
        }
        $keptWrite = function ($key) use ($stored, $same) {
            return $same && $stored['write'][$key] !== '';
        };
        if ($this->version === '2c')
        {
            $rules['writeCommunity'] = 'nullable|string|max:100';
        }
        else
        {
            $writer = trim($this->writeUser) !== '';
            $rules['writeUser'] = 'nullable|string|max:100';
            $rules['writeAuthProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::authProtocols());
            $rules['writeAuthPassword'] = ($writer && $this->writeAuthProtocol && !$keptWrite('auth_password') ? 'required' : 'nullable').'|string|min:8|max:100';
            $rules['writePrivProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::PRIV_PROTOCOLS);
            $rules['writePrivPassword'] = ($writer && $this->writePrivProtocol && !$keptWrite('priv_password') ? 'required' : 'nullable').'|string|min:8|max:100';
        }
        return $rules;
    }

    /* Settings from the form. A secret field left empty means "the stored value", but only of the switch in the form
       and only at its address: otherwise a new address would receive the hidden community or password. */
    protected function formConfig()
    {
        $stored = $this->storedConfig();
        $same = $this->sameSwitch($stored);
        return [
            'host' => trim($this->host),
            'version' => $this->version,
            'community' => $this->community !== '' ? $this->community : ($same ? $stored['community'] : ''),
            'user' => $this->user,
            'auth_protocol' => $this->authProtocol,
            'auth_password' => $this->authPassword !== '' ? $this->authPassword : ($same ? $stored['auth_password'] : ''),
            'priv_protocol' => $this->privProtocol,
            'priv_password' => $this->privPassword !== '' ? $this->privPassword : ($same ? $stored['priv_password'] : ''),
            'method' => $this->method,
            'detected' => null,
            'write' => [
                'community' => $this->writeCommunity !== '' ? $this->writeCommunity : ($same ? $stored['write']['community'] : ''),
                'user' => trim($this->writeUser),
                'auth_protocol' => $this->writeAuthProtocol,
                'auth_password' => $this->writeAuthPassword !== '' ? $this->writeAuthPassword : ($same ? $stored['write']['auth_password'] : ''),
                'priv_protocol' => $this->writePrivProtocol,
                'priv_password' => $this->writePrivPassword !== '' ? $this->writePrivPassword : ($same ? $stored['write']['priv_password'] : ''),
            ],
        ];
    }

    /* The stored settings of the switch in the form; empty for a new one */
    protected function storedConfig(): array
    {
        $switch = $this->editingId ? EthernetSwitch::find($this->editingId) : null;
        return $switch ? $switch->config() : EthernetSwitch::emptyConfig();
    }

    protected function sameSwitch(array $stored)
    {
        return $stored['host'] !== '' && trim($this->host) === $stored['host'] && $this->version === $stored['version'];
    }

    /* "This is sw01 (192.0.2.10) under another address" when another switch of the list has this base MAC */
    protected function sameAs(?string $mac): ?string
    {
        if ($mac === null)
            return null;
        $other = EthernetSwitch::where('base_mac', $mac)->when($this->editingId, function ($q) { $q->whereKeyNot($this->editingId); })->first();
        return $other ? 'This is '.$other->hostname().' ('.$other->host.') under another address.' : null;
    }

    /* What the stand's last poll of the switch said (SnapshotCache::last()), without asking it now */
    protected static function lastAnswer(array $state): string
    {
        if (!$state['checked_at'])
            return 'not asked yet';
        if ($state['error'])
            return 'not answering since '.Carbon::createFromTimestamp($state['error']['since'])->local()->format('j M H:i').': '.$state['error']['message'];
        return 'answered '.Carbon::createFromTimestamp($state['checked_at'])->diffForHumans();
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
