<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The provisioner's Wi-Fi networks (tools/wifi-networks, docs/superpowers/specs/2026-10-15-wifi-networks-design.md),
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
        foreach (['bin', 'keyfiles', 'elsewhere', 'run', 'sys/wlan0'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        file_put_contents($this->dir.'/sys/wlan0/address', "2c:cf:67:30:7d:a3\n");
        $this->stub('nmcli', 'exec php '.escapeshellarg(base_path('tests/Support/fake-nmcli.php')).' "$@"');
        $this->stub('ip', 'cat "$FAKE/routes" 2>/dev/null; exit 0');
        $this->stub('logger', 'echo "$*" >> "$FAKE/journal"');
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

    /** run tools/wifi-networks <command> with these lines on stdin, the way root does; its exit code */
    protected function runHelper(string $command, array $lines = []): int
    {
        $env = 'FAKE='.escapeshellarg($this->dir).' PATH='.escapeshellarg($this->dir.'/bin').':"$PATH"'
            .' WIFI_NETWORKS_KEYFILES='.escapeshellarg($this->dir.'/keyfiles')
            .' WIFI_NETWORKS_RUN='.escapeshellarg($this->dir.'/run')
            .' WIFI_NETWORKS_SYS='.escapeshellarg($this->dir.'/sys')
            .' WIFI_NETWORKS_SPARE='.escapeshellarg($this->dir.'/bin/uplink-spare');
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
        $this->assertStringContainsString(': save refused: ', $this->journal());
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
}
