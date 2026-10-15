# Wi-Fi провижинера из веб-интерфейса (2.5.3) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** оператор в Settings видит сети Wi-Fi провижинера, добавляет и правит их (SSID, защита WPA2/WPA3/смешанная, пароль, скрытая сеть), меняет порядок и проверяет сеть на простаивающем встроенном Wi-Fi, не роняя карту; пароль сети не возвращается в браузер.

**Architecture:** root-помощник на POSIX sh `tools/wifi-networks` (пять команд, ввод `ключ=значение` на stdin, узкое правило sudo) правит keyfile-профили NetworkManager: одна сеть — один профиль без привязки к карте с `multi-connect=multiple`; пароль он сам вписывает в keyfile root, мимо аргументов процессов. Приложение читает профили от `www-data` через `nmcli` без секретов (`App\Services\WifiNetworks`) и показывает раздел `WifiSettings` в Settings; любое изменение — после подтверждения пароля учётки (Jetstream). Метрики по устройствам даёт файл `conf.d` пакета, значок подключения берёт предупреждение об аварии из состояния холодного запаса.

**Tech Stack:** POSIX sh (dash), NetworkManager 1.52 (`nmcli`, keyfile, `conf.d`), sudo, PHP 8.3+ (тесты на 8.4), Laravel 13 (раскладка Laravel 8), Livewire 3, Jetstream 5 (`ConfirmsPasswords`), Debian-пакет (debhelper 13), стенд WSL `cmprov-trixie`.

**Spec:** `docs/superpowers/specs/2026-10-15-wifi-networks-design.md`

## Global Constraints

- Сеть — keyfile-профиль: `type=wifi`, `mode=infrastructure`, `connection.id` — SSID; без `802-11-wireless.mac-address` и `connection.interface-name`; `connection.multi-connect=multiple` (в keyfile `3`); `ipv4.route-metric=-1`, `ipv6.route-metric=-1`; `connection.autoconnect-priority` по порядку — первая сеть 100, вторая 99 и так далее; `802-11-wireless.hidden=yes` у скрытой; `802-11-wireless-security.psk-flags=0`.
- Защита: WPA2-Personal — `key-mgmt=wpa-psk`, `pmf=1`; WPA2/WPA3 (mixed) — `wpa-psk`, `pmf=2`; WPA3-Personal — `sae`, `pmf=3`. В интерфейсе и на входе помощника — `wpa2`, `mixed`, `wpa3`.
- SSID — 1–32 байта UTF-8 без управляющих символов (0x00–0x1F, 0x7F); пароль — 8–63 печатных символа ASCII (0x20–0x7E), для `wpa2` и `mixed` ещё 64 шестнадцатеричные цифры; у новой сети пароль обязателен, у существующей пустой — «не менять»; смена защиты на WPA3 с сохранённым 64-значным ключом — отказ «enter a password: WPA3 does not take a 64-digit key».
- Управляемый профиль — Wi-Fi-профиль в режиме infrastructure (или без режима) с `key-mgmt` `wpa-psk` или `sae`, чей файл лежит в `/etc/NetworkManager/system-connections/`, с SSID по правилу выше; сеть — все управляемые профили с одним SSID. Остальные Wi-Fi-профили показываются серыми строками «managed outside cmprovision (<причина>)» и не меняются.
- Помощник `/usr/lib/cmprovision/wifi-networks` (root, 0755): команды `save`, `delete`, `order`, `check`, `scan`; ввод — строки `ключ=значение` на stdin (значение — всё после первого `=`); выход 0 — сделано, 1 — отказ или сбой (`error=<текст для оператора>`), 2 — неверный вызов. Правило sudo — ровно пять строк `www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks <команда>` без аргументов и без `*`.
- Пароль сети не попадает в аргументы процессов, журнал и вывод помощника и не возвращается в браузер: несекретное задаёт `nmcli`, строку `psk=` вписывает сам помощник (awk через `ENVIRON`), затем `nmcli connection load`; GKeyFile-экранирование — обратная косая черта `\\`, пробел в начале и в конце — `\s`; пароль читается обратно (`nmcli -s`) и сверяется, при расхождении keyfile возвращается как был.
- Замки: изменения по одному (`flock -w 10` на `/run/cmprovision/wifi-networks.lock`, иначе отказ «another Wi-Fi change is running: try again»); `check` держит и замок холодного запаса `/run/cmprovision/uplink-spare.lock` (`flock -w 10`, иначе `result=busy`).
- `check`: только при `state=standby` холодного запаса (иначе `result=busy` с причинами «the built-in Wi-Fi carries the traffic», «the cold spare is switching», «the cold spare is off (SPARE=no)», «no built-in Wi-Fi»); `nmcli -w 30 connection up <uuid> ifname <встроенный>`; итоги `connected`, `wrong-password`, `not-found`, `no-address`, `timeout`, `failed`; затем `nmcli -w 20 device disconnect <встроенный>` и `uplink-spare check wifi-check`.
- Журнал — `logger -t cmprovision-wifi`, без паролей: `<кто> added '<ssid>' (<WPA2|WPA2/WPA3|WPA3>[, hidden])`, `<кто> saved '<ssid>' (…[, new password])`, `<кто> took over '<ssid>' (<n> profiles into 1)`, `<кто> deleted '<ssid>'`, `<кто> ordered '<a>', '<b>'`, `<кто> checked '<ssid>' on <dev>: <итог>`, `<кто>: <команда> refused: <причина>`.
- Метрики по устройствам — `/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf`: `match-device=driver:brcmfmac` — 600, `match-device=type:wifi` — 100; `postinst` — `nmcli general reload conf`.
- Раздел «Wi-Fi networks» в Settings сразу после DHCP; Save, Save and check, Delete, смена порядка и Check now — через `<x-confirms-password>` (Jetstream, `auth.password_timeout` = 3 часа); других компонентов с `<x-confirms-password>` на странице Settings нет — его окно выводится `@once`, одно на страницу.
- Пароль сети — не свойство Livewire: поле живёт в Alpine (`x-data="{ secret: '' }"`, `x-model="secret"`) и уходит параметром действия `save(secret)` / `saveAndCheck(secret)`; ни снимок компонента, ни HTML его не содержат. Это отступление от спеки (там свойство чистится в `dehydrate()`): подтверждение пароля учётки делает лишний запрос ещё до Save и стёрло бы свойство — обоснование в задаче 5.
- Скрипты — POSIX sh без bashisms (Debian `/bin/sh` — dash), LF. Код, строки интерфейса и комментарии — по-английски в стиле соседнего кода; проза документов — как в них.
- Версия `2.5.3~dev1` для стенда, `2.5.3` при выпуске.
- Коммиты кончаются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; выпуск (слияние, тег, установка на wlanpi-da2, пуш) и живые проверки на wlanpi-da2 — только по слову пользователя.

## Review Focus

1. SSID с двоеточием, пробелами и кириллицей («Офис: 2 этаж»): `nmcli` в списках экранирует `:` в имени файла, сравнение и отказ «already in the list» должны работать с таким именем (`test_an_ssid_with_a_colon_spaces_and_cyrillic_is_kept_as_it_is`, задача 1; `test_an_ssid_with_a_colon_in_the_file_name`, задача 4).
2. Пароль с пробелами по краям и обратной косой чертой: GKeyFile их иначе потеряет; пароль, прочитанный NetworkManager, должен совпасть с введённым (`test_spaces_at_the_ends_and_backslashes_survive_the_keyfile`, задача 1).
3. Профиль `multi-connect`, поднятый на обеих картах (60 с перекрытия при возврате карты): `nmcli` показывает его дважды — сеть одна, её устройства оба (`test_a_profile_active_on_both_radios_is_one_network`, задачи 2 и 4).
4. Помощник не запустился (sudo без правила, `NetworkManager` не отвечает, таймаут): раздел показывает понятную причину, а не падает (`test_a_helper_that_cannot_run_is_reported`, задача 4; `test_after_an_error_the_password_is_not_sent_back_either`, задача 5).
5. Перевод строки в значении (пароль `x\nuuid=…`) подменил бы ключи ввода помощника: такая строка до помощника не доходит (`test_a_line_break_never_reaches_the_helper`, задача 4), а сам помощник на повтор ключа отвечает отказом (`refusals`, задача 1).

---

## Карта файлов

- `tools/wifi-networks` (новый) — root-помощник; `tools/90-cmprovision-uplink.conf` (новый) — метрики по устройствам для NetworkManager.
- `tests/Support/fake-nmcli.php` (новый) — NetworkManager для теста помощника: профили — keyfile в каталоге теста.
- `tests/Feature/WifiNetworksTest.php` (новый) — помощник под `sh`.
- `app/Services/WifiNetworks.php` (новый) — чтение профилей и вызовы помощника; `tests/Unit/WifiNetworksServiceTest.php` (новый).
- `app/Services/NetworkStatus.php` — `spare()` становится публичным.
- `app/Http/Livewire/WifiSettings.php`, `resources/views/livewire/wifi-settings.blade.php` (новые) — раздел; `resources/views/livewire/settings.blade.php` — секция; `tests/Support/FakeWifiNetworks.php`, `tests/Feature/WifiSettingsTest.php` (новые).
- `app/Support/UplinkSummary.php`, `resources/views/livewire/uplink-indicator.blade.php` — предупреждение об аварии и ссылка; `tests/Unit/UplinkSummaryTest.php`, `tests/Feature/UplinkIndicatorTest.php`.
- `debian/rules`, `debian/010_cmprovision`, `debian/postinst`, `debian/changelog`; `tests/Unit/PackagingTest.php`.
- `README.md`, `CLAUDE.md`, `docs/provisioner-network.md`, `ROADMAP.md`.

## Команды

`SP` — черновик сессии: `C:/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad`. Тесты — на стенде WSL (PHP 8.4): `bash "$SP/wsl-bench/tests-ok.sh" <фильтр>` синхронизирует рабочее дерево в `/root/dev` и запускает phpunit (последние строки — `phpunit exit N` и итог phpunit; полный вывод — `/tmp/phpunit-filter.log` на стенде); весь набор — `bash "$SP/wsl-bench/suite-ok.sh"` (PHP и `npm run test:js`). Стенд засыпает без открытой сессии: перед работой — фоновый `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 14400` (Bash с `run_in_background`). Команды внутри стенда — скриптом через stdin: `cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s` (в аргументах `wsl.exe` переменные раскрываются раньше времени). Правки документов — Python-скриптом: файл пишется через Write в `$SP`, запускается `python -I <скрипт> "$PWD"`. Один heredoc на команду; сообщение коммита с двумя heredoc'ами в одной команде путает их тела — пишите его в файл и `git commit -F <файл>`.

---

### Task 1: Помощник — ввод, проверка и новая сеть

**Files:**
- Create: `tests/Support/fake-nmcli.php`
- Create: `tests/Feature/WifiNetworksTest.php`
- Create: `tools/wifi-networks`

**Interfaces:**
- Consumes: — (первая задача).
- Produces: `tools/wifi-networks save` для новой сети (ввод `ssid`, `security`, `hidden`, `password`, `by`; вывод `uuid=<uuid>`); переменные окружения для тестов `WIFI_NETWORKS_KEYFILES`, `WIFI_NETWORKS_RUN`, `WIFI_NETWORKS_SYS`, `WIFI_NETWORKS_SPARE`; функции помощника `log`, `fail`, `usage`, `read_input <ключи…>` (значения в `in_<ключ>`, у `order` — `uuid` в `$order`), `user` (`$by`), `is_uuid`, `valid_ssid`, `valid_password <пароль> <защита>`, `key_mgmt`, `pmf`, `label`, `described <защита> <yes|no>`, `prop <uuid> <свойство> [-s]`, `unescape`, `managed` (строки `uuid<TAB>ssid`), `listed <ssid>` (по `$TMP/managed`), `keyfile <uuid>`, `set_password <uuid> <пароль>`, `save_new`, `cmd_save`. Тест: помощники `stub`, `spare`, `profile`, `pair`, `runHelper`, `keyfile`, `changes`, `calls`, `journal`, `result`, `forget`; константы `M2`, `ONBOARD`, `SECRET`, `CARD`.

- [ ] **Step 1: NetworkManager для теста**

Создать `tests/Support/fake-nmcli.php`:

```php
<?php
/*
 * nmcli for WifiNetworksTest, which runs tools/wifi-networks under sh. NetworkManager's profiles are keyfiles in
 * $FAKE/keyfiles (where the helper manages) and $FAKE/elsewhere (netplan's /run, say); active connections are lines
 * "uuid:device" in $FAKE/active. Every call is appended to $FAKE/calls. $FAKE/up says how "connection up" ends
 * ("connected 192.168.68.236/24", "connected" without an address, "wrong-password", "not-found", "no-address",
 * "timeout", "failed"); $FAKE/scan is what "device wifi list" prints; $FAKE/nm-gone makes every call fail;
 * $FAKE/mangle-psk makes the password read back differ. Values are printed as nmcli's terse detail view prints them.
 */

const PROPERTIES = [
    'connection.id' => ['connection', 'id'], 'con-name' => ['connection', 'id'],
    'connection.uuid' => ['connection', 'uuid'],
    'connection.interface-name' => ['connection', 'interface-name'],
    'connection.autoconnect-priority' => ['connection', 'autoconnect-priority'],
    'connection.multi-connect' => ['connection', 'multi-connect'],
    '802-11-wireless.ssid' => ['wifi', 'ssid'], 'ssid' => ['wifi', 'ssid'],
    '802-11-wireless.mode' => ['wifi', 'mode'],
    '802-11-wireless.hidden' => ['wifi', 'hidden'],
    '802-11-wireless.mac-address' => ['wifi', 'mac-address'],
    '802-11-wireless-security.key-mgmt' => ['wifi-security', 'key-mgmt'], 'wifi-sec.key-mgmt' => ['wifi-security', 'key-mgmt'],
    '802-11-wireless-security.pmf' => ['wifi-security', 'pmf'], 'wifi-sec.pmf' => ['wifi-security', 'pmf'],
    '802-11-wireless-security.psk-flags' => ['wifi-security', 'psk-flags'], 'wifi-sec.psk-flags' => ['wifi-security', 'psk-flags'],
    '802-11-wireless-security.psk' => ['wifi-security', 'psk'],
    'ipv4.route-metric' => ['ipv4', 'route-metric'], 'ipv6.route-metric' => ['ipv6', 'route-metric'],
];

function read_keyfile(string $file): array
{
    $data = [];
    $section = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line)
    {
        if (preg_match('/^\[(.+)\]$/', $line, $m))
            $data[$section = $m[1]] = $data[$m[1]] ?? [];
        elseif (($at = strpos($line, '=')) !== false)
            $data[$section][substr($line, 0, $at)] = substr($line, $at + 1);
    }
    return $data;
}

function write_keyfile(string $file, array $data): void
{
    $text = '';
    foreach ($data as $section => $values)
    {
        $text .= "[$section]\n";
        foreach ($values as $key => $value)
            $text .= "$key=$value\n";
        $text .= "\n";
    }
    file_put_contents($file, $text);
}

/* uuid => [file, data] of every keyfile */
function profiles(string $fake): array
{
    $profiles = [];
    foreach (['keyfiles', 'elsewhere'] as $dir)
        foreach (glob("$fake/$dir/*.nmconnection") ?: [] as $file)
        {
            $data = read_keyfile($file);
            $profiles[$data['connection']['uuid']] = ['file' => $file, 'data' => $data];
        }
    return $profiles;
}

/* GKeyFile's escapes, as NetworkManager reads a value */
function unescape_value(string $value): string
{
    return preg_replace_callback('/\x5c(.)/', function ($m) {
        return ['s' => ' ', 'n' => "\n", 't' => "\t", 'r' => "\r"][$m[1]] ?? $m[1];
    }, $value);
}

/* nmcli's terse lists escape ':' and the backslash */
function escape_field(string $value): string
{
    return str_replace([chr(92), ':'], [chr(92).chr(92), chr(92).':'], $value);
}

/* a property as nmcli's terse detail view prints it */
function show(array $data, string $property, bool $secrets): string
{
    [$section, $key] = PROPERTIES[$property];
    $value = $data[$section][$key] ?? null;
    switch ($property)
    {
        case '802-11-wireless.hidden':
            return $value === 'true' ? 'yes' : 'no';
        case 'connection.multi-connect':
        case '802-11-wireless-security.pmf':
        case 'connection.autoconnect-priority':
            return $value ?? '0';
        case 'ipv4.route-metric':
        case 'ipv6.route-metric':
            return $value ?? '-1';
        case '802-11-wireless-security.psk':
            if ($value === null)
                return '';
            return $secrets ? unescape_value($value) : '<hidden>';
    }
    return $value ?? '';
}

/* a value given to nmcli, into the keyfile as NetworkManager writes it */
function store(array &$data, string $property, string $value): void
{
    if (!isset(PROPERTIES[$property]))
    {
        fwrite(STDERR, "Error: invalid property '$property'.\n");
        exit(2);
    }
    [$section, $key] = PROPERTIES[$property];
    if ($property === '802-11-wireless.hidden')
        $value = $value === 'yes' ? 'true' : 'false';
    if ($property === 'connection.multi-connect' && $value === 'multiple')
        $value = '3';
    if ($value === '' || (substr($property, -13) === '.route-metric' && $value === '-1'))
        unset($data[$section][$key]);
    else
        $data[$section][$key] = $value;
}

function save_active(string $fake, array $lines): void
{
    file_put_contents("$fake/active", implode('', array_map(function ($line) { return "$line\n"; }, $lines)));
}

$fake = getenv('FAKE');
$args = array_slice($argv, 1);
file_put_contents("$fake/calls", 'nmcli '.implode(' ', $args)."\n", FILE_APPEND);
if (file_exists("$fake/nm-gone"))
    exit(8);

$secrets = false;
$fields = [];
while ($args && $args[0] !== '' && $args[0][0] === '-')
{
    $option = array_shift($args);
    if ($option === '-s')
        $secrets = true;
    elseif ($option === '-f' || $option === '-g')
        $fields = explode(',', array_shift($args));
    elseif ($option === '-w')
        array_shift($args);
}
$active = is_file("$fake/active") ? file("$fake/active", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$profiles = profiles($fake);
$what = ($args[0] ?? '').' '.($args[1] ?? '');

if (($args[0] ?? '') === 'general')
{
    echo "running\n";
    exit(0);
}

if ($what === 'connection show' && (count($args) === 2 || $args[2] === '--active'))
{
    $rows = [];
    if (count($args) === 3)
        foreach ($active as $line)
        {
            [$uuid, $device] = explode(':', $line, 2);
            $rows[] = ['UUID' => $uuid, 'DEVICE' => $device];
        }
    else
        foreach ($profiles as $uuid => $p)
        {
            $devices = [];
            foreach ($active as $line)
            {
                [$u, $device] = explode(':', $line, 2);
                if ($u === $uuid)
                    $devices[] = $device;
            }
            $type = ($p['data']['connection']['type'] ?? '') === 'wifi' ? '802-11-wireless' : '802-3-ethernet';
            // a profile active on two devices is listed twice, as nmcli does
            foreach ($devices ?: [''] as $device)
                $rows[] = ['UUID' => $uuid, 'TYPE' => $type, 'ACTIVE' => $device === '' ? 'no' : 'yes', 'DEVICE' => $device, 'FILENAME' => $p['file']];
        }
    foreach ($rows as $row)
        echo implode(':', array_map(function ($field) use ($row) { return escape_field($row[$field] ?? ''); }, $fields)), "\n";
    exit(0);
}

if ($what === 'connection show')
{
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
    {
        fwrite(STDERR, "Error: {$args[2]} - no such connection profile.\n");
        exit(10);
    }
    foreach ($fields as $property)
    {
        $value = show($p['data'], $property, $secrets);
        if ($property === '802-11-wireless-security.psk' && $secrets && file_exists("$fake/mangle-psk"))
            $value .= 'x';
        echo $property, ':', $value, "\n";
    }
    exit(0);
}

if ($what === 'connection add')
{
    $data = ['connection' => ['id' => '', 'uuid' => '', 'type' => 'wifi'], 'wifi' => [], 'wifi-security' => [],
             'ipv4' => ['method' => 'auto'], 'ipv6' => ['method' => 'auto']];
    $pairs = array_slice($args, 2);
    for ($i = 0; $i + 1 < count($pairs); $i += 2)
        if ($pairs[$i] !== 'type')
            store($data, $pairs[$i], $pairs[$i + 1]);
    if ($data['connection']['uuid'] === '')
        $data['connection']['uuid'] = '00000000-0000-4000-8000-'.substr(md5(microtime()), 0, 12);
    write_keyfile("$fake/keyfiles/".str_replace('/', '_', $data['connection']['id']).'.nmconnection', $data);
    echo "Connection '{$data['connection']['id']}' ({$data['connection']['uuid']}) successfully added.\n";
    exit(0);
}

if ($what === 'connection modify')
{
    if (file_exists("$fake/fail-modify"))
    {
        fwrite(STDERR, "Error: Failed to modify connection: refused.\n");
        exit(4);
    }
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
        exit(10);
    $data = $p['data'];
    $pairs = array_slice($args, 3);
    for ($i = 0; $i + 1 < count($pairs); $i += 2)
        store($data, $pairs[$i], $pairs[$i + 1]);
    write_keyfile($p['file'], $data);
    exit(0);
}

if ($what === 'connection delete')
{
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
        exit(10);
    unlink($p['file']);
    save_active($fake, array_filter($active, function ($line) use ($args) { return strpos($line, $args[2].':') !== 0; }));
    exit(0);
}

if ($what === 'connection load')
    exit(0);

if ($what === 'connection up')
{
    $uuid = $args[2];
    $device = $args[4] ?? '';
    $outcome = is_file("$fake/up") ? trim(file_get_contents("$fake/up")) : 'connected 192.168.68.236/24';
    [$result, $address] = array_pad(explode(' ', $outcome, 2), 2, '');
    $errors = [
        'wrong-password' => 'Error: Connection activation failed: Secrets were required, but not provided.',
        'not-found' => 'Error: Connection activation failed: The Wi-Fi network could not be found.',
        'no-address' => 'Error: Connection activation failed: IP configuration could not be reserved (no available address, timeout, etc.).',
        'timeout' => 'Error: Timeout expired (30 seconds)',
        'failed' => 'Error: Connection activation failed: The device could not be readied for configuration.',
    ];
    if (isset($errors[$result]))
    {
        fwrite(STDERR, $errors[$result]."\n");
        exit(4);
    }
    save_active($fake, array_merge($active, ["$uuid:$device"]));
    file_put_contents("$fake/address-$device", $address);
    echo "Connection successfully activated (D-Bus active path: /org/freedesktop/NetworkManager/ActiveConnection/9)\n";
    exit(0);
}

if ($what === 'device show')
{
    echo is_file("$fake/address-{$args[2]}") ? file_get_contents("$fake/address-{$args[2]}") : '', "\n";
    exit(0);
}

if ($what === 'device disconnect')
{
    $device = $args[2];
    save_active($fake, array_filter($active, function ($line) use ($device) { return substr($line, -strlen(":$device")) !== ":$device"; }));
    @unlink("$fake/address-$device");
    exit(0);
}

if ($what === 'device wifi')
{
    echo is_file("$fake/scan") ? file_get_contents("$fake/scan") : '';
    exit(0);
}

fwrite(STDERR, 'fake nmcli does not know: '.implode(' ', $args)."\n");
exit(2);
```

- [ ] **Step 2: Падающие тесты**

Создать `tests/Feature/WifiNetworksTest.php`:

```php
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
    protected function result(): array
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

        $k = $this->keyfile($this->result()['uuid']);
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

        $this->assertSame('true', $this->keyfile($this->result()['uuid'])['wifi']['hidden']);
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
        $this->assertSame('\\sa\\\\b c\\s\\s', $this->keyfile($this->result()['uuid'])['wifi-security']['psk']);
    }

    public function test_a_new_network_goes_to_the_end_of_the_order()
    {
        $this->pair();   // priorities 20 and 10

        $this->runHelper('save', ['ssid=phone', 'security=wpa3', 'hidden=no', 'password=phone-pass', 'by=anna']);

        $this->assertSame('9', $this->keyfile($this->result()['uuid'])['connection']['autoconnect-priority']);
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
        $this->assertSame($ssid, $this->keyfile($this->result()['uuid'])['wifi']['ssid']);

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
```

- [ ] **Step 3: Убедиться, что тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 1`; падают все 26 (помощника нет: в выводе `sh: 0: cannot open …/tools/wifi-networks`, коды выхода 2 вместо 0 или 1).

- [ ] **Step 4: Помощник**

Создать `tools/wifi-networks`:

```sh
#!/bin/sh
# The provisioner's Wi-Fi networks from the web UI (cmprovision 2.5.3, docs/superpowers/specs/2026-10-15-wifi-networks-design.md).
# www-data runs it through sudo (debian/010_cmprovision: the command and no arguments); the rest comes on stdin as
# key=value lines and is checked here, whatever the web app checked before.
#   wifi-networks save     ssid security hidden password by   ->  uuid=<uuid>
# A managed profile is a Wi-Fi profile in infrastructure mode with WPA2/WPA3-Personal whose keyfile lies in $KEYFILES;
# a network is every managed profile with one SSID. A password never becomes an argument of a process, a journal line
# or output: it goes straight into the root-only keyfile.
# Exit codes: 0 done, 1 refused or failed (error=<the reason for the operator>), 2 a wrong call.
# Tests point it elsewhere with WIFI_NETWORKS_KEYFILES, WIFI_NETWORKS_RUN, WIFI_NETWORKS_SYS and WIFI_NETWORKS_SPARE.

KEYFILES=${WIFI_NETWORKS_KEYFILES:-/etc/NetworkManager/system-connections}
RUN=${WIFI_NETWORKS_RUN:-/run/cmprovision}
SYS=${WIFI_NETWORKS_SYS:-/sys/class/net}
SPARE=${WIFI_NETWORKS_SPARE:-/usr/lib/cmprovision/uplink-spare}
# nmcli untranslated, text tools on UTF-8
LC_ALL=C.UTF-8
export LC_ALL
umask 077

command=${1:-}
by=unknown
order=

log() {
    logger -t cmprovision-wifi "$1" 2>/dev/null || true
}

# a refusal: the reason to the journal and to the web app
fail() {
    log "$by: $command refused: $1"
    printf 'error=%s\n' "$1"
    exit 1
}

usage() {
    echo "usage: wifi-networks save, the input on stdin" >&2
    exit 2
}

# the input: key=value lines with the keys the command takes, each once (order takes uuid once per network), as in_<key>
read_input() {
    allowed=" $* "
    while IFS= read -r line || [ -n "$line" ]; do
        key=${line%%=*}
        [ "$key" != "$line" ] || fail "a line of the input has no '='"
        case "$allowed" in *" $key "*) ;; *) fail "unknown input: $key" ;; esac
        if [ "$key" = uuid ] && [ "$command" = order ]; then
            order="$order ${line#*=}"
            continue
        fi
        eval "[ -z \"\${seen_$key:-}\" ]" || fail "$key given twice"
        eval "seen_$key=1 in_$key=\${line#*=}"
    done
}

# the user named in the journal: control characters dropped, at most 64 bytes
user() {
    by=$(printf %s "${in_by:-}" | tr -d '\000-\037\177' | head -c 64)
    [ -n "$by" ] || by=unknown
}

is_uuid() {
    printf %s "$1" | grep -Eqx '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
}

# 1-32 bytes of UTF-8 without control characters
valid_ssid() {
    n=$(printf %s "$1" | wc -c)
    [ "$n" -ge 1 ] && [ "$n" -le 32 ] || return 1
    printf %s "$1" | iconv -f UTF-8 -t UTF-8 >/dev/null 2>&1 || return 1
    ! printf %s "$1" | LC_ALL=C grep -q '[[:cntrl:]]'
}

# 8-63 printable ASCII characters, or 64 hexadecimal digits (a ready key) but for WPA3
valid_password() {   # password security
    case "$1" in
        *[!0-9a-fA-F]*) ;;
        *) if [ ${#1} -eq 64 ]; then [ "$2" != wpa3 ]; return; fi ;;
    esac
    [ ${#1} -ge 8 ] && [ ${#1} -le 63 ] || return 1
    ! printf %s "$1" | LC_ALL=C grep -q '[^ -~]'
}

key_mgmt() { case "$1" in wpa3) echo sae ;; *) echo wpa-psk ;; esac; }
pmf() { case "$1" in wpa2) echo 1 ;; mixed) echo 2 ;; *) echo 3 ;; esac; }
label() { case "$1" in wpa2) echo WPA2 ;; mixed) echo WPA2/WPA3 ;; *) echo WPA3 ;; esac; }

# "WPA2/WPA3, hidden" for the journal
described() {   # security hidden
    printf %s "$(label "$1")"
    [ "$2" = no ] || printf ', hidden'
}

# a property of a profile from nmcli's terse detail view (name:value, not escaped); $3 is -s for a secret
prop() {   # uuid property [-s]
    nmcli $3 -t -f "$2" connection show "$1" 2>/dev/null | sed -n "s/^$2://p" | head -n 1
}

# nmcli's terse lists escape ':' and '\' with a backslash
unescape() {
    awk '{ out = ""; n = length($0)
           for (i = 1; i <= n; i++) { c = substr($0, i, 1); if (c == "\\" && i < n) { i++; c = substr($0, i, 1) } out = out c }
           print out }'
}

# the managed profiles as "uuid<TAB>ssid" lines; nmcli lists a profile active on two devices twice
managed() {
    nmcli -t -f UUID,TYPE,FILENAME connection show 2>/dev/null | while IFS= read -r line; do
        uuid=${line%%:*}
        rest=${line#*:}
        [ "${rest%%:*}" = 802-11-wireless ] || continue
        file=$(printf '%s\n' "${rest#*:}" | unescape)
        case "$file" in "$KEYFILES"/*) ;; *) continue ;; esac
        case "$(prop "$uuid" 802-11-wireless.mode)" in ''|infrastructure) ;; *) continue ;; esac
        case "$(prop "$uuid" 802-11-wireless-security.key-mgmt)" in wpa-psk|sae) ;; *) continue ;; esac
        ssid=$(prop "$uuid" 802-11-wireless.ssid)
        valid_ssid "$ssid" && printf '%s\t%s\n' "$uuid" "$ssid"
    done | awk -F '\t' '!seen[$1]++'
}

# true when a managed profile of $TMP/managed has this SSID
listed() {   # ssid
    S=$1 awk -F '\t' '$2 == ENVIRON["S"] { found = 1 } END { exit !found }' "$TMP/managed"
}

# the keyfile of a profile
keyfile() {   # uuid
    nmcli -t -f UUID,FILENAME connection show 2>/dev/null | sed -n "s/^$1://p" | head -n 1 | unescape
}

# the password into the profile's keyfile (root, 0600), which NetworkManager then reads again; read back, it must be
# the same, or the keyfile goes back as it was. GKeyFile escapes a backslash, and a space at either end as \s
set_password() {   # uuid password
    file=$(keyfile "$1")
    [ -n "$file" ] && [ -f "$file" ] || return 1
    cp -p "$file" "$TMP/keyfile" || return 1
    PSK=$2 awk '
        function esc(s,   n, lead, trail, i, c, r) {
            n = length(s)
            lead = 0; while (lead < n && substr(s, lead + 1, 1) == " ") lead++
            trail = 0; while (trail < n - lead && substr(s, n - trail, 1) == " ") trail++
            r = ""
            for (i = 1; i <= n; i++) {
                c = substr(s, i, 1)
                if (c == "\\") r = r "\\\\"
                else if (c == " " && (i <= lead || i > n - trail)) r = r "\\s"
                else r = r c
            }
            return r
        }
        BEGIN { psk = "psk=" esc(ENVIRON["PSK"]) }
        /^\[/ { if (section && !done) { print psk; done = 1 } section = ($0 == "[wifi-security]") }
        section && /^psk=/ { if (!done) { print psk; done = 1 } next }
        { print }
        END { if (!done) { if (!section) { print ""; print "[wifi-security]" } print psk } }
    ' "$TMP/keyfile" > "$file.new" && mv -f "$file.new" "$file" || return 1
    nmcli connection load "$file" >/dev/null 2>&1
    [ "$(prop "$1" 802-11-wireless-security.psk -s)" = "$2" ] && return 0
    cp -p "$TMP/keyfile" "$file"
    nmcli connection load "$file" >/dev/null 2>&1
    return 1
}

save_new() {
    managed > "$TMP/managed"
    ! listed "$in_ssid" || fail "'$in_ssid' is already in the list: edit it"
    [ -n "$password" ] || fail "enter the password of the new network"
    uuid=$(cat /proc/sys/kernel/random/uuid)
    lowest=$(cut -f 1 "$TMP/managed" | while IFS= read -r u; do prop "$u" connection.autoconnect-priority; done | sort -n | head -n 1)
    priority=$((${lowest:-101} - 1))
    [ "$priority" -ge -999 ] || priority=-999
    nmcli connection add type wifi connection.uuid "$uuid" con-name "$in_ssid" ssid "$in_ssid" \
        802-11-wireless.mode infrastructure 802-11-wireless.hidden "$hidden" \
        wifi-sec.key-mgmt "$(key_mgmt "$in_security")" wifi-sec.pmf "$(pmf "$in_security")" wifi-sec.psk-flags 0 \
        connection.multi-connect multiple connection.autoconnect-priority "$priority" \
        ipv4.route-metric -1 ipv6.route-metric -1 >/dev/null 2>"$TMP/err" \
        || fail "NetworkManager did not add the network: $(head -n 1 "$TMP/err")"
    if ! set_password "$uuid" "$password"; then
        nmcli connection delete "$uuid" >/dev/null 2>&1
        fail "the password could not be stored: nothing was added"
    fi
    log "$by added '$in_ssid' ($(described "$in_security" "$hidden"))"
    printf 'uuid=%s\n' "$uuid"
}

cmd_save() {
    read_input ssid security hidden password by
    user
    valid_ssid "${in_ssid:-}" || fail "the network name (SSID) must be 1 to 32 bytes of text without control characters"
    case "${in_security:-}" in wpa2|mixed|wpa3) ;; *) fail "security must be wpa2, mixed or wpa3" ;; esac
    hidden=${in_hidden:-no}
    case "$hidden" in yes|no) ;; *) fail "hidden must be yes or no" ;; esac
    password=${in_password:-}
    if [ -n "$password" ] && ! valid_password "$password" "$in_security"; then
        [ "$in_security" != wpa3 ] || fail "the password must be 8 to 63 printable ASCII characters"
        fail "the password must be 8 to 63 printable ASCII characters or 64 hexadecimal digits"
    fi
    save_new
}

# the command
case "$command" in
    save) ;;
    *) usage ;;
esac
[ $# -eq 1 ] || usage
nmcli -t -f RUNNING general 2>/dev/null | grep -qx running || fail "NetworkManager does not answer"
[ -d "$RUN" ] || (umask 022; mkdir -p "$RUN")
TMP=$(mktemp -d "$RUN/wifi-networks.XXXXXX") || fail "no room for a temporary file in $RUN"
trap 'rm -rf "$TMP"' EXIT
exec 9>"$RUN/wifi-networks.lock"
flock -w 10 9 || fail "another Wi-Fi change is running: try again"
"cmd_$command"
exit 0
```

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 0`, `OK (26 tests, …)`.

- [ ] **Step 6: Коммит**

Сообщение — в файл `$SP/commit-253-t1.txt` (через Write):

```
The Wi-Fi helper: a new network as one profile for both cards

tools/wifi-networks save checks its stdin input, adds a NetworkManager
profile without a card binding (multi-connect, metric by device, at the
end of the order) and writes the password itself into the root-only
keyfile, reading it back through NetworkManager.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add tools/wifi-networks tests/Support/fake-nmcli.php tests/Feature/WifiNetworksTest.php
git commit -q -F "$SP/commit-253-t1.txt"
git show --stat --oneline HEAD | head -5
```

---
### Task 2: Помощник — правка, переход пары «78», удаление и порядок

**Files:**
- Modify: `tools/wifi-networks`
- Modify: `tests/Feature/WifiNetworksTest.php`

**Interfaces:**
- Consumes: из задачи 1 — функции помощника (`read_input`, `user`, `is_uuid`, `prop`, `managed`, `listed`, `set_password`, `key_mgmt`, `pmf`, `described`, `log`, `fail`) и помощники теста (`pair`, `profile`, `runHelper`, `keyfile`, `changes`, `journal`, `result`, `forget`).
- Produces: `save` с `uuid` (правка, переход пары на один профиль), `delete`, `order`; функции `network_of <uuid>` (`$TMP/network`, `$ssid`), `route_device`, `active_on <uuid>`, `kept`, `save_existing`, `cmd_delete`, `cmd_order`; помощник теста `network(string $ssid = 'office'): string`.

- [ ] **Step 1: Падающие тесты**

Добавить в конец класса `WifiNetworksTest` (перед закрывающей `}`):

```php
    /** a new network through the helper, its UUID; the calls and journal lines of adding it are forgotten */
    protected function network(string $ssid = 'office'): string
    {
        $this->runHelper('save', ["ssid=$ssid", 'security=mixed', 'hidden=no', 'password=office-pass', 'by=anna']);
        $uuid = $this->result()['uuid'];
        $this->forget();

        return $uuid;
    }

    public function test_saving_the_78_pair_takes_it_over_without_touching_the_card()
    {
        $this->pair();

        $this->assertSame(0, $this->runHelper('save', ['uuid='.self::ONBOARD, 'ssid=78', 'security=mixed', 'hidden=no', 'by=anna']), $this->output);

        $this->assertSame(['uuid' => self::M2], $this->result(), 'the profile the card is using stays');
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
```

- [ ] **Step 2: Убедиться, что новые тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 1`; падают 16 новых (у `save` нет ключа `uuid` — `error=unknown input: uuid`; `delete` и `order` — неверный вызов, код 2), 26 тестов задачи 1 проходят.

- [ ] **Step 3: Правка, переход, удаление, порядок**

В `tools/wifi-networks`:

1. Строку шапки

```sh
#   wifi-networks save     ssid security hidden password by   ->  uuid=<uuid>
```

заменить на

```sh
#   wifi-networks save     uuid (none: a new network) ssid security hidden password by   ->  uuid=<uuid>
#   wifi-networks delete   uuid by
#   wifi-networks order    uuid (a line per network, the preferred first) by
```

2. В `usage` строку `    echo "usage: wifi-networks save, the input on stdin" >&2` заменить на `    echo "usage: wifi-networks save|delete|order, the input on stdin" >&2`.

3. Перед строкой `save_new() {` вставить:

```sh
# the network of a managed profile: its UUIDs into $TMP/network, its SSID into $ssid; false when it is not managed
network_of() {   # uuid
    managed > "$TMP/managed"
    ssid=$(U=$1 awk -F '\t' '$1 == ENVIRON["U"] { print $2; exit }' "$TMP/managed")
    [ -n "$ssid" ] || return 1
    S=$ssid awk -F '\t' '$2 == ENVIRON["S"] { print $1 }' "$TMP/managed" | sort > "$TMP/network"
}

# the device of the IPv4 default route with the lowest metric
route_device() {
    ip -4 route show default 2>/dev/null | awk '
        { dev = ""; metric = 0
          for (i = 1; i < NF; i++) { if ($i == "dev") dev = $(i + 1); if ($i == "metric") metric = $(i + 1) + 0 }
          if (dev != "" && (best == "" || metric < low)) { best = dev; low = metric } }
        END { print best }'
}

# the devices a profile is active on, one per line
active_on() {   # uuid
    nmcli -t -f UUID,DEVICE connection show --active 2>/dev/null | sed -n "s/^$1://p"
}

# the profile of $TMP/network that stays: active where the default route goes, else any active one, else the first
kept() {
    route=$(route_device)
    any=
    while IFS= read -r u; do
        devices=$(active_on "$u")
        if [ -n "$route" ] && printf '%s\n' "$devices" | grep -qx "$route"; then
            echo "$u"
            return
        fi
        [ -z "$devices" ] || [ -n "$any" ] || any=$u
    done < "$TMP/network"
    if [ -n "$any" ]; then echo "$any"; else head -n 1 "$TMP/network"; fi
}

# an edited network: its profiles become one, edited in place (an active connection keeps its old settings until it
# connects again), and the others of the network go
save_existing() {
    is_uuid "$in_uuid" || fail "not a network ID: $in_uuid"
    network_of "$in_uuid" || fail "this is not a network managed here: reload the page"
    old=$ssid
    [ "$in_ssid" = "$old" ] || ! listed "$in_ssid" || fail "'$in_ssid' is already in the list: edit it"
    keep=$(kept)
    if [ -z "$password" ]; then
        stored=$(prop "$keep" 802-11-wireless-security.psk -s)
        [ -n "$stored" ] || fail "enter the password: '$old' has none stored"
        if [ "$in_security" = wpa3 ] && [ ${#stored} -eq 64 ]; then
            case "$stored" in *[!0-9a-fA-F]*) ;; *) fail "enter a password: WPA3 does not take a 64-digit key" ;; esac
        fi
    fi
    nmcli connection modify "$keep" connection.id "$in_ssid" 802-11-wireless.ssid "$in_ssid" \
        802-11-wireless.mode infrastructure 802-11-wireless.hidden "$hidden" \
        802-11-wireless.mac-address "" connection.interface-name "" \
        wifi-sec.key-mgmt "$(key_mgmt "$in_security")" wifi-sec.pmf "$(pmf "$in_security")" wifi-sec.psk-flags 0 \
        connection.multi-connect multiple ipv4.route-metric -1 ipv6.route-metric -1 >/dev/null 2>"$TMP/err" \
        || fail "NetworkManager did not change '$old': $(head -n 1 "$TMP/err")"
    if [ -n "$password" ] && ! set_password "$keep" "$password"; then
        fail "'$in_ssid' is saved, its new password is not: try again"
    fi
    count=$(wc -l < "$TMP/network")
    for u in $(grep -vx "$keep" "$TMP/network"); do
        nmcli connection delete "$u" >/dev/null 2>&1 || fail "'$in_ssid' is saved, but its old profile $u stayed: save it again"
    done
    [ "$count" -le 1 ] || log "$by took over '$in_ssid' ($count profiles into 1)"
    log "$by saved '$in_ssid' ($(described "$in_security" "$hidden")$([ -z "$password" ] || printf ', new password'))"
    printf 'uuid=%s\n' "$keep"
}

cmd_delete() {
    read_input uuid by
    user
    is_uuid "${in_uuid:-}" || fail "not a network ID: ${in_uuid:-}"
    network_of "$in_uuid" || fail "this is not a network managed here: reload the page"
    route=$(route_device)
    if [ -n "$route" ]; then
        while IFS= read -r u; do
            ! active_on "$u" | grep -qx "$route" || fail "'$ssid' is in use: connect another network first"
        done < "$TMP/network"
    fi
    while IFS= read -r u; do
        nmcli connection delete "$u" >/dev/null 2>&1 || fail "NetworkManager did not delete '$ssid'"
    done < "$TMP/network"
    log "$by deleted '$ssid'"
}

# the whole order, a UUID per network, the preferred first: every profile of a network gets its priority
cmd_order() {
    read_input uuid by
    user
    managed > "$TMP/managed"
    : > "$TMP/given"
    names=
    for u in $order; do
        is_uuid "$u" || fail "not a network ID: $u"
        s=$(U=$u awk -F '\t' '$1 == ENVIRON["U"] { print $2; exit }' "$TMP/managed")
        [ -n "$s" ] || fail "the networks changed: reload the page"
        printf '%s\n' "$s" >> "$TMP/given"
        names="$names${names:+, }'$s'"
    done
    [ -z "$(sort "$TMP/given" | uniq -d)" ] || fail "a network is in the order twice: reload the page"
    cut -f 2 "$TMP/managed" | sort -u > "$TMP/all"
    sort "$TMP/given" | cmp -s - "$TMP/all" || fail "the networks changed: reload the page"
    priority=100
    while IFS= read -r s; do
        S=$s awk -F '\t' '$2 == ENVIRON["S"] { print $1 }' "$TMP/managed" > "$TMP/ids"
        while IFS= read -r u; do
            nmcli connection modify "$u" connection.autoconnect-priority "$priority" >/dev/null 2>&1 \
                || fail "NetworkManager did not change the order"
        done < "$TMP/ids"
        priority=$((priority - 1))
    done < "$TMP/given"
    log "$by ordered $names"
}

```

4. В `cmd_save` строку `    read_input ssid security hidden password by` заменить на `    read_input uuid ssid security hidden password by`, а последнюю строку тела `    save_new` — на `    if [ -z "${in_uuid:-}" ]; then save_new; else save_existing; fi`.

5. В разборе команды строку `    save) ;;` заменить на `    save|delete|order) ;;`.

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 0`, `OK (42 tests, …)`.

- [ ] **Step 5: Коммит**

Сообщение — в `$SP/commit-253-t2.txt`:

```
The Wi-Fi helper edits, deletes and orders networks

save with a uuid takes the profiles of one SSID over into one, edited in
place so the card stays connected; the network in use is not deleted;
order gives every profile of a network its priority.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add tools/wifi-networks tests/Feature/WifiNetworksTest.php
git commit -q -F "$SP/commit-253-t2.txt"
git show --stat --oneline HEAD | head -4
```

---

### Task 3: Помощник — Check now и поиск сетей

**Files:**
- Modify: `tools/wifi-networks`
- Modify: `tests/Feature/WifiNetworksTest.php`

**Interfaces:**
- Consumes: из задач 1–2 — `network_of`, `prop`, `fail`, `log`, помощники теста; файл состояния холодного запаса `$RUN/uplink-spare` (2.5.2: `state`, `spare`, `uplink`).
- Produces: `check` (вывод `result=` одно из `connected`, `wrong-password`, `not-found`, `no-address`, `timeout`, `failed`, `busy`; `address=` вида `192.168.68.236/24`; `detail=`) и `scan` (строки `SSID:SIGNAL:SECURITY:FREQ` с экранированием `nmcli`); функции `spare_ready` (`$state`, `$spare`, `$uplink`, `$reason`), `busy`, `cmd_check`, `cmd_scan`.

- [ ] **Step 1: Падающие тесты**

Добавить в конец класса `WifiNetworksTest`:

```php
    public function test_check_tries_the_network_on_the_idle_built_in_wifi()
    {
        $uuid = $this->network();

        $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']), $this->output);

        $this->assertSame(['result' => 'connected', 'address' => '192.168.68.236/24', 'detail' => ''], $this->result());
        $this->assertSame([
            "nmcli -w 30 connection up $uuid ifname wlan0",
            'nmcli -w 20 device disconnect wlan0',
            'uplink-spare check wifi-check',
        ], $this->changes());
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

        $this->assertSame($result, $this->result()['result']);
        $this->assertStringContainsString("checked 'office' on wlan0: $text", $this->journal());
        $this->assertContains('nmcli -w 20 device disconnect wlan0', $this->changes(), 'the built-in Wi-Fi goes back to the spare');
        $this->assertContains('uplink-spare check wifi-check', $this->changes());
    }

    public static function busySpare(): array
    {
        return [
            'the built-in Wi-Fi carries the traffic' => ['active', 'the built-in Wi-Fi carries the traffic'],
            'the cold spare is counting' => ['waiting', 'the cold spare is switching'],
            'SPARE=no' => ['off', 'the cold spare is off (SPARE=no)'],
            'no cold spare at all' => [null, 'no built-in Wi-Fi'],
        ];
    }

    #[DataProvider('busySpare')]
    public function test_check_waits_for_an_idle_built_in_wifi($state, $reason)
    {
        $uuid = $this->network();
        $this->spare($state);

        $this->assertSame(0, $this->runHelper('check', ["uuid=$uuid", 'by=anna']));

        $this->assertSame(['result' => 'busy', 'address' => '', 'detail' => $reason], $this->result());
        $this->assertSame([], $this->changes());
    }

    public function test_the_78_pair_is_checked_through_its_built_in_profile()
    {
        $this->pair();

        $this->runHelper('check', ['uuid='.self::M2, 'by=anna']);

        $this->assertSame('connected', $this->result()['result']);
        $this->assertSame('nmcli -w 30 connection up '.self::ONBOARD.' ifname wlan0', $this->changes()[0]);
    }

    public function test_a_network_bound_only_to_the_card_is_saved_once_before_a_check()
    {
        $this->pair();
        unlink($this->dir.'/keyfiles/uplink-onboard.nmconnection');

        $this->assertSame(1, $this->runHelper('check', ['uuid='.self::M2, 'by=anna']));

        $this->assertStringContainsString("'78' is bound to the card: save it once to manage it here", $this->output);
        $this->assertSame([], $this->changes());
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
```

- [ ] **Step 2: Убедиться, что новые тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 1`; падают 15 новых (`check` и `scan` — неверный вызов, код 2), 42 прежних проходят.

- [ ] **Step 3: Check now и поиск**

В `tools/wifi-networks`:

1. После строки шапки `#   wifi-networks order    uuid (a line per network, the preferred first) by` добавить:

```sh
#   wifi-networks check    uuid by   ->  result= address= detail=
#   wifi-networks scan               ->  nmcli's SSID:SIGNAL:SECURITY:FREQ lines
```

2. В `usage` строку `    echo "usage: wifi-networks save|delete|order, the input on stdin" >&2` заменить на `    echo "usage: wifi-networks save|delete|order|check|scan, the input on stdin" >&2`.

3. Перед строкой `# the command` вставить:

```sh
# the cold spare (tools/uplink-spare): the built-in Wi-Fi is idle while another device carries the default route;
# otherwise $reason says why not
spare_ready() {
    state=$(sed -n 's/^state=//p' "$RUN/uplink-spare" 2>/dev/null | head -n 1)
    spare=$(sed -n 's/^spare=//p' "$RUN/uplink-spare" 2>/dev/null | head -n 1)
    uplink=$(sed -n 's/^uplink=//p' "$RUN/uplink-spare" 2>/dev/null | head -n 1)
    reason="no built-in Wi-Fi"
    [ -n "$spare" ] || return 1
    case "$state" in
        standby) [ -z "$uplink" ] || return 0; reason="the built-in Wi-Fi carries the traffic" ;;
        active) reason="the built-in Wi-Fi carries the traffic" ;;
        waiting) reason="the cold spare is switching" ;;
        off) reason="the cold spare is off (SPARE=no)" ;;
    esac
    return 1
}

# a check that cannot run now: not a refusal, the page says why
busy() {
    printf 'result=busy\naddress=\ndetail=%s\n' "$1"
    exit 0
}

# the network on the idle built-in Wi-Fi while the card keeps working; the cold spare's lock keeps uplink-spare out
cmd_check() {
    read_input uuid by
    user
    is_uuid "${in_uuid:-}" || fail "not a network ID: ${in_uuid:-}"
    network_of "$in_uuid" || fail "this is not a network managed here: reload the page"
    spare_ready || busy "$reason"
    mac=$(tr '[:upper:]' '[:lower:]' < "$SYS/$spare/address" 2>/dev/null)
    pick=
    while IFS= read -r u; do
        bound=$(prop "$u" 802-11-wireless.mac-address | tr '[:upper:]' '[:lower:]')
        name=$(prop "$u" connection.interface-name)
        if { [ -z "$bound" ] || [ "$bound" = "$mac" ]; } && { [ -z "$name" ] || [ "$name" = "$spare" ]; }; then
            pick=$u
            break
        fi
    done < "$TMP/network"
    [ -n "$pick" ] || fail "'$ssid' is bound to the card: save it once to manage it here"
    exec 8>"$RUN/uplink-spare.lock"
    flock -w 10 8 || busy "the cold spare is switching right now"
    spare_ready || busy "$reason"
    address=
    if out=$(nmcli -w 30 connection up "$pick" ifname "$spare" 2>&1); then
        address=$(nmcli -g IP4.ADDRESS device show "$spare" 2>/dev/null | head -n 1 | sed 's/ | .*//')
        if [ -n "$address" ]; then result=connected text="connected, ${address%%/*}"; else result=no-address text="no address"; fi
    else
        case "$out" in
            *"Secrets were required"*|*"pre-shared key"*) result=wrong-password text="wrong password" ;;
            *"could not be found"*|*"No network with SSID"*) result=not-found text="network not found" ;;
            *"IP configuration could not be reserved"*) result=no-address text="no address" ;;
            *"Timeout expired"*) result=timeout text="no answer in 30 s" ;;
            *) result=failed text="failed" ;;
        esac
    fi
    nmcli -w 20 device disconnect "$spare" >/dev/null 2>&1
    exec 8>&-
    "$SPARE" check wifi-check >/dev/null 2>&1
    log "$by checked '$ssid' on $spare: $text"
    detail=$(printf '%s\n' "$out" | grep -m 1 '^Error' | tr -d '\000-\037\177')
    printf 'result=%s\naddress=%s\ndetail=%s\n' "$result" "$address" "$detail"
}

# the networks in reach of either radio (rescanning is for root alone), but those without a name
cmd_scan() {
    nmcli -t -f SSID,SIGNAL,SECURITY,FREQ device wifi list --rescan yes 2>/dev/null | grep -v '^:' || true
}

```

4. В разборе команды строку `    save|delete|order) ;;` заменить на `    save|delete|order|check|scan) ;;`, а после строки `nmcli -t -f RUNNING general 2>/dev/null | grep -qx running || fail "NetworkManager does not answer"` вставить:

```sh
if [ "$command" = scan ]; then
    cmd_scan
    exit 0
fi
```

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksTest`
Expected: `phpunit exit 0`, `OK (57 tests, …)`.

- [ ] **Step 5: Проверка синтаксиса под dash и коммит**

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
dash -n /root/dev/tools/wifi-networks && echo "dash: syntax OK"
EOF
```

Expected: `dash: syntax OK` (тесты и так гоняют помощник под `sh` стенда — это dash).

Сообщение — в `$SP/commit-253-t3.txt`:

```
The Wi-Fi helper checks a network on the idle built-in Wi-Fi

check holds the cold spare's lock, brings the network up on the built-in
Wi-Fi while the card keeps working, tells what NetworkManager answered
and puts the radio back into standby; scan lists the named networks in
reach (rescanning is for root alone).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add tools/wifi-networks tests/Feature/WifiNetworksTest.php
git commit -q -F "$SP/commit-253-t3.txt"
git show --stat --oneline HEAD | head -4
```

---
### Task 4: Сервис `WifiNetworks` — чтение профилей и вызовы помощника

**Files:**
- Create: `app/Services/WifiNetworks.php`
- Modify: `app/Services/NetworkStatus.php` (`spare()` — публичный)
- Create: `tests/Unit/WifiNetworksServiceTest.php`

**Interfaces:**
- Consumes: протокол помощника из задач 1–3 (команды, ввод `ключ=значение`, вывод `uuid=`, `error=`, `result=`/`address=`/`detail=`, строки `scan`); `NetworkStatus::spare(): ?array` (`state`, `since`, `device`, `lost_since`).
- Produces: `App\Services\WifiNetworks`: `const SECURITY` (`wpa2`, `mixed`, `wpa3` => название), `const FIELDS`, `__construct(?callable $run = null, string $helper = self::HELPER, string $keyfiles = '/etc/NetworkManager/system-connections', string $net = '/sys/class/net', string $routes = '/proc/net/route', string $spare = '/run/cmprovision/uplink-spare')` (`$run(array $command, ?string $input): [int $code, string $stdout]`), `available(): bool`, `list(): array` (`networks`: `uuid`, `uuids`, `ssid`, `security`, `hidden`, `priority`, `devices`; `others`: `name`, `ssid`, `why`), `checkBlocker(): ?string`, `save(array $network, string $by): array` (`uuid`, `error`), `delete(string $uuid, string $by): ?string`, `order(array $uuids, string $by): ?string`, `check(string $uuid, string $by): array` (`result`, `address`, `detail`), `scan(): array` (`ssid`, `signal`, `security`, `bands`), `log(): string`, `deviceName(string $device): string`.

- [ ] **Step 1: Падающие тесты**

Создать `tests/Unit/WifiNetworksServiceTest.php`:

```php
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
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiNetworksServiceTest`
Expected: `phpunit exit 1`, ошибки `Class "App\Services\WifiNetworks" not found` у всех 15.

- [ ] **Step 3: `NetworkStatus::spare()` — публичный**

В `app/Services/NetworkStatus.php` строки

```php
    /** the built-in Wi-Fi as a cold spare (tools/uplink-spare writes the file as root): null without a file it can read */
    private function spare(): ?array
```

заменить на

```php
    /**
     * The built-in Wi-Fi as a cold spare (tools/uplink-spare writes the file as root): null without a file it can read.
     * The Wi-Fi section asks it too: Check now needs the built-in Wi-Fi idle.
     */
    public function spare(): ?array
```

- [ ] **Step 4: Сервис**

Создать `app/Services/WifiNetworks.php`:

```php
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
                'security' => $keep['key_mgmt'] === 'sae' ? 'wpa3' : ($keep['pmf'] === '2' ? 'mixed' : 'wpa2'),
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
            return 'no built-in Wi-Fi';
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
        $r = $this->helper('check', [['uuid', $uuid], ['by', $by]], 60);
        if (isset($r['error'])) {
            return ['result' => 'error', 'address' => '', 'detail' => $r['error']];
        }

        return ['result' => $r['result'] ?? 'failed', 'address' => $r['address'] ?? '', 'detail' => $r['detail'] ?? ''];
    }

    /** the named networks in reach, each once with its best signal: [ssid, signal (0-100), security, bands], strongest first */
    public function scan(): array
    {
        [$code, $out] = $this->call(['timeout', '30', 'sudo', '-n', $this->helper, 'scan']);
        $seen = [];
        foreach ($code === 0 ? explode("\n", trim($out)) : [] as $line) {
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

        return $list;
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
```

- [ ] **Step 5: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" "WifiNetworksServiceTest|NetworkStatusTest|UplinkSummaryTest"`
Expected: `phpunit exit 0`; `WifiNetworksServiceTest` — 15 тестов, прежние `NetworkStatusTest` и `UplinkSummaryTest` зелёные.

- [ ] **Step 6: Коммит**

Сообщение — в `$SP/commit-253-t4.txt`:

```
Read the Wi-Fi networks and hand changes to the helper

WifiNetworks reads the profiles as the web server user (nmcli without
secrets), groups those of one SSID into a network, names profiles left
to other tools, and runs the helper through sudo with the input on
stdin; a line break never reaches it. NetworkStatus::spare() is public:
Check now needs the built-in Wi-Fi idle.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add app/Services/WifiNetworks.php app/Services/NetworkStatus.php tests/Unit/WifiNetworksServiceTest.php
git commit -q -F "$SP/commit-253-t4.txt"
git show --stat --oneline HEAD | head -5
```

---

### Task 5: Раздел «Wi-Fi networks» в Settings

**Files:**
- Create: `app/Http/Livewire/WifiSettings.php`
- Create: `resources/views/livewire/wifi-settings.blade.php`
- Modify: `resources/views/livewire/settings.blade.php`
- Create: `tests/Support/FakeWifiNetworks.php`
- Create: `tests/Feature/WifiSettingsTest.php`
- Modify (если сборка CSS даст другой файл): `public/css/app.css`

**Interfaces:**
- Consumes: `App\Services\WifiNetworks` из задачи 4 (из контейнера: `app(WifiNetworks::class)`), `Laravel\Jetstream\ConfirmsPasswords`, `<x-confirms-password>`, `livewire.viewlog` (`$logTitle`, `$logOutput`, `closeModal()`).
- Produces: компонент `App\Http\Livewire\WifiSettings` (`<livewire:wifi-settings />`): публичные `add()`, `edit($uuid)`, `cancel()`, `save($password = '')`, `saveAndCheck($password = '')`, `checkNow($uuid)`, `delete($uuid)`, `moveUp($uuid)`, `moveDown($uuid)`, `findNetworks()`, `pick($index)`, `showLog()`, `closeModal()`; свойства `ssid`, `security`, `hidden`, `form`, `editingUuid`, `checks`, `found`, `notice`, `noticeIsError`, `logModalOpen`, `logTitle`, `logOutput`. Пароль сети — **не** свойство: поле в Alpine (`x-data="{ secret: '' }"`, `x-model`), уходит параметром `save(secret)` и `saveAndCheck(secret)`.

Отступление от спеки (раздел «Пароль сети в Livewire»), решение плана: спека чистит свойство пароля в `dehydrate()`. Но `<x-confirms-password>` делает лишний запрос (`startConfirmingPassword`) ещё до Save: свойство ушло бы с ним, `dehydrate()` стёр бы его, и Save получил бы пустой пароль — у правки это значило бы «оставить старый». Параметр действия в снимок не попадает вообще, а поле в браузере после ошибки не пустеет. Цель спеки — сервер никогда не отдаёт пароль сети в браузер — выполняется строже.

- [ ] **Step 1: Подставной сервис для тестов страницы**

Создать `tests/Support/FakeWifiNetworks.php`:

```php
<?php

namespace Tests\Support;

use App\Services\WifiNetworks;

/** WifiNetworks for the settings page tests: the list is set by the test, every change is recorded */
class FakeWifiNetworks extends WifiNetworks
{
    public $available = true;
    public $networks = [];
    public $others = [];
    public $blocker = null;
    public $scanned = [];
    /** [method, arguments...] of every change */
    public $calls = [];
    /** method => what it returns instead of success */
    public $answers = [];

    public function __construct()
    {
        parent::__construct(function () {
            return [1, ''];
        });
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function list(): array
    {
        return ['networks' => $this->networks, 'others' => $this->others];
    }

    public function checkBlocker(): ?string
    {
        return $this->blocker;
    }

    public function save(array $network, string $by): array
    {
        $this->calls[] = ['save', $network, $by];

        return $this->answers['save'] ?? ['uuid' => $network['uuid'] ?: 'new-uuid', 'error' => null];
    }

    public function delete(string $uuid, string $by): ?string
    {
        $this->calls[] = ['delete', $uuid, $by];

        return $this->answers['delete'] ?? null;
    }

    public function order(array $uuids, string $by): ?string
    {
        $this->calls[] = ['order', $uuids, $by];

        return $this->answers['order'] ?? null;
    }

    public function check(string $uuid, string $by): array
    {
        $this->calls[] = ['check', $uuid, $by];

        return $this->answers['check'] ?? ['result' => 'connected', 'address' => '192.168.68.236/24', 'detail' => ''];
    }

    public function scan(): array
    {
        return $this->scanned;
    }

    public function log(): string
    {
        return "Oct 15 12:00:00 wlanpi-da2 cmprovision-wifi[1]: anna saved '78' (WPA2)\n";
    }
}
```

- [ ] **Step 2: Падающие тесты**

Создать `tests/Feature/WifiSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\WifiSettings;
use App\Models\User;
use App\Services\WifiNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeWifiNetworks;
use Tests\TestCase;

/** The Wi-Fi networks section of the settings page: every change asks for the account password; a network password goes to the server once and never comes back. */
class WifiSettingsTest extends TestCase
{
    use RefreshDatabase;

    const M2 = 'ab18b939-1ff4-4500-99e6-d306e02194af';
    const ONBOARD = '74a0d3da-084a-49ef-9e8b-7a6bc7919cc5';
    const PHONE = '0b1c2d3e-0000-4000-8000-000000000001';
    const SECRET = 'S3cret-pass-1';

    protected $wifi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wifi = new FakeWifiNetworks;
        $this->wifi->networks = [
            ['uuid' => self::M2, 'uuids' => [self::M2, self::ONBOARD], 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false,
             'priority' => 20, 'devices' => ['wlan1' => 'M.2 card (wlan1)']],
            ['uuid' => self::PHONE, 'uuids' => [self::PHONE], 'ssid' => 'phone', 'security' => 'wpa3', 'hidden' => true,
             'priority' => 9, 'devices' => []],
        ];
        $this->wifi->others = [['name' => 'netplan-wlan0-guest', 'ssid' => 'guest', 'why' => 'netplan']];
        $this->app->instance(WifiNetworks::class, $this->wifi);
        $this->actingAs(User::factory()->create(['name' => 'anna']));
    }

    protected function confirmed(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    /** the network password is nowhere in what the component sends back: snapshot and HTML */
    protected function assertPasswordNeverSent($component, string $password): void
    {
        $this->assertStringNotContainsString($password, json_encode($component->snapshot));
        $this->assertStringNotContainsString($password, $component->html());
    }

    public function test_the_settings_page_has_the_section()
    {
        $this->get('/settings')->assertOk()->assertSee('Wi-Fi networks');
    }

    public function test_networks_show_in_order_with_their_state_and_foreign_profiles_greyed()
    {
        Livewire::test(WifiSettings::class)
            ->assertSeeInOrder(['78', 'phone', 'guest'])
            ->assertSee('in use · M.2 card (wlan1)')
            ->assertSee('WPA3-Personal')
            ->assertSee('managed outside cmprovision (netplan)')
            ->assertSeeHtml('78 is in use: connect another network first');
    }

    public static function changes(): array
    {
        return [
            'save' => ['save', [self::SECRET]],
            'save and check' => ['saveAndCheck', [self::SECRET]],
            'delete' => ['delete', [self::PHONE]],
            'check now' => ['checkNow', [self::M2]],
            'move up' => ['moveUp', [self::PHONE]],
            'move down' => ['moveDown', [self::M2]],
        ];
    }

    #[DataProvider('changes')]
    public function test_nothing_changes_without_the_account_password($method, $arguments)
    {
        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa3')
            ->call($method, ...$arguments)
            ->assertForbidden();

        $this->assertSame([], $this->wifi->calls);
    }

    public function test_a_new_network_goes_to_the_helper_once_and_its_password_never_comes_back()
    {
        $this->confirmed();

        $component = Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa3')->set('hidden', true)
            ->call('save', self::SECRET)
            ->assertHasNoErrors()
            ->assertSet('form', false)
            ->assertSee('Saved lab.');

        $this->assertSame([['save', ['uuid' => null, 'ssid' => 'lab', 'security' => 'wpa3', 'hidden' => true, 'password' => self::SECRET], 'anna']],
            $this->wifi->calls);
        $this->assertPasswordNeverSent($component, self::SECRET);
    }

    public function test_after_an_error_the_password_is_not_sent_back_either()
    {
        $this->confirmed();
        $this->wifi->answers['save'] = ['uuid' => null, 'error' => 'NetworkManager did not add the network: refused'];

        $component = Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'wpa2')
            ->call('save', self::SECRET)
            ->assertSee('NetworkManager did not add the network: refused')
            ->assertSet('form', true);

        $this->assertPasswordNeverSent($component, self::SECRET);
    }

    public function test_editing_keeps_an_empty_password_and_tells_when_the_network_is_in_use()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('edit', self::ONBOARD)
            ->assertSet('ssid', '78')->assertSet('security', 'wpa2')
            ->call('save', '')
            ->assertHasNoErrors()
            ->assertSee('Saved 78: the network in use takes it at its next connection.');

        $this->assertSame(['uuid' => self::M2, 'ssid' => '78', 'security' => 'wpa2', 'hidden' => false, 'password' => ''],
            $this->wifi->calls[0][1]);
    }

    public static function badInput(): array
    {
        return [
            'a password of 7' => ['wpa2', 'office', '1234567', 'password'],
            'a 64-digit key for WPA3' => ['wpa3', 'office', str_repeat('ab', 32), 'password'],
            'a password outside ASCII' => ['wpa2', 'office', 'пароль-длинный', 'password'],
            'no password for a new network' => ['wpa2', 'office', '', 'password'],
            'an SSID of 33 bytes' => ['wpa2', str_repeat('a', 33), 'office-pass', 'ssid'],
            'an SSID with a tab' => ['wpa2', "off\tice", 'office-pass', 'ssid'],
        ];
    }

    #[DataProvider('badInput')]
    public function test_the_form_checks_what_the_helper_checks($security, $ssid, $password, $field)
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', $ssid)->set('security', $security)
            ->call('save', $password)
            ->assertHasErrors([$field]);

        $this->assertSame([], $this->wifi->calls);
    }

    public function test_a_64_digit_key_is_fine_for_wpa2()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'office')->set('security', 'wpa2')
            ->call('save', str_repeat('ab', 32))
            ->assertHasNoErrors();

        $this->assertCount(1, $this->wifi->calls);
    }

    public function test_check_now_shows_the_result_in_the_row()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('checkNow', self::ONBOARD)
            ->assertSee('connected, 192.168.68.236');

        $this->assertSame([['check', self::M2, 'anna']], $this->wifi->calls);
    }

    public function test_save_and_check_checks_the_saved_network()
    {
        $this->confirmed();
        $this->wifi->answers['save'] = ['uuid' => self::PHONE, 'error' => null];   // the list of the fake does not grow

        Livewire::test(WifiSettings::class)
            ->call('add')->set('ssid', 'lab')->set('security', 'mixed')
            ->call('saveAndCheck', self::SECRET);

        $this->assertSame(['save', 'check'], array_column($this->wifi->calls, 0));
    }

    public function test_check_now_waits_for_an_idle_built_in_wifi()
    {
        $this->wifi->blocker = 'the built-in Wi-Fi carries the traffic';

        Livewire::test(WifiSettings::class)
            ->assertSee('Check now is not available: the built-in Wi-Fi carries the traffic.')
            ->assertSeeHtml('disabled title="Check now: the built-in Wi-Fi carries the traffic"');
    }

    public function test_delete_and_order_go_to_the_helper()
    {
        $this->confirmed();

        Livewire::test(WifiSettings::class)
            ->call('delete', self::PHONE)
            ->assertSee('Removed phone.')
            ->call('moveUp', self::PHONE)
            ->assertSee('Order saved');

        $this->assertSame([['delete', self::PHONE, 'anna'], ['order', [self::PHONE, self::M2], 'anna']], $this->wifi->calls);
    }

    public function test_find_networks_fills_the_name_and_the_security()
    {
        $this->wifi->scanned = [['ssid' => 'Service PSK', 'signal' => 80, 'security' => 'mixed', 'bands' => ['2.4 GHz']],
                                ['ssid' => 'T-NET', 'signal' => 92, 'security' => 'enterprise', 'bands' => ['5 GHz']]];

        Livewire::test(WifiSettings::class)
            ->call('add')
            ->call('findNetworks')
            ->assertSee('Service PSK')
            ->assertSee('Enterprise (802.1X): not supported')
            ->call('pick', 0)
            ->assertSet('ssid', 'Service PSK')
            ->assertSet('security', 'mixed');
    }

    public function test_where_networkmanager_does_not_run_the_wifi_the_section_says_so()
    {
        $this->wifi->available = false;

        Livewire::test(WifiSettings::class)
            ->assertSee('Wi-Fi is not managed by NetworkManager here')
            ->assertDontSee('Add network');
    }

    public function test_the_changes_log_opens()
    {
        Livewire::test(WifiSettings::class)
            ->call('showLog')
            ->assertSee("anna saved '78' (WPA2)");
    }
}
```

- [ ] **Step 3: Убедиться, что тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" WifiSettingsTest`
Expected: `phpunit exit 1`; все 25 падают (`Class "App\Http\Livewire\WifiSettings" not found` или нет текста «Wi-Fi networks» на странице).

- [ ] **Step 4: Компонент**

Создать `app/Http/Livewire/WifiSettings.php`:

```php
<?php

namespace App\Http\Livewire;

use App\Services\WifiNetworks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

/* Settings page section: the provisioner's Wi-Fi networks (2.5.3), each one NetworkManager profile for both cards,
   changed by the root helper tools/wifi-networks. Every change asks for the account password first. The network
   password is no property: the field lives in Alpine and comes as a parameter of save, so no snapshot ever holds it */
class WifiSettings extends Component
{
    use ConfirmsPasswords;

    public $ssid = '', $security = 'mixed', $hidden = false;
    /** the form is open; editingUuid: the network in it, null for a new one */
    #[Locked]
    public $form = false;
    #[Locked]
    public $editingUuid = null;
    /** the last Check now of each network on this page: uuid => [at, text, ok] */
    #[Locked]
    public $checks = [];
    /** Find networks: the networks in reach, null before a search */
    #[Locked]
    public $found = null;
    public $notice = null, $noticeIsError = false;
    public $logModalOpen = false, $logTitle = '', $logOutput = '';

    public function render()
    {
        $wifi = app(WifiNetworks::class);
        $available = $wifi->available();
        $list = $available ? $wifi->list() : ['networks' => [], 'others' => []];
        return view('livewire.wifi-settings', [
            'available' => $available,
            'networks' => $list['networks'],
            'others' => $list['others'],
            'blocker' => $available ? $wifi->checkBlocker() : null,
            'securities' => WifiNetworks::SECURITY,
        ]);
    }

    public function add()
    {
        $this->open(null);
    }

    public function edit($uuid)
    {
        $network = $this->network($uuid);
        if ($network)
            $this->open($network);
    }

    public function cancel()
    {
        $this->form = false;
        $this->editingUuid = null;
        $this->found = null;
        $this->resetErrorBag();
    }

    public function save($password = '')
    {
        $this->ensurePasswordIsConfirmed();
        $this->store((string) $password);
    }

    public function saveAndCheck($password = '')
    {
        $this->ensurePasswordIsConfirmed();
        $uuid = $this->store((string) $password);
        if ($uuid !== null)
            $this->runCheck($uuid);
    }

    public function checkNow($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->runCheck($uuid);
    }

    public function delete($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $network = $this->network($uuid);
        if (!$network)
            return;
        $error = app(WifiNetworks::class)->delete($network['uuid'], $this->by());
        if ($error)
        {
            $this->flash($error, true);
            return;
        }
        if ($this->editingUuid === $network['uuid'])
            $this->cancel();
        $this->flash('Removed '.$network['ssid'].'.');
    }

    public function moveUp($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->move($uuid, -1);
    }

    public function moveDown($uuid)
    {
        $this->ensurePasswordIsConfirmed();
        $this->move($uuid, 1);
    }

    /* Find networks: what both radios see, for the form */
    public function findNetworks()
    {
        $this->found = app(WifiNetworks::class)->scan();
        if (!$this->found)
            $this->flash('No networks found: is the Wi-Fi switched on?', true);
    }

    public function pick($index)
    {
        $network = $this->found[$index] ?? null;
        if (!$network)
            return;
        $this->ssid = $network['ssid'];
        if (isset(WifiNetworks::SECURITY[$network['security']]))
            $this->security = $network['security'];
        $this->found = null;
    }

    public function showLog()
    {
        $this->logTitle = 'Wi-Fi changes';
        $this->logOutput = app(WifiNetworks::class)->log();
        $this->logModalOpen = true;
    }

    public function closeModal()
    {
        $this->logModalOpen = false;
    }

    /* the form and the password to the helper; the saved profile's UUID, null when nothing was saved */
    protected function store(string $password): ?string
    {
        $this->notice = null;
        if (!$this->form)
            return null;
        $new = $this->editingUuid === null;
        Validator::make(['ssid' => $this->ssid, 'security' => $this->security, 'password' => $password], [
            'ssid' => ['required', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) > 32 || preg_match('/[\x00-\x1F\x7F]/', $value) || !mb_check_encoding($value, 'UTF-8'))
                    $fail('The network name must be at most 32 bytes of text without control characters.');
            }],
            'security' => 'required|in:'.implode(',', array_keys(WifiNetworks::SECURITY)),
            // the helper's rule: 8-63 printable ASCII characters, or 64 hexadecimal digits but for WPA3; empty keeps the stored one
            'password' => [$new ? 'required' : 'nullable', 'string', function ($attribute, $value, $fail) {
                if (strlen($value) === 64 && ctype_xdigit($value) && $this->security !== 'wpa3')
                    return;
                if (strlen($value) < 8 || strlen($value) > 63 || !preg_match('/^[\x20-\x7E]+$/', $value))
                    $fail($this->security === 'wpa3' ? 'The password must be 8 to 63 printable ASCII characters.'
                        : 'The password must be 8 to 63 printable ASCII characters or 64 hexadecimal digits.');
            }],
        ])->validate();
        $inUse = !$new && ($this->network($this->editingUuid)['devices'] ?? []) !== [];
        $ssid = $this->ssid;
        $result = app(WifiNetworks::class)->save(['uuid' => $this->editingUuid, 'ssid' => $ssid, 'security' => $this->security,
                                                 'hidden' => (bool) $this->hidden, 'password' => $password], $this->by());
        if ($result['error'])
        {
            $this->flash($result['error'], true);
            return null;
        }
        $this->cancel();
        $this->flash($inUse ? 'Saved '.$ssid.': the network in use takes it at its next connection. Check now tests it on the built-in Wi-Fi.'
                            : 'Saved '.$ssid.'.');
        return $result['uuid'];
    }

    protected function runCheck(string $uuid): void
    {
        $network = $this->network($uuid);
        if (!$network)
            return;
        $r = app(WifiNetworks::class)->check($network['uuid'], $this->by());
        $address = $r['address'] !== '' ? ', '.explode('/', $r['address'])[0] : '';
        $text = [
            'connected' => 'connected'.$address,
            'wrong-password' => 'wrong password',
            'not-found' => 'not found by the built-in Wi-Fi',
            'no-address' => 'connected, but no address (DHCP)',
            'timeout' => 'no answer in 30 s',
            'busy' => 'not now: '.$r['detail'],
        ][$r['result']] ?? ($r['detail'] !== '' ? $r['result'].': '.$r['detail'] : $r['result']);
        $this->checks[$network['uuid']] = ['at' => Carbon::now()->local()->format('H:i'), 'text' => $text, 'ok' => $r['result'] === 'connected'];
    }

    /* one place up or down: the helper gets the whole order, the preferred first */
    protected function move($uuid, int $step): void
    {
        $uuids = array_column(app(WifiNetworks::class)->list()['networks'], 'uuid');
        $at = array_search($uuid, $uuids, true);
        if ($at === false || !isset($uuids[$at + $step]))
            return;
        [$uuids[$at], $uuids[$at + $step]] = [$uuids[$at + $step], $uuids[$at]];
        $error = app(WifiNetworks::class)->order($uuids, $this->by());
        $this->flash($error ?? 'Order saved: the provisioner tries the networks in this order when it connects.', $error !== null);
    }

    /* a network of the list by the UUID of any of its profiles */
    protected function network($uuid): ?array
    {
        foreach (app(WifiNetworks::class)->list()['networks'] as $network)
            if (in_array($uuid, $network['uuids'], true))
                return $network;
        return null;
    }

    protected function open(?array $network): void
    {
        $this->resetErrorBag();
        $this->notice = null;
        $this->found = null;
        $this->form = true;
        $this->editingUuid = $network['uuid'] ?? null;
        $this->ssid = $network['ssid'] ?? '';
        $this->security = $network['security'] ?? 'mixed';
        $this->hidden = $network['hidden'] ?? false;
    }

    protected function by(): string
    {
        return (string) (Auth::user()->name ?? 'unknown');
    }

    protected function flash($text, $error = false)
    {
        $this->notice = $text;
        $this->noticeIsError = $error;
    }
}
```

- [ ] **Step 5: Шаблон раздела**

Создать `resources/views/livewire/wifi-settings.blade.php`:

```blade
<div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md">
    @php($input = 'cmp-field w-full')
    @php($label = 'block text-fg text-sm font-bold mb-2')
    @php($small = 'cmp-btn cmp-btn-secondary cmp-btn-small')

    @if ($logModalOpen)
        @include('livewire.viewlog')
    @endif

    @if (!$available)
        <p class="text-sm text-fg-muted">Wi-Fi is not managed by NetworkManager here: its networks are set up with the system's own tools.</p>
    @else
        @if ($notice)
            <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
        @endif

        @if ($networks || $others)
            <div class="cmp-card-scroll mb-4">
                <table class="cmp-table">
                    <thead><tr><th>Order</th><th>Network</th><th>Security</th><th>State</th><th>Check</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($networks as $i => $n)
                        <tr wire:key="wifi-{{ $n['uuid'] }}">
                            <td class="whitespace-nowrap">
                                @if ($i > 0)
                                    <x-confirms-password wire:then="moveUp('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" aria-label="Move {{ $n['ssid'] }} up">↑</button>
                                    </x-confirms-password>
                                @endif
                                @if ($i < count($networks) - 1)
                                    <x-confirms-password wire:then="moveDown('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" aria-label="Move {{ $n['ssid'] }} down">↓</button>
                                    </x-confirms-password>
                                @endif
                            </td>
                            <td>{{ $n['ssid'] }} @if ($n['hidden'])<span class="text-fg-muted">· hidden</span>@endif</td>
                            <td>{{ $securities[$n['security']] }}</td>
                            <td>{{ $n['devices'] ? 'in use · '.implode(', ', $n['devices']) : 'saved' }}</td>
                            <td>
                                @isset($checks[$n['uuid']])
                                    <span class="{{ $checks[$n['uuid']]['ok'] ? 'text-pos' : 'text-neg' }}">{{ $checks[$n['uuid']]['at'] }}: {{ $checks[$n['uuid']]['text'] }}</span>
                                @endisset
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($blocker === null)
                                    <x-confirms-password wire:then="checkNow('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" wire:loading.attr="disabled">Check now</button>
                                    </x-confirms-password>
                                @else
                                    <button type="button" class="{{ $small }}" disabled title="Check now: {{ $blocker }}">Check now</button>
                                @endif
                                <button type="button" wire:click="edit('{{ $n['uuid'] }}')" class="{{ $small }} ml-1">Edit</button>
                                @if ($n['devices'])
                                    <button type="button" class="{{ $small }} ml-1" disabled title="{{ $n['ssid'] }} is in use: connect another network first">Delete</button>
                                @else
                                    <x-confirms-password wire:then="delete('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }} ml-1" x-on:click="if (!confirm('Delete ' + @js($n['ssid']) + '?')) $event.stopPropagation()">Delete</button>
                                    </x-confirms-password>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($others as $o)
                        <tr class="text-fg-faint">
                            <td></td>
                            <td>{{ $o['ssid'] !== '' ? $o['ssid'] : $o['name'] }}</td>
                            <td colspan="4">managed outside cmprovision ({{ $o['why'] }})</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($blocker !== null)
                <p class="text-sm text-fg-muted mb-4">Check now is not available: {{ $blocker }}.</p>
            @endif
        @endif

        @if (!$form)
            <button type="button" wire:click="add" class="cmp-btn">Add network</button>
            <button type="button" wire:click="showLog" class="cmp-btn cmp-btn-secondary ml-2">Changes log</button>
        @else
            {{-- the network password lives in Alpine and goes as a parameter of save: no snapshot ever holds it --}}
            <div class="border-t border-pline pt-4" x-data="{ secret: '' }">
                <div class="font-bold mb-3">{{ $editingUuid ? 'Edit network' : 'New network' }}</div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-ssid">Network name (SSID)</label>
                    <div class="flex">
                        <input id="wifi-ssid" type="text" wire:model="ssid" class="{{ $input }}" autocomplete="off">
                        <button type="button" wire:click="findNetworks" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary ml-2 whitespace-nowrap">Find networks</button>
                    </div>
                    @error('ssid') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                    @if ($found)
                        <div class="cmp-card-scroll mt-2">
                            <table class="cmp-table">
                                <thead><tr><th>Network</th><th>Signal</th><th>Security</th><th>Band</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($found as $f)
                                    <tr wire:key="found-{{ $loop->index }}">
                                        <td>{{ $f['ssid'] }}</td>
                                        <td>{{ $f['signal'] }}%</td>
                                        <td>{{ $securities[$f['security']] ?? (['enterprise' => 'Enterprise (802.1X): not supported', 'open' => 'open: not supported'][$f['security']] ?? $f['security']) }}</td>
                                        <td>{{ implode(', ', $f['bands']) }}</td>
                                        <td>
                                            @isset($securities[$f['security']])
                                                <button type="button" wire:click="pick({{ $loop->index }})" class="{{ $small }}">Use</button>
                                            @endisset
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-security">Security</label>
                    <select id="wifi-security" wire:model="security" class="{{ $input }}">
                        @foreach ($securities as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('security') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-password">Password</label>
                    <input id="wifi-password" type="password" x-model="secret" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ $editingUuid ? 'leave empty to keep' : '' }}">
                    @error('password') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <label class="flex items-center mb-4 text-sm text-fg">
                    <input type="checkbox" wire:model="hidden" class="form-checkbox h-6 w-6 text-accent mr-2">
                    Hidden network: the provisioner looks for it by name
                </label>
                <p class="text-sm text-fg-muted mb-4">
                    The provisioner joins the first network of the list it finds when it connects; it does not leave a
                    working network for a higher one. The password is stored on the server and never shown again.
                </p>
                <x-confirms-password wire:then="save(secret)">
                    <button type="button" class="cmp-btn" wire:loading.attr="disabled">Save</button>
                </x-confirms-password>
                @if ($blocker === null)
                    <x-confirms-password wire:then="saveAndCheck(secret)">
                        <button type="button" class="cmp-btn cmp-btn-secondary ml-2" wire:loading.attr="disabled">Save and check</button>
                    </x-confirms-password>
                @endif
                <button type="button" wire:click="cancel" class="cmp-btn cmp-btn-secondary ml-2">Cancel</button>
            </div>
        @endif
    @endif
</div>
```

- [ ] **Step 6: Секция в Settings**

В `resources/views/livewire/settings.blade.php` перед блоком

```blade
        <div class="md:col-span-1">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Ethernet switches</h3>
```

вставить:

```blade
        <div class="md:col-span-1" id="wifi">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Wi-Fi networks</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    The networks the provisioner connects to, the preferred first. Each one serves both radios: the
                    M.2 card carries the traffic, the built-in Wi-Fi stands by. A password is stored on the server and
                    never shown again; Check now tries a network on the idle built-in Wi-Fi while the card keeps working.
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <livewire:wifi-settings />
        </div>

```

- [ ] **Step 7: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" "WifiSettingsTest|TokenGuardTest|FormFieldsTest|OverlayLayerTest|PageLayoutTest|SwitchSettingsTest"`
Expected: `phpunit exit 0`; `WifiSettingsTest` — 25 тестов; соседние проверки шаблонов зелёные.

- [ ] **Step 8: Стили**

Сборка CSS на стенде и сравнение с закоммиченной (новые классы в шаблоне требуют пересборки):

```bash
( . "$SP/wsl-bench/dev.sh"; sync_dev > /dev/null; w sh /root/bench/25-css.sh )
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- md5sum /root/dev/public/css/app.css
md5sum public/css/app.css
```

Expected: суммы совпадают — коммитить нечего. Если различаются: `( . "$SP/wsl-bench/dev.sh"; from_dev public/css/app.css )`, затем `bash "$SP/wsl-bench/tests-ok.sh" "TokenGuardTest"` и `npm run test:js` — зелёные, файл идёт в коммит ниже.

- [ ] **Step 9: Коммит**

Сообщение — в `$SP/commit-253-t5.txt`:

```
Settings: the provisioner's Wi-Fi networks

A section lists the networks in their order with their state and the
profiles left to other tools; it adds, edits, deletes, reorders and
checks networks through the helper, each change after the account
password. The network password is no Livewire property: it goes as a
parameter of save, so no snapshot ever holds it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add app/Http/Livewire/WifiSettings.php resources/views/livewire/wifi-settings.blade.php resources/views/livewire/settings.blade.php tests/Support/FakeWifiNetworks.php tests/Feature/WifiSettingsTest.php
git add public/css/app.css
git commit -q -F "$SP/commit-253-t5.txt"
git show --stat --oneline HEAD | head -8
```

---
### Task 6: Значок подключения — авария из состояния холодного запаса и ссылка

**Files:**
- Modify: `app/Support/UplinkSummary.php`
- Modify: `resources/views/livewire/uplink-indicator.blade.php`
- Modify: `tests/Unit/UplinkSummaryTest.php`
- Modify: `tests/Feature/UplinkIndicatorTest.php`

**Interfaces:**
- Consumes: снимок `NetworkStatus` с ключом `spare` (`state`, `since`, `device`, `lost_since`); `UplinkSummary::spareLost()`; маршрут `settings` и якорь `#wifi` из задачи 5.
- Produces: предупреждение «The Wi-Fi card is not connected[ since HH:MM], the built-in Wi-Fi carries the traffic.» (`tone=warn`), когда `spare.state=active`, встроенный несёт маршрут, а `preferred_down` пуст; ссылка «Settings → Wi-Fi networks» в подсказке значка.

- [ ] **Step 1: Падающие тесты**

Добавить в конец класса `UplinkSummaryTest`:

```php
    public function test_on_the_cold_spare_with_one_profile_for_both_cards_the_card_is_named_down()
    {
        // 2.5.3: the profile of the network is active on the built-in Wi-Fi, so no preferred profile is down
        $lost = Carbon::parse('2026-10-15 06:41:00', 'UTC')->getTimestamp();   // 09:41 in Moscow
        $v = UplinkSummary::from(self::snapshot([
            'uplink' => self::onboard(), 'backups' => [], 'preferred_down' => null,
            'spare' => ['state' => 'active', 'since' => $lost + 36, 'device' => 'wlan0', 'lost_since' => $lost],
        ]));

        $this->assertSame('warn', $v['tone']);
        $this->assertSame('The Wi-Fi card is not connected since 09:41, the built-in Wi-Fi carries the traffic.', $v['warning']);
        $this->assertSame('Internet via Wi-Fi, excellent signal, 24 Mbit/s. The Wi-Fi card is not connected', $v['label']);
    }
```

Добавить в конец класса `UplinkIndicatorTest`:

```php
    public function test_the_tooltip_leads_to_the_wifi_networks()
    {
        Livewire::test(UplinkIndicator::class)
            ->assertSeeHtml('href="'.route('settings').'#wifi"')
            ->assertSee('Settings → Wi-Fi networks');
    }
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" "UplinkSummaryTest|UplinkIndicatorTest"`
Expected: `phpunit exit 1`; падают два новых (`warning` — `null` вместо текста; ссылки нет), остальные проходят.

- [ ] **Step 3: Код**

В `app/Support/UplinkSummary.php` блок

```php
            if ($status['preferred_down'] !== null) {
                $view['tone'] = 'warn';
                $view['warning'] = 'Preferred connection '.$status['preferred_down'].' is not connected'.self::spareLost($status)
                    .', the backup carries the traffic.';
                $view['label'] .= '. Preferred connection '.$status['preferred_down'].' is not connected';
            }
```

заменить на

```php
            if ($status['preferred_down'] !== null) {
                $view['tone'] = 'warn';
                $view['warning'] = 'Preferred connection '.$status['preferred_down'].' is not connected'.self::spareLost($status)
                    .', the backup carries the traffic.';
                $view['label'] .= '. Preferred connection '.$status['preferred_down'].' is not connected';
            } elseif (self::onSpare($status, $uplink)) {
                $view['tone'] = 'warn';
                $view['warning'] = 'The Wi-Fi card is not connected'.self::spareLost($status).', the built-in Wi-Fi carries the traffic.';
                $view['label'] .= '. The Wi-Fi card is not connected';
            }
```

и после метода `spareLost()` добавить:

```php
    /**
     * The built-in Wi-Fi carries the traffic as the cold spare. With one profile per network for both cards (2.5.3) no
     * preferred profile is down then: the profile runs on the built-in Wi-Fi.
     */
    private static function onSpare(array $status, array $uplink): bool
    {
        $spare = $status['spare'] ?? null;

        return $spare !== null && $spare['state'] === 'active' && $spare['device'] === $uplink['name'];
    }
```

В `resources/views/livewire/uplink-indicator.blade.php` строку

```blade
            <div class="mt-2 text-fg-faint">{{ $v['checked'] }}</div>
```

заменить на

```blade
            <div class="mt-2 text-fg-faint">{{ $v['checked'] }}</div>
            <div class="mt-1"><a href="{{ route('settings') }}#wifi" class="text-link hover:underline">Settings → Wi-Fi networks</a></div>
```

- [ ] **Step 4: Убедиться, что тесты проходят**

Run: `bash "$SP/wsl-bench/tests-ok.sh" "UplinkSummaryTest|UplinkIndicatorTest|TokenGuardTest"`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

Сообщение — в `$SP/commit-253-t6.txt`:

```
The uplink icon tells a card failure from the cold spare's state

With one profile per network for both cards no preferred profile is
down while the built-in Wi-Fi carries the traffic: the icon warns from
the cold spare's state instead. Its tooltip leads to the Wi-Fi networks.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add app/Support/UplinkSummary.php resources/views/livewire/uplink-indicator.blade.php tests/Unit/UplinkSummaryTest.php tests/Feature/UplinkIndicatorTest.php
git commit -q -F "$SP/commit-253-t6.txt"
git show --stat --oneline HEAD | head -6
```

---

### Task 7: Пакет — помощник, правило sudo, метрики; стенд

**Files:**
- Create: `tools/90-cmprovision-uplink.conf`
- Modify: `debian/rules`, `debian/010_cmprovision`, `debian/postinst`, `debian/changelog`
- Modify: `tests/Unit/PackagingTest.php`
- Create (вне репозитория): `$SP/plan253-t7-package.py`

**Interfaces:**
- Consumes: `tools/wifi-networks` (задачи 1–3).
- Produces: пакет `2.5.3~dev1`: `/usr/lib/cmprovision/wifi-networks` (0755 root), `/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf` (0644 root), пять строк sudo, `nmcli general reload conf` в `postinst`.

- [ ] **Step 1: Падающие тесты**

Добавить в конец класса `PackagingTest`:

```php
    public function test_the_wifi_helper_is_installed_and_sudo_allows_exactly_its_commands()
    {
        $this->assertStringContainsString("\tinstall -D -m 755 tools/wifi-networks debian/cmprovision4/usr/lib/cmprovision/wifi-networks\n",
            $this->file('debian/rules'));
        $sudoers = $this->file('debian/010_cmprovision');
        foreach (['save', 'delete', 'order', 'check', 'scan'] as $command)
            $this->assertStringContainsString("www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks $command\n", $sudoers);
        // the command alone: the input comes on stdin, and a wildcard would let anything through
        $this->assertStringNotContainsString('*', $sudoers);
        $this->assertSame(6, substr_count($sudoers, 'NOPASSWD'), 'the dnsmasq restart and the five Wi-Fi commands');
        $this->assertStringEndsWith("\n", $sudoers);
    }

    public function test_networkmanager_gets_the_metrics_by_device()
    {
        $conf = $this->file('tools/90-cmprovision-uplink.conf');
        $this->assertStringContainsString("[connection-cmprovision-builtin-wifi]\nmatch-device=driver:brcmfmac\nipv4.route-metric=600\nipv6.route-metric=600\n", $conf);
        $this->assertStringContainsString("[connection-cmprovision-wifi]\nmatch-device=type:wifi\nipv4.route-metric=100\nipv6.route-metric=100\n", $conf);
        $this->assertLessThan(strpos($conf, '[connection-cmprovision-wifi]'), strpos($conf, '[connection-cmprovision-builtin-wifi]'),
            'the first section that matches a device wins: the built-in Wi-Fi before any Wi-Fi');
        $this->assertStringContainsString("\tinstall -D -m 644 tools/90-cmprovision-uplink.conf debian/cmprovision4/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf\n",
            $this->file('debian/rules'));
        $postinst = $this->file('debian/postinst');
        $this->assertStringContainsString('nmcli general reload conf >/dev/null 2>&1 || true', $postinst);
        $this->assertLessThan(strpos($postinst, '#DEBHELPER#'), strpos($postinst, 'nmcli general reload conf'));
    }
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `bash "$SP/wsl-bench/tests-ok.sh" PackagingTest`
Expected: `phpunit exit 1`; падают два новых (нет строк в `debian/rules`, нет файла `tools/90-cmprovision-uplink.conf`).

- [ ] **Step 3: Файл метрик**

Создать `tools/90-cmprovision-uplink.conf`:

```ini
# cmprovision (2.5.3): the metrics of the default routes by device, for profiles that leave theirs at -1 (one profile
# per Wi-Fi network serves both radios). The built-in Wi-Fi (brcmfmac on the CM4) is the cold spare, any other Wi-Fi
# device carries the traffic. The first section that matches a device wins; a file of this name in
# /etc/NetworkManager/conf.d/ replaces this one.
[connection-cmprovision-builtin-wifi]
match-device=driver:brcmfmac
ipv4.route-metric=600
ipv6.route-metric=600

[connection-cmprovision-wifi]
match-device=type:wifi
ipv4.route-metric=100
ipv6.route-metric=100
```

- [ ] **Step 4: Правки пакета**

Записать в `$SP/plan253-t7-package.py` и выполнить `python -I "$SP/plan253-t7-package.py" "$PWD"`:

```python
"""2.5.3: the Wi-Fi helper, its sudo lines and the metrics by device in the package; version 2.5.3~dev1."""
import datetime
import email.utils
import pathlib
import sys

REPO = pathlib.Path(sys.argv[1])


def patch(name, old, new):
    path = REPO / name
    text = path.read_text(encoding='utf-8')
    if text.count(old) != 1:
        sys.exit(f'{name}: expected one match, found {text.count(old)}: {old[:60]!r}')
    path.write_text(text.replace(old, new), encoding='utf-8', newline='\n')
    print(f'{name}: patched')


hook = '\tinstall -D -m 755 tools/90-cmprovision-uplink debian/cmprovision4/usr/lib/NetworkManager/dispatcher.d/90-cmprovision-uplink\n'
patch('debian/rules', hook, hook
      + '\tinstall -D -m 755 tools/wifi-networks debian/cmprovision4/usr/lib/cmprovision/wifi-networks\n'
      + '\tinstall -D -m 644 tools/90-cmprovision-uplink.conf debian/cmprovision4/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf\n')

sudoers = REPO / 'debian/010_cmprovision'
text = sudoers.read_text(encoding='utf-8')
if 'wifi-networks' in text:
    sys.exit('debian/010_cmprovision: it has the Wi-Fi lines already')
if not text.endswith('\n'):
    text += '\n'
text += '# the Wi-Fi networks of the provisioner (2.5.3): the command alone, the input comes on stdin\n'
text += ''.join(f'www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks {c}\n' for c in ('save', 'delete', 'order', 'check', 'scan'))
sudoers.write_text(text, encoding='utf-8', newline='\n')
print('debian/010_cmprovision: five lines')

check = '    /usr/lib/cmprovision/uplink-spare check postinst || true\n'
patch('debian/postinst', check, check
      + '    # the metrics by device (2.5.3): NetworkManager reads its conf.d again, connections take them when they connect\n'
      + '    nmcli general reload conf >/dev/null 2>&1 || true\n')

changelog = REPO / 'debian/changelog'
date = email.utils.format_datetime(datetime.datetime.now().astimezone())
entry = ('cmprovision4 (2.5.3~dev1) unstable; urgency=medium\n\n'
         "  * The provisioner's Wi-Fi networks in Settings: add, edit, delete and\n"
         '    order networks (WPA2, WPA3 or mixed, hidden ones too), check one on\n'
         '    the idle built-in Wi-Fi while the card keeps working, and find the\n'
         '    networks in reach. A root helper behind five exact sudo lines\n'
         "    changes NetworkManager's profiles, one per network for both cards;\n"
         '    a password goes only into the root-only keyfile.\n'
         '  * The uplink icon warns when the built-in Wi-Fi carries the traffic.\n\n'
         f' -- nimbo78 <igor.sin.78@gmail.com>  {date}\n\n')
changelog.write_text(entry + changelog.read_text(encoding='utf-8'), encoding='utf-8', newline='\n')
print('debian/changelog: 2.5.3~dev1')
```

Expected: `debian/rules: patched`, `debian/010_cmprovision: five lines`, `debian/postinst: patched`, `debian/changelog: 2.5.3~dev1`.

- [ ] **Step 5: Убедиться, что тесты проходят, и весь набор**

Run: `bash "$SP/wsl-bench/tests-ok.sh" PackagingTest`, затем `bash "$SP/wsl-bench/suite-ok.sh" > "$SP/suite-253-t7.log" 2>&1; echo rc=$?; tail -n 12 "$SP/suite-253-t7.log"`
Expected: `PackagingTest` зелёный; весь набор — `rc=0`, `php rc=0 js rc=0`, PHP-тестов на 101 больше, чем в 2.5.2 (780 → 881: 57 помощника, 15 сервиса, 25 раздела, 2 значка, 2 пакета; случаи провайдеров уже в счёте), пропусков 13; итог записать в ledger.

- [ ] **Step 6: Коммит**

Сообщение — в `$SP/commit-253-t7.txt`:

```
Package the Wi-Fi helper, its sudo lines and the metrics by device

The helper goes to /usr/lib/cmprovision with exactly five sudo lines
for www-data, one per command and no arguments; NetworkManager's conf.d
gets the metrics by device and postinst has it read them again.
2.5.3~dev1.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add tools/90-cmprovision-uplink.conf debian/rules debian/010_cmprovision debian/postinst debian/changelog tests/Unit/PackagingTest.php
git commit -q -F "$SP/commit-253-t7.txt"
git show --stat --oneline HEAD | head -8
```

- [ ] **Step 7: Сборка на стенде**

Сохранить стоящий на стенде пакет для отката, собрать рабочее дерево:

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
mkdir -p /root/keep
v=$(dpkg-deb -f /root/own/last.deb Version)
cp /root/own/last.deb /root/keep/before-253.deb && echo "kept $v"
EOF
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | sed 's#^#./#' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh /tmp/src-wt.tar
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/13-build-wt.sh
```

Expected: `kept 2.5.2~dev4` (последняя сборка стенда), затем `built 2.5.3~dev1: <размер> bytes`.

- [ ] **Step 8: Установка, правило sudo, откат и снова установка**

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
show() {
    echo "  version $(dpkg-query -W -f='${Version}' cmprovision4)"
    echo "  helper: $(stat -c '%a %U' /usr/lib/cmprovision/wifi-networks 2>&1); conf.d: $(stat -c '%a %U' /usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf 2>&1)"
    echo "  sudoers: $(visudo -cf /etc/sudoers.d/010_cmprovision 2>&1 | tr '\n' ' '); Wi-Fi lines: $(grep -c 'wifi-networks' /etc/sudoers.d/010_cmprovision)"
}
echo "== 2.5.3~dev1 over $(dpkg-query -W -f='${Version}' cmprovision4)"
DEBIAN_FRONTEND=noninteractive apt-get install -y /root/own/last.deb > /tmp/i-253.log 2>&1 || { tail -20 /tmp/i-253.log; exit 1; }
show
echo "  www-data, scan: $(runuser -u www-data -- sudo -n /usr/lib/cmprovision/wifi-networks scan 2>&1 | tr '\n' ' ')"
echo "  www-data, scan with an argument: $(runuser -u www-data -- sudo -n /usr/lib/cmprovision/wifi-networks scan extra 2>&1 | tr '\n' ' ')"
echo "  www-data, another program: $(runuser -u www-data -- sudo -n /bin/true 2>&1 | tr '\n' ' ')"
echo "== back to $(dpkg-deb -f /root/keep/before-253.deb Version)"
DEBIAN_FRONTEND=noninteractive apt-get install -y --allow-downgrades /root/keep/before-253.deb > /tmp/i-253-down.log 2>&1 || { tail -20 /tmp/i-253-down.log; exit 1; }
show
echo "== 2.5.3~dev1 again"
DEBIAN_FRONTEND=noninteractive apt-get install -y /root/own/last.deb > /tmp/i-253b.log 2>&1 || { tail -20 /tmp/i-253b.log; exit 1; }
show
EOF
```

Expected:
- после первой установки: `version 2.5.3~dev1`, `helper: 755 root; conf.d: 644 root`, `sudoers: /etc/sudoers.d/010_cmprovision: parsed OK`, `Wi-Fi lines: 5`; `www-data, scan: error=NetworkManager does not answer` (sudo пустил, а NetworkManager на стенде нет); со вторым аргументом и с `/bin/true` — `sudo: a password is required` (правило их не пускает);
- после отката: `version 2.5.2~dev4`, у `helper` и `conf.d` — «No such file or directory», `parsed OK`, `Wi-Fi lines: 0` (conffile вернулся к версии 2.5.2: его не правили);
- после повторной установки — снова как после первой.

- [ ] **Step 9: Дымовой тест стенда**

```bash
{ printf "PW='%s'\n" "$(cat "$SP/bench-web-password.txt")"; echo 'export PW; sh /root/bench/05-smoke.sh'; } \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s > "$SP/smoke-2.5.3.log" 2>&1
grep -E "GET /scriptexecute|alldone|sh -n|ERROR lines|error.log new lines" "$SP/smoke-2.5.3.log"
```

Expected: `GET /scriptexecute: 200 …`, `sh -n: syntax OK`, `alldone: 200`, число ERROR в `laravel.log` не выросло, новых строк `error.log` nginx нет. Итог шагов 7–9 — в ledger, коммита нет.

---

### Task 8: Документы

**Files:**
- Modify: `README.md`, `CLAUDE.md`, `docs/provisioner-network.md`
- Create (вне репозитория): `$SP/plan253-docs.py`

**Interfaces:**
- Consumes: всё сделанное в задачах 1–7.
- Produces: README — раздел «The provisioner's Wi-Fi networks»; CLAUDE.md — пункт о сетях Wi-Fi, правило sudo, тесты, значок; docs/provisioner-network.md — часть C по факту.

- [ ] **Step 1: Правки документов**

Записать в `$SP/plan253-docs.py` и выполнить `python -I "$SP/plan253-docs.py" "$PWD"`:

```python
"""2.5.3: README, CLAUDE.md and the network doc tell how the Wi-Fi networks are managed from Settings."""
import pathlib
import sys

REPO = pathlib.Path(sys.argv[1])


def patch(name, pairs):
    path = REPO / name
    text = path.read_text(encoding='utf-8')
    for old, new in pairs:
        count = text.count(old)
        if count != 1:
            sys.exit(f'{name}: expected one match, found {count}: {old[:70]!r}')
        text = text.replace(old, new)
    path.write_text(text, encoding='utf-8', newline='\n')
    print(f'{name}: {len(pairs)} change(s)')


patch('README.md', [(
    "- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.\n\n"
    "## Switches: port lookup, the Ports page and PoE restart ##\n",
    "- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS, where it does not, nothing changes.\n\n"
    "## The provisioner's Wi-Fi networks ##\n\n"
    "Settings → Wi-Fi networks lists the networks the provisioner connects to, the preferred first, and the Wi-Fi profiles it "
    "leaves to other tools (greyed). Each network is one NetworkManager profile that serves both radios: the card carries the "
    "traffic, the built-in Wi-Fi stands by as the cold spare.\n\n"
    "- **Add network** takes the name (SSID; **Find networks** lists the networks in reach), the security (WPA2-Personal, "
    "WPA2/WPA3 mixed or WPA3-Personal), the password, and whether the network is hidden. Every change asks for your account "
    "password first.\n"
    "- The password is stored on the server and never shown again; left empty when editing, it keeps the stored one. A new "
    "password of the network in use takes effect at its next connection, so it can be entered before the router changes.\n"
    "- **Check now** tries a network on the idle built-in Wi-Fi while the card keeps working: connected, wrong password, not "
    "found, no address. A network only the card can reach (6 GHz, say) shows as not found and can be saved all the same.\n"
    "- The order (↑ ↓) decides which network the provisioner joins when it connects; it does not leave a working network for "
    "a higher one. The network in use cannot be deleted. **Changes log** shows every change (`journalctl -t cmprovision-wifi`).\n"
    "- A provisioner set up with Raspberry Pi Imager keeps its Wi-Fi in a netplan profile, shown greyed: add the same network "
    "here, check it, then remove the old profile with `sudo nmcli connection delete <name>`.\n"
    "- It works where NetworkManager runs the Wi-Fi (Raspberry Pi OS); on WLAN Pi OS the section says it is not available.\n\n"
    "## Switches: port lookup, the Ports page and PoE restart ##\n",
)])

patch('CLAUDE.md', [
    ("- Через `sudo` веб-приложению разрешён только `systemctl restart cmprovision-dnsmasq` (`debian/010_cmprovision`); "
     "журналы читаются благодаря группе `systemd-journal`, которую выдаёт `postinst`. Новый привилегированный вызов требует "
     "правки sudoers-файла.",
     "- Через `sudo` веб-приложению разрешены только `systemctl restart cmprovision-dnsmasq` и пять команд помощника Wi-Fi "
     "(`debian/010_cmprovision`, по строке на команду, без аргументов); журналы читаются благодаря группе `systemd-journal`, "
     "которую выдаёт `postinst`. Новый привилегированный вызов требует правки sudoers-файла (это conffile: правленный вручную "
     "остановит обновление вопросом dpkg)."),
    ("- Печать этикеток (FTP или команда;",
     "- Сети Wi-Fi провижинера из Settings (2.5.3, спека `docs/superpowers/specs/2026-10-15-wifi-networks-design.md`): раздел — "
     "компонент `WifiSettings`, чтение — `App\\Services\\WifiNetworks` (`nmcli -t` от `www-data` без `-s`; профили одного SSID — "
     "одна сеть; не keyfile в `/etc/NetworkManager/system-connections`, Enterprise и открытые — серые строки), запись — "
     "root-помощник `tools/wifi-networks` (POSIX sh, ставится в `/usr/lib/cmprovision/`) через `sudo -n` с вводом "
     "`ключ=значение` на stdin: `save`, `delete`, `order`, `check`, `scan`. Сеть — один профиль без привязки к карте "
     "(`multi-connect=multiple`, метрика −1, `autoconnect-priority` по порядку 100, 99…); метрики дают устройства — "
     "`/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf` (`brcmfmac` — 600, остальной Wi-Fi — 100). Пароль сети не "
     "попадает ни в аргументы процессов (строку `psk=` помощник вписывает в keyfile сам, через `ENVIRON` awk, и сверяет, прочитав "
     "`nmcli -s`), ни в журнал, ни в снимок Livewire: поле живёт в Alpine и уходит параметром `save(secret)`. Каждое изменение — "
     "после `<x-confirms-password>` (Jetstream; его окно выводится `@once`, поэтому на странице Settings он только в этом "
     "компоненте). Check now поднимает сеть на простаивающем встроенном (`state=standby` холодного запаса) под его замком и "
     "возвращает встроенный в запас. Первое сохранение сети из нескольких профилей оставляет активный профиль (правит на месте, "
     "подключение не рвётся) и удаляет остальные. Журнал — `logger -t cmprovision-wifi`.\n"
     "- Печать этикеток (FTP или команда;"),
    ("строка в Service status — `ServiceStatusTest`), ",
     "строка в Service status — `ServiceStatusTest`), сети Wi-Fi (`WifiNetworksTest` гоняет `tools/wifi-networks` под `sh` с "
     "подставным NetworkManager `tests/Support/fake-nmcli.php`; сервис — `WifiNetworksServiceTest`, раздел Settings — "
     "`WifiSettingsTest` с `Tests\\Support\\FakeWifiNetworks`), "),
    ("  Состояние холодного запаса встроенного Wi-Fi `NetworkStatus` читает из `/run/cmprovision/uplink-spare`.",
     "  Состояние холодного запаса встроенного Wi-Fi `NetworkStatus` читает из `/run/cmprovision/uplink-spare`. При аварии карты "
     "предупреждение значка берётся из этого состояния (`state=active`): с одним профилем на обе карты неподключённого "
     "«предпочтительного» профиля нет. В подсказке — ссылка на Settings → Wi-Fi networks."),
])

patch('docs/provisioner-network.md', [(
    "- **C, 2.5.3 — управление Wi-Fi из веб-интерфейса.** Правятся keyfile-профили (SSID,\n"
    "  защита, пароль, приоритет, порядок карт) через root-помощника с узким sudo; пароль\n"
    "  в браузер не возвращается (`nmcli` без `-s`). Доступно только там, где NM управляет\n"
    "  Wi-Fi (Raspberry Pi OS Trixie), и недоступно на WLAN Pi OS.",
    "- **C, 2.5.3 — управление Wi-Fi из веб-интерфейса.** Settings → Wi-Fi networks: сеть —\n"
    "  один keyfile-профиль без привязки к карте (`multi-connect=multiple`), его поднимают\n"
    "  обе карты; метрику даёт устройство (`conf.d` пакета: встроенный `brcmfmac` — 600,\n"
    "  остальной Wi-Fi — 100), порядок сетей — `autoconnect-priority`. Правит root-помощник\n"
    "  `wifi-networks` через пять строк sudo, ввод на stdin; пароль он сам вписывает в\n"
    "  keyfile root и в браузер не возвращает. Check now пробует сеть на простаивающем\n"
    "  встроенном, пока карта работает. Пара `uplink-m2`/`uplink-onboard` при первом\n"
    "  сохранении становится одним профилем без обрыва. Недоступно на WLAN Pi OS.",
)])
```

Expected: `README.md: 1 change(s)`, `CLAUDE.md: 4 change(s)`, `docs/provisioner-network.md: 1 change(s)`.

- [ ] **Step 2: Тесты документов**

Run: `bash "$SP/wsl-bench/tests-ok.sh" "PackagingTest|BrandingTest"`
Expected: `phpunit exit 0` (документы тестами не покрыты, кроме упоминаний в пакете; прогон — что ничего не сломано).

- [ ] **Step 3: Коммит**

Сообщение — в `$SP/commit-253-t8.txt`:

```
Document the provisioner's Wi-Fi networks

README tells how the networks are managed from Settings, Check now, the
password and the move from Imager's netplan profile; CLAUDE.md and the
network doc follow.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add README.md CLAUDE.md docs/provisioner-network.md
git commit -q -F "$SP/commit-253-t8.txt"
git show --stat --oneline HEAD | head -5
```

---

### Task 9: Выпуск 2.5.3 — только по слову пользователя

Перед этой задачей — финальное ревью всей ветки (процесс исполнения плана) и исправление его мелочей, каждой с тестом (порядок выпуска пользователя). **Остановиться и спросить пользователя перед шагом 1.** Шаг 6 (живые проверки на wlanpi-da2: переход «78», Check now, неверный пароль, WPA3 с раздачей телефона) — отдельный вопрос пользователю.

**Files:**
- Modify: `debian/changelog` (2.5.3~devN → 2.5.3), `ROADMAP.md` (2.5.3 — в «Сделано»)
- Create (вне репозитория): `$SP/plan253-release.py`, `$SP/prov-install253.sh`, `$SP/prov-wifi253.sh`

**Interfaces:**
- Consumes: ветка `stage2` после задач 1–8 и ревью.
- Produces: тег `v2.5.3` на слиянии в `main`; пакет `cmprovision4_2.5.3_all.deb` на wlanpi-da2.

- [ ] **Step 1: Коммит выпуска**

Записать в `$SP/plan253-release.py` и выполнить `python -I "$SP/plan253-release.py" "$PWD" "<дата выпуска, «16 октября 2026»>" "<PHP-тестов>" "<из них пропущено>" "<JS-тестов>"` (числа — из последнего `suite-ok.sh`):

```python
"""2.5.3: the release entry in debian/changelog and ROADMAP."""
import datetime
import email.utils
import pathlib
import re
import sys

REPO = pathlib.Path(sys.argv[1])
DAY, PHP_TESTS, SKIPPED, JS_TESTS = sys.argv[2:6]

changelog = REPO / 'debian/changelog'
text = changelog.read_text(encoding='utf-8')
text, count = re.subn(r'^cmprovision4 \(2\.5\.3~dev\d+\) unstable;', 'cmprovision4 (2.5.3) unstable;', text, count=1, flags=re.M)
if count != 1:
    sys.exit('debian/changelog: no 2.5.3~devN entry on top')
date = email.utils.format_datetime(datetime.datetime.now().astimezone())
text = re.sub(r'^ -- nimbo78 <igor\.sin\.78@gmail\.com>  .*$', f' -- nimbo78 <igor.sin.78@gmail.com>  {date}', text, count=1, flags=re.M)
changelog.write_text(text, encoding='utf-8', newline='\n')
print('debian/changelog: 2.5.3')

roadmap = REPO / 'ROADMAP.md'
text = roadmap.read_text(encoding='utf-8')
start = text.index('**2.5.3 — управление Wi-Fi из веб-интерфейса**')
end = text.index('**Удалённое затирание сенсоров**')
text = text[:start] + text[end:]
done = (f'**2.5.3** вышла {DAY}, тег `v2.5.3`. Сети Wi-Fi провижинера — из Settings.\n\n'
        '- **Сети.** Settings → Wi-Fi networks: сеть — один профиль NetworkManager на обе карты (`multi-connect`), защита '
        'WPA2, WPA3 или смешанная, скрытые сети, порядок стрелками (`autoconnect-priority`), поиск видимых сетей; метрики — по '
        'устройствам из `conf.d` пакета. Пароль вписывает в keyfile root-помощник `wifi-networks` (пять строк sudo, ввод на '
        'stdin), в браузер он не возвращается; каждое изменение — после пароля учётки.\n'
        '- **Проверка сети.** Check now поднимает сеть на простаивающем встроенном Wi-Fi, пока карта работает; пара '
        '`uplink-m2`/`uplink-onboard` при первом сохранении стала одним профилем без обрыва.\n\n'
        f'Проверено: {PHP_TESTS} тестов на PHP 8.4 ({SKIPPED} из них пропускаются без симулятора SNMP) и {JS_TESTS} на JS; '
        'на стенде WSL — установка, откат на 2.5.2 и повторная установка, `visudo -cf`, правило sudo для www-data; на '
        'wlanpi-da2 — установка и живые проверки шага 6.\n\n')
if text.count('## Этап 2: что осталось') != 1:
    sys.exit('ROADMAP.md: no single "## Этап 2: что осталось"')
text = text.replace('## Этап 2: что осталось', done + '## Этап 2: что осталось')
roadmap.write_text(text, encoding='utf-8', newline='\n')
print('ROADMAP.md: 2.5.3 done')
```

Expected: `debian/changelog: 2.5.3`, `ROADMAP.md: 2.5.3 done`. Коммит — сообщение в `$SP/commit-253-release.txt`:

```
The provisioner's Wi-Fi networks from Settings; 2.5.3

Each network is one NetworkManager profile for both cards; the section
adds, edits, deletes, orders and checks them through a root helper
behind five exact sudo lines, and the password never comes back.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

```bash
git add debian/changelog ROADMAP.md
git commit -q -F "$SP/commit-253-release.txt"
```

- [ ] **Step 2: Локальное слияние и тег — без пуша**

```bash
set -e
test "$(git rev-parse --abbrev-ref HEAD)" = stage2
test "$(git rev-parse main)" = "$(git rev-parse origin/main)"
git checkout -q main
git merge -q --no-ff stage2 -m "Merge stage2: release 2.5.3"
git tag -a v2.5.3 -m "cmprovision4 2.5.3"
test "$(git rev-parse 'v2.5.3^{commit}')" = "$(git rev-parse HEAD)"
git checkout -q stage2 && git merge -q --ff-only main
git log --oneline -3 main
```

Expected: верхняя строка — `Merge stage2: release 2.5.3`; `stage2` совпадает с `main`.

- [ ] **Step 3: Сборка на wlanpi-da2 из тега**

```bash
P="ssh -o HostKeyAlias=192.168.25.30 -o BatchMode=yes -o ConnectTimeout=10 claude@192.168.68.237"
$P 'rm -rf ~/cmprovision/build/cmprovision && mkdir -p ~/cmprovision/build/cmprovision'
git archive v2.5.3 | $P 'tar -x -C ~/cmprovision/build/cmprovision && test ! -e ~/cmprovision/build/cmprovision/vendor && echo "no vendor"'
$P 'cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d > ../build-2.5.3.log 2>&1; echo "build rc=$?"; ls -la ../cmprovision4_2.5.3_all.deb; dpkg-deb -c ../cmprovision4_2.5.3_all.deb | grep -E "wifi-networks|conf.d/90-cmprovision"'
```

(таймаут Bash — 600000.)
Expected: `build rc=0`, файл пакета; в нём `usr/lib/cmprovision/wifi-networks` (`-rwxr-xr-x root/root`) и `usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf` (`-rw-r--r--`).

- [ ] **Step 4: Установка**

Записать `$SP/prov-install253.sh`:

```sh
#!/bin/sh
# Install 2.5.3 on the provisioner, only while no module is being flashed; then check what 2.5.3 brings, changing nothing
set -e
trap 'echo "check exit $?"' EXIT
[ "$(hostname)" = wlanpi-da2 ] || { echo "wrong host: $(hostname)"; exit 1; }
cd /home/claude/cmprovision/build
DEB=cmprovision4_2.5.3_all.deb
APP=/var/lib/cmprovision
DB=$APP/database/database.sqlite
q() { sudo -n -u www-data sqlite3 -readonly -cmd '.timeout 5000' $DB "$1"; }
n=$(q "select count(*) from cms where phase in ('preinstall','write','verify','postinstall') and provisioning_started_at > datetime('now','-2 hours');")
[ "$n" = 0 ] || { echo "a module is being flashed ($n): not installing"; exit 1; }
# the way back: 2.5.3 takes no snapshot, so the 2.5.2 package must be at hand
[ -f cmprovision4_2.5.2_all.deb ] || { echo "no cmprovision4_2.5.2_all.deb here to go back to: not installing"; exit 1; }
f0=$(q 'select count(*) from failed_jobs;')
echo "before: $(dpkg-query -W -f='${Version}' cmprovision4); $(nmcli -t -f DEVICE,STATE device status | grep '^wlan' | tr '\n' ' ')"
sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y ./$DEB > install-2.5.3.log 2>&1 < /dev/null || { tail -40 install-2.5.3.log; exit 1; }
dpkg-query -W -f='${Version} ${Status}\n' cmprovision4
for s in nginx php8.4-fpm cmprovision-queue cmprovision-queue-images cmprovision-dnsmasq cmprovision-rpiboot cmprovision-uplink.timer; do
    printf '%s: %s\n' "$s" "$(systemctl is-active $s || true)"
done
echo "helper: $(stat -c '%a %U' /usr/lib/cmprovision/wifi-networks); conf.d: $(stat -c '%a %U' /usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf)"
echo "sudoers: $(sudo visudo -cf /etc/sudoers.d/010_cmprovision | tr '\n' ' ')"
echo "www-data scans: $(sudo -n -u www-data sudo -n /usr/lib/cmprovision/wifi-networks scan < /dev/null | grep -c . ) networks in reach"
cd $APP && sudo -n -u www-data env HOME=/tmp php artisan tinker --execute='echo json_encode(app(App\Services\WifiNetworks::class)->list()), PHP_EOL;' 2>/dev/null < /dev/null | grep -v Deprecated || echo "tinker: no answer"
echo "the card: $(nmcli -t -f DEVICE,STATE device status | grep '^wlan' | tr '\n' ' ')"
cd /home/claude/cmprovision/build
echo "failed jobs: $(q 'select count(*) from failed_jobs;') (before: $f0)"
```

Скопировать и запустить отдельно от SSH (одной командой `&&` с `&` нельзя: фоновая цепочка получает stdin из /dev/null):

```bash
P="ssh -o HostKeyAlias=192.168.25.30 -o BatchMode=yes -o ConnectTimeout=10 claude@192.168.68.237"
$P 'cat > ~/cmprovision/build/prov-install253.sh' < "$SP/prov-install253.sh"
$P 'F=~/cmprovision/build/prov-install253.sh; L=~/cmprovision/build/check-2.5.3.log; sh -n $F || exit 1; rm -f $L; { setsid nohup sh $F > $L 2>&1 < /dev/null & }; sleep 5; cat $L'
```

Затем (Bash с `run_in_background`) ждать `check exit` в журнале: `until $P 'grep -q "^check exit" ~/cmprovision/build/check-2.5.3.log'; do sleep 15; done; $P 'cat ~/cmprovision/build/check-2.5.3.log'`.

Expected: `2.5.3 install ok installed`; все семь служб `active`; `helper: 755 root; conf.d: 644 root`; `sudoers: /etc/sudoers.d/010_cmprovision: parsed OK`; `www-data scans: N networks in reach` (N > 0); JSON списка — сеть `78` с двумя UUID (`uplink-m2`, `uplink-onboard`), `devices` — `{"wlan1":"M.2 card (wlan1)"}`; карта `wlan1:connected`, встроенный `wlan0:disconnected`; `failed jobs` — как до установки; `check exit 0`.

- [ ] **Step 5: Пуш — только если шаги 3–4 прошли**

С этого компьютера: `curl -s -o /dev/null -w '%{http_code}\n' http://192.168.68.237/login` → `200`. Затем:

```bash
git push -q origin main stage2 v2.5.3
git fetch -q origin && git status -sb | head -1
```

Expected: `## stage2...origin/stage2` без расхождений. Если шаги 3–4 не прошли — не пушить и сообщить пользователю; локальные слияние и тег до пуша можно переделать (ruling), откат пакета — `sudo apt install --allow-downgrades ~/cmprovision/build/cmprovision4_2.5.2_all.deb`, только по его слову.

- [ ] **Step 6: Живые проверки — только по отдельному слову пользователя**

Записать `$SP/prov-wifi253.sh` — тот же путь, что у раздела Settings (`www-data` → `sudo -n` → помощник), без браузера:

```sh
#!/bin/sh
# The Wi-Fi networks of 2.5.3, live: Check now on the built-in Wi-Fi, the take-over of '78', a network out of reach; the
# card keeps working throughout, and a ping through it says so. Only the provisioner's own network and a made-up name:
# no attempt on anybody else's network
set -e
trap 'kill $PING 2>/dev/null; echo "check exit $?"' EXIT
W="sudo -n -u www-data sudo -n /usr/lib/cmprovision/wifi-networks"
uuid() { nmcli -t -f UUID,NAME connection show | sed -n "s/:$1\$//p" | head -n 1; }
wifi() { nmcli -t -f DEVICE,STATE device status | grep '^wlan' | tr '\n' ' '; }
GW=$(ip -4 route show default dev wlan1 | awk '{ print $3; exit }')
ping -i 1 -I wlan1 "$GW" > /tmp/ping-wlan1.log 2>&1 &
PING=$!
M2=$(uuid uplink-m2)
echo "== Check now '78' before the take-over (through uplink-onboard, its own metric 600)"
printf 'uuid=%s\nby=claude\n' "$M2" | $W check
echo "  $(wifi)"
echo "== take-over: save '78' (WPA2, the password kept)"
printf 'uuid=%s\nssid=78\nsecurity=wpa2\nhidden=no\npassword=\nby=claude\n' "$M2" | $W save
nmcli -t -f NAME,UUID,DEVICE,FILENAME connection show | grep -v -E '^cmprovision:|^lo:'
echo "  $(nmcli -t -f connection.multi-connect,ipv4.route-metric connection show "$M2" | tr '\n' ' ')"
echo "  $(wifi)"
echo "== Check now '78' after the take-over: the metric of the built-in Wi-Fi comes from conf.d"
( for i in $(seq 1 60); do ip -4 route show default dev wlan0 2>/dev/null; sleep 0.5; done ) > /tmp/routes-wlan0.log &
printf 'uuid=%s\nby=claude\n' "$M2" | $W check
sleep 1; echo "  wlan0's default routes during the check: $(sort -u /tmp/routes-wlan0.log | tr '\n' ' ')"
echo "  $(wifi)"
echo "== a network out of reach: a made-up name, checked, deleted"
X=$(printf 'ssid=cmp-check-out-of-reach\nsecurity=wpa2\nhidden=no\npassword=nothing-here-1\nby=claude\n' | $W save | sed -n 's/^uuid=//p')
printf 'uuid=%s\nby=claude\n' "$X" | $W check
printf 'uuid=%s\nby=claude\n' "$X" | $W delete && echo "  deleted"
echo "  $(wifi)"
kill $PING 2>/dev/null || true
sleep 1
echo "ping through the card: $(grep -c 'bytes from' /tmp/ping-wlan1.log) answers, no answer $(grep -c -i -E 'unreachable|timeout' /tmp/ping-wlan1.log) times"
echo "journal:"; sudo journalctl -t cmprovision-wifi --since "-10min" --no-pager -o cat | sed 's/^/  /'
```

Запустить так же, как `prov-install253.sh` (копия, затем `setsid nohup`, журнал `check-2.5.3-wifi.log`).

Expected:
- первый Check now: `result=connected`, `address=192.168.68.236/24`; после — `wlan1:connected wlan0:disconnected`;
- переход: `uuid=<M2>`; остался один Wi-Fi-профиль (бывший `uplink-m2`, теперь `78`), `connection.multi-connect:3 ipv4.route-metric:-1`; карта не отключалась;
- второй Check now — снова `connected`; маршрут `wlan0` во время проверки — `… metric 600` (метрики у профиля больше нет: это `conf.d`, раздел `brcmfmac`);
- выдуманная сеть: `result=not-found`, затем `deleted`;
- пинг через карту — ответы всё время, `no answer 0 times`; в журнале — `claude checked '78' on wlan0: connected, 192.168.68.236`, `claude took over '78' (2 profiles into 1)`, `claude saved '78' (WPA2)`, второй `checked '78' … connected`, `claude added 'cmp-check-out-of-reach' (WPA2)`, `… checked 'cmp-check-out-of-reach' on wlan0: network not found`, `claude deleted 'cmp-check-out-of-reach'`.

Спека предлагала проверить неверный пароль «новой сетью с тем же SSID», но помощник такую не примет (SSID уже в списке). Неверный пароль, WPA3 и смешанный режим — на раздаче телефона пользователя, если он её включит (Android 10+: WPA3-Personal или WPA2/WPA3): добавить сеть через раздел Settings в браузере (так проверяется и сама страница) с неверным паролем → Check now → `wrong password`; поправить пароль → Check now → `connected`; удалить. Что NetworkManager передал в wpa_supplicant, — по `sudo journalctl -u NetworkManager --since -5min | grep -i -E 'sae|key_mgmt|pmf'`. Чужие сети не трогать.

- [ ] **Step 7: Память**

В `memory/cmprovision-fork-plan.md`: 2.5.3 выпущена и установлена на wlanpi-da2 (дата, что проверено вживую), следующая — удалённое затирание сенсоров (спека только в локальной ветке `wipe-spec`, «на другой модели»); строку в `MEMORY.md` обновить. В `memory/provisioner-trixie-setup.md`: сеть «78» — один профиль на обе карты (`multi-connect`), метрики из `conf.d`, Wi-Fi правится в Settings.
