<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The provisioner's Wi-Fi networks (tools/wifi-networks, docs/operations.md#wi-fi-networks),
 * run the way root runs it: under sh, with tests/Support/fake-nmcli.php for NetworkManager, stand-ins for ip, logger
 * and uplink-spare that record what they were asked, and a made-up keyfile directory, /run and /sys.
 */
class WifiNetworksTest extends TestCase
{
    /** the two profiles of wlanpi-da2 before 2.5.3 */
    const M2 = 'ab18b939-1ff4-4500-99e6-d306e02194af';
    const ONBOARD = '74a0d3da-084a-49ef-9e8b-7a6bc7919cc5';
    const SECRET = 'old-secret-78';
    const CARD = "default via 192.168.68.1 dev wlan1 proto dhcp src 192.168.68.237 metric 100\n";

    protected $dir;
    protected $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['flock', 'iconv'] as $tool)
            if (!is_executable('/bin/sh') || trim((string) shell_exec('command -v '.$tool.' 2>/dev/null')) === '')
                $this->markTestSkipped('needs a POSIX shell, flock and iconv');
        $this->dir = sys_get_temp_dir().'/cmp-wifi-'.bin2hex(random_bytes(4));
        foreach (['bin', 'keyfiles', 'elsewhere', 'run', 'sys/wlan0/wireless', 'devices/wlan0', 'bus/sdio'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        // the built-in Wi-Fi: a wireless device on the SDIO bus, as tools/uplink-spare finds it
        if (!@symlink($this->dir.'/bus/sdio', $this->dir.'/devices/wlan0/subsystem'))
            $this->markTestSkipped('symlinks are not available here');
        symlink($this->dir.'/devices/wlan0', $this->dir.'/sys/wlan0/device');
        $this->stub('nmcli', 'exec php '.escapeshellarg(base_path('tests/Support/fake-nmcli.php')).' "$@"');
        $this->stub('ip', 'cat "$FAKE/routes" 2>/dev/null; exit 0');
        // logger as util-linux parses it: -t TAG, -- ends the options, and any other leading dash is an option it refuses
        $this->stub('logger', 'tag=; while [ $# -gt 0 ]; do case "$1" in -t) tag=$2; shift 2 ;; --) shift; break ;; -*) exit 1 ;; *) break ;; esac; done; echo "-t $tag $*" >> "$FAKE/journal"');
        $this->stub('uplink-spare', 'echo "uplink-spare $*" >> "$FAKE/calls"');
        file_put_contents($this->dir.'/routes', self::CARD);
        $this->spare('standby');
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    /** the cold spare's state file (tools/uplink-spare); null: there is none */
    protected function spare(?string $state): void
    {
        $file = $this->dir.'/run/uplink-spare';
        if ($state === null) {
            @unlink($file);
            return;
        }
        file_put_contents($file, "state=$state\nsince=1790000000\nspare=wlan0\nuplink=".($state === 'standby' ? 'wlan1' : '')."\nup_since=\nlost_since=\n");
    }

    /** a keyfile as NetworkManager writes it; a null value leaves the key out; $where: keyfiles (managed) or elsewhere */
    protected function profile(string $uuid, string $id, array $values = [], string $where = 'keyfiles'): void
    {
        $data = array_replace_recursive([
            'connection' => ['id' => $id, 'uuid' => $uuid, 'type' => 'wifi'],
            'wifi' => ['mode' => 'infrastructure', 'ssid' => $id],
            'wifi-security' => ['key-mgmt' => 'wpa-psk', 'psk' => self::SECRET],
            'ipv4' => ['method' => 'auto'],
        ], $values);
        $text = '';
        foreach ($data as $section => $keys) {
            $text .= "[$section]\n";
            foreach ($keys as $key => $value)
                if ($value !== null)
                    $text .= "$key=$value\n";
            $text .= "\n";
        }
        file_put_contents($this->dir.'/'.$where.'/'.$id.'.nmconnection', $text);
    }

    /** wlanpi-da2 before 2.5.3: a profile per card, bound by MAC, both on '78'; the card is connected */
    protected function pair(): void
    {
        $this->profile(self::M2, 'uplink-m2', ['connection' => ['autoconnect-priority' => '20'],
            'wifi' => ['ssid' => '78', 'mac-address' => '4C:D5:77:F7:AD:FB'], 'ipv4' => ['route-metric' => '100']]);
        $this->profile(self::ONBOARD, 'uplink-onboard', ['connection' => ['autoconnect-priority' => '10'],
            'wifi' => ['ssid' => '78', 'mac-address' => '2C:CF:67:30:7D:A3'], 'ipv4' => ['route-metric' => '600']]);
        file_put_contents($this->dir.'/active', self::M2.":wlan1\n");
    }

    /** the environment of the helper in a test: the stand-ins first in PATH, the made-up places, short lock waits */
    protected function env(): string
    {
        return 'FAKE='.escapeshellarg($this->dir).' PATH='.escapeshellarg($this->dir.'/bin').':"$PATH"'
            .' WIFI_NETWORKS_KEYFILES='.escapeshellarg($this->dir.'/keyfiles')
            .' WIFI_NETWORKS_RUNTIME='.escapeshellarg($this->dir.'/elsewhere')
            .' WIFI_NETWORKS_RUN='.escapeshellarg($this->dir.'/run')
            .' WIFI_NETWORKS_SYS='.escapeshellarg($this->dir.'/sys')
            .' WIFI_NETWORKS_SPARE='.escapeshellarg($this->dir.'/bin/uplink-spare')
            .' WIFI_NETWORKS_WAIT=1';
    }

    /** run tools/wifi-networks <command> with these lines on stdin, the way root does; its exit code */
    protected function runHelper(string $command, array $lines = []): int
    {
        $env = $this->env();
        $process = proc_open($env.' sh '.escapeshellarg(base_path('tools/wifi-networks')).' '.$command.' 2>&1',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $lines ? implode("\n", $lines)."\n" : '');
        fclose($pipes[0]);
        $this->output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process);
    }

    /** a profile's keyfile by UUID, [section => [key => value]]; null when there is none */
    protected function keyfile(string $uuid): ?array
    {
        foreach (array_merge(glob($this->dir.'/keyfiles/*.nmconnection'), glob($this->dir.'/elsewhere/*.nmconnection')) as $file) {
            $data = [];
            $section = '';
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^\[(.+)\]$/', $line, $m))
                    $section = $m[1];
                elseif (($at = strpos($line, '=')) !== false)
                    $data[$section][substr($line, 0, $at)] = substr($line, $at + 1);
            }
            if (($data['connection']['uuid'] ?? null) === $uuid)
                return $data;
        }

        return null;
    }

    /** the recorded calls that change something: nmcli add, modify, delete, up, disconnect, and uplink-spare */
    protected function changes(): array
    {
        $calls = is_file($this->dir.'/calls') ? file($this->dir.'/calls', FILE_IGNORE_NEW_LINES) : [];

        return array_values(array_filter($calls, function ($call) {
            return !preg_match('/^nmcli (-s )?-t |^nmcli -g |^nmcli connection load /', $call);
        }));
    }

    protected function calls(): string
    {
        return is_file($this->dir.'/calls') ? file_get_contents($this->dir.'/calls') : '';
    }

    protected function journal(): string
    {
        return is_file($this->dir.'/journal') ? file_get_contents($this->dir.'/journal') : '';
    }

    /** the helper's key=value output */
    protected function answer(): array
    {
        $values = [];
        foreach (explode("\n", trim($this->output)) as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) === 2)
                $values[$pair[0]] = $pair[1];
        }

        return $values;
    }

    /** forget the recorded calls and journal lines between the steps of one test */
    protected function forget(): void
    {
        @unlink($this->dir.'/calls');
        @unlink($this->dir.'/journal');
    }

    public static function securities(): array
    {
        return [
            'WPA2-Personal' => ['wpa2', 'wpa-psk', '1', 'WPA2'],
            'WPA2/WPA3 mixed' => ['mixed', 'wpa-psk', '2', 'WPA2/WPA3'],
            'WPA3-Personal' => ['wpa3', 'sae', '3', 'WPA3'],
        ];
    }

    #[DataProvider('securities')]
    public function test_a_new_network_is_one_profile_for_both_cards($security, $keyMgmt, $pmf, $label)
    {
        $this->assertSame(0, $this->runHelper('save', ['ssid=office', "security=$security", 'hidden=no', 'password=office-pass-1', 'by=anna']), $this->output);

        $k = $this->keyfile($this->answer()['uuid']);
        $this->assertSame('office', $k['connection']['id']);
        $this->assertSame('office', $k['wifi']['ssid']);
        $this->assertSame('infrastructure', $k['wifi']['mode']);
        $this->assertSame('false', $k['wifi']['hidden']);
        $this->assertSame($keyMgmt, $k['wifi-security']['key-mgmt']);
        $this->assertSame($pmf, $k['wifi-security']['pmf']);
        $this->assertSame('3', $k['connection']['multi-connect'], 'one profile may run on both cards');
        $this->assertSame('100', $k['connection']['autoconnect-priority']);
        $this->assertArrayNotHasKey('mac-address', $k['wifi'], 'not bound to a card');
        $this->assertArrayNotHasKey('interface-name', $k['connection']);
        $this->assertArrayNotHasKey('route-metric', $k['ipv4'], 'the device gives the metric (conf.d)');
        $this->assertSame('office-pass-1', $k['wifi-security']['psk']);
        $this->assertSame("-t cmprovision-wifi anna added 'office' ($label)\n", $this->journal());
    }

    public function test_a_hidden_network_is_looked_for_by_name()
    {
        $this->runHelper('save', ['ssid=lab', 'security=wpa3', 'hidden=yes', 'password=lab-pass-12', 'by=anna']);

        $this->assertSame('true', $this->keyfile($this->answer()['uuid'])['wifi']['hidden']);
        $this->assertStringContainsString("anna added 'lab' (WPA3, hidden)", $this->journal());
    }

    public function test_the_password_reaches_no_argument_no_journal_and_no_output()
    {
        $password = 'Zq7#pass word:x';

        $this->assertSame(0, $this->runHelper('save', ['ssid=office', 'security=mixed', 'hidden=no', "password=$password", 'by=anna']));

        $this->assertStringNotContainsString($password, $this->calls(), 'nmcli never gets it as an argument');
        $this->assertStringNotContainsString($password, $this->journal());
        $this->assertStringNotContainsString($password, $this->output);
        $this->assertStringContainsString('nmcli connection load ', $this->calls(), 'NetworkManager reads the keyfile again');
    }

    public function test_spaces_at_the_ends_and_backslashes_survive_the_keyfile()
    {
        $this->assertSame(0, $this->runHelper('save', ['ssid=office', 'security=wpa2', 'hidden=no', 'password= a\\b c  ', 'by=anna']), $this->output);

        // GKeyFile: a backslash doubled, a space at either end as \s; NetworkManager read back ' a\b c  '
        $this->assertSame('\\sa\\\\b c\\s\\s', $this->keyfile($this->answer()['uuid'])['wifi-security']['psk']);
    }

    public function test_a_new_network_goes_to_the_end_of_the_order()
    {
        $this->pair();   // priorities 20 and 10

        $this->runHelper('save', ['ssid=phone', 'security=wpa3', 'hidden=no', 'password=phone-pass', 'by=anna']);

        $this->assertSame('9', $this->keyfile($this->answer()['uuid'])['connection']['autoconnect-priority']);
    }

    public static function refusals(): array
    {
        return [
            'an unknown key' => [['ssid=office', 'security=wpa2', 'password=office-pass', 'colour=blue'], 'unknown input: colour'],
            'a key twice' => [['ssid=office', 'ssid=other', 'security=wpa2', 'password=office-pass'], 'ssid given twice'],
            'a line without =' => [['ssid=office', 'security wpa2'], "has no '='"],
            'no SSID' => [['security=wpa2', 'password=office-pass'], 'SSID'],
            'an SSID of 33 bytes' => [['ssid='.str_repeat('a', 33), 'security=wpa2', 'password=office-pass'], 'SSID'],
            'an SSID with a tab' => [["ssid=off\tice", 'security=wpa2', 'password=office-pass'], 'SSID'],
            'an SSID that is not UTF-8' => [["ssid=caf\xE9", 'security=wpa2', 'password=office-pass'], 'SSID'],
            'unknown security' => [['ssid=office', 'security=wep', 'password=office-pass'], 'security must be'],
            'hidden neither yes nor no' => [['ssid=office', 'security=wpa2', 'hidden=maybe', 'password=office-pass'], 'hidden must be'],
            'a password of 7' => [['ssid=office', 'security=wpa2', 'password=1234567'], 'the password must be'],
            'a password of 64 that is not hex' => [['ssid=office', 'security=wpa2', 'password='.str_repeat('z', 64)], 'the password must be'],
            'a password with letters outside ASCII' => [['ssid=office', 'security=wpa2', 'password=пароль-длинный'], 'the password must be'],
            'a 64-digit key for WPA3' => [['ssid=office', 'security=wpa3', 'password='.str_repeat('ab', 32)], 'the password must be'],
            'a new network without a password' => [['ssid=office', 'security=wpa2'], 'enter the password'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_the_input_is_checked_before_anything_changes($lines, $reason)
    {
        $this->assertSame(1, $this->runHelper('save', array_merge($lines, ['by=anna'])));

        $this->assertStringStartsWith('error=', $this->output);
        $this->assertStringContainsString($reason, $this->output);
        $this->assertSame([], $this->changes(), 'no profile changed');
        $this->assertStringContainsString('anna: save refused: ', $this->journal(), 'the refusal names the user');
    }

    public function test_a_listed_ssid_is_edited_not_added_again()
    {
        $this->pair();

        $this->assertSame(1, $this->runHelper('save', ['ssid=78', 'security=wpa2', 'password=another-pass', 'by=anna']));

        $this->assertStringContainsString("'78' is already in the list: edit it", $this->output);
        $this->assertSame([], $this->changes());
    }

    public function test_an_ssid_with_a_colon_spaces_and_cyrillic_is_kept_as_it_is()
    {
        $ssid = 'Офис: 2 этаж';

        $this->assertSame(0, $this->runHelper('save', ["ssid=$ssid", 'security=wpa2', 'hidden=no', 'password=office-pass', 'by=anna']), $this->output);
        $this->assertSame($ssid, $this->keyfile($this->answer()['uuid'])['wifi']['ssid']);

        // found again through nmcli's list, where the colon of the file name comes escaped
        $this->assertSame(1, $this->runHelper('save', ["ssid=$ssid", 'security=wpa2', 'password=office-pass', 'by=anna']));
        $this->assertStringContainsString('already in the list', $this->output);
    }

    public function test_nothing_happens_without_networkmanager()
    {
        touch($this->dir.'/nm-gone');

        $this->assertSame(1, $this->runHelper('save', ['ssid=office', 'security=wpa2', 'password=office-pass', 'by=anna']));

        $this->assertStringContainsString('NetworkManager does not answer', $this->output);
    }

    public function test_a_password_networkmanager_reads_otherwise_leaves_nothing_behind()
    {
        touch($this->dir.'/mangle-psk');

        $this->assertSame(1, $this->runHelper('save', ['ssid=office', 'security=wpa2', 'hidden=no', 'password=office-pass', 'by=anna']));

        $this->assertStringContainsString('the password could not be stored: nothing was added', $this->output);
        $this->assertSame([], glob($this->dir.'/keyfiles/*'), 'the new profile is gone again');
    }

    public function test_a_wrong_call_is_refused()
    {
        $this->assertSame(2, $this->runHelper('bogus'));
        $this->assertStringContainsString('usage: wifi-networks', $this->output);
        $this->assertSame(2, $this->runHelper('save extra'), 'sudo gives the command alone: anything more is a wrong call');
    }

    /** a new network through the helper, its UUID; the calls and journal lines of adding it are forgotten */
    protected function network(string $ssid = 'office'): string
    {
        $this->runHelper('save', ["ssid=$ssid", 'security=mixed', 'hidden=no', 'password=office-pass', 'by=anna']);
        $uuid = $this->answer()['uuid'];
        $this->forget();

        return $uuid;
    }

    public function test_saving_the_78_pair_takes_it_over_without_touching_the_card()
    {
        $this->pair();

        $this->assertSame(0, $this->runHelper('save', ['uuid='.self::ONBOARD, 'ssid=78', 'security=mixed', 'hidden=no', 'by=anna']), $this->output);

        $this->assertSame(['uuid' => self::M2], $this->answer(), 'the profile the card is using stays');
        $k = $this->keyfile(self::M2);
        $this->assertSame('78', $k['connection']['id']);
        $this->assertArrayNotHasKey('mac-address', $k['wifi']);
        $this->assertSame('3', $k['connection']['multi-connect']);
        $this->assertArrayNotHasKey('route-metric', $k['ipv4']);
        $this->assertSame(['wpa-psk', '2', self::SECRET], [$k['wifi-security']['key-mgmt'], $k['wifi-security']['pmf'], $k['wifi-security']['psk']]);
        $this->assertSame('20', $k['connection']['autoconnect-priority'], 'only order changes the order');
        $this->assertNull($this->keyfile(self::ONBOARD), 'the idle duplicate is gone');
        foreach ($this->changes() as $call)
            $this->assertDoesNotMatchRegularExpression('/connection (up|down)|device disconnect/', $call, 'the card stays connected');
        $this->assertSame("-t cmprovision-wifi anna took over '78' (2 profiles into 1)\n-t cmprovision-wifi anna saved '78' (WPA2/WPA3)\n", $this->journal());
    }

    public function test_a_new_password_replaces_the_stored_one()
    {
        $this->pair();

        $this->runHelper('save', ['uuid='.self::M2, 'ssid=78', 'security=wpa2', 'hidden=no', 'password=new-secret-78', 'by=anna']);

        $this->assertSame('new-secret-78', $this->keyfile(self::M2)['wifi-security']['psk']);
        $this->assertStringContainsString("anna saved '78' (WPA2, new password)", $this->journal());
    }

    public function test_an_empty_password_keeps_the_stored_one_but_there_must_be_one()
    {
        $this->profile('0b1c2d3e-0000-4000-8000-000000000001', 'bare', ['wifi-security' => ['psk' => null]]);

        $this->assertSame(1, $this->runHelper('save', ['uuid=0b1c2d3e-0000-4000-8000-000000000001', 'ssid=bare', 'security=wpa2', 'by=anna']));

        $this->assertStringContainsString("enter the password: 'bare' has none stored", $this->output);
        $this->assertSame([], $this->changes());
    }

    public function test_wpa3_does_not_take_a_stored_64_digit_key()
    {
        $this->profile('0b1c2d3e-0000-4000-8000-000000000002', 'keyed', ['wifi-security' => ['psk' => str_repeat('ab', 32)]]);

        $this->assertSame(1, $this->runHelper('save', ['uuid=0b1c2d3e-0000-4000-8000-000000000002', 'ssid=keyed', 'security=wpa3', 'by=anna']));

        $this->assertStringContainsString('enter a password: WPA3 does not take a 64-digit key', $this->output);
        $this->assertSame([], $this->changes());
    }

    public function test_renaming_onto_a_listed_ssid_is_refused()
    {
        $this->pair();
        $phone = $this->network('phone');

        $this->assertSame(1, $this->runHelper('save', ["uuid=$phone", 'ssid=78', 'security=wpa2', 'by=anna']));

        $this->assertStringContainsString("'78' is already in the list: edit it", $this->output);
        $this->assertSame([], $this->changes());
    }

    public function test_the_network_in_use_cannot_be_deleted()
    {
        $this->pair();

        $this->assertSame(1, $this->runHelper('delete', ['uuid='.self::ONBOARD, 'by=anna']));

        $this->assertStringContainsString("'78' is in use: connect another network first", $this->output);
        $this->assertNotNull($this->keyfile(self::ONBOARD));
        $this->assertStringContainsString("anna: delete refused: '78' is in use", $this->journal());
    }

    public function test_deleting_a_network_removes_all_its_profiles()
    {
        $this->pair();
        file_put_contents($this->dir.'/active', '');
        file_put_contents($this->dir.'/routes', "default via 10.0.0.1 dev eth1 metric 50\n");

        $this->assertSame(0, $this->runHelper('delete', ['uuid='.self::M2, 'by=anna']), $this->output);

        $this->assertNull($this->keyfile(self::M2));
        $this->assertNull($this->keyfile(self::ONBOARD));
        $this->assertSame("-t cmprovision-wifi anna deleted '78'\n", $this->journal());
    }

    public function test_order_gives_every_profile_of_a_network_its_priority()
    {
        $this->pair();
        $phone = $this->network('phone');

        $this->assertSame(0, $this->runHelper('order', ["uuid=$phone", 'uuid='.self::M2, 'by=anna']), $this->output);

        $this->assertSame('100', $this->keyfile($phone)['connection']['autoconnect-priority']);
        $this->assertSame('99', $this->keyfile(self::M2)['connection']['autoconnect-priority']);
        $this->assertSame('99', $this->keyfile(self::ONBOARD)['connection']['autoconnect-priority'], 'the pair moves as one network');
        $this->assertSame("-t cmprovision-wifi anna ordered 'phone', '78'\n", $this->journal());
    }

    public static function staleOrders(): array
    {
        return [
            'a network missing' => [['uuid=PHONE'], 'the networks changed: reload the page'],
            'a network twice' => [['uuid=PHONE', 'uuid='.self::M2, 'uuid='.self::ONBOARD], 'a network is in the order twice'],
            'an unknown profile' => [['uuid=PHONE', 'uuid=0b1c2d3e-0000-4000-8000-00000000dead'], 'the networks changed: reload the page'],
        ];
    }

    #[DataProvider('staleOrders')]
    public function test_an_order_from_a_stale_page_is_refused($lines, $reason)
    {
        $this->pair();
        $phone = $this->network('phone');
        $lines = array_map(function ($line) use ($phone) { return str_replace('PHONE', $phone, $line); }, $lines);

        $this->assertSame(1, $this->runHelper('order', array_merge($lines, ['by=anna'])));

        $this->assertStringContainsString($reason, $this->output);
        $this->assertSame([], $this->changes());
    }

    public static function foreign(): array
    {
        return [
            "netplan's profile in /run" => ['elsewhere', []],
            'WPA2-Enterprise' => ['keyfiles', ['wifi-security' => ['key-mgmt' => 'wpa-eap', 'psk' => null]]],
            'an open network' => ['keyfiles', ['wifi-security' => ['key-mgmt' => null, 'psk' => null]]],
            'Ethernet' => ['keyfiles', ['connection' => ['type' => 'ethernet']]],
        ];
    }

    #[DataProvider('foreign')]
    public function test_profiles_not_managed_here_are_left_alone($where, $values)
    {
        $this->profile('0b1c2d3e-0000-4000-8000-000000000003', 'foreign', $values, $where);

        $this->assertSame(1, $this->runHelper('save', ['uuid=0b1c2d3e-0000-4000-8000-000000000003', 'ssid=foreign', 'security=wpa2', 'password=foreign-pass', 'by=anna']));
        $this->assertStringContainsString('this is not a network managed here', $this->output);
        $this->assertSame(1, $this->runHelper('delete', ['uuid=0b1c2d3e-0000-4000-8000-000000000003', 'by=anna']));
        $this->assertStringContainsString('this is not a network managed here', $this->output);
        $this->assertSame([], $this->changes());
        $this->assertNotNull($this->keyfile('0b1c2d3e-0000-4000-8000-000000000003'));
    }

    public function test_a_profile_active_on_both_radios_is_one_network()
    {
        $this->pair();
        $this->runHelper('save', ['uuid='.self::M2, 'ssid=78', 'security=wpa2', 'hidden=no', 'by=anna']);
        file_put_contents($this->dir.'/active', self::M2.":wlan1\n".self::M2.":wlan0\n");   // the minute both radios are up
        $this->forget();

        $this->assertSame(0, $this->runHelper('save', ['uuid='.self::M2, 'ssid=78', 'security=mixed', 'hidden=no', 'by=anna']), $this->output);

        $this->assertStringNotContainsString('took over', $this->journal(), 'nmcli lists it twice, it is one profile');
        $this->assertSame(1, $this->runHelper('delete', ['uuid='.self::M2, 'by=anna']));
        $this->assertStringContainsString('is in use', $this->output);
    }

    public function test_check_tries_the_network_on_the_idle_built_in_wifi()
    {
        $uuid = $this->network();

        $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']), $this->output);

        $this->assertSame(['result' => 'connected', 'address' => '192.168.68.236/24', 'detail' => ''], $this->answer());
        $changes = $this->changes();
        $this->assertCount(4, $changes, implode("\n", $changes));
        $this->assertSame(1, preg_match('/^nmcli -w 30 connection up (\S+) ifname wlan0$/', $changes[0], $m), $changes[0]);
        $this->assertNotSame($uuid, $m[1], 'a copy of the profile comes up, not the profile itself');
        $this->assertSame(['nmcli -w 20 device disconnect wlan0', 'nmcli connection delete '.$m[1], 'uplink-spare check wifi-check'],
            array_slice($changes, 1));
        $copy = $this->upProfile();
        $this->assertSame(['cmprovision-check', 'wlan0', 'false'],
            [$copy['connection']['id'], $copy['connection']['interface-name'], $copy['connection']['autoconnect']]);
        $this->assertSame(['office', 'office-pass'], [$copy['wifi']['ssid'], $copy['wifi-security']['psk']]);
        $this->assertSame([], $this->copies(), 'the copy is gone');
        $this->assertSame("-t cmprovision-wifi anna checked 'office' on wlan0: connected, 192.168.68.236\n", $this->journal());
    }

    public static function outcomes(): array
    {
        return [
            'a wrong password' => ['wrong-password', 'wrong-password', 'wrong password'],
            'out of reach' => ['not-found', 'not-found', 'network not found'],
            'no DHCP answer' => ['no-address', 'no-address', 'no address'],
            'connected without an address' => ['connected', 'no-address', 'no address'],
            'no answer in time' => ['timeout', 'timeout', 'no answer in 30 s'],
            'anything else' => ['failed', 'failed', 'failed'],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_check_says_what_networkmanager_answered($up, $result, $text)
    {
        $uuid = $this->network();
        file_put_contents($this->dir.'/up', $up);

        $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']));

        $this->assertSame($result, $this->answer()['result']);
        $this->assertStringContainsString("checked 'office' on wlan0: $text", $this->journal());
        $this->assertContains('nmcli -w 20 device disconnect wlan0', $this->changes(), 'the built-in Wi-Fi goes back to the spare');
        $this->assertContains('uplink-spare check wifi-check', $this->changes());
        $this->assertSame([], $this->copies(), 'the copy is gone whatever the answer');
    }

    public static function busySpare(): array
    {
        return [
            'the built-in Wi-Fi carries the traffic' => ['active', 'the built-in Wi-Fi carries the traffic'],
            'the cold spare is counting' => ['waiting', 'the cold spare is switching'],
            'SPARE=no' => ['off', 'the cold spare is off (SPARE=no)'],
            'no word from the cold spare yet' => [null, 'the cold spare has not reported yet'],
        ];
    }

    #[DataProvider('busySpare')]
    public function test_check_waits_for_an_idle_built_in_wifi($state, $reason)
    {
        $uuid = $this->network();
        $this->spare($state);

        $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']));

        $this->assertSame(['result' => 'busy', 'address' => '', 'detail' => $reason], $this->answer());
        $this->assertSame([], $this->changes());
    }

    public function test_the_78_pair_is_checked_through_a_copy_of_the_profile_the_card_uses()
    {
        $this->pair();
        $before = [file_get_contents($this->dir.'/keyfiles/uplink-m2.nmconnection'), file_get_contents($this->dir.'/keyfiles/uplink-onboard.nmconnection')];

        $this->runHelper('check', ['uuid='.self::ONBOARD, 'by=anna']);

        $this->assertSame('connected', $this->answer()['result']);
        $copy = $this->upProfile();
        $this->assertSame(['78', 'wlan0', self::SECRET], [$copy['wifi']['ssid'], $copy['connection']['interface-name'], $copy['wifi-security']['psk']]);
        $this->assertArrayNotHasKey('mac-address', $copy['wifi'], "the card's MAC binding stays out of the copy");
        // the copy takes the built-in Wi-Fi's metric (conf.d), not the card's: two equal default routes would share the traffic
        $this->assertArrayNotHasKey('route-metric', $copy['ipv4']);
        $this->assertSame($before, [file_get_contents($this->dir.'/keyfiles/uplink-m2.nmconnection'), file_get_contents($this->dir.'/keyfiles/uplink-onboard.nmconnection')],
            'the profiles themselves are untouched');
        $this->assertSame(self::M2.":wlan1\n", file_get_contents($this->dir.'/active'), 'the card stays connected');
    }

    public function test_a_network_bound_to_the_card_alone_is_checked_too()
    {
        $this->pair();
        unlink($this->dir.'/keyfiles/uplink-onboard.nmconnection');

        $this->assertSame(0, $this->runHelper('check', ['uuid='.self::M2, 'by=anna']));

        $this->assertSame('connected', $this->answer()['result']);
        $this->assertSame('4C:D5:77:F7:AD:FB', $this->keyfile(self::M2)['wifi']['mac-address'], 'the profile keeps its binding');
    }

    public function test_a_profile_not_managed_here_is_not_checked()
    {
        $this->profile('0b1c2d3e-0000-4000-8000-000000000003', 'foreign', [], 'elsewhere');

        $this->assertSame(1, $this->runHelper('check', ['uuid=0b1c2d3e-0000-4000-8000-000000000003', 'by=anna']));

        $this->assertStringContainsString('this is not a network managed here', $this->output);
        $this->assertSame([], $this->changes());
    }

    public function test_scan_lists_the_networks_that_have_a_name()
    {
        file_put_contents($this->dir.'/scan', "78:100:WPA2:2422 MHz\n:40:WPA2:2412 MHz\nService PSK:80:WPA2 WPA3:2437 MHz\nlab\\:2:55:WPA3:5180 MHz\n");

        $this->assertSame(0, $this->runHelper('scan'));

        $this->assertSame("78:100:WPA2:2422 MHz\nService PSK:80:WPA2 WPA3:2437 MHz\nlab\\:2:55:WPA3:5180 MHz\n", $this->output);
        $this->assertStringContainsString('nmcli -t -f SSID,SIGNAL,SECURITY,FREQ device wifi list --rescan yes', $this->calls());
    }

    public function test_a_user_name_that_looks_like_an_option_is_logged_as_text()
    {
        // root runs logger: a message starting with a dash would be read as an option unless -- ends them
        $this->assertSame(0, $this->runHelper('save', ['ssid=office', 'security=wpa2', 'hidden=no', 'password=office-pass', 'by=-f/etc/shadow']));

        $this->assertSame("-t cmprovision-wifi -f/etc/shadow added 'office' (WPA2)\n", $this->journal());
    }

    /** the settings the fake NetworkManager saw in the profile it brought up last */
    protected function upProfile(): ?array
    {
        return is_file($this->dir.'/up-profile') ? json_decode(file_get_contents($this->dir.'/up-profile'), true) : null;
    }

    /** the copies of checks in NetworkManager's memory */
    protected function copies(): array
    {
        return glob($this->dir.'/elsewhere/cmprovision-check*') ?: [];
    }

    public function test_a_check_never_touches_the_connection_of_the_card()
    {
        // a profile made by hand: no card binding, and active only once at a time (no multi-connect): brought up on the
        // built-in Wi-Fi, NetworkManager would take it off the card
        $this->profile('0b1c2d3e-0000-4000-8000-000000000004', 'by-hand');
        file_put_contents($this->dir.'/active', "0b1c2d3e-0000-4000-8000-000000000004:wlan1\n");

        $this->runHelper('check', ['uuid=0b1c2d3e-0000-4000-8000-000000000004', 'by=anna']);

        $this->assertSame('connected', $this->answer()['result']);
        foreach ($this->changes() as $call)
            $this->assertStringNotContainsString('connection up 0b1c2d3e-0000-4000-8000-000000000004', $call);
        $this->assertSame("0b1c2d3e-0000-4000-8000-000000000004:wlan1\n", file_get_contents($this->dir.'/active'));
    }

    public function test_a_wrong_password_stays_with_the_copy()
    {
        // NetworkManager blocks a profile that failed for want of secrets from connecting on its own: here, the copy
        $uuid = $this->network();
        $before = $this->keyfile($uuid);
        file_put_contents($this->dir.'/up', 'wrong-password');

        $this->runHelper('check', ["uuid=$uuid", 'by=anna']);

        $this->assertSame('wrong-password', $this->answer()['result']);
        $this->assertNotSame($uuid, $this->upProfile()['connection']['uuid']);
        $this->assertSame($before, $this->keyfile($uuid));
        $this->assertSame([], $this->copies());
    }

    public function test_a_copy_a_killed_check_left_is_removed_first()
    {
        $uuid = $this->network();
        $this->profile('0b1c2d3e-0000-4000-8000-00000000c0de', 'cmprovision-check', ['connection' => ['interface-name' => 'wlan0']], 'elsewhere');

        $this->runHelper('check', ["uuid=$uuid", 'by=anna']);

        $this->assertSame('nmcli connection delete 0b1c2d3e-0000-4000-8000-00000000c0de', $this->changes()[0]);
        $this->assertSame([], $this->copies());
    }

    public function test_a_check_cut_short_removes_its_copy()
    {
        // the web app gives a check 90 s, then timeout stops sudo and the helper: the copy must not stay behind
        $uuid = $this->network();
        file_put_contents($this->dir.'/up-delay', '3');
        $process = proc_open('exec env '.$this->env().' sh '.escapeshellarg(base_path('tools/wifi-networks')).' check',
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], "uuid=$uuid\nby=anna\n");
        fclose($pipes[0]);
        for ($i = 0; $i < 200 && !$this->copies(); $i++)
            usleep(50000);
        $this->assertNotSame([], $this->copies(), 'the check got as far as its copy');

        proc_terminate($process, 15);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame([], $this->copies(), 'the copy is deleted');
        $this->assertSame([], array_filter(glob($this->dir.'/run/wifi-networks.*') ?: [], 'is_dir'), 'and so is the scratch directory (the lock file stays)');
    }

    public function test_without_a_built_in_wifi_there_is_no_check()
    {
        $uuid = $this->network();
        $this->spare(null);
        unlink($this->dir.'/sys/wlan0/device');

        $this->runHelper('check', ["uuid=$uuid", 'by=anna']);

        $this->assertSame(['result' => 'busy', 'address' => '', 'detail' => 'no built-in Wi-Fi'], $this->answer());
    }

    public function test_scan_waits_for_a_change_in_progress()
    {
        // a rescan while a check brings the built-in Wi-Fi up could spoil the check
        $lock = fopen($this->dir.'/run/wifi-networks.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame(1, $this->runHelper('scan'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertStringContainsString('error=another Wi-Fi change is running: try again', $this->output);
    }

    public function test_check_waits_for_the_cold_spare_lock()
    {
        $uuid = $this->network();
        $lock = fopen($this->dir.'/run/uplink-spare.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(['result' => 'busy', 'address' => '', 'detail' => 'the cold spare is switching right now'], $this->answer());
        $this->assertSame([], $this->changes());
    }

    public function test_a_key_of_two_words_is_refused_as_unknown()
    {
        $this->assertSame(1, $this->runHelper('save', ['ssid security=office', 'by=anna']));

        $this->assertStringContainsString('error=unknown input: ssid security', $this->output);
    }

    public function test_an_order_entry_is_never_expanded_as_a_file_name()
    {
        $this->assertSame(1, $this->runHelper('order', ['uuid=*', 'by=anna']));

        $this->assertStringContainsString('error=not a network ID: *', $this->output);
    }

    public function test_a_new_network_connects_on_its_own_only_once_its_password_is_in()
    {
        // tried without a password on an idle radio, a profile fails for want of secrets and NetworkManager blocks it
        $this->runHelper('save', ['ssid=office', 'security=mixed', 'hidden=no', 'password=office-pass', 'by=anna']);
        $uuid = $this->answer()['uuid'];

        $calls = $this->changes();
        $this->assertStringContainsString(' connection.autoconnect no ', $calls[0]);
        $this->assertSame("nmcli connection modify $uuid connection.autoconnect yes", end($calls));
        $this->assertSame('true', $this->keyfile($uuid)['connection']['autoconnect']);
    }

    public function test_an_edit_whose_new_password_does_not_survive_keeps_the_old_one()
    {
        $this->pair();
        touch($this->dir.'/mangle-psk');

        $this->assertSame(1, $this->runHelper('save', ['uuid='.self::M2, 'ssid=78', 'security=mixed', 'hidden=no', 'password=new-secret-78', 'by=anna']));

        $this->assertStringContainsString("'78' is saved, its new password is not: try again", $this->output);
        $this->assertSame(self::SECRET, $this->keyfile(self::M2)['wifi-security']['psk']);
    }
}
