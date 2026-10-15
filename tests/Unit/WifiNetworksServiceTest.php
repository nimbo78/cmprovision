<?php

namespace Tests\Unit;

use App\Services\WifiNetworks;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** What the Wi-Fi section reads of NetworkManager (as the web server user) and how it hands changes to the helper. */
class WifiNetworksServiceTest extends TestCase
{
    const M2 = 'ab18b939-1ff4-4500-99e6-d306e02194af';
    const ONBOARD = '74a0d3da-084a-49ef-9e8b-7a6bc7919cc5';
    const KEYFILES = '/etc/NetworkManager/system-connections/';

    protected $dir;
    /** command (without "timeout N") => [exit code, stdout] */
    protected $answers = [];
    /** what was run: [command, input] */
    protected $commands = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmp-wifi-service-'.bin2hex(random_bytes(4));
        foreach (['sys/wlan0/device', 'sys/wlan1/device', 'bus/pci', 'bus/sdio'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        if (!@symlink($this->dir.'/bus/pci', $this->dir.'/sys/wlan1/device/subsystem'))
            $this->markTestSkipped('symlinks are not available here');
        symlink($this->dir.'/bus/sdio', $this->dir.'/sys/wlan0/device/subsystem');
        file_put_contents($this->dir.'/route', "Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\tMTU\tWindow\tIRTT\n"
            ."wlan1\t00000000\t0144A8C0\t0003\t0\t0\t100\t00000000\t0\t0\t0\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function wifi(): WifiNetworks
    {
        return new WifiNetworks(function (array $command, ?string $input = null) {
            $this->commands[] = ['command' => $command, 'input' => $input];
            if ($command[0] === 'timeout')
                $command = array_slice($command, 2);

            return $this->answers[implode(' ', $command)] ?? [1, ''];
        }, $this->dir.'/helper', self::KEYFILES, $this->dir.'/sys', $this->dir.'/route', $this->dir.'/spare');
    }

    /** nmcli's terse detail view of a Wi-Fi profile */
    protected function details(string $id, string $ssid, string $keyMgmt, string $pmf, string $priority, string $hidden = 'no'): string
    {
        return "connection.id:$id\n802-11-wireless.ssid:$ssid\n802-11-wireless.mode:infrastructure\n802-11-wireless.hidden:$hidden\n"
            ."802-11-wireless-security.key-mgmt:$keyMgmt\n802-11-wireless-security.pmf:$pmf\nconnection.autoconnect-priority:$priority\n";
    }

    protected function shows(string $uuid, string $details): void
    {
        $this->answers['nmcli -t -f '.implode(',', array_keys(WifiNetworks::FIELDS)).' connection show '.$uuid] = [0, $details];
    }

    /** wlanpi-da2 before 2.5.3: a profile per card on '78', the card's connected; and the stand's Ethernet */
    protected function pair(): void
    {
        $this->answers['nmcli -t -f UUID,TYPE,DEVICE,FILENAME connection show'] = [0,
            self::M2.':802-11-wireless:wlan1:'.self::KEYFILES."uplink-m2.nmconnection\n"
            .'d7fe05dc-bc27-430c-8f01-1d3065365941:802-3-ethernet:eth0:'.self::KEYFILES."provisioning.nmconnection\n"
            .self::ONBOARD.':802-11-wireless::'.self::KEYFILES."uplink-onboard.nmconnection\n"];
        $this->shows(self::M2, $this->details('uplink-m2', '78', 'wpa-psk', '0', '20'));
        $this->shows(self::ONBOARD, $this->details('uplink-onboard', '78', 'wpa-psk', '0', '10'));
    }

    public function test_the_78_pair_is_one_network_in_use_on_the_card()
    {
        $this->pair();

        $list = $this->wifi()->list();

        $this->assertSame([[
            'uuid' => self::M2, 'uuids' => [self::M2, self::ONBOARD], 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false,
            'priority' => 20, 'devices' => ['wlan1' => 'M.2 card (wlan1)'],
        ]], $list['networks']);
        $this->assertSame([], $list['others'], 'Ethernet is no Wi-Fi profile at all');
    }

    public function test_profiles_left_to_other_tools_are_listed_with_the_reason()
    {
        $this->answers['nmcli -t -f UUID,TYPE,DEVICE,FILENAME connection show'] = [0,
            "11111111-0000-4000-8000-000000000001:802-11-wireless::/run/NetworkManager/system-connections/netplan-wlan0-guest.nmconnection\n"
            .'11111111-0000-4000-8000-000000000002:802-11-wireless::'.self::KEYFILES."T-NET.nmconnection\n"
            .'11111111-0000-4000-8000-000000000003:802-11-wireless::'.self::KEYFILES."cafe.nmconnection\n"];
        $this->shows('11111111-0000-4000-8000-000000000001', $this->details('netplan-wlan0-guest', 'guest', 'wpa-psk', '0', '0'));
        $this->shows('11111111-0000-4000-8000-000000000002', $this->details('T-NET', 'T-NET', 'wpa-eap', '0', '0'));
        $this->shows('11111111-0000-4000-8000-000000000003', $this->details('cafe', 'cafe', '', '0', '0'));

        $list = $this->wifi()->list();

        $this->assertSame([], $list['networks']);
        $this->assertSame([
            ['name' => 'netplan-wlan0-guest', 'ssid' => 'guest', 'why' => 'netplan'],
            ['name' => 'T-NET', 'ssid' => 'T-NET', 'why' => 'Enterprise (802.1X)'],
            ['name' => 'cafe', 'ssid' => 'cafe', 'why' => 'open network'],
        ], $list['others']);
    }

    public function test_networks_come_preferred_first()
    {
        $this->pair();
        $this->answers['nmcli -t -f UUID,TYPE,DEVICE,FILENAME connection show'][1] .=
            '33333333-0000-4000-8000-000000000001:802-11-wireless::'.self::KEYFILES."phone.nmconnection\n";
        $this->shows('33333333-0000-4000-8000-000000000001', $this->details('phone', 'phone', 'sae', '3', '100'));

        $this->assertSame(['phone', '78'], array_column($this->wifi()->list()['networks'], 'ssid'));
    }

    public function test_a_profile_active_on_both_radios_is_one_network()
    {
        // the minute both radios are up: nmcli lists the multi-connect profile once per device
        $this->answers['nmcli -t -f UUID,TYPE,DEVICE,FILENAME connection show'] = [0,
            self::M2.':802-11-wireless:wlan1:'.self::KEYFILES."78.nmconnection\n"
            .self::M2.':802-11-wireless:wlan0:'.self::KEYFILES."78.nmconnection\n"];
        $this->shows(self::M2, $this->details('78', '78', 'wpa-psk', '2', '100'));

        $networks = $this->wifi()->list()['networks'];

        $this->assertCount(1, $networks);
        $this->assertSame([self::M2], $networks[0]['uuids']);
        $this->assertSame(['wlan1' => 'M.2 card (wlan1)', 'wlan0' => 'built-in (wlan0)'], $networks[0]['devices']);
        $this->assertSame('mixed', $networks[0]['security']);
    }

    public function test_an_ssid_with_a_colon_in_the_file_name()
    {
        $this->answers['nmcli -t -f UUID,TYPE,DEVICE,FILENAME connection show'] = [0,
            '22222222-0000-4000-8000-000000000001:802-11-wireless::'.self::KEYFILES."Офис\\: 2 этаж.nmconnection\n"];
        $this->shows('22222222-0000-4000-8000-000000000001', $this->details('Офис: 2 этаж', 'Офис: 2 этаж', 'sae', '3', '99', 'yes'));

        $networks = $this->wifi()->list()['networks'];

        $this->assertSame('Офис: 2 этаж', $networks[0]['ssid']);
        $this->assertSame('wpa3', $networks[0]['security']);
        $this->assertTrue($networks[0]['hidden']);
    }

    public function test_save_sends_the_form_on_stdin_and_never_as_an_argument()
    {
        $this->answers['sudo -n '.$this->dir.'/helper save'] = [0, 'uuid='.self::M2."\n"];

        $result = $this->wifi()->save(['uuid' => null, 'ssid' => 'office', 'security' => 'mixed', 'hidden' => true, 'password' => 'S3cret pass'], 'anna');

        $this->assertSame(['uuid' => self::M2, 'error' => null], $result);
        $this->assertSame(['timeout', '30', 'sudo', '-n', $this->dir.'/helper', 'save'], $this->commands[0]['command']);
        $this->assertSame("ssid=office\nsecurity=mixed\nhidden=yes\npassword=S3cret pass\nby=anna\n", $this->commands[0]['input']);
    }

    public function test_an_edit_names_the_network_and_may_keep_the_password()
    {
        $this->answers['sudo -n '.$this->dir.'/helper save'] = [0, 'uuid='.self::M2."\n"];

        $this->wifi()->save(['uuid' => self::M2, 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false, 'password' => ''], 'anna');

        $this->assertSame('uuid='.self::M2."\nssid=78\nsecurity=wpa2\nhidden=no\npassword=\nby=anna\n", $this->commands[0]['input']);
    }

    public function test_a_refusal_comes_back_as_its_reason()
    {
        $this->answers['sudo -n '.$this->dir.'/helper delete'] = [1, "error='78' is in use: connect another network first\n"];

        $this->assertSame("'78' is in use: connect another network first", $this->wifi()->delete(self::M2, 'anna'));
    }

    public function test_a_helper_that_cannot_run_is_reported()
    {
        // sudo without the rule complains on stderr, which is not read: the exit code is what is left
        $this->answers['sudo -n '.$this->dir.'/helper delete'] = [1, ''];
        $this->assertSame('the Wi-Fi helper could not run (exit 1)', $this->wifi()->delete(self::M2, 'anna'));

        $this->answers['sudo -n '.$this->dir.'/helper check'] = [124, ''];
        $this->assertSame(['result' => 'error', 'address' => '', 'detail' => 'the Wi-Fi helper did not answer in time'],
            $this->wifi()->check(self::M2, 'anna'));
    }

    public function test_a_line_break_never_reaches_the_helper()
    {
        $result = $this->wifi()->save(['uuid' => null, 'ssid' => 'office', 'security' => 'wpa2', 'hidden' => false,
                                       'password' => "pass-word\nuuid=".self::M2], 'anna');

        $this->assertSame('a line break is not allowed in password', $result['error']);
        $this->assertSame([], $this->commands);
    }

    public function test_order_sends_a_uuid_line_per_network()
    {
        $this->answers['sudo -n '.$this->dir.'/helper order'] = [0, ''];

        $this->assertNull($this->wifi()->order(['33333333-0000-4000-8000-000000000001', self::M2], 'anna'));

        $this->assertSame("uuid=33333333-0000-4000-8000-000000000001\nuuid=".self::M2."\nby=anna\n", $this->commands[0]['input']);
    }

    public function test_check_returns_what_the_helper_found()
    {
        $this->answers['sudo -n '.$this->dir.'/helper check'] = [0, "result=busy\naddress=\ndetail=the cold spare is switching\n"];

        $this->assertSame(['result' => 'busy', 'address' => '', 'detail' => 'the cold spare is switching'], $this->wifi()->check(self::M2, 'anna'));
        $this->assertSame(['timeout', '60', 'sudo', '-n', $this->dir.'/helper', 'check'], $this->commands[0]['command']);
    }

    public function test_scan_names_each_network_once_with_its_best_signal_and_bands()
    {
        $this->answers['sudo -n '.$this->dir.'/helper scan'] = [0,
            "78:100:WPA2:2422 MHz\nT-NET:92:WPA2 802.1X:5240 MHz\n78:90:WPA2:5660 MHz\nService PSK:80:WPA2 WPA3:2437 MHz\n"
            ."lab\\:2:55:WPA3:5955 MHz\nGuest:40::2412 MHz\n"];

        $this->assertSame([
            ['ssid' => '78', 'signal' => 100, 'security' => 'wpa2', 'bands' => ['2.4 GHz', '5 GHz']],
            ['ssid' => 'T-NET', 'signal' => 92, 'security' => 'enterprise', 'bands' => ['5 GHz']],
            ['ssid' => 'Service PSK', 'signal' => 80, 'security' => 'mixed', 'bands' => ['2.4 GHz']],
            ['ssid' => 'lab:2', 'signal' => 55, 'security' => 'wpa3', 'bands' => ['6 GHz']],
            ['ssid' => 'Guest', 'signal' => 40, 'security' => 'open', 'bands' => ['2.4 GHz']],
        ], $this->wifi()->scan());
    }

    public function test_check_now_needs_the_built_in_wifi_idle_in_the_cold_spare()
    {
        $wifi = $this->wifi();
        $this->assertSame('no built-in Wi-Fi', $wifi->checkBlocker(), 'no state file');
        foreach (['standby' => null, 'active' => 'the built-in Wi-Fi carries the traffic', 'waiting' => 'the cold spare is switching',
                  'off' => 'the cold spare is off (SPARE=no)'] as $state => $reason) {
            file_put_contents($this->dir.'/spare', "state=$state\nsince=1790000000\nspare=wlan0\nuplink=wlan1\nup_since=\nlost_since=\n");
            $this->assertSame($reason, $wifi->checkBlocker(), $state);
        }
    }

    public function test_available_where_networkmanager_runs_a_wifi_device_and_the_helper_is_there()
    {
        $this->answers['nmcli -t -f TYPE,STATE device'] = [0, "wifi:connected\nethernet:connected\nwifi-p2p:disconnected\n"];
        $this->assertFalse($this->wifi()->available(), 'no helper');

        touch($this->dir.'/helper');
        $this->assertTrue($this->wifi()->available());

        $this->answers['nmcli -t -f TYPE,STATE device'] = [0, "wifi:unmanaged\nethernet:connected\n"];
        $this->assertFalse($this->wifi()->available(), 'WLAN Pi OS: NetworkManager leaves the Wi-Fi alone');
    }
}
