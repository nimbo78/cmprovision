<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Models\Host;
use App\Models\Setting;
use App\Services\ServiceLogs;
use App\Services\ServiceState;
use App\Services\UtilityOs;
use Illuminate\Support\Facades\Validator;

#[Title('Settings')]
class Settings extends Component
{
    /** tab => name; each has its own address (/settings?tab=network) and reads only its own sections */
    const TABS = [
        'status' => 'Status',
        'system' => 'System',
        'network' => 'Network',
        'provisioning' => 'Provisioning',
        'notifications' => 'Notifications',
        'updates' => 'Updates',
    ];

    /** Log source => [service label, systemd unit, FPMS short name]; the web interface answers this request. */
    const SERVICES = [
        'dnsmasq' => ['dnsmasq (DHCP server)', 'cmprovision-dnsmasq', 'dnsmasq'],
        'rpiboot' => ['rpiboot (USB file server)', 'cmprovision-rpiboot', 'rpiboot'],
        'usb-diagnostics' => ['USB diagnostic fallback receiver', 'cmprovision-usb-diagnostics', 'USB diagnostics'],
        'queue' => ['queue worker (switch ports, notifications)', 'cmprovision-queue', 'queue'],
        'queue-images' => ['queue worker (image checksums)', 'cmprovision-queue-images', 'image queue'],
        'uplink-spare' => ['uplink cold spare (built-in Wi-Fi)', 'cmprovision-uplink.timer', 'Wi-Fi spare'],
        'laravel' => ['web interface', null, 'web'],
    ];

    #[Url(as: 'tab')]
    public $tab = 'status';

    public $logTitle, $logSource;
    public $ip, $mac, $detectedMacs;
    public $logModalOpen = false, $staticModalOpen = false;

    public function render()
    {
        // the property comes from the address and from the browser: ?tab[]=x makes it an array
        $this->tab = is_string($this->tab) && array_key_exists($this->tab, self::TABS) ? $this->tab : 'status';

        $data = ['tabs' => self::TABS];
        if ($this->tab === 'status') {
            $state = app(ServiceState::class);
            $data['services'] = [];
            foreach (self::SERVICES as $source => [$label, $unit]) {
                $status = $unit === null ? 'running' : $state->status($unit);
                $data['services'][$source] = [
                    'label' => $label,
                    'status' => $status,
                    'chip' => ['running' => 'cmp-chip-ok', 'disabled' => 'cmp-chip-idle', 'stopped' => 'cmp-chip-fail'][$status],
                    'method' => 'viewLog'.str_replace(' ', '', ucwords(str_replace('-', ' ', $source))),
                ];
            }
            $os = app(UtilityOs::class);
            $current = $os->current();
            $data['utilityOs'] = UtilityOs::label($current ? $os->manifest($current) : null);
        }
        if ($this->tab === 'network') {
            $data['hosts'] = Host::orderBy('ip')->get();
        }

        return view('livewire.settings', $data);
    }

    /* the journal since boot of a unit (-u) or of a syslog tag (-t) */
    protected function getLog($name, $option = '-u')
    {
        $output = $retcode = '';
        exec("/bin/journalctl --boot ".$option." ".escapeshellarg($name), $output, $retcode);
        return implode("\n", $output);
    }

    protected function restartDnsmasq()
    {
        $output = $retcode = '';
        exec(config('cmprovision.dnsmasq_restart'), $output, $retcode);
        return ($retcode == 0);
    }

    /* a log window follows its log by itself (the ServiceLog component inside it) */
    protected function openLog(string $source)
    {
        $this->logSource = $source;
        $this->logTitle = ServiceLogs::title($source);
        $this->logModalOpen = true;
    }

    public function viewLogDnsmasq()
    {
        $this->openLog('dnsmasq');
    }

    public function viewLogRpiboot()
    {
        $this->openLog('rpiboot');
    }

    public function viewLogUsbDiagnostics()
    {
        $this->openLog('usb-diagnostics');
    }

    public function viewLogQueue()
    {
        $this->openLog('queue');
    }

    public function viewLogQueueImages()
    {
        $this->openLog('queue-images');
    }

    public function viewLogUplinkSpare()
    {
        $this->openLog('uplink-spare');
    }

    public function viewLogLaravel()
    {
        $this->openLog('laravel');
    }

    public function closeModal()
    {
        $this->logModalOpen = $this->staticModalOpen = false;
    }

    protected function regenDnsmasqConfAndRestart()
    {
        try { app(\App\Services\DhcpReservations::class)->write(); }
        catch (\RuntimeException $error) {
            session()->flash('message', $error->getMessage());
            return;
        }

        if ($this->restartDnsmasq() )
            session()->flash('message', 'Restarted dnsmasq.');
        else
            session()->flash('message', 'Unable to restart dnsmasq automatically, please do so manually.');
    }

    public function deleteStaticIP($id)
    {
        Host::destroy($id);
        $this->regenDnsmasqConfAndRestart();
    }

    public function addStaticIP()
    {
        $this->resetErrorBag();
        $this->resetValidation();
        $this->ip = Host::firstAvailableIP();
        $this->mac = '';
        $this->detectedMacs = [];
        $this->staticModalOpen = true;
        
        $dnsmasqlog = explode("\n", $this->getLog('cmprovision-dnsmasq'));
        foreach ($dnsmasqlog as $l)
        {
            if (preg_match("/ ([0-9A-Fa-f:]{17}) no address available/", $l, $regs))
            {
                if (!in_array($regs[1], $this->detectedMacs))
                {
                    $this->detectedMacs[] = $regs[1];
                }
            }
        }

        /* Prefill MAC address field if a host attempted to get DHCP lease recently
           and it is not already in our database */
        if (count($this->detectedMacs))
        {
            $lastmac = end($this->detectedMacs);
            if (!Host::firstWhere('mac', $lastmac))
            {
                $this->mac = $lastmac;
            }
        }
    }

    public function storeStaticIP()
    {
        $this->mac = strtolower($this->mac);

        $this->validate([
            'ip' => 'required|ipv4|unique:hosts,ip',
            'mac' => 'required|regex:/^([0-9A-Fa-f]{2}[:]){5}([0-9A-Fa-f]{2})$/|unique:hosts,mac'
        ]);

        Host::create([
            'ip' => $this->ip,
            'mac' => $this->mac
        ]);

        $this->regenDnsmasqConfAndRestart();
        $this->closeModal();        
    }
}
