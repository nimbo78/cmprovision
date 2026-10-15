# Встроенный Wi-Fi — холодный запас (2.5.2) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** встроенный Wi-Fi провижинера отключён, пока маршрут по умолчанию несёт другое устройство; подключается, когда такого маршрута нет 30 с, и уходит, когда маршрут продержался минуту.

**Architecture:** root-помощник на POSIX sh `tools/uplink-spare` решает по текущему состоянию системы (`nmcli`, `ip -4 route`) и подключает или отключает только устройство встроенного Wi-Fi. Его зовут скрипт диспетчера NetworkManager на событиях, отложенные перепроверки (`systemd-run`) и страховочный таймер раз в 5 минут. Состояние лежит в `/run/cmprovision/uplink-spare`; веб-приложение его только читает (`NetworkStatus` → `UplinkSummary`, строка в Settings).

**Tech Stack:** POSIX sh (dash), NetworkManager 1.52 (`nmcli`, диспетчер), systemd (таймер, `systemd-run`), PHP 8.3+ (тесты на 8.4), Laravel 13 (раскладка Laravel 8), Livewire 3, Debian-пакет (debhelper 13), стенд WSL `cmprov-trixie`.

**Spec:** `docs/superpowers/specs/2026-10-15-uplink-cold-spare-design.md`

## Global Constraints

- `LOST_SECONDS=30`, `STABLE_SECONDS=60`; страховочный таймер — `OnBootSec=2min`, `OnUnitActiveSec=5min`.
- Встроенный Wi-Fi — первое по имени беспроводное устройство (`wireless` или `phy80211` в `/sys/class/net/<dev>`), у которого `device/subsystem` — `sdio`. Аплинк — маршрут IPv4 по умолчанию через любое другое устройство; из нескольких — с наименьшей метрикой (без `metric` — 0).
- Только `nmcli device connect|disconnect <dev>` и `nmcli device set <dev> autoconnect yes`; профили NetworkManager (`nmcli connection modify`) не трогаются.
- Файл состояния `/run/cmprovision/uplink-spare`: каталог 0755, файл 0644; строки `state=`, `since=`, `spare=`, `uplink=`, `up_since=`, `lost_since=`; состояния `standby`, `active`, `waiting`, `off`; `since` не меняется, пока состояние то же.
- Выключатель `/etc/default/cmprovision-uplink`: `SPARE=no` выключает (кавычки, пробелы, комментарий после `#` и регистр не важны), любое другое значение или отсутствие файла — включено. Файл пишет `postinst`, если его нет; это не conffile.
- Журнал — `logger -t cmprovision-uplink`, строки только на действиях и смене выключателя: `built-in Wi-Fi <dev> off: <uplink> has carried the default route for 60 s`, `built-in Wi-Fi <dev> on: no default route but through it for 30 s`, `built-in Wi-Fi <dev>: nmcli device disconnect failed` (и `connect`), `cold spare off (SPARE=no): <dev> connects on its own`, `cold spare released: <dev> connects on its own`.
- Помощник ничего не делает и выходит с кодом 0, если встроенного Wi-Fi нет, `nmcli` не отвечает или NetworkManager им не управляет (`unmanaged`).
- Веб-приложение только читает состояние; новых правил sudo нет.
- Скрипты — POSIX sh без bashisms (Debian `/bin/sh` — dash); LF (`.gitattributes`).
- Код, строки интерфейса и комментарии — по-английски, в стиле соседнего кода; прозу документов — как в них.
- Версия `2.5.2~dev1` для стенда, `2.5.2` при выпуске; снимок перед обновлением не нужен.
- Коммиты кончаются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; выпуск (слияние, тег, установка на wlanpi-da2, пуш) и живая проверка аварии — только по слову пользователя.

## Review Focus

1. Имена перепутаны: карта стала `wlan0`, встроенный — `wlan1`. Встроенный ищется по шине, не по имени (`test_the_bus_not_the_name_tells_the_built_in_wifi`, задача 1).
2. Выключатель записан, как обычно пишут файлы `/etc/default`: `SPARE="no"`, `SPARE='no'   # …`, `SPARE=NO` — это «выключено» (провайдер `switchedOff`, задача 1).
3. Встроенный Wi-Fi в момент проверки ещё подключается (`connecting (getting IP configuration)`) — второй раз его не подключают (`test_a_built_in_wifi_still_connecting_is_not_connected_twice`, задача 1).
4. `nmcli` отказался переключить (устройство занято) — состояние `waiting`, строка в журнале, следующая проверка пробует снова (`test_a_refused_switch_stays_waiting_and_says_so`, задача 1).
5. Снимок сети, сохранённый в кэше версией 2.5.1 (без ключа `spare`), или испорченный файл состояния — индикатор как раньше, без ошибки (`test_no_cold_spare_without_its_file_or_with_one_it_cannot_read`, задача 4; снимки `UplinkSummaryTest` без `spare`).

---

## Карта файлов

- `tools/uplink-spare` (новый) — помощник; `tools/90-cmprovision-uplink` (новый) — скрипт диспетчера.
- `debian/cmprovision-uplink.service`, `debian/cmprovision-uplink.timer` (новые); `debian/rules`, `debian/install`, `debian/postinst`, `debian/prerm`, `debian/postrm`; `tools/backup.sh`.
- `app/Services/NetworkStatus.php` — поле `spare`; `app/Support/UplinkSummary.php` — строка холодного запаса и время; `app/Http/Livewire/Settings.php`, `resources/views/livewire/settings.blade.php` — строка Service status.
- `README.md`, `CLAUDE.md`, `docs/provisioner-network.md`, `ROADMAP.md`, `debian/changelog`.
- Тесты: `tests/Feature/UplinkSpareTest.php`, `tests/Feature/ServiceStatusTest.php` (новые); `tests/Unit/NetworkStatusTest.php`, `tests/Unit/UplinkSummaryTest.php`, `tests/Unit/PackagingTest.php`.

## Команды

```bash
SP=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad
DEV=$SP/wsl-bench
cd "/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
bash "$DEV/tests-ok.sh" 'ClassA|ClassB'   # синхронизирует /root/dev (rsync --delete) и гоняет PHP-тесты по регулярке; exit 0 только при «phpunit exit 0»
bash "$DEV/suite-ok.sh"                   # весь набор PHP и JS
```

Стенд засыпает без открытой сессии: перед работой — фоновый `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 14400` (Bash с `run_in_background`). Команды внутри стенда — скриптом через stdin: `cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s` (в аргументах `wsl.exe` переменные `$X` раскрываются раньше времени). Правки документов — Python-скриптом: файл пишется через Write в `$SP`, запускается `python -I <скрипт> "$PWD"`. Полный вывод phpunit на стенде — `/tmp/phpunit-filter.log`.

---

### Task 1: Помощник `uplink-spare`

**Files:**
- Create: `tools/uplink-spare`
- Test: `tests/Feature/UplinkSpareTest.php` (новый)

**Interfaces:**
- Consumes: —
- Produces: `tools/uplink-spare` — `check [событие] [устройство]` и `release`, код 0 (2 — неизвестная команда, с `usage: uplink-spare check …` в stderr); переменные для тестов `UPLINK_SPARE_SYS`, `UPLINK_SPARE_RUN`, `UPLINK_SPARE_CONF`, `UPLINK_SPARE_NOW`; файл состояния и строки журнала из Global Constraints; отложенная перепроверка — `systemd-run --collect --quiet --unit=cmprovision-uplink-check-<время>-<pid> --on-active=<n>s /usr/lib/cmprovision/uplink-spare check later`. В `UplinkSpareTest` — константы `T`, `CARD`, `ONBOARD` и помощники `wifi()`, `stub()`, `world()`, `run(int $t, array $args = ['check'], string $script = 'tools/uplink-spare'): int`, `actions()`, `forget()`, `state()`, `journal()`, `device()`.

- [ ] **Step 1: Write the failing tests**

Создать `tests/Feature/UplinkSpareTest.php`:

```php
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
    protected function run(int $t, array $args = ['check'], string $script = 'tools/uplink-spare'): int
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

        $this->assertSame(0, $this->run(self::T));
        $this->assertSame('connected', $this->device('wlan0'), 'not before the card has held a minute');
        $this->assertSame(['state' => 'waiting', 'since' => (string) self::T, 'spare' => 'wlan0', 'uplink' => 'wlan1',
                           'up_since' => (string) self::T, 'lost_since' => ''], $this->state());
        $this->assertMatchesRegularExpression(
            '#^systemd-run --collect --quiet --unit=cmprovision-uplink-check-\S+ --on-active=60s /usr/lib/cmprovision/uplink-spare check later$#',
            $this->actions()[0]);
        $this->assertSame('', $this->journal(), 'a check that changes nothing is not logged');

        $this->forget();
        $this->run(self::T + 59);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertStringContainsString('--on-active=1s', $this->actions()[0]);
        $this->assertSame((string) self::T, $this->state()['since'], 'the state has not changed');

        $this->forget();
        $this->run(self::T + 60);
        $this->assertSame('disconnected', $this->device('wlan0'));
        $this->assertSame(['nmcli device disconnect wlan0'], $this->actions());
        $this->assertSame('standby', $this->state()['state']);
        $this->assertSame((string) (self::T + 60), $this->state()['since']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0 off: wlan1 has carried the default route for 60 s\n", $this->journal());
    }

    public function test_without_a_route_through_the_card_for_30_seconds_the_built_in_wifi_connects()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'unavailable'], '');

        $this->run(self::T);
        $this->assertSame('disconnected', $this->device('wlan0'), 'not before 30 s without a route');
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame((string) self::T, $this->state()['lost_since']);
        $this->assertStringContainsString('--on-active=30s', $this->actions()[0]);

        $this->forget();
        $this->run(self::T + 30);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame(['nmcli device connect wlan0'], $this->actions());
        $this->assertSame('active', $this->state()['state']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0 on: no default route but through it for 30 s\n", $this->journal());
    }

    public function test_a_card_that_blinks_starts_the_count_again()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'disconnected'], '');
        $this->run(self::T);                                    // lost
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'connected'], self::CARD);
        $this->run(self::T + 10);                               // back
        $this->assertSame('standby', $this->state()['state']);
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'disconnected'], '');
        $this->run(self::T + 20);                               // lost again

        $this->run(self::T + 30);
        $this->assertSame('disconnected', $this->device('wlan0'), '30 s from the first loss, but only 10 s from the second');
        $this->run(self::T + 50);
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

        $this->run(self::T);
        $this->run(self::T + 120);

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

        $this->run(self::T);
        $this->run(self::T + 60);

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

        $this->assertSame(0, $this->run(self::T + 600));
        $this->assertSame([], $this->actions());
        $this->assertSame([], $this->state());
        $this->assertSame('', $this->journal());
    }

    public function test_a_provisioner_without_a_card_keeps_its_built_in_wifi()
    {
        File::deleteDirectory($this->dir.'/sys/wlan1');
        $this->world(['wlan0' => 'connected'], self::ONBOARD);

        $this->run(self::T);
        $this->run(self::T + 600);

        $this->assertSame([], $this->actions());
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('active', $this->state()['state']);
    }

    public function test_release_gives_the_built_in_wifi_back()
    {
        $this->world(['wlan0' => 'disconnected', 'wlan1' => 'connected'], self::CARD);
        $this->run(self::T);
        $this->forget();

        $this->assertSame(0, $this->run(self::T + 1, ['release']));
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
            $this->run(self::T);
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

        $this->run(self::T);
        $this->run(self::T + 60);

        $this->assertSame('disconnected', $this->device('wlan1'));
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('wlan0', $this->state()['uplink']);
    }

    public function test_any_other_default_route_counts_and_the_built_in_wifis_own_never_does()
    {
        // the card's route without a metric, the built-in Wi-Fi's own with a better one
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'],
            "default via 192.168.68.1 dev wlan0 proto dhcp metric 50\ndefault via 192.168.68.1 dev wlan1 proto dhcp\n");
        $this->run(self::T);
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame('wlan1', $this->state()['uplink']);

        // of two other uplinks, the one with the lower metric
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected', 'eth1' => 'connected'],
            "default via 192.168.68.1 dev wlan1 metric 100\ndefault via 10.0.0.1 dev eth1 metric 50\n");
        $this->run(self::T + 1);
        $this->assertSame('eth1', $this->state()['uplink']);
    }

    public function test_a_built_in_wifi_still_connecting_is_not_connected_twice()
    {
        $this->world(['wlan0' => 'connecting (getting IP configuration)', 'wlan1' => 'unavailable'], '');

        $this->run(self::T);
        $this->run(self::T + 60);

        $this->assertSame([], $this->actions());
        $this->assertSame('active', $this->state()['state']);
    }

    public function test_a_refused_switch_stays_waiting_and_says_so()
    {
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);
        $this->run(self::T);
        touch($this->dir.'/nm-fails');
        $this->forget();

        $this->run(self::T + 60);
        $this->assertSame('connected', $this->device('wlan0'));
        $this->assertSame('waiting', $this->state()['state']);
        $this->assertSame("-t cmprovision-uplink built-in Wi-Fi wlan0: nmcli device disconnect failed\n", $this->journal());

        unlink($this->dir.'/nm-fails');
        $this->run(self::T + 300);                              // the 5-minute check tries again
        $this->assertSame('disconnected', $this->device('wlan0'));
    }

    public function test_an_unknown_command_is_refused()
    {
        $this->assertSame(2, $this->run(self::T, ['bogus']));
        $this->assertStringContainsString('usage: uplink-spare check [event] [device] | release', $this->output);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'UplinkSpareTest'`
Expected: `phpunit exit 1` или `2`; все 21 тест не проходят (провалы, а где тест берёт `actions()[0]` у пустого массива — ошибки): `sh` не находит `tools/uplink-spare` и выходит с кодом 2, без вывода `usage`.

- [ ] **Step 3: Write the implementation**

Создать `tools/uplink-spare`:

```sh
#!/bin/sh
# The built-in Wi-Fi as a cold spare (cmprovision 2.5.2, docs/superpowers/specs/2026-10-15-uplink-cold-spare-design.md).
# The built-in Wi-Fi (the wireless device on the SDIO bus) stays disconnected while another device carries an IPv4
# default route, connects when there has been none for LOST_SECONDS, and leaves again once the other route has held
# for STABLE_SECONDS. Every run decides from the state of the system, not from what started it: NetworkManager's
# dispatcher (90-cmprovision-uplink), a deferred check, or cmprovision-uplink.timer every 5 minutes.
#   uplink-spare check [event] [device]    decide now
#   uplink-spare release                   let the built-in Wi-Fi connect on its own again (prerm)
# Only the device is disconnected, never a NetworkManager profile: after a reboot both radios connect as before.
# Tests point it elsewhere with UPLINK_SPARE_SYS, UPLINK_SPARE_RUN, UPLINK_SPARE_CONF and UPLINK_SPARE_NOW.

LOST_SECONDS=30
STABLE_SECONDS=60
SYS=${UPLINK_SPARE_SYS:-/sys/class/net}
RUN=${UPLINK_SPARE_RUN:-/run/cmprovision}
CONF=${UPLINK_SPARE_CONF:-/etc/default/cmprovision-uplink}
STATE=$RUN/uplink-spare
SELF=/usr/lib/cmprovision/uplink-spare

now() {
    echo "${UPLINK_SPARE_NOW:-$(date +%s)}"
}

log() {
    logger -t cmprovision-uplink "$1" 2>/dev/null || true
}

# the built-in Wi-Fi: the first wireless device on the SDIO bus (the CM4's brcmfmac)
builtin() {
    for dir in "$SYS"/*; do
        [ -d "$dir/wireless" ] || [ -e "$dir/phy80211" ] || continue
        [ "$(basename "$(readlink -f "$dir/device/subsystem")")" = sdio ] || continue
        basename "$dir"
        return 0
    done
    return 1
}

# NetworkManager's word for a device: connected, connecting (...), disconnected, unavailable, unmanaged; nothing when
# NetworkManager does not answer
device_state() {
    nmcli -t -f DEVICE,STATE device status 2>/dev/null | awk -F: -v d="$1" '$1 == d { print $2; exit }'
}

# the device of the IPv4 default route with the lowest metric through anything but $1; nothing when there is none
uplink() {
    ip -4 route show default 2>/dev/null | awk -v spare="$1" '
        { dev = ""; metric = 0
          for (i = 1; i < NF; i++) { if ($i == "dev") dev = $(i + 1); if ($i == "metric") metric = $(i + 1) + 0 }
          if (dev != "" && dev != spare && (best == "" || metric < low)) { best = dev; low = metric } }
        END { print best }'
}

# SPARE=no in the switch turns the cold spare off; quotes, spaces, a comment and capitals do not count
switched_off() {
    value=$(sed -n 's/^[[:space:]]*SPARE[[:space:]]*=//p' "$CONF" 2>/dev/null | tail -n 1 | sed 's/#.*//' \
        | tr -d " \t\"'" | tr '[:upper:]' '[:lower:]')
    [ "$value" = no ]
}

# a value of the state file
get() {
    [ -f "$STATE" ] && sed -n "s/^$1=//p" "$STATE" | head -n 1
}

# the state file, readable by the web server (NetworkStatus): the state, since when (kept while the state stays), the
# devices, and the two counts the decisions run on
record() {   # state uplink up_since lost_since
    since=$(get since)
    { [ "$(get state)" = "$1" ] && [ -n "$since" ]; } || since=$(now)
    mkdir -p "$RUN" && chmod 0755 "$RUN"
    printf 'state=%s\nsince=%s\nspare=%s\nuplink=%s\nup_since=%s\nlost_since=%s\n' \
        "$1" "$since" "$spare" "$2" "$3" "$4" > "$STATE.new" && chmod 0644 "$STATE.new" && mv -f "$STATE.new" "$STATE"
}

# decide again in $1 seconds: a transient timer of its own, so a check it starts may schedule the next one
later() {
    systemd-run --collect --quiet --unit="cmprovision-uplink-check-$(now)-$$" --on-active="$1s" \
        "$SELF" check later >/dev/null 2>&1 || true
}

check() {
    spare=$(builtin) || return 0
    state=$(device_state "$spare")
    case "$state" in ""|unmanaged) return 0 ;; esac         # NetworkManager does not answer or is not in charge of it
    if switched_off; then
        [ "$(get state)" = off ] || log "cold spare off (SPARE=no): $spare connects on its own"
        nmcli device set "$spare" autoconnect yes >/dev/null 2>&1
        record off "" "" ""
        return 0
    fi
    case "$state" in connected*|connecting*) on=yes ;; *) on=no ;; esac
    t=$(now)
    up=$(uplink "$spare")
    if [ -n "$up" ]; then
        up_since=$(get up_since)
        [ -n "$up_since" ] || up_since=$t
        if [ "$on" = no ]; then
            record standby "$up" "$up_since" ""
        elif [ $((t - up_since)) -lt $STABLE_SECONDS ]; then
            record waiting "$up" "$up_since" ""
            later $((up_since + STABLE_SECONDS - t))
        elif nmcli device disconnect "$spare" >/dev/null 2>&1; then
            log "built-in Wi-Fi $spare off: $up has carried the default route for $STABLE_SECONDS s"
            record standby "$up" "$up_since" ""
        else
            log "built-in Wi-Fi $spare: nmcli device disconnect failed"
            record waiting "$up" "$up_since" ""
        fi
    else
        lost_since=$(get lost_since)
        [ -n "$lost_since" ] || lost_since=$t
        if [ "$on" = yes ]; then
            record active "" "" "$lost_since"
        elif [ $((t - lost_since)) -lt $LOST_SECONDS ]; then
            record waiting "" "" "$lost_since"
            later $((lost_since + LOST_SECONDS - t))
        elif nmcli device connect "$spare" >/dev/null 2>&1; then
            log "built-in Wi-Fi $spare on: no default route but through it for $LOST_SECONDS s"
            record active "" "" "$lost_since"
        else
            log "built-in Wi-Fi $spare: nmcli device connect failed"
            record waiting "" "" "$lost_since"
        fi
    fi
}

release() {
    spare=$(builtin) || return 0
    nmcli device set "$spare" autoconnect yes >/dev/null 2>&1
    rm -f "$STATE"
    log "cold spare released: $spare connects on its own"
}

case "${1:-}" in
    check)
        mkdir -p "$RUN"
        exec 9>"$RUN/uplink-spare.lock"
        flock -w 10 9 || exit 0     # another run is deciding right now
        check
        ;;
    release)
        release
        ;;
    *)
        echo "usage: uplink-spare check [event] [device] | release" >&2
        exit 2
        ;;
esac
exit 0
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'UplinkSpareTest'`
Expected: `phpunit exit 0`, `OK (21 tests, …)`; `php84-errors.log` пуст.

Run: `sh -n tools/uplink-spare && echo "syntax OK"`
Expected: `syntax OK`.

- [ ] **Step 5: Commit**

```bash
git add tools/uplink-spare tests/Feature/UplinkSpareTest.php
git commit -q -F - <<'EOF'
The cold spare helper for the built-in Wi-Fi

tools/uplink-spare keeps the built-in Wi-Fi (the wireless device on the
SDIO bus) disconnected while another device carries the default route,
connects it after 30 s without one and lets it go once the route has
held for a minute. It switches the device only, never a profile, keeps
its state in /run/cmprovision for the web UI and logs each switch.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Скрипт диспетчера

**Files:**
- Create: `tools/90-cmprovision-uplink`
- Test: `tests/Feature/UplinkSpareTest.php`

**Interfaces:**
- Consumes: из задачи 1 — `UplinkSpareTest::run($t, $args, $script)`, `world()`, `state()`, константы `T`, `CARD`, `ONBOARD`; `tools/uplink-spare check`.
- Produces: `tools/90-cmprovision-uplink` — аргументы диспетчера (`$1` интерфейс, `$2` событие); на `up`, `down`, `dhcp4-change`, `connectivity-change` выполняет `sh "${UPLINK_SPARE:-/usr/lib/cmprovision/uplink-spare}" check "$2" "$1"`, иначе выходит с кодом 0.

- [ ] **Step 1: Write the failing test**

В `tests/Feature/UplinkSpareTest.php` в конец класса (перед последней `}`) добавить:

```php
    public static function dispatcherActions(): array
    {
        return [
            'up' => ['up', true], 'down' => ['down', true], 'dhcp4-change' => ['dhcp4-change', true],
            'connectivity-change' => ['connectivity-change', true],
            'pre-up' => ['pre-up', false], 'hostname' => ['hostname', false], 'vpn-up' => ['vpn-up', false],
        ];
    }

    #[DataProvider('dispatcherActions')]
    public function test_the_dispatcher_checks_on_route_events_only($action, $checks)
    {
        $this->world(['wlan0' => 'connected', 'wlan1' => 'connected'], self::CARD.self::ONBOARD);

        $this->assertSame(0, $this->run(self::T, ['wlan1', $action], 'tools/90-cmprovision-uplink'));

        $this->assertSame($checks, $this->state() !== [], $action);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'UplinkSpareTest'`
Expected: `phpunit exit 1`; падают все семь строк `dispatcherActions` (скрипта нет, `sh` выходит с кодом 2); 21 тест задачи 1 проходит.

- [ ] **Step 3: Write the implementation**

Создать `tools/90-cmprovision-uplink`:

```sh
#!/bin/sh
# NetworkManager's dispatcher runs this on connection and device events ($1 the interface, $2 the action); the decision
# is uplink-spare's: the built-in Wi-Fi as a cold spare (cmprovision 2.5.2). It returns at once.
case "$2" in
    up|down|dhcp4-change|connectivity-change)
        exec sh "${UPLINK_SPARE:-/usr/lib/cmprovision/uplink-spare}" check "$2" "$1"
        ;;
esac
exit 0
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'UplinkSpareTest'`
Expected: `phpunit exit 0`, `OK (28 tests, …)`.

- [ ] **Step 5: Commit**

```bash
git add tools/90-cmprovision-uplink tests/Feature/UplinkSpareTest.php
git commit -q -F - <<'EOF'
The NetworkManager dispatcher hook of the cold spare

On up, down, dhcp4-change and connectivity-change events it hands over
to uplink-spare, which decides from the state of the system.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Пакет

**Files:**
- Create: `debian/cmprovision-uplink.service`, `debian/cmprovision-uplink.timer`
- Modify: `debian/rules` (`override_dh_install`), `debian/install`, `debian/postinst` (конец ветки `configure`), `debian/prerm`, `debian/postrm`, `tools/backup.sh:39-42`
- Test: `tests/Unit/PackagingTest.php`

**Interfaces:**
- Consumes: `tools/uplink-spare` (задача 1), `tools/90-cmprovision-uplink` (задача 2).
- Produces: в пакете — `/usr/lib/cmprovision/uplink-spare` и `/usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink` (0755 root), юниты `cmprovision-uplink.service` и `cmprovision-uplink.timer`; `postinst` пишет `/etc/default/cmprovision-uplink`; `prerm` зовёт `uplink-spare release` при удалении и откате ниже `2.5.2~`.

- [ ] **Step 1: Write the failing tests**

В `tests/Unit/PackagingTest.php` в конец класса (перед последней `}`) добавить:

```php
    public function test_the_cold_spare_is_installed_as_networkmanager_and_systemd_want_it()
    {
        // NetworkManager runs only dispatcher scripts that root owns and nobody else can change
        $rules = $this->file('debian/rules');
        $this->assertStringContainsString('install -D -m 755 tools/uplink-spare debian/cmprovision4/usr/lib/cmprovision/uplink-spare', $rules);
        $this->assertStringContainsString('install -D -m 755 tools/90-cmprovision-uplink debian/cmprovision4/usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink', $rules);
        $install = $this->file('debian/install');
        $this->assertStringContainsString('debian/cmprovision-uplink.service lib/systemd/system', $install);
        $this->assertStringContainsString('debian/cmprovision-uplink.timer lib/systemd/system', $install);
        $timer = $this->file('debian/cmprovision-uplink.timer');
        foreach (['OnBootSec=2min', 'OnUnitActiveSec=5min', 'WantedBy=timers.target'] as $line)
            $this->assertStringContainsString($line, $timer);
        $service = $this->file('debian/cmprovision-uplink.service');
        $this->assertStringContainsString('Type=oneshot', $service);
        $this->assertStringContainsString('ExecStart=/usr/lib/cmprovision/uplink-spare check timer', $service);
        $this->assertStringNotContainsString('[Install]', $service, 'only the timer starts it');
    }

    public function test_the_cold_spare_switch_is_written_once_and_never_fails_the_configure()
    {
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('if [ ! -e /etc/default/cmprovision-uplink ]; then', $postinst);
        $this->assertStringContainsString('SPARE=yes', $postinst);
        $this->assertStringContainsString('/usr/lib/cmprovision/uplink-spare check postinst || true', $postinst);
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'uplink-spare check postinst'));
        $this->assertStringContainsString('/etc/default/cmprovision-uplink', $this->file('tools/backup.sh'));
    }

    public function test_leaving_the_package_or_going_below_2_5_2_gives_the_built_in_wifi_back()
    {
        // otherwise the built-in Wi-Fi would stay disconnected until a reboot; a downgrade also takes the timer's unit away
        $prerm = $this->file('debian/prerm');
        $this->assertStringContainsString('dpkg --compare-versions "$2" lt "2.5.2~"', $prerm);
        $this->assertStringContainsString('deb-systemd-invoke stop cmprovision-uplink.timer', $prerm);
        $this->assertStringContainsString('deb-systemd-helper disable cmprovision-uplink.timer', $prerm);
        $this->assertStringContainsString('/usr/lib/cmprovision/uplink-spare release || true', $prerm);
        $this->assertLessThan(strpos($prerm, '#DEBHELPER#'), strpos($prerm, 'uplink-spare release'));
        $this->assertStringContainsString('rm -f /etc/default/cmprovision-uplink', $this->file('debian/postrm'));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'PackagingTest'`
Expected: `phpunit exit 1` (или `2`: нет файла юнита), падают три новых теста; десять прежних проходят.

- [ ] **Step 3: Write the implementation**

Создать `debian/cmprovision-uplink.service`:

```ini
[Unit]
Description=CMProvision: the built-in Wi-Fi as a cold spare (a check for events the dispatcher missed)
After=NetworkManager.service

[Service]
Type=oneshot
ExecStart=/usr/lib/cmprovision/uplink-spare check timer
```

Создать `debian/cmprovision-uplink.timer`:

```ini
[Unit]
Description=CMProvision: check the built-in Wi-Fi cold spare every 5 minutes

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
```

В `debian/rules` в `override_dh_install` после строки `install -D -m 755 tools/restore.sh …` добавить (отступ — табуляция):

```make
	install -D -m 755 tools/uplink-spare debian/cmprovision4/usr/lib/cmprovision/uplink-spare
	install -D -m 755 tools/90-cmprovision-uplink debian/cmprovision4/usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink
```

В `debian/install` после строки `debian/cmprovision-queue-images.service lib/systemd/system` добавить:

```
debian/cmprovision-uplink.service lib/systemd/system
debian/cmprovision-uplink.timer lib/systemd/system
```

В `debian/postinst` в конце ветки `configure)` — после строки `fix_ownership || echo "Could not adjust file ownership in /var/lib/cmprovision"` и перед `;;` — добавить:

```sh

    # The built-in Wi-Fi as a cold spare (2.5.2): the switch is written once and then left to the operator, and one check
    # runs now (the built-in Wi-Fi leaves a minute after the install if the card holds); it never fails the configure
    if [ ! -e /etc/default/cmprovision-uplink ]; then
        cat > /etc/default/cmprovision-uplink <<'EOF'
# The built-in Wi-Fi as a cold spare (cmprovision): disconnected while another uplink carries the default route,
# connected when there has been none for 30 seconds. SPARE=no keeps it connected all the time.
SPARE=yes
EOF
    fi
    /usr/lib/cmprovision/uplink-spare check postinst || true
```

В `debian/prerm` перед строкой `#DEBHELPER#` добавить:

```sh
# The built-in Wi-Fi as a cold spare (2.5.2): leaving the package, or going back to a version without it, must not leave
# the built-in Wi-Fi disconnected until a reboot. A downgrade also takes the timer's unit away, so it is disabled too
# (a remove keeps the timer enabled for a later reinstall: debhelper stops it).
if [ "$1" = "remove" ] || { [ "$1" = "upgrade" ] && [ -n "$2" ] && dpkg --compare-versions "$2" lt "2.5.2~"; }; then
    deb-systemd-invoke stop cmprovision-uplink.timer >/dev/null || true
    if [ "$1" = "upgrade" ]; then
        deb-systemd-helper disable cmprovision-uplink.timer >/dev/null || true
    fi
    systemctl stop 'cmprovision-uplink-check-*.timer' >/dev/null 2>&1 || true
    /usr/lib/cmprovision/uplink-spare release || true
fi

```

В `debian/postrm` в ветке `purge` после строки `rm -f /etc/php/*/fpm/pool.d/cmprovision.conf` добавить:

```sh
    # the cold spare's switch and state (2.5.2)
    rm -f /etc/default/cmprovision-uplink /run/cmprovision/uplink-spare /run/cmprovision/uplink-spare.lock
    rmdir /run/cmprovision 2>/dev/null || true
```

В `tools/backup.sh` в списке `system-config.tar.gz` заменить строку

```sh
    /lib/systemd/system/cmprovision-*.service 2>/dev/null || true
```

на

```sh
    /lib/systemd/system/cmprovision-*.service /lib/systemd/system/cmprovision-*.timer \
    /etc/default/cmprovision-uplink 2>/dev/null || true
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'PackagingTest|UplinkSpareTest'`
Expected: `phpunit exit 0`, `OK (41 tests, …)` (13 в `PackagingTest`, 28 в `UplinkSpareTest`).

Run: `for f in debian/postinst debian/prerm debian/postrm tools/backup.sh; do sh -n $f || echo "BROKEN $f"; done; echo checked`
Expected: только `checked`.

- [ ] **Step 5: Commit**

```bash
git add debian/cmprovision-uplink.service debian/cmprovision-uplink.timer debian/rules debian/install debian/postinst \
        debian/prerm debian/postrm tools/backup.sh tests/Unit/PackagingTest.php
git commit -q -F - <<'EOF'
Package the cold spare

The helper goes to /usr/lib/cmprovision, the dispatcher hook to
NetworkManager's own dispatcher.d, and a timer checks every 5 minutes.
postinst writes /etc/default/cmprovision-uplink once and runs a check;
removing the package or going below 2.5.2 gives the built-in Wi-Fi back.
The backup keeps the switch.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Веб-интерфейс

**Files:**
- Modify: `app/Services/NetworkStatus.php` (конструктор, `read()`, новый метод `spare()`)
- Modify: `app/Support/UplinkSummary.php` (`from()`, новый метод `spareLost()`)
- Modify: `app/Http/Livewire/Settings.php`, `resources/views/livewire/settings.blade.php`
- Test: `tests/Unit/NetworkStatusTest.php`, `tests/Unit/UplinkSummaryTest.php`, `tests/Feature/ServiceStatusTest.php` (новый)

**Interfaces:**
- Consumes: файл состояния задачи 1 (`state`, `since`, `spare`, `lost_since`); тег журнала `cmprovision-uplink`; юнит `cmprovision-uplink.timer` (задача 3).
- Produces: `NetworkStatus::__construct(string $net = '/sys/class/net', string $routes = '/proc/net/route', ?callable $run = null, ?callable $addresses = null, string $spare = '/run/cmprovision/uplink-spare')`; в снимке `read()` — ключ `spare`: `['state' => string, 'since' => ?int, 'device' => ?string, 'lost_since' => ?int]` или `null`; `UplinkSummary::from()` читает `$status['spare'] ?? null`; `Settings::viewLogUplinkSpare()`, свойство `$uplinkSpareRunning`.

- [ ] **Step 1: Write the failing tests**

В `tests/Unit/NetworkStatusTest.php` заменить заголовок и последнюю строку помощника `readStatus` так, чтобы он принимал путь к файлу состояния:

```php
    private function readStatus(?array $addresses = null, ?string $spare = null)
```

и

```php
        return (new NetworkStatus($this->root.'/net', $this->root.'/route', $run, function () use ($addresses) {
            return $addresses;
        }, $spare !== null ? $spare : $this->root.'/no-spare'))->read();
```

и в конец класса (перед последней `}`) добавить:

```php
    public function test_the_cold_spare_state_is_read_from_its_file()
    {
        file_put_contents($this->root.'/spare', "state=active\nsince=1790000030\nspare=wlan0\nuplink=\nup_since=\nlost_since=1790000000\n");

        $this->assertSame(['state' => 'active', 'since' => 1790000030, 'device' => 'wlan0', 'lost_since' => 1790000000],
            $this->readStatus(null, $this->root.'/spare')['spare']);
    }

    public function test_no_cold_spare_without_its_file_or_with_one_it_cannot_read()
    {
        $this->assertNull($this->readStatus(null, $this->root.'/none')['spare']);
        file_put_contents($this->root.'/spare', "garbage\nstate=sideways\n");
        $this->assertNull($this->readStatus(null, $this->root.'/spare')['spare']);
    }
```

В `tests/Unit/UplinkSummaryTest.php` добавить `use Illuminate\Support\Carbon;` к импортам и в конец класса (перед последней `}`):

```php
    public function test_the_built_in_wifi_as_a_cold_spare_is_a_section_of_its_own()
    {
        $v = UplinkSummary::from(self::snapshot([
            'backups' => [],
            'spare' => ['state' => 'standby', 'since' => 1790000060, 'device' => 'wlan0', 'lost_since' => null],
        ]));

        $this->assertSame('normal', $v['tone']);
        $this->assertSame([
            ['Cold spare: built-in Wi-Fi (wlan0)', 'off while the M.2 Wi-Fi card carries the traffic; connects after 30 s without it'],
            ['Modules network: Ethernet (eth0)', '1 Gbit/s full duplex, 172.20.0.1/16'],
        ], $v['sections']);
    }

    public function test_running_on_the_cold_spare_says_since_when()
    {
        $lost = Carbon::parse('2026-10-15 06:41:00', 'UTC')->getTimestamp();   // 09:41 in Moscow
        $v = UplinkSummary::from(self::snapshot([
            'uplink' => self::onboard(), 'backups' => [], 'preferred_down' => 'uplink-m2',
            'spare' => ['state' => 'active', 'since' => $lost + 30, 'device' => 'wlan0', 'lost_since' => $lost],
        ]));

        $this->assertSame('Preferred connection uplink-m2 is not connected since 09:41, the backup carries the traffic.', $v['warning']);
        $this->assertSame([['Modules network: Ethernet (eth0)', '1 Gbit/s full duplex, 172.20.0.1/16']], $v['sections']);
    }
```

Создать `tests/Feature/ServiceStatusTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Settings → Service status: the cold spare of the built-in Wi-Fi has a row, and its log is its journal */
class ServiceStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cold_spare_has_a_row_with_a_log()
    {
        Livewire::test(Settings::class)
            ->assertSee('uplink cold spare (built-in Wi-Fi)')
            ->assertSeeHtml('wire:click="viewLogUplinkSpare()"')
            ->call('viewLogUplinkSpare')
            ->assertSet('logModalOpen', true)
            ->assertSet('logTitle', 'Uplink cold spare log since boot');
    }

    public function test_its_log_is_what_the_helper_wrote_to_the_journal()
    {
        if (trim((string) shell_exec('command -v logger 2>/dev/null')) === '' || !is_executable('/bin/journalctl'))
            $this->markTestSkipped('needs logger and journalctl');
        $marker = 'cold spare test '.bin2hex(random_bytes(4));
        exec('logger -t cmprovision-uplink '.escapeshellarg($marker));
        // journald takes the line a moment later; without a journal that takes it there is nothing to check
        $seen = false;
        for ($i = 0; $i < 20 && !$seen; $i++) {
            usleep(100000);
            $seen = str_contains((string) shell_exec('/bin/journalctl --boot -t cmprovision-uplink 2>/dev/null'), $marker);
        }
        if (!$seen)
            $this->markTestSkipped('the journal does not take logger lines here');

        $log = Livewire::test(Settings::class)->call('viewLogUplinkSpare')->get('logOutput');

        $this->assertStringContainsString($marker, $log);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'NetworkStatusTest|UplinkSummaryTest|ServiceStatusTest'`
Expected: `phpunit exit 2` или `1`: два новых теста `NetworkStatusTest` — ошибка `Undefined array key "spare"`; два новых `UplinkSummaryTest` падают (нет секции холодного запаса, нет «since 09:41»); `ServiceStatusTest` — нет строки и метода `viewLogUplinkSpare` (второй тест пропускается, если журнал на стенде не принимает строки `logger`). Прежние тесты этих классов проходят.

- [ ] **Step 3: Write the implementation**

В `app/Services/NetworkStatus.php`:

- к свойствам после `private $addresses;` добавить `private $spare;`;
- конструктор:

```php
    /**
     * @param callable|null $run       runs a shell command, returns its output or null when it failed
     * @param callable|null $addresses returns the interfaces with their addresses, as net_get_interfaces() does
     * @param string        $spare     the state file of the built-in Wi-Fi's cold spare (tools/uplink-spare)
     */
    public function __construct(string $net = '/sys/class/net', string $routes = '/proc/net/route',
                                ?callable $run = null, ?callable $addresses = null,
                                string $spare = '/run/cmprovision/uplink-spare')
    {
        $this->net = $net;
        $this->routes = $routes;
        $this->spare = $spare;
```

(остальное тело конструктора не меняется);

- в docblock `read()` к перечню полей добавить `spare (the built-in Wi-Fi's cold spare: state, since, device, lost_since; null without its state file)`, а в возвращаемый массив `read()` после `'preferred_down' => …,` добавить строку:

```php
            'spare' => $this->spare(),
```

- после метода `serverMac()` добавить:

```php
    /** the built-in Wi-Fi as a cold spare (tools/uplink-spare writes the file as root): null without a file it can read */
    private function spare(): ?array
    {
        if (!is_readable($this->spare)) {
            return null;
        }
        $values = [];
        foreach (file($this->spare, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) === 2) {
                $values[$pair[0]] = $pair[1];
            }
        }
        if (!in_array($values['state'] ?? '', ['standby', 'active', 'waiting', 'off'], true)) {
            return null;
        }
        $time = function ($key) use ($values) {
            return ctype_digit($values[$key] ?? '') ? (int) $values[$key] : null;
        };

        return [
            'state' => $values['state'],
            'since' => $time('since'),
            'device' => ($values['spare'] ?? '') !== '' ? $values['spare'] : null,
            'lost_since' => $time('lost_since'),
        ];
    }
```

В `app/Support/UplinkSummary.php` в `from()` заменить блок

```php
            if ($status['preferred_down'] !== null) {
                $view['tone'] = 'warn';
                $view['warning'] = 'Preferred connection '.$status['preferred_down'].' is not connected, the backup carries the traffic.';
```

на

```php
            if ($status['preferred_down'] !== null) {
                $view['tone'] = 'warn';
                $view['warning'] = 'Preferred connection '.$status['preferred_down'].' is not connected'.self::spareLost($status)
                    .', the backup carries the traffic.';
```

и между циклом `foreach ($status['backups'] …) { … }` и блоком `if ($status['modules'] !== null)` вставить:

```php
        // a snapshot cached by a version before 2.5.2 has no spare
        $spare = $status['spare'] ?? null;
        if ($spare !== null && $spare['state'] === 'standby' && $spare['device'] !== null) {
            // "30 s" is LOST_SECONDS of tools/uplink-spare
            $view['sections'][] = ['Cold spare: built-in Wi-Fi ('.$spare['device'].')',
                'off while '.($uplink !== null ? 'the '.self::adapterPhrase($uplink) : 'another uplink')
                .' carries the traffic; connects after 30 s without it'];
        }
```

и после метода `from()` добавить:

```php
    /** " since 09:41" when the built-in Wi-Fi carries the traffic as the cold spare: the uplink was lost then */
    private static function spareLost(array $status): string
    {
        $spare = $status['spare'] ?? null;
        if ($spare === null || $spare['state'] !== 'active' || $spare['lost_since'] === null) {
            return '';
        }

        return ' since '.Carbon::createFromTimestamp($spare['lost_since'])->local()->format('H:i');
    }
```

В `app/Http/Livewire/Settings.php`:

- в строке `public $dnsmasqRunning, $rpibootRunning, $queueRunning, $imagesQueueRunning, $logOutput, $logTitle;` после `$imagesQueueRunning` добавить `$uplinkSpareRunning`;
- в `render()` после строки `$this->imagesQueueRunning = …;` добавить:

```php
        $this->uplinkSpareRunning = $this->isActive('cmprovision-uplink.timer');
```

- заменить метод `getLog`:

```php
    /* the journal since boot of a unit (-u) or of a syslog tag (-t) */
    protected function getLog($name, $option = '-u')
    {
        $output = $retcode = '';
        exec("/bin/journalctl --boot ".$option." ".escapeshellarg($name), $output, $retcode);
        return implode("\n", $output);
    }
```

- после `viewLogImagesQueue()` добавить:

```php
    public function viewLogUplinkSpare()
    {
        $this->logTitle = "Uplink cold spare log since boot";
        $this->logOutput = $this->getLog('cmprovision-uplink', '-t');
        $this->logModalOpen = true;
    }
```

В `resources/views/livewire/settings.blade.php` после строки таблицы с `viewLogImagesQueue()` (её `</tr>`) вставить:

```blade
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($uplinkSpareRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> uplink cold spare (built-in Wi-Fi)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogUplinkSpare()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
                    </td>
                </tr>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'NetworkStatusTest|UplinkSummaryTest|ServiceStatusTest|UplinkIndicatorTest|TokenGuardTest'`
Expected: `phpunit exit 0`; новые тесты проходят (второй `ServiceStatusTest` может быть пропущен — тогда записать это в ledger), `UplinkIndicatorTest` и `TokenGuardTest` не сломаны.

- [ ] **Step 5: Весь набор**

Run: `bash "$DEV/suite-ok.sh"`
Expected: `php rc=0 js rc=0`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NetworkStatus.php app/Support/UplinkSummary.php app/Http/Livewire/Settings.php \
        resources/views/livewire/settings.blade.php tests/Unit/NetworkStatusTest.php tests/Unit/UplinkSummaryTest.php \
        tests/Feature/ServiceStatusTest.php
git commit -q -F - <<'EOF'
The cold spare in the uplink icon and Settings

NetworkStatus reads the state the helper keeps in /run/cmprovision: the
icon's tooltip shows the built-in Wi-Fi as a cold spare, and while it
carries the traffic the warning says since when. Settings lists the cold
spare under Service status with its journal.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: Документы, версия 2.5.2~dev1 и проверка на стенде

**Files:**
- Modify: `README.md` (новый раздел перед `## Switches: …`), `CLAUDE.md`, `docs/provisioner-network.md`, `ROADMAP.md`, `debian/changelog`

**Interfaces:**
- Consumes: всё из задач 1–4.
- Produces: пакет `cmprovision4_2.5.2~dev1_all.deb` на стенде WSL, установленный поверх 2.5.1~dev2, откаченный и поставленный снова; документы.

- [ ] **Step 1: Документы и версия**

Записать в `$SP/plan252-docs.py` и выполнить `python -I "$SP/plan252-docs.py" "$PWD"`:

```python
"""2.5.2: README, CLAUDE.md, docs/provisioner-network.md, ROADMAP and changelog 2.5.2~dev1."""
import datetime
import email.utils
import pathlib
import sys

REPO = pathlib.Path(sys.argv[1])


def replace_once(name, text, old, new):
    count = text.count(old)
    if count != 1:
        sys.exit(f'{name}: expected one match, found {count}: {old[:70]!r}')
    return text.replace(old, new)


def edit(name, change):
    path = REPO / name
    path.write_text(change(path.read_text(encoding='utf-8')), encoding='utf-8', newline='\n')
    print(f'{name}: edited')


README = (
    "## The built-in Wi-Fi as a cold spare ##\n\n"
    "A provisioner with a better Wi-Fi card (an M.2 card, a USB adapter) keeps its built-in Wi-Fi off the network: while "
    "another device carries the default route, the built-in Wi-Fi stays disconnected. When there has been no other default "
    "route for 30 seconds (the card was removed, its driver failed, it cannot connect), the built-in Wi-Fi connects on its "
    "own, and once the card has carried the default route again for a minute, it leaves. Only the device is disconnected, "
    "never a NetworkManager profile: after a reboot both radios connect, and the built-in Wi-Fi leaves a minute later.\n\n"
    "- The provisioner's address changes with the radio that carries the traffic; `<hostname>.local` (avahi) follows it.\n"
    "- Every switch goes to the journal: `journalctl -t cmprovision-uplink`, or Settings → Service status → uplink cold spare.\n"
    "- `SPARE=no` in `/etc/default/cmprovision-uplink` keeps the built-in Wi-Fi connected all the time.\n"
    "- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.\n\n"
)


def readme(text):
    return replace_once('README.md', text, '## Switches: port lookup, the Ports page and PoE restart ##',
                        README + '## Switches: port lookup, the Ports page and PoE restart ##')


CLAUDE_BULLET = (
    "- Встроенный Wi-Fi — холодный запас (2.5.2, спека `docs/superpowers/specs/2026-10-15-uplink-cold-spare-design.md`): "
    "`tools/uplink-spare` (POSIX sh, root) ставится в `/usr/lib/cmprovision/uplink-spare`, `tools/90-cmprovision-uplink` — в "
    "`/usr/lib/NetworkManager/dispatcher.d/` (каталог дистрибутива, не conffile; NetworkManager запускает только скрипты root "
    "без записи для других), страховка — `cmprovision-uplink.timer` (через 2 минуты после загрузки, потом раз в 5 минут). "
    "Встроенный Wi-Fi — беспроводное устройство на шине `sdio`; его отключают (`nmcli device disconnect` — устройство, "
    "не профиль), когда маршрут IPv4 по умолчанию через другое устройство держится `STABLE_SECONDS` (60), и подключают, когда "
    "такого маршрута нет `LOST_SECONDS` (30); решение — по текущему состоянию, а не по событию, отложенные перепроверки — "
    "временные таймеры `systemd-run` с уникальными именами. Состояние — `/run/cmprovision/uplink-spare` (`state`: standby, "
    "active, waiting, off; `since`, `spare`, `uplink`, `up_since`, `lost_since`), его читает `NetworkStatus` (поле `spare`); "
    "«30 s» в строке `UplinkSummary` — тот же `LOST_SECONDS`. Выключатель — `SPARE=no` в `/etc/default/cmprovision-uplink` "
    "(пишет `postinst`, если файла нет; не conffile; в бэкапе). `prerm` при удалении и откате ниже 2.5.2 зовёт "
    "`uplink-spare release` — иначе встроенный остался бы отключённым до перезагрузки.\n"
)


def claude_md(text):
    text = replace_once('CLAUDE.md', text, '- Печать этикеток (FTP или команда;', CLAUDE_BULLET + '- Печать этикеток (FTP или команда;')
    text = replace_once('CLAUDE.md', text,
        'доступ к `/scriptexecute` только из сети стенда (`StandNetworkTest`),',
        'доступ к `/scriptexecute` только из сети стенда (`StandNetworkTest`), холодный запас встроенного Wi-Fi '
        '(`UplinkSpareTest` гоняет настоящий скрипт под `sh` с заглушками `nmcli`, `ip`, `systemd-run`, `systemctl`, '
        '`logger`; строка в Service status — `ServiceStatusTest`),')
    return replace_once('CLAUDE.md', text,
        '  Тексты значка и подсказки готовит `App\\Support\\UplinkSummary`.',
        '  Состояние холодного запаса встроенного Wi-Fi `NetworkStatus` читает из `/run/cmprovision/uplink-spare`. '
        'Тексты значка и подсказки готовит `App\\Support\\UplinkSummary`.')


def network_doc(text):
    return replace_once('docs/provisioner-network.md', text,
        '- **B, 2.5.2 — встроенный Wi-Fi как холодный запасной.** Приоритета NM недостаточно: нужна\n'
        '  активная логика, которая держит профиль встроенной карты опущенным, пока внешняя\n'
        '  карта несёт аплинк, и поднимает его, только когда внешняя карта пропала. Профили\n'
        '  уже привязаны к MAC — «встроенная» и «внешняя» определяются по железу, а не по имени.\n',
        '- **B, 2.5.2 — встроенный Wi-Fi как холодный запасной.** Приоритета NM недостаточно, поэтому\n'
        '  root-помощник `uplink-spare` (по событиям диспетчера NetworkManager и раз в 5 минут) отключает\n'
        '  устройство встроенного Wi-Fi, пока маршрут по умолчанию через другое устройство держится\n'
        '  минуту, и подключает его, когда такого маршрута нет 30 с. Встроенный — устройство на шине\n'
        '  `sdio`, профили не меняются; выключатель — `SPARE=no` в `/etc/default/cmprovision-uplink`.\n')


def roadmap(text):
    return replace_once('ROADMAP.md', text,
        '**2.5.2 — встроенный Wi-Fi провижинера холодным запасным** (просьба 14 октября 2026):',
        '**2.5.2 — встроенный Wi-Fi провижинера холодным запасным** (просьба 14 октября 2026; в работе, спека '
        '`docs/superpowers/specs/2026-10-15-uplink-cold-spare-design.md`):')


def changelog(text):
    if not text.startswith('cmprovision4 (2.5.1) '):
        sys.exit('debian/changelog: the top entry is not 2.5.1')
    date = email.utils.format_datetime(datetime.datetime.now().astimezone())
    return ('cmprovision4 (2.5.2~dev1) unstable; urgency=medium\n\n'
            '  * The built-in Wi-Fi as a cold spare: while another device carries the\n'
            '    default route it stays disconnected; it connects when there has been\n'
            '    none for 30 s and leaves once the uplink has held for a minute.\n'
            '    NetworkManager dispatcher events drive the helper, a timer checks every\n'
            '    5 minutes; SPARE=no in /etc/default/cmprovision-uplink turns it off.\n'
            '  * The uplink icon shows the cold spare, Settings its journal.\n\n'
            f' -- nimbo78 <igor.sin.78@gmail.com>  {date}\n\n') + text


edit('README.md', readme)
edit('CLAUDE.md', claude_md)
edit('docs/provisioner-network.md', network_doc)
edit('ROADMAP.md', roadmap)
edit('debian/changelog', changelog)
```

Expected: пять строк `…: edited`; `head -1 debian/changelog` → `cmprovision4 (2.5.2~dev1) unstable; urgency=medium`.

- [ ] **Step 2: Весь набор**

Run: `bash "$DEV/suite-ok.sh"`
Expected: `php rc=0 js rc=0`.

- [ ] **Step 3: Commit**

```bash
git add README.md CLAUDE.md docs/provisioner-network.md ROADMAP.md debian/changelog
git commit -q -F - <<'EOF'
2.5.2~dev1: docs for the cold spare

README tells how the built-in Wi-Fi works as a cold spare, its switch and
its journal; CLAUDE.md, the network doc and the roadmap follow.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 4: Сборка на стенде**

Стенд должен не спать (см. «Команды»). Сначала сохранить пакет 2.5.1~dev2 для отката, затем собрать рабочее дерево:

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
mkdir -p /root/keep
v=$(dpkg-deb -f /root/own/last.deb Version)
cp /root/own/last.deb "/root/keep/cmprovision4_${v}_all.deb" && echo "kept $v"
EOF
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | sed 's#^#./#' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh /tmp/src-wt.tar
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/13-build-wt.sh
```

Expected: `kept 2.5.1~dev2` (стоящий на стенде пакет — последняя сборка 2.5.1), затем `built 2.5.2~dev1: <размер> bytes`.

- [ ] **Step 5: Установка, откат и снова установка**

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
show() {
    echo "  version $(dpkg-query -W -f='${Version}' cmprovision4); timer: $(systemctl is-enabled cmprovision-uplink.timer 2>&1) $(systemctl is-active cmprovision-uplink.timer 2>&1)"
    echo "  helper: $(stat -c '%a %U' /usr/lib/cmprovision/uplink-spare 2>&1); hook: $(stat -c '%a %U' /usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink 2>&1)"
}
echo "== 2.5.2~dev1 over 2.5.1~dev2"
DEBIAN_FRONTEND=noninteractive apt-get install -y /root/own/last.deb > /tmp/i-252.log 2>&1 || { tail -20 /tmp/i-252.log; exit 1; }
show
echo "  switch: $(grep -v '^#' /etc/default/cmprovision-uplink)"
/usr/lib/cmprovision/uplink-spare check manual; echo "  check rc=$? state file: $(cat /run/cmprovision/uplink-spare 2>/dev/null || echo none)"
echo "== back to 2.5.1~dev2"
DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades /root/keep/cmprovision4_2.5.1~dev2_all.deb > /tmp/i-251b.log 2>&1 || { tail -20 /tmp/i-251b.log; exit 1; }
show
echo "== 2.5.2~dev1 again"
DEBIAN_FRONTEND=noninteractive apt-get install -y /root/own/last.deb > /tmp/i-252b.log 2>&1 || { tail -20 /tmp/i-252b.log; exit 1; }
show
EOF
```

Expected:
- после первой установки: `version 2.5.2~dev1; timer: enabled active`, `helper: 755 root; hook: 755 root`, `switch: SPARE=yes`, `check rc=0 state file: none` (на стенде нет Wi-Fi под NetworkManager);
- после отката: `version 2.5.1~dev2`; у таймера — сообщение об отсутствии юнита (`Failed to get unit file state … No such file or directory` или `not-found`) и `inactive`; у `helper` и `hook` — «No such file or directory»;
- после повторной установки — снова `enabled active`, `755 root` и `755 root`.

- [ ] **Step 6: Дымовой тест стенда**

```bash
{ printf "PW='%s'\n" "$(cat "$SP/bench-web-password.txt")"; echo 'export PW; sh /root/bench/05-smoke.sh'; } \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s > "$SP/smoke-2.5.2.log" 2>&1
grep -E "GET /scriptexecute|alldone|sh -n|ERROR lines|error.log new lines|uplink" "$SP/smoke-2.5.2.log"
```

Expected: `GET /scriptexecute: 200 …`, `sh -n: syntax OK`, `alldone: 200`, число ERROR в `laravel.log` не выросло, новых строк `error.log` nginx нет.

- [ ] **Step 7: Записать итог проверки**

В ledger — версии и строки ожидаемого вывода шагов 4–6. Коммита нет.

---

### Task 6: Выпуск 2.5.2 — только по слову пользователя

Перед этой задачей — финальное ревью всей ветки (процесс исполнения плана) и исправление его мелочей, каждой с тестом (порядок выпуска пользователя). **Остановиться и спросить пользователя перед шагом 1.** Шаг 6 (живая проверка аварии) — отдельный вопрос пользователю.

**Files:**
- Modify: `debian/changelog` (2.5.2~devN → 2.5.2), `ROADMAP.md` (2.5.2 — в «Сделано»)
- Create: `$SP/prov-install252.sh`, `$SP/plan252-release.py` (вне репозитория)

**Interfaces:**
- Consumes: ветка `stage2` после задач 1–5 и ревью.
- Produces: тег `v2.5.2` на слиянии в `main`; пакет `cmprovision4_2.5.2_all.deb` на wlanpi-da2; `wlan0` там в холодном запасе.

- [ ] **Step 1: Коммит выпуска**

Записать в `$SP/plan252-release.py` и выполнить `python -I "$SP/plan252-release.py" "$PWD" "<дата выпуска в виде «15 октября 2026»>" "<число PHP-тестов>" "<сколько из них пропущено>" "<число JS-тестов>"` (числа — из последнего `suite-ok.sh`):

```python
"""2.5.2: the release entry in debian/changelog and ROADMAP."""
import datetime
import email.utils
import pathlib
import re
import sys

REPO = pathlib.Path(sys.argv[1])
DAY, PHP_TESTS, SKIPPED, JS_TESTS = sys.argv[2:6]


def replace_once(name, text, old, new):
    count = text.count(old)
    if count != 1:
        sys.exit(f'{name}: expected one match, found {count}: {old[:70]!r}')
    return text.replace(old, new)


changelog = REPO / 'debian/changelog'
text = changelog.read_text(encoding='utf-8')
text, count = re.subn(r'^cmprovision4 \(2\.5\.2~dev\d+\) unstable;', 'cmprovision4 (2.5.2) unstable;', text, count=1, flags=re.M)
if count != 1:
    sys.exit('debian/changelog: no 2.5.2~devN entry on top')
date = email.utils.format_datetime(datetime.datetime.now().astimezone())
text = re.sub(r'^ -- nimbo78 <igor\.sin\.78@gmail\.com>  .*$', f' -- nimbo78 <igor.sin.78@gmail.com>  {date}', text, count=1, flags=re.M)
changelog.write_text(text, encoding='utf-8', newline='\n')
print('debian/changelog: 2.5.2')

roadmap = REPO / 'ROADMAP.md'
text = roadmap.read_text(encoding='utf-8')
start = text.index('**2.5.2 — встроенный Wi-Fi провижинера холодным запасным**')
end = text.index('**2.5.3 — управление Wi-Fi из веб-интерфейса**')
text = text[:start] + text[end:]
done = (f'**2.5.2** вышла {DAY}, тег `v2.5.2`. Встроенный Wi-Fi — холодный запас.\n\n'
        '- **Переключение.** Пока маршрут по умолчанию несёт другое устройство (карта MT7922 на wlanpi-da2), встроенный Wi-Fi '
        'отключён; без такого маршрута 30 с — подключается, когда маршрут продержался минуту — уходит. Решает root-помощник '
        '`uplink-spare` по событиям диспетчера NetworkManager, страховка — таймер раз в 5 минут. Отключается устройство, '
        'профили не меняются.\n'
        '- **Видно.** Подсказка значка подключения показывает холодный запас, а при аварии — с какого времени; Settings → '
        'Service status — журнал переключений. Выключатель — `SPARE=no` в `/etc/default/cmprovision-uplink`.\n\n'
        f'Проверено: {PHP_TESTS} тестов на PHP 8.4 ({SKIPPED} из них пропускаются без симулятора SNMP) и {JS_TESTS} на JS; '
        'на стенде WSL — установка, откат на 2.5.1 и повторная установка; на wlanpi-da2 — встроенный ушёл в холодный запас '
        'после установки.\n\n')
text = replace_once('ROADMAP.md', text, '## Этап 2: что осталось', done + '## Этап 2: что осталось')
roadmap.write_text(text, encoding='utf-8', newline='\n')
print('ROADMAP.md: 2.5.2 done')
```

Expected: `debian/changelog: 2.5.2`, `ROADMAP.md: 2.5.2 done`.

```bash
git add debian/changelog ROADMAP.md
git commit -q -F - <<'EOF'
The built-in Wi-Fi as a cold spare; 2.5.2

While another device carries the default route the built-in Wi-Fi stays
disconnected; it connects after 30 s without one and leaves once the
route has held for a minute. The uplink icon and Settings show it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 2: Локальное слияние и тег — без пуша**

```bash
test "$(git rev-parse --abbrev-ref HEAD)" = stage2
test "$(git rev-parse main)" = "$(git rev-parse origin/main)"
git checkout -q main
git merge -q --no-ff stage2 -m "Merge stage2: release 2.5.2"
git tag -a v2.5.2 -m "cmprovision4 2.5.2"
test "$(git rev-parse 'v2.5.2^{commit}')" = "$(git rev-parse HEAD)"
git checkout -q stage2 && git merge -q --ff-only main
git log --oneline -3 main
```

Expected: верхняя строка — `Merge stage2: release 2.5.2`; `stage2` совпадает с `main`.

- [ ] **Step 3: Сборка на wlanpi-da2 из тега**

```bash
P="ssh -o HostKeyAlias=192.168.25.30 -o BatchMode=yes -o ConnectTimeout=10 claude@192.168.68.237"
$P 'rm -rf ~/cmprovision/build/cmprovision && mkdir -p ~/cmprovision/build/cmprovision'
git archive v2.5.2 | $P 'tar -x -C ~/cmprovision/build/cmprovision'
$P 'cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d > ../build-2.5.2.log 2>&1; echo "build rc=$?"; ls -la ../cmprovision4_2.5.2_all.deb'
```

(таймаут Bash — 600000.)
Expected: `build rc=0` и файл `cmprovision4_2.5.2_all.deb`.

- [ ] **Step 4: Установка и холодный запас**

Записать `$SP/prov-install252.sh`:

```sh
#!/bin/sh
# Install 2.5.2 on the provisioner, only while no module is being flashed; then watch the built-in Wi-Fi go cold
set -e
[ "$(hostname)" = wlanpi-da2 ] || { echo "wrong host: $(hostname)"; exit 1; }
cd /home/claude/cmprovision/build
DEB=cmprovision4_2.5.2_all.deb
APP=/var/lib/cmprovision
DB=$APP/database/database.sqlite
q() { sudo -n -u www-data sqlite3 -readonly -cmd '.timeout 5000' $DB "$1"; }
n=$(q "select count(*) from cms where phase in ('preinstall','write','verify','postinstall') and provisioning_started_at > datetime('now','-2 hours');")
[ "$n" = 0 ] || { echo "a module is being flashed ($n): not installing"; exit 1; }
# the way back: 2.5.2 takes no snapshot, so the 2.5.1 package must be at hand
[ -f cmprovision4_2.5.1_all.deb ] || { echo "no cmprovision4_2.5.1_all.deb here to go back to: not installing"; exit 1; }
f0=$(q 'select count(*) from failed_jobs;')
echo "before: $(dpkg-query -W -f='${Version}' cmprovision4); $(nmcli -t -f DEVICE,STATE device status | grep '^wlan' | tr '\n' ' ')"
start=$(date '+%Y-%m-%d %H:%M:%S')
sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y ./$DEB > install-2.5.2.log 2>&1 || { tail -40 install-2.5.2.log; exit 1; }
dpkg-query -W -f='${Version} ${Status}\n' cmprovision4
for s in nginx php8.4-fpm cmprovision-queue cmprovision-queue-images cmprovision-dnsmasq cmprovision-rpiboot cmprovision-uplink.timer; do
    printf '%s: %s\n' "$s" "$(systemctl is-active $s || true)"
done
echo "hook: $(stat -c '%a %U' /usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink); switch: $(grep -v '^#' /etc/default/cmprovision-uplink)"
# a minute of the card holding after postinst's check, then the deferred check
waited=0
while [ $waited -lt 180 ] && nmcli -t -f DEVICE,STATE device status | grep -q '^wlan0:connected'; do sleep 10; waited=$((waited + 10)); done
echo "after ${waited} s: $(nmcli -t -f DEVICE,STATE device status | grep '^wlan' | tr '\n' ' ')"
echo "state: $(tr '\n' ' ' < /run/cmprovision/uplink-spare)"
echo "journal:"; sudo journalctl -t cmprovision-uplink --since "$start" --no-pager -o cat | sed 's/^/  /'
cd $APP && sudo -n -u www-data env HOME=/tmp php artisan tinker --execute='echo json_encode((new App\Services\NetworkStatus)->read()["spare"]), PHP_EOL;' 2>/dev/null | grep -v Deprecated
echo "failed jobs: $(q 'select count(*) from failed_jobs;') (before: $f0)"
```

Run: `$P 'sh -s' < "$SP/prov-install252.sh"`
Expected:
- `before: 2.5.1; wlan1:connected wlan0:connected`;
- `2.5.2 install ok installed`; все семь `active`; `hook: 755 root; switch: SPARE=yes`;
- `after <60–120> s: wlan1:connected wlan0:disconnected`;
- `state: state=standby … spare=wlan0 uplink=wlan1 …`;
- в журнале — `built-in Wi-Fi wlan0 off: wlan1 has carried the default route for 60 s`;
- `{"state":"standby","since":…,"device":"wlan0","lost_since":null}`;
- `failed jobs:` — как до установки.

- [ ] **Step 5: Пуш — только если шаги 3–4 прошли**

С этого компьютера: `curl -s -o /dev/null -w '%{http_code}\n' http://192.168.68.237/login` → `200` (адрес карты отвечает). Затем:

```bash
git push -q origin main stage2 v2.5.2
git status -sb | head -1
```

Expected: `## stage2...origin/stage2` без расхождений. Если шаги 3–4 не прошли — не пушить и сообщить пользователю; откат — `sudo apt install --allow-downgrades ~/cmprovision/build/cmprovision4_2.5.1_all.deb` (скрипт проверил, что пакет на месте), только по его слову.

- [ ] **Step 6: Живая проверка аварии — только по отдельному слову пользователя**

Провижинер на пару минут уходит из офисной сети; модули на `eth0` это не задевает. SSH идёт через карту, поэтому команды ставятся таймерами systemd на самом провижинере, а страховка возвращает карту через 5 минут в любом случае:

```bash
$P 'sudo systemd-run --unit=cmp-failover-card-back --on-active=300 /usr/bin/nmcli device connect wlan1 && sudo systemd-run --unit=cmp-failover-card-off --on-active=5 /usr/bin/nmcli device disconnect wlan1 && echo scheduled'
```

Expected: `scheduled`. Через 45–60 с встроенный подключился — зайти по его адресу (`192.168.68.236`; ключ хоста тот же):

```bash
Q="ssh -o HostKeyAlias=192.168.25.30 -o BatchMode=yes -o ConnectTimeout=10 claude@192.168.68.236"
for i in $(seq 1 12); do $Q true 2>/dev/null && break; sleep 10; done
$Q 'nmcli -t -f DEVICE,STATE device status | grep "^wlan"; tr "\n" " " < /run/cmprovision/uplink-spare; echo; sudo journalctl -t cmprovision-uplink -n 3 --no-pager -o cat; sudo nmcli device connect wlan1 && sudo systemctl stop cmp-failover-card-back.timer && echo "card back"'
```

Expected: `wlan0:connected`, `wlan1:disconnected`; `state=active`; в журнале `built-in Wi-Fi wlan0 on: no default route but through it for 30 s`; `card back`. Ещё через 60–90 с (сессия на `.236` оборвётся — так и должно быть):

```bash
sleep 90; $P 'nmcli -t -f DEVICE,STATE device status | grep "^wlan"; tr "\n" " " < /run/cmprovision/uplink-spare; echo; sudo journalctl -t cmprovision-uplink -n 2 --no-pager -o cat'
```

Expected: `wlan1:connected`, `wlan0:disconnected`, `state=standby`, в журнале снова `built-in Wi-Fi wlan0 off: …`.

- [ ] **Step 7: Память**

В `memory/cmprovision-fork-plan.md`: 2.5.2 выпущена и установлена на wlanpi-da2 (дата, что проверено, была ли живая проверка аварии); следующая — 2.5.3 (управление Wi-Fi из веб-интерфейса); строку в `MEMORY.md` обновить. В `memory/provisioner-trixie-setup.md`: встроенный Wi-Fi теперь в холодном запасе, адрес `.236` отвечает только при аварии карты.
