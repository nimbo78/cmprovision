<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Setting;
use App\Services\Snmp\PhpSnmpClient;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use App\Services\Switch\SwitchSnapshot;
use App\Services\SwitchPortFinder;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/* Settings page section: the SNMP switch that tells which port a module is plugged into */
class SwitchSettings extends Component
{
    public $host = '', $version = '2c', $community = '', $user = '';
    public $authProtocol = '', $authPassword = '', $privProtocol = '', $privPassword = '', $method = 'auto';
    // PoE restart: write credentials of their own; secrets never go to the browser either
    public $writeCommunity = '', $writeUser = '', $writeAuthProtocol = '', $writeAuthPassword = '', $writePrivProtocol = '', $writePrivPassword = '';
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
        $w = $c['write'];
        $this->writeUser = $w['user'];
        $this->writeAuthProtocol = $w['auth_protocol'];
        $this->writePrivProtocol = $w['priv_protocol'];
        $this->stored += ['write_community' => $w['community'] !== '', 'write_auth_password' => $w['auth_password'] !== '',
                          'write_priv_password' => $w['priv_password'] !== ''];
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
        if (!$scan['answered'])
            $this->flash('The switch did not answer: check the address, the community or SNMPv3 user, and that SNMP is enabled for this server\'s address.', true);
        else if (!$rows)
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
            'snmp_write_community' => $config['write']['community'], 'snmp_write_user' => $config['write']['user'],
            'snmp_write_auth_protocol' => $config['write']['auth_protocol'], 'snmp_write_auth_password' => $config['write']['auth_password'],
            'snmp_write_priv_protocol' => $config['write']['priv_protocol'], 'snmp_write_priv_password' => $config['write']['priv_password'],
        ];
        foreach ($values as $key => $value)
            Setting::updateOrCreate(['key' => 'ethernetswitch_'.$key], ['value' => (string) $value]);
        if ($before['host'] !== $config['host'] || $before['method'] !== $this->method)
            Setting::destroy('ethernetswitch_method_detected');

        $this->mount();
        $this->community = $this->authPassword = $this->privPassword = '';
        $this->writeCommunity = $this->writeAuthPassword = $this->writePrivPassword = '';
        $this->flash('Saved. Modules get their switch port as board from now on.');
    }

    /* Write access without a change: the first PoE port's value is written back as it is (PoeControl::check()) */
    public function checkWrite()
    {
        $this->result = null;
        $this->notice = null;
        $config = $this->formConfig();
        if (!PoeControl::writable($config))
        {
            $this->flash('Enter the write community (v2c) or the write user (v3) first.', true);
            return;
        }
        // the value it reads and writes back could be a restart's "off", written after the restart switched the port on
        $lock = Cache::lock(PoeRestart::LOCK, 60);
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

    /* No more PoE restarts: the write credentials go, the read ones stay */
    public function forgetWrite()
    {
        Setting::where('key', 'like', 'ethernetswitch_snmp_write_%')->delete();
        $this->mount();
        $this->writeCommunity = $this->writeAuthPassword = $this->writePrivPassword = '';
        $this->flash('Write access removed: no PoE restarts.');
    }

    protected function rules()
    {
        $methods = implode(',', array_merge(['auto'], array_keys(SwitchPortFinder::METHODS)));
        $rules = [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.:_-]+$/'],
            'version' => 'required|in:2c,3',
            'method' => 'required|in:'.$methods,
        ];
        $stored = SwitchPortFinder::config();
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

    /* Settings from the form. A secret field left empty means "the stored value", but only for the
       switch it was stored for: otherwise a new address would receive the hidden community or password. */
    protected function formConfig()
    {
        $stored = SwitchPortFinder::config();
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

    protected function sameSwitch(array $stored)
    {
        return $stored['host'] !== '' && trim($this->host) === $stored['host'] && $this->version === $stored['version'];
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
