<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Setting;
use App\Services\Snmp\PhpSnmpClient;
use App\Services\SwitchPortFinder;
use Livewire\Component;

/* Settings page section: the SNMP switch that tells which port a module is plugged into */
class SwitchSettings extends Component
{
    public $host = '', $version = '2c', $community = '', $user = '';
    public $authProtocol = '', $authPassword = '', $privProtocol = '', $privPassword = '', $method = 'auto';
    public $stored = [];          // which secrets are stored (they are never sent to the browser)
    public $result = null;
    public $notice = null, $noticeIsError = false;

    public function mount()
    {
        $c = SwitchPortFinder::config();
        $this->host = $c['host'];
        $this->version = $c['version'] ?: '2c';
        $this->user = $c['user'];
        $this->authProtocol = $c['auth_protocol'];
        $this->privProtocol = $c['priv_protocol'];
        $this->method = $c['method'] ?: 'auto';
        $this->stored = ['community' => $c['community'] !== '', 'auth_password' => $c['auth_password'] !== '',
                         'priv_password' => $c['priv_password'] !== '', 'detected' => $c['detected']];
    }

    public function render()
    {
        return view('livewire.switch-settings');
    }

    public function test()
    {
        $this->result = null;
        $this->notice = null;
        if (trim($this->host) === '')
        {
            $this->addError('host', 'Enter the switch address.');
            return;
        }

        try
        {
            $scan = SwitchPortFinder::forConfig($this->formConfig())->scan();
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
            'method' => $scan['method'] ? SwitchPortFinder::METHODS[$scan['method']] : null,
            'rows' => $rows,
            'tried' => $tried,
        ];
        if (!$rows)
            $this->flash('The switch answered, but no method returned its MAC address table. See the answers below and the SNMP hints.', true);
    }

    public function save()
    {
        $this->notice = null;
        if (trim($this->host) === '')
        {
            Setting::where('key', 'like', 'ethernetswitch_%')->delete();
            $this->mount();
            $this->flash('Switch lookup is off: boards come from the jumpers again.');
            return;
        }

        $this->validate($this->rules());

        $before = SwitchPortFinder::config();
        $config = $this->formConfig();
        $values = [
            'ip' => $config['host'], 'snmp_version' => $config['version'], 'snmp_community' => $config['community'],
            'snmp_user' => $config['user'], 'snmp_auth_protocol' => $config['auth_protocol'], 'snmp_auth_password' => $config['auth_password'],
            'snmp_priv_protocol' => $config['priv_protocol'], 'snmp_priv_password' => $config['priv_password'], 'method' => $this->method,
        ];
        foreach ($values as $key => $value)
            Setting::updateOrCreate(['key' => 'ethernetswitch_'.$key], ['value' => (string) $value]);
        if ($before['host'] !== $config['host'] || $before['method'] !== $this->method)
            Setting::destroy('ethernetswitch_method_detected');

        $this->mount();
        $this->community = $this->authPassword = $this->privPassword = '';
        $this->flash('Saved. Modules get their switch port as board from now on.');
    }

    protected function rules()
    {
        $methods = implode(',', array_merge(['auto'], array_keys(SwitchPortFinder::METHODS)));
        $rules = [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'version' => 'required|in:2c,3',
            'method' => 'required|in:'.$methods,
        ];
        if ($this->version === '2c')
        {
            $rules['community'] = ($this->stored['community'] ?? false ? 'nullable' : 'required').'|string|max:100';
        }
        else
        {
            $rules['user'] = 'required|string|max:100';
            $rules['authProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::authProtocols());
            $rules['authPassword'] = ($this->authProtocol && !($this->stored['auth_password'] ?? false) ? 'required' : 'nullable').'|string|min:8|max:100';
            $rules['privProtocol'] = 'nullable|in:'.implode(',', PhpSnmpClient::PRIV_PROTOCOLS);
            $rules['privPassword'] = ($this->privProtocol && !($this->stored['priv_password'] ?? false) ? 'required' : 'nullable').'|string|min:8|max:100';
        }
        return $rules;
    }

    /* Settings from the form; secret fields left empty mean "the stored value" */
    protected function formConfig()
    {
        $stored = SwitchPortFinder::config();
        return [
            'host' => trim($this->host),
            'version' => $this->version,
            'community' => $this->community !== '' ? $this->community : $stored['community'],
            'user' => $this->user,
            'auth_protocol' => $this->authProtocol,
            'auth_password' => $this->authPassword !== '' ? $this->authPassword : $stored['auth_password'],
            'priv_protocol' => $this->privProtocol,
            'priv_password' => $this->privPassword !== '' ? $this->privPassword : $stored['priv_password'],
            'method' => $this->method,
            'detected' => null,
        ];
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
