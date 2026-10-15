<?php

namespace App\Services;

/**
 * The provisioner's Wi-Fi networks (2.5.3, docs/superpowers/specs/2026-10-15-wifi-networks-design.md), for the section
 * of the settings page: read the way the web server user can (nmcli without secrets, /sys/class/net, /proc/net/route,
 * the cold spare's state file) and changed by the root helper tools/wifi-networks through sudo, the input on stdin. A
 * network password goes to the helper once and is never read back.
 */
class WifiNetworks
{
    const HELPER = '/usr/lib/cmprovision/wifi-networks';
    /** the name of the copy of a profile that Check now brings up for a few seconds (tools/wifi-networks) */
    const CHECK_ID = 'cmprovision-check';
    /** the security of a network as the helper takes it => as the page names it */
    const SECURITY = ['wpa2' => 'WPA2-Personal', 'mixed' => 'WPA2/WPA3 (mixed)', 'wpa3' => 'WPA3-Personal'];
    /** what is read of a profile, from nmcli's terse detail view (name:value lines, not escaped) */
    const FIELDS = [
        'connection.id' => 'id', '802-11-wireless.ssid' => 'ssid', '802-11-wireless.mode' => 'mode',
        '802-11-wireless.hidden' => 'hidden', '802-11-wireless-security.key-mgmt' => 'key_mgmt',
        '802-11-wireless-security.pmf' => 'pmf', 'connection.autoconnect-priority' => 'priority',
    ];

    private $run;
    private $helper;
    private $keyfiles;
    private $net;
    private $routes;
    private $spare;

    /**
     * @param callable|null $run runs [program, arguments...] with an optional stdin; returns [exit code, stdout]
     */
    public function __construct(?callable $run = null, string $helper = self::HELPER,
                                string $keyfiles = '/etc/NetworkManager/system-connections',
                                string $net = '/sys/class/net', string $routes = '/proc/net/route',
                                string $spare = '/run/cmprovision/uplink-spare')
    {
        $this->helper = $helper;
        $this->keyfiles = rtrim($keyfiles, '/').'/';
        $this->net = $net;
        $this->routes = $routes;
        $this->spare = $spare;
        $this->run = $run ?: function (array $command, ?string $input = null) {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                return [127, ''];
            }
            fwrite($pipes[0], (string) $input);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            return [proc_close($process), $output];
        };
    }

    /** NetworkManager runs a Wi-Fi device here and the helper is installed: not on WLAN Pi OS, say */
    public function available(): bool
    {
        if (!is_file($this->helper)) {
            return false;
        }
        [$code, $out] = $this->call(['timeout', '3', 'nmcli', '-t', '-f', 'TYPE,STATE', 'device']);
        foreach ($code === 0 ? explode("\n", trim($out)) : [] as $line) {
            $f = self::fields($line);
            if ($f[0] === 'wifi' && ($f[1] ?? 'unmanaged') !== 'unmanaged') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array networks: [uuid (the profile that stays), uuids, ssid, security (wpa2, mixed, wpa3), hidden, priority,
     *               devices (device => "M.2 card (wlan1)")], the preferred first; others: [name, ssid, why] of the Wi-Fi
     *               profiles left to other tools
     */
    public function list(): array
    {
        [$code, $out] = $this->call(['timeout', '3', 'nmcli', '-t', '-f', 'UUID,TYPE,DEVICE,FILENAME', 'connection', 'show']);
        $profiles = [];
        foreach ($code === 0 ? explode("\n", trim($out)) : [] as $line) {
            $f = self::fields($line);
            if (count($f) < 4 || $f[1] !== '802-11-wireless') {
                continue;
            }
            // nmcli lists a profile active on two devices twice
            $profiles[$f[0]] = $profiles[$f[0]] ?? ['uuid' => $f[0], 'file' => $f[3], 'devices' => []];
            if ($f[2] !== '') {
                $profiles[$f[0]]['devices'][] = $f[2];
            }
        }
        $groups = $others = [];
        foreach ($profiles as $p) {
            $p += $this->details($p['uuid']);
            if ($p['id'] === self::CHECK_ID) {
                continue;
            }
            $why = $this->unmanaged($p);
            if ($why !== null) {
                $others[] = ['name' => $p['id'], 'ssid' => $p['ssid'], 'why' => $why];
            } else {
                $groups[$p['ssid']][] = $p;
            }
        }
        $route = $this->routeDevice();
        $networks = [];
        foreach ($groups as $ssid => $group) {
            $keep = $this->kept($group, $route);
            $devices = [];
            foreach ($group as $p) {
                foreach ($p['devices'] as $device) {
                    $devices[$device] = $this->deviceName($device);
                }
            }
            $networks[] = [
                'uuid' => $keep['uuid'], 'uuids' => array_column($group, 'uuid'), 'ssid' => (string) $ssid,
                'security' => $keep['key_mgmt'] === 'sae' ? 'wpa3' : ($keep['pmf'] === '1' ? 'wpa2' : 'mixed'),
                'hidden' => $keep['hidden'] === 'yes', 'priority' => (int) $keep['priority'], 'devices' => $devices,
            ];
        }
        usort($networks, function ($a, $b) {
            return $b['priority'] <=> $a['priority'] ?: strcmp($a['ssid'], $b['ssid']);
        });

        return ['networks' => $networks, 'others' => $others];
    }

    /** why Check now cannot run: it needs the built-in Wi-Fi idle in the cold spare; null when it can */
    public function checkBlocker(): ?string
    {
        $spare = (new NetworkStatus($this->net, $this->routes, null, null, $this->spare))->spare();
        if ($spare === null || $spare['device'] === null) {
            // tools/uplink-spare writes its state on a NetworkManager event, or two minutes after a boot
            return $this->builtinWifi() ? 'the cold spare has not reported yet' : 'no built-in Wi-Fi';
        }

        return [
            'standby' => null,
            'active' => 'the built-in Wi-Fi carries the traffic',
            'waiting' => 'the cold spare is switching',
            'off' => 'the cold spare is off (SPARE=no)',
        ][$spare['state']];
    }

    /**
     * A new network (no uuid) or an edited one; an empty password keeps the stored one.
     *
     * @return array uuid of the saved profile, error (null when saved)
     */
    public function save(array $network, string $by): array
    {
        $pairs = [];
        if (!empty($network['uuid'])) {
            $pairs[] = ['uuid', $network['uuid']];
        }
        array_push($pairs, ['ssid', $network['ssid']], ['security', $network['security']],
            ['hidden', !empty($network['hidden']) ? 'yes' : 'no'], ['password', (string) ($network['password'] ?? '')], ['by', $by]);
        $result = $this->helper('save', $pairs, 30);

        return ['uuid' => $result['uuid'] ?? null, 'error' => $result['error'] ?? null];
    }

    /** @return string|null why the network was not deleted */
    public function delete(string $uuid, string $by): ?string
    {
        return $this->helper('delete', [['uuid', $uuid], ['by', $by]], 30)['error'] ?? null;
    }

    /** the whole order, a UUID per network, the preferred first; @return string|null why it was not saved */
    public function order(array $uuids, string $by): ?string
    {
        $pairs = array_map(function ($uuid) {
            return ['uuid', $uuid];
        }, $uuids);
        $pairs[] = ['by', $by];

        return $this->helper('order', $pairs, 30)['error'] ?? null;
    }

    /**
     * Check now: the network on the idle built-in Wi-Fi.
     *
     * @return array result (connected, wrong-password, not-found, no-address, timeout, failed, busy, error), address, detail
     */
    public function check(string $uuid, string $by): array
    {
        // the helper's worst case: the lists, up 30 s, disconnect 20 s, the cold spare's own check
        $r = $this->helper('check', [['uuid', $uuid], ['by', $by]], 90);
        if (isset($r['error'])) {
            return ['result' => 'error', 'address' => '', 'detail' => $r['error']];
        }

        return ['result' => $r['result'] ?? 'failed', 'address' => $r['address'] ?? '', 'detail' => $r['detail'] ?? ''];
    }

    /**
     * The named networks in reach, each once with its best signal.
     *
     * @return array networks: [ssid, signal (0-100), security, bands], strongest first; error: why there is no list
     */
    public function scan(): array
    {
        [$code, $out] = $this->call(['timeout', '30', 'sudo', '-n', $this->helper, 'scan']);
        if ($code !== 0) {
            return ['networks' => [], 'error' => preg_match('/^error=(.*)$/m', $out, $m) ? $m[1]
                : ($code === 124 ? 'the Wi-Fi helper did not answer in time' : 'the Wi-Fi helper could not run (exit '.$code.')')];
        }
        $seen = [];
        foreach (explode("\n", trim($out)) as $line) {
            $f = self::fields($line);
            if (count($f) < 4 || $f[0] === '') {
                continue;
            }
            $signal = (int) $f[1];
            $band = self::band((int) $f[3]);
            if (!isset($seen[$f[0]]) || $signal > $seen[$f[0]]['signal']) {
                $seen[$f[0]] = ['ssid' => $f[0], 'signal' => $signal, 'security' => self::scanned($f[2]),
                                'bands' => $seen[$f[0]]['bands'] ?? []];
            }
            $seen[$f[0]]['bands'][$band] = $band;
        }
        $list = [];
        foreach ($seen as $network) {
            ksort($network['bands']);
            $network['bands'] = array_values($network['bands']);
            $list[] = $network;
        }
        usort($list, function ($a, $b) {
            return $b['signal'] <=> $a['signal'] ?: strcmp($a['ssid'], $b['ssid']);
        });

        return ['networks' => $list, 'error' => null];
    }

    /** the helper's changes and refusals from the journal (the web server user is in systemd-journal) */
    public function log(): string
    {
        return $this->call(['journalctl', '-t', 'cmprovision-wifi', '-n', '200', '--no-pager', '-o', 'short'])[1];
    }

    /** "M.2 card (wlan1)", "USB adapter (wlan2)", "built-in (wlan0)", by the bus, as the uplink icon names them */
    public function deviceName(string $device): string
    {
        $bus = @readlink($this->net.'/'.$device.'/device/subsystem');
        $bus = $bus === false ? null : basename($bus);

        return ($bus === 'pci' ? 'M.2 card' : ($bus === 'usb' ? 'USB adapter' : 'built-in')).' ('.$device.')';
    }

    /** a built-in Wi-Fi is there: a wireless device on the SDIO bus, as tools/uplink-spare finds it */
    private function builtinWifi(): bool
    {
        foreach (@scandir($this->net) ?: [] as $name) {
            $dir = $this->net.'/'.$name;
            if ($name[0] === '.' || !(is_dir($dir.'/wireless') || file_exists($dir.'/phy80211'))) {
                continue;
            }
            $bus = @readlink($dir.'/device/subsystem');
            if ($bus !== false && basename($bus) === 'sdio') {
                return true;
            }
        }

        return false;
    }

    /** a profile's settings, FIELDS' names => values */
    private function details(string $uuid): array
    {
        $values = array_fill_keys(array_values(self::FIELDS), '');
        [$code, $out] = $this->call(['timeout', '3', 'nmcli', '-t', '-f', implode(',', array_keys(self::FIELDS)), 'connection', 'show', $uuid]);
        foreach ($code === 0 ? explode("\n", rtrim($out, "\n")) : [] as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) === 2 && isset(self::FIELDS[$pair[0]])) {
                $values[self::FIELDS[$pair[0]]] = $pair[1];
            }
        }

        return $values;
    }

    /** why a Wi-Fi profile is left to other tools; null when it is managed here (the rule of tools/wifi-networks) */
    private function unmanaged(array $p): ?string
    {
        if (strpos($p['file'], $this->keyfiles) !== 0) {
            return strpos($p['file'], 'netplan') !== false ? 'netplan' : 'not a keyfile of NetworkManager';
        }
        if (!in_array($p['mode'], ['', 'infrastructure'], true)) {
            return 'not a client of an access point';
        }
        if (in_array($p['key_mgmt'], ['wpa-eap', 'wpa-eap-suite-b-192', 'ieee8021x'], true)) {
            return 'Enterprise (802.1X)';
        }
        if (in_array($p['key_mgmt'], ['', 'owe'], true)) {
            return 'open network';
        }
        if (!in_array($p['key_mgmt'], ['wpa-psk', 'sae'], true)) {
            return $p['key_mgmt'] === 'none' ? 'WEP' : $p['key_mgmt'];
        }
        if ($p['ssid'] === '' || strlen($p['ssid']) > 32 || preg_match('/[\x00-\x1F\x7F]/', $p['ssid']) || !mb_check_encoding($p['ssid'], 'UTF-8')) {
            return 'a network name this page cannot edit';
        }

        return null;
    }

    /** the profile of a network that stays, as tools/wifi-networks decides: active where the default route goes, else any active one, else the first by UUID */
    private function kept(array $group, ?string $route): array
    {
        usort($group, function ($a, $b) {
            return strcmp($a['uuid'], $b['uuid']);
        });
        foreach ($group as $p) {
            if ($route !== null && in_array($route, $p['devices'], true)) {
                return $p;
            }
        }
        foreach ($group as $p) {
            if ($p['devices']) {
                return $p;
            }
        }

        return $group[0];
    }

    /** the interface of the default route with the lowest metric; null without one */
    private function routeDevice(): ?string
    {
        $best = null;
        $low = null;
        foreach (array_slice(@file($this->routes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], 1) as $line) {
            $f = preg_split('/\s+/', trim($line));
            // Iface Destination Gateway Flags RefCnt Use Metric Mask ...; flag 1 is RTF_UP
            if (count($f) < 8 || $f[1] !== '00000000' || $f[7] !== '00000000' || !(hexdec($f[3]) & 1)) {
                continue;
            }
            if ($low === null || (int) $f[6] < $low) {
                $best = $f[0];
                $low = (int) $f[6];
            }
        }

        return $best;
    }

    /** the helper through sudo with key=value lines on stdin: its key=value output, with error= when it refused or did not run */
    private function helper(string $command, array $pairs, int $seconds): array
    {
        $input = '';
        foreach ($pairs as [$key, $value]) {
            // the rest of the value after a line break would be a key of its own
            if (preg_match('/[\r\n]/', (string) $value)) {
                return ['error' => 'a line break is not allowed in '.$key];
            }
            $input .= $key.'='.$value."\n";
        }
        [$code, $out] = $this->call(['timeout', (string) $seconds, 'sudo', '-n', $this->helper, $command], $input);
        $result = [];
        foreach (explode("\n", $out) as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) === 2) {
                $result[$pair[0]] = $pair[1];
            }
        }
        if ($code !== 0 && !isset($result['error'])) {
            $result['error'] = $code === 124 ? 'the Wi-Fi helper did not answer in time' : 'the Wi-Fi helper could not run (exit '.$code.')';
        }

        return $result;
    }

    private function call(array $command, ?string $input = null): array
    {
        return call_user_func($this->run, $command, $input);
    }

    /** a line of nmcli's terse list: the fields split at the ':' that are not escaped, '\:' and '\\' unescaped */
    private static function fields(string $line): array
    {
        $fields = [''];
        $n = strlen($line);
        for ($i = 0; $i < $n; $i++) {
            if ($line[$i] === '\\' && $i + 1 < $n) {
                $fields[count($fields) - 1] .= $line[++$i];
            } elseif ($line[$i] === ':') {
                $fields[] = '';
            } else {
                $fields[count($fields) - 1] .= $line[$i];
            }
        }

        return $fields;
    }

    /** nmcli's SECURITY of a scanned network as the form takes it */
    private static function scanned(string $security): string
    {
        if (strpos($security, '802.1X') !== false) {
            return 'enterprise';
        }
        if (trim($security) === '') {
            return 'open';
        }
        $wpa3 = strpos($security, 'WPA3') !== false;
        $wpa2 = strpos($security, 'WPA2') !== false || strpos($security, 'WPA1') !== false;
        if ($wpa3) {
            return $wpa2 ? 'mixed' : 'wpa3';
        }

        return $wpa2 ? 'wpa2' : 'other';
    }

    private static function band(int $mhz): string
    {
        if ($mhz < 3000) {
            return '2.4 GHz';
        }

        return $mhz < 5925 ? '5 GHz' : '6 GHz';
    }
}
