<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The built-in Wi-Fi as a cold spare (tools/uplink-spare, docs/superpowers/specs/2026-10-15-uplink-cold-spare-design.md),
 * run the way root runs it: under sh, with stand-ins for nmcli, ip, systemd-run, systemctl and logger that record what
 * they were asked, and a made-up /sys/class/net, state directory, switch and clock.
 */
class UplinkSpareTest extends TestCase
{
    const T = 1790000000;
    const CARD = "default via 192.168.68.1 dev wlan1 proto dhcp src 192.168.68.237 metric 100\n";
    const ONBOARD = "default via 192.168.68.1 dev wlan0 proto dhcp src 192.168.68.236 metric 600\n";

    protected $dir;
    protected $output = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh') || trim((string) shell_exec('command -v flock 2>/dev/null')) === '')
            $this->markTestSkipped('needs a POSIX shell and flock');
        $this->dir = sys_get_temp_dir().'/cmp-uplink-'.bin2hex(random_bytes(4));
        foreach (['bin', 'sys', 'devices', 'nm', 'bus/sdio', 'bus/pci', 'run'] as $sub)
            mkdir($this->dir.'/'.$sub, 0755, true);
        $this->wifi('wlan0', 'sdio');
        $this->wifi('wlan1', 'pci');
        // NetworkManager: a state per device in nm/<device>; nm-gone: it does not answer, nm-fails: it refuses to switch
        $this->stub('nmcli', implode("\n", [
            'echo "nmcli $*" >> "$FAKE/calls"',
            '[ -e "$FAKE/nm-gone" ] && exit 8',
            'case "$1 $2 $3 $4 $5" in',
            '    "-t -f DEVICE,STATE device status") for f in "$FAKE"/nm/*; do printf "%s:%s\n" "${f##*/}" "$(cat "$f")"; done ;;',
            '    "device connect "*) [ -e "$FAKE/nm-fails" ] && exit 4; echo connected > "$FAKE/nm/$3" ;;',
            '    "device disconnect "*) [ -e "$FAKE/nm-fails" ] && exit 4; echo disconnected > "$FAKE/nm/$3" ;;',
            'esac',
            'exit 0',
        ]));
        $this->stub('ip', 'cat "$FAKE/routes" 2>/dev/null; exit 0');
        $this->stub('systemd-run', 'echo "systemd-run $*" >> "$FAKE/calls"');
        $this->stub('systemctl', 'echo "systemctl $*" >> "$FAKE/calls"');
        $this->stub('logger', 'echo "$*" >> "$FAKE/journal"');
    }

    protected function tearDown(): void
    {
        if ($this->dir)
            File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** a /sys/class/net/<name> of a wireless device on <bus> */
    protected function wifi(string $name, string $bus): void
    {
        mkdir($this->dir.'/sys/'.$name.'/wireless', 0755, true);
        mkdir($this->dir.'/devices/'.$name, 0755, true);
        if (!@symlink($this->dir.'/bus/'.$bus, $this->dir.'/devices/'.$name.'/subsystem'))
            $this->markTestSkipped('symlinks are not available here');
        symlink($this->dir.'/devices/'.$name, $this->dir.'/sys/'.$name.'/device');
    }

    protected function stub(string $name, string $body): void
    {
        file_put_contents($this->dir.'/bin/'.$name, "#!/bin/sh\n".$body."\n");
        chmod($this->dir.'/bin/'.$name, 0755);
    }

    /** what NetworkManager says about each device, the default routes, and the switch (null: no switch file) */
    protected function world(array $devices, string $routes, ?string $switch = 'SPARE=yes'): void
    {
        array_map('unlink', glob($this->dir.'/nm/*'));
        foreach ($devices as $device => $state)
            file_put_contents($this->dir.'/nm/'.$device, $state."\n");
        file_put_contents($this->dir.'/routes', $routes);
        if ($switch === null)
            @unlink($this->dir.'/default');
        else
            file_put_contents($this->dir.'/default', "# the built-in Wi-Fi as a cold spare\n".$switch."\n");
    }

    /** run a script of tools/ under sh at Unix time $t, the way root does; its exit code (the output is in $output) */
    protected function runAt(int $t, array $args = ['check'], string $script = 'tools/uplink-spare'): int
    {
        $env = 'FAKE='.escapeshellarg($this->dir).' PATH='.escapeshellarg($this->dir.'/bin').':"$PATH"'
            .' UPLINK_SPARE_SYS='.escapeshellarg($this->dir.'/sys').' UPLINK_SPARE_RUN='.escapeshellarg($this->dir.'/run')
            .' UPLINK_SPARE_CONF='.escapeshellarg($this->dir.'/default').' UPLINK_SPARE_NOW='.$t
            .' UPLINK_SPARE='.escapeshellarg(base_path('tools/uplink-spare'));
        $out = [];
        exec($env.' sh '.escapeshellarg(base_path($script)).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1', $out, $code);
        $this->output = implode("\n", $out);
        return $code;
    }

    /** the recorded calls that change something: nmcli connect, disconnect and set, systemd-run, systemctl */
    protected function actions(): array
    {
        $calls = is_file($this->dir.'/calls') ? file($this->dir.'/calls', FILE_IGNORE_NEW_LINES) : [];
        return array_values(array_filter($calls, function ($call) {
            return !str_starts_with($call, 'nmcli -t ');
        }));
    }

    /** forget the recorded calls and journal lines between the steps of one test */
    protected function forget(): void
    {
        @unlink($this->dir.'/calls');
        @unlink($this->dir.'/journal');
    }

    protected function state(): array
    {
        $file = $this->dir.'/run/uplink-spare';
        $state = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            [$key, $value] = explode('=', $line, 2);
            $state[$key] = $value;
        }
        return $state;
    }

    protected function journal(): string
    {
        return is_file($this->dir.'/journal') ? file_get_contents($this->dir.'/journal') : '';
    }

    protected function device(string $name): string
    {
        return trim(file_get_contents($this->dir.'/nm/'.$name));
    }

    public function test_the_built_in_wifi_leaves_once_the_card_has_held_a_minute()
    {
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);

        $this->assertSame(0, $this->runAt(self::T));
        $this->assertSame('connected', $this->device('wlan0'), 'not before the card has held a minute');
        $this->assertSame(['state' => 'waiting', 'since' => (string) self::T, 'spare' => 'wlan0', 'uplink' => 'wlan1',
                           'up_since' => (string) self::T, 'lost_since' => ''], $this->state());
        $this->assertMatchesRegularExpression(
            '#^systemd-run --collect --quiet --unit=cmprovision-uplink-check-\S+ --on-active=60s /usr/lib/cmprovision/uplink-spare check later$#',
            $this->actions()[0]);
        $this->assertSame('', $this->journal(), 'a check that changes nothing is not logged');

        $this->forget();
        $this->runAt(self::T + 59);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertStringContainsString('--on-active=1s', $this->actions()[0]);
        $this->assertSame((string) self::T, $this->state()['since'], 'the state has not changed');

        $this->forget();
        $this->runAt(self::T + 60);
        $this->assertSame('disconnected', $this->device('wlan0'));
        $this->assertSame(['nmcli device disconnect wlan0'], $this->actions());
        $this->assertSame('standby', $this->state()['state']);
        $this->assertSame((string) (self::T + 60), $this->state()['since']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0 off: wlan1 has carried the default route for 60 s\n", $this->journal());
    }

    public function test_without_a_route_through_the_card_for_30_seconds_the_built_in_wifi_connects()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'unavailable'], '');

        $this->runAt(self::T);
        $this->assertSame('disconnected', $this->device('wlan0'), 'not before 30 s without a route');
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame((string) self::T, $this->state()['lost_since']);
        $this->assertStringContainsString('--on-active=30s', $this->actions()[0]);

        $this->forget();
        $this->runAt(self::T + 30);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame(['nmcli device connect wlan0'], $this->actions());
        $this->assertSame('active', $this->state()['state']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0 on: no default route but through it for 30 s\n", $this->journal());
    }

    public function test_a_card_that_blinks_starts_the_count_again()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'disconnected'], '');
        $this->runAt(self::T);                                    // lost
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'connected'], self::CARD);
        $this->runAt(self::T + 10);                               // back
        $this->assertSame('standby', $this->state()['state']);
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'disconnected'], '');
        $this->runAt(self::T + 20);                               // lost again

        $this->runAt(self::T + 30);
        $this->assertSame('disconnected', $this->device('wlan0'), '30 s from the first loss, but only 10 s from the second');
        $this->runAt(self::T + 50);
        $this->assertSame('connected', $this->device('wlan0'));
    }

    public static function switchedOff(): array
    {
        return [
            'as postinst writes it' => ['SPARE=no'],
            'quoted' => ['SPARE="no"'],
            'with a comment' => ["SPARE='no'   # keep the built-in Wi-Fi connected"],
            'in capitals' => ['SPARE=NO'],
        ];
    }

    #[DataProvider('switchedOff')]
    public function test_switched_off_the_built_in_wifi_connects_on_its_own($line)
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'connected'], self::CARD, $line);

        $this->runAt(self::T);
        $this->runAt(self::T + 120);

        $this->assertSame(['nmcli device set wlan0 autoconnect yes', 'nmcli device set wlan0 autoconnect yes'], $this->actions());
        $this->assertSame('off', $this->state()['state']);
        $this->assertSame("-t cmprovision-uplink cold spare off (SPARE=no): wlan0 connects on its own\n", $this->journal(), 'said once');
    }

    public static function switchedOn(): array
    {
        return [
            'as postinst writes it' => ['SPARE=yes'],
            'no switch file' => [null],
            'anything but no' => ['SPARE=maybe'],
        ];
    }

    #[DataProvider('switchedOn')]
    public function test_any_other_switch_keeps_the_cold_spare_on($line)
    {
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD, $line);

        $this->runAt(self::T);
        $this->runAt(self::T + 60);

        $this->assertSame('disconnected', $this->device('wlan0'));
    }

    public static function nothingToDo(): array
    {
        return [
            'no built-in Wi-Fi' => ['no built-in'],
            'NetworkManager does not manage it (WLAN Pi OS)' => ['unmanaged'],
            'NetworkManager does not answer' => ['gone'],
        ];
    }

    #[DataProvider('nothingToDo')]
    public function test_nothing_happens_where_there_is_nothing_to_switch($case)
    {
        $this->world(['wlan0' => $case === 'unmanaged' ? 'unmanaged' : 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);
        if ($case === 'no built-in')
            File::deleteDirectory($this->dir.'/sys/wlan0');
        if ($case === 'gone')
            touch($this->dir.'/nm-gone');

        $this->assertSame(0, $this->runAt(self::T + 600));
        $this->assertSame([], $this->actions());
        $this->assertSame([], $this->state());
        $this->assertSame('', $this->journal());
    }

    public function test_a_provisioner_without_a_card_keeps_its_built_in_wifi()
    {
        File::deleteDirectory($this->dir.'/sys/wlan1');
        $this->world(['wlan0' => 'connected'], self::ONBOARD);

        $this->runAt(self::T);
        $this->runAt(self::T + 600);

        $this->assertSame([], $this->actions());
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('active', $this->state()['state']);
    }

    public function test_release_gives_the_built_in_wifi_back()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'connected'], self::CARD);
        $this->runAt(self::T);
        $this->forget();

        $this->assertSame(0, $this->runAt(self::T + 1, ['release']));
        $this->assertSame(['nmcli device set wlan0 autoconnect yes'], $this->actions());
        $this->assertSame([], $this->state(), 'no state left behind');
        $this->assertSame("-t cmprovision-uplink cold spare released: wlan0 connects on its own\n", $this->journal());
    }

    public function test_the_state_file_is_readable_by_the_web_server()
    {
        rmdir($this->dir.'/run');
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);

        $umask = umask(0077);
        try {
            $this->runAt(self::T);
        } finally {
            umask($umask);
        }

        $this->assertSame('0755', substr(sprintf('%o', fileperms($this->dir.'/run')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->dir.'/run/uplink-spare')), -4));
    }

    public function test_the_bus_not_the_name_tells_the_built_in_wifi()
    {
        // a box where the card came up first: the card is wlan0, the built-in Wi-Fi wlan1
        File::deleteDirectory($this->dir.'/sys');
        File::deleteDirectory($this->dir.'/devices');
        mkdir($this->dir.'/sys');
        mkdir($this->dir.'/devices');
        $this->wifi('wlan0', 'pci');
        $this->wifi('wlan1', 'sdio');
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'],
            "default via 192.168.68.1 dev wlan0 metric 100\ndefault via 192.168.68.1 dev wlan1 metric 600\n");

        $this->runAt(self::T);
        $this->runAt(self::T + 60);

        $this->assertSame('disconnected', $this->device('wlan1'));
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('wlan0', $this->state()['uplink']);
    }

    public function test_any_other_default_route_counts_and_the_built_in_wifis_own_never_does()
    {
        // the card's route without a metric, the built-in Wi-Fi's own with a better one
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'],
            "default via 192.168.68.1 dev wlan0 proto dhcp metric 50\ndefault via 192.168.68.1 dev wlan1 proto dhcp\n");
        $this->runAt(self::T);
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame('wlan1', $this->state()['uplink']);

        // of two other uplinks, the one with the lower metric
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected', 'eth1' => 'connected'],
            "default via 192.168.68.1 dev wlan1 metric 100\ndefault via 10.0.0.1 dev eth1 metric 50\n");
        $this->runAt(self::T + 1);
        $this->assertSame('eth1', $this->state()['uplink']);
    }

    public function test_a_built_in_wifi_still_connecting_is_not_connected_twice()
    {
        $this->world(['wlan0' => 'connecting (getting IP configuration)', 'wlan1' => 'unavailable'], '');

        $this->runAt(self::T);
        $this->runAt(self::T + 60);

        $this->assertSame([], $this->actions());
        $this->assertSame('active', $this->state()['state']);
    }

    public function test_a_refused_switch_stays_waiting_and_says_so()
    {
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);
        $this->runAt(self::T);
        touch($this->dir.'/nm-fails');
        $this->forget();

        $this->runAt(self::T + 60);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0: nmcli device disconnect failed\n", $this->journal());

        unlink($this->dir.'/nm-fails');
        $this->runAt(self::T + 300);                              // the 5-minute check tries again
        $this->assertSame('disconnected', $this->device('wlan0'));
    }

    public function test_an_unknown_command_is_refused()
    {
        $this->assertSame(2, $this->runAt(self::T, ['bogus']));
        $this->assertStringContainsString('usage: uplink-spare check [event] [device] | release', $this->output);
    }
}
