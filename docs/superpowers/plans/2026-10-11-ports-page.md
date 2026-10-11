# Страница Ports — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** страница `/ports` показывает стенд по портам коммутатора: какой модуль в каком порту и что с ним, линк и PoE порта, сводку «что на стенде» с фильтром по состояниям.

**Architecture:** таблица MAC выносится из `SwitchPortFinder` в `App\Services\Switch\MacTable`. `SwitchSnapshot` одним опросом читает порты (IF-MIB), MAC на них и PoE (`Poe`: HUAWEI-POE-MIB, Cisco IOS через ENTITY-MIB, RFC 3621). `SnapshotCache` делит снимок между открытыми страницами: 5 секунд, дольше для медленного коммутатора, один опрос за раз. `App\Support\StandMap` — чистая логика плиток, раскладки и сводки. Livewire-компонент `Ports` рисует панель коммутатора, фильтр живёт в Alpine.

**Tech Stack:** PHP 8.3+ (стенд 8.4), Laravel 13, Livewire 3.8, Alpine 3 (приходит с Livewire), Tailwind 3.4 (бинарник), php-snmp, PHPUnit 12, snmpsim.

**Spec:** `docs/superpowers/specs/2026-10-11-ports-page-design.md` (макет: https://claude.ai/artifact/WU82TamYCFzbnZMDgW4yqp, версия 2).

## Global Constraints

- PHP 8.3 и новее; Laravel 13 / Livewire 3: компоненты в `App\Http\Livewire`, макет `layouts.app`, методы, которые браузер вызывать не должен, — `protected`.
- Коммутатор только читается, через существующие настройки (`SwitchPortFinder::config()`). Новых секретов, служб и процессов нет; коммутатор опрашивается, только пока страница открыта (`wire:poll.5s`).
- Снимок свежий 5 секунд; если опрос длился дольше 2,5 секунды — вдвое дольше длительности опроса. Одновременно идёт один опрос (`Cache::lock`), остальные ждут его не дольше 1 секунды.
- Существующие тесты `SwitchPortFinderTest`, `SwitchSettingsTest` и `SnmpAgentTest` не меняются и проходят; в `SnmpAgentTest` только добавляются новые тесты.
- Тексты интерфейса — по-английски, как всё приложение. Русские строки спеки переводятся (таблица в задаче 7).
- В репозитории в OneDrive не создаются `vendor/` и `node_modules/`. Тесты гоняются на WSL-стенде `cmprov-trixie` помощниками из `dev.sh`; CSS собирает `/root/bench/25-css.sh`, результат `public/css/app.css` коммитится.
- Версия сборок — `2.1.0~devN` в `debian/changelog`.
- Каждый коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Push — только при выпуске, после «да» пользователя.

Переменные и помощники в командах (Git Bash):

```bash
REPO="/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
SP="/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad"
. "$SP/wsl-bench/dev.sh"   # sync_dev, tests '<regex>', tests_all, tests_ok, tests_all_ok, w, w_raw, from_dev
```

`wsl.exe` раскрывает `$` в аргументах раньше времени и ломает кавычки в `sh -c '...'`, поэтому многострочные команды для стенда идут скриптом на stdin: `cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s`. Стенд останавливается без открытой сессии `wsl.exe`, а его `/tmp` — tmpfs: то, что должно пережить вызов (snmpsim, файлы в `/tmp`), запускается в той же сессии или при фоновом `wsl.exe -d cmprov-trixie -u root -- sleep 3600`.

## Review Focus

1. **Несколько модулей за одним портом** (настольный свитч, цепочка). Оператор видит их все: плитка берёт худшее состояние и пишет «×N», в подробностях все модули, сводка считает каждый. Тест — задача 5.
2. **Модуль переставили в другой порт**, а его MAC ещё не устарел в таблице коммутатора. Старый порт без линка показан свободным, модуль — только в новом и посчитан один раз. Тест — задача 5.
3. **Старый или непривычный агент:** нет ifXTable (`ifName`, `ifHighSpeed`, `ifConnectorPresent`) или `ifConnectorPresent` у всех интерфейсов «нет». Имена берутся из `ifDescr`, скорость — из `ifSpeed`, Ethernet-порты видны. Тесты — задача 2.
4. **Имена MikroTik и Cisco** (`ether10`, `sfp-sfpplus1`, `Te1/1/1`) на коммутаторе без PoE-таблиц. Гнёзда уходят в блок аплинков, сортировка естественная (`ether2` раньше `ether10`). Тест — задача 5.
5. **Коммутатор перестал отвечать или отвечает отказом** (таймаут; неверная учётка v3, при которой все таблицы пусты). Остаётся последний снимок с плашкой, опрос не чаще окна свежести; без снимка — «Cannot read the switch: причина», а не пустая панель. Тесты — задачи 2, 4 и 7.

---

### Task 1: MacTable — таблица MAC отдельно от поиска порта

**Files:**
- Create: `app/Services/Switch/MacTable.php`
- Modify: `app/Services/SwitchPortFinder.php` (методы чтения таблицы переезжают, публичное поведение то же)
- Test: `tests/Unit/MacTableTest.php`

**Interfaces:**
- Produces: `App\Services\Switch\MacTable` — `__construct(SnmpClient $snmp, $method = 'auto', $preferred = null)`, `methods(): array`, `rows(string $method): array` (бросает `SnmpError`), `rowsOrNothing(string $method): array`, `entries(): array` → `['method' => ?string, 'rows' => [['mac' => 'aa:bb:cc:dd:ee:ff', 'vlan' => ?int, 'ifIndex' => int], ...]]` (молчащий коммутатор — `SnmpError` с `timeout = true`); статические `pick(array $rows)`, `vlanOf(array $rows, $mac, $ifIndex)`, `index(string $oid, string $column)`, `mac(array $octets)`; константы `METHODS`, `OID_QBRIDGE_FDB_PORT`, `OID_BRIDGE_FDB_PORT`, `OID_BASEPORT_IFINDEX`, `OID_HUAWEI_L2IF_IFINDEX`, `OID_HUAWEI_FDB_PORT`, `OID_CISCO_VLAN_STATE`, `CISCO_RESERVED_VLANS`.
- `SwitchPortFinder` сохраняет все свои константы (как ссылки на `MacTable::…`) и публичные методы.

Имя пространства `App\Services\Switch` допустимо: с PHP 8.0 составное имя — один токен, ключевое слово `switch` внутри него не мешает.

- [ ] **Step 1: Падающий тест**

`tests/Unit/MacTableTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * The switch's MAC address table as rows the Ports page and the port finder share.
 */
class MacTableTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    public function test_entries_come_from_the_first_method_with_rows_and_later_ones_add_only_missing_macs()
    {
        $snmp = new FakeSnmp(['' => [
            MacTable::OID_QBRIDGE_FDB_PORT.'.10.'.self::MODULE_OID => 5,
            MacTable::OID_BASEPORT_IFINDEX.'.5' => 105,
            // BRIDGE-MIB knows the module as well (on another port: not taken) and one MAC Q-BRIDGE lacks
            MacTable::OID_BRIDGE_FDB_PORT.'.'.self::MODULE_OID => 7,
            MacTable::OID_BRIDGE_FDB_PORT.'.0.17.34.51.68.85' => 7,
            MacTable::OID_BASEPORT_IFINDEX.'.7' => 107,
        ]]);

        $entries = (new MacTable($snmp))->entries();

        $this->assertSame('qbridge', $entries['method']);
        $this->assertEquals([
            ['mac' => self::MODULE, 'vlan' => 10, 'ifIndex' => 105],
            ['mac' => '00:11:22:33:44:55', 'vlan' => null, 'ifIndex' => 107],
        ], $entries['rows']);
    }

    public function test_entries_of_a_switch_without_a_mac_table_are_empty()
    {
        $entries = (new MacTable(new FakeSnmp(['' => []])))->entries();
        $this->assertSame(['method' => null, 'rows' => []], $entries);
    }

    public function test_a_silent_switch_is_an_error()
    {
        $snmp = new FakeSnmp(['' => []]);
        $snmp->timeout = true;
        $this->expectException(SnmpError::class);
        (new MacTable($snmp))->entries();
    }

    public function test_pick_gives_a_mac_seen_on_two_ports_to_the_one_with_fewer_macs()
    {
        $rows = [
            ['mac' => 'aa:aa:aa:aa:aa:01', 'ifIndex' => 1, 'vlan' => 1],
            ['mac' => 'aa:aa:aa:aa:aa:01', 'ifIndex' => 9, 'vlan' => 1],
            ['mac' => 'aa:aa:aa:aa:aa:02', 'ifIndex' => 9, 'vlan' => 1],
        ];
        $this->assertSame(['aa:aa:aa:aa:aa:01' => 1, 'aa:aa:aa:aa:aa:02' => 9], MacTable::pick($rows));
    }
}
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'MacTableTest'`
Expected: `phpunit exit 2` (или `1`), в выводе `Class "App\Services\Switch\MacTable" not found`.

- [ ] **Step 3: `app/Services/Switch/MacTable.php`**

```php
<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/**
 * The switch's learned MAC address table over SNMP, as [mac, vlan, ifIndex] rows. Switches publish it
 * differently, so several methods are tried (see METHODS):
 *  - Q-BRIDGE-MIB dot1qTpFdbTable: VLAN-aware switches (Huawei, MikroTik, Cisco Business, ...)
 *  - BRIDGE-MIB dot1dTpFdbTable: the classic table
 *  - HUAWEI-L2MAM-MIB hwDynFdbTable: Huawei, gives the interface directly
 *  - Cisco IOS: one BRIDGE-MIB instance per VLAN (community@vlan, or context vlan-<id> for v3),
 *    VLANs from CISCO-VTP-MIB
 * Bridge port numbers are mapped to interfaces with dot1dBasePortIfIndex (or HUAWEI-L2IF-MIB).
 * SwitchPortFinder (the port of one module) and SwitchSnapshot (the Ports page) read it.
 */
class MacTable
{
    const METHODS = [
        'qbridge' => 'Q-BRIDGE-MIB (VLAN-aware MAC table)',
        'bridge' => 'BRIDGE-MIB (MAC table)',
        'huawei' => 'Huawei HUAWEI-L2MAM-MIB',
        'cisco' => 'Cisco IOS: BRIDGE-MIB per VLAN',
    ];

    const OID_QBRIDGE_FDB_PORT = '.1.3.6.1.2.1.17.7.1.2.2.1.2';   // dot1qTpFdbPort.<fdbId>.<mac>
    const OID_BRIDGE_FDB_PORT = '.1.3.6.1.2.1.17.4.3.1.2';        // dot1dTpFdbPort.<mac>
    const OID_BASEPORT_IFINDEX = '.1.3.6.1.2.1.17.1.4.1.2';       // dot1dBasePortIfIndex.<port>
    const OID_HUAWEI_L2IF_IFINDEX = '.1.3.6.1.4.1.2011.5.25.42.1.1.1.3.1.2';   // hwL2IfPortIfIndex.<port>
    const OID_HUAWEI_FDB_PORT = '.1.3.6.1.4.1.2011.5.25.42.2.1.3.1.4';   // hwDynFdbPort.<mac>.<vlan>.<vsi>
    const OID_CISCO_VLAN_STATE = '.1.3.6.1.4.1.9.9.46.1.3.1.1.2';        // vtpVlanState.<domain>.<vlan>
    const CISCO_RESERVED_VLANS = [1002, 1003, 1004, 1005];

    protected $snmp, $method, $preferred;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->method = $method;
        $this->preferred = $preferred;
    }

    /* The methods to try, in order */
    public function methods()
    {
        if ($this->method !== 'auto')
            return [$this->method];
        $methods = array_keys(self::METHODS);
        if ($this->preferred && in_array($this->preferred, $methods, true))
            $methods = array_values(array_unique(array_merge([$this->preferred], $methods)));
        return $methods;
    }

    /**
     * The rows of the first method with entries; later methods add only MACs it lacks, as
     * SwitchPortFinder::scan() does for the settings page. A switch that does not answer throws SnmpError.
     */
    public function entries()
    {
        $result = ['method' => null, 'rows' => []];
        $seenBy = [];
        foreach ($this->methods() as $method)
        {
            $rows = $this->rowsOrNothing($method);
            if (!$rows)
                continue;
            $result['method'] = $result['method'] ?: $method;
            foreach ($rows as $row)
                if (!isset($seenBy[$row['mac']]) || $seenBy[$row['mac']] === $method)
                {
                    $seenBy[$row['mac']] = $method;
                    $result['rows'][] = $row;
                }
        }
        return $result;
    }

    /* rows() that turns "this method does not work here" into no rows, but lets a silent switch through */
    public function rowsOrNothing($method)
    {
        try
        {
            return $this->rows($method);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
    }

    /* Learned entries as [['mac' => ..., 'vlan' => ..., 'ifIndex' => ...], ...] */
    public function rows($method)
    {
        switch ($method)
        {
            case 'qbridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_QBRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = self::index($oid, self::OID_QBRIDGE_FDB_PORT);
                    if (count($index) >= 7 && (int) $port > 0)
                        $rows[] = ['mac' => self::mac(array_slice($index, -6)), 'port' => (int) $port, 'vlan' => $index[0]];
                }
                return $this->toIfIndex($rows);

            case 'bridge':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_BRIDGE_FDB_PORT) as $oid => $port)
                {
                    $index = self::index($oid, self::OID_BRIDGE_FDB_PORT);
                    if (count($index) == 6 && (int) $port > 0)
                        $rows[] = ['mac' => self::mac($index), 'port' => (int) $port, 'vlan' => null];
                }
                return $this->toIfIndex($rows);

            case 'huawei':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_HUAWEI_FDB_PORT) as $oid => $ifIndex)
                {
                    $index = self::index($oid, self::OID_HUAWEI_FDB_PORT);
                    if (count($index) >= 7 && (int) $ifIndex > 0)
                        $rows[] = ['mac' => self::mac(array_slice($index, 0, 6)), 'ifIndex' => (int) $ifIndex, 'vlan' => $index[6]];
                }
                return $rows;

            case 'cisco':
                $rows = [];
                foreach ($this->snmp->walk(self::OID_CISCO_VLAN_STATE) as $oid => $state)
                {
                    $vlan = (int) substr(strrchr($oid, '.'), 1);
                    if ((int) $state !== 1 || in_array($vlan, self::CISCO_RESERVED_VLANS, true))
                        continue;   // only operational VLANs; 1002-1005 are the FDDI/Token Ring leftovers
                    $vlanRows = [];
                    foreach ($this->snmp->walk(self::OID_BRIDGE_FDB_PORT, $vlan) as $fdbOid => $port)
                    {
                        $index = self::index($fdbOid, self::OID_BRIDGE_FDB_PORT);
                        if (count($index) == 6 && (int) $port > 0)
                            $vlanRows[] = ['mac' => self::mac($index), 'port' => (int) $port, 'vlan' => $vlan];
                    }
                    if ($vlanRows)
                        $rows = array_merge($rows, $this->toIfIndex($vlanRows, $vlan));
                }
                return $rows;
        }
        throw new \InvalidArgumentException("Unknown method $method");
    }

    /* Bridge port numbers to interface indexes (per VLAN instance on Cisco), from BRIDGE-MIB or, where
       that is missing (some Huawei YunShan models), HUAWEI-L2IF-MIB. An agent with neither is assumed
       to report interface indexes already (Cisco Business does). */
    protected function toIfIndex(array $rows, $vlan = null)
    {
        if (!$rows)
            return [];
        $map = [];
        foreach ([self::OID_BASEPORT_IFINDEX, self::OID_HUAWEI_L2IF_IFINDEX] as $column)
        {
            if ($map || ($column === self::OID_HUAWEI_L2IF_IFINDEX && $vlan !== null))
                break;
            try
            {
                $values = $this->snmp->walk($column, $vlan);
            }
            catch (SnmpError $e)
            {
                if ($e->timeout)
                    throw $e;
                continue;
            }
            foreach ($values as $oid => $ifIndex)
                $map[(int) substr(strrchr($oid, '.'), 1)] = (int) $ifIndex;
        }
        foreach ($rows as &$row)
        {
            $row['ifIndex'] = $map[$row['port']] ?? $row['port'];
            unset($row['port']);
        }
        return $rows;
    }

    /* [mac => ifIndex, or false when it is equally present on several ports] */
    public static function pick(array $rows)
    {
        $macsPerPort = [];
        $portsPerMac = [];
        foreach ($rows as $row)
        {
            $macsPerPort[$row['ifIndex']][$row['mac']] = true;
            $portsPerMac[$row['mac']][$row['ifIndex']] = true;
        }

        $result = [];
        foreach ($portsPerMac as $mac => $ports)
        {
            $ports = array_keys($ports);
            if (count($ports) == 1)
            {
                $result[$mac] = $ports[0];
                continue;
            }
            usort($ports, function ($a, $b) use ($macsPerPort) {
                return count($macsPerPort[$a]) - count($macsPerPort[$b]);
            });
            $result[$mac] = count($macsPerPort[$ports[0]]) < count($macsPerPort[$ports[1]]) ? $ports[0] : false;
        }
        return $result;
    }

    public static function vlanOf(array $rows, $mac, $ifIndex)
    {
        foreach ($rows as $row)
            if ($row['mac'] === $mac && ($ifIndex === false || $row['ifIndex'] === $ifIndex))
                return $row['vlan'] === null ? null : (int) $row['vlan'];
        return null;
    }

    public static function index($oid, $column)
    {
        return array_map('intval', explode('.', substr($oid, strlen($column) + 1)));
    }

    public static function mac(array $octets)
    {
        return implode(':', array_map(function ($o) { return sprintf('%02x', $o); }, $octets));
    }
}
```

- [ ] **Step 4: `SwitchPortFinder` читает через `MacTable`**

В `app/Services/SwitchPortFinder.php`:

1. Добавить `use App\Services\Switch\MacTable;` к остальным `use`.
2. Комментарий класса заменить на:

```php
/**
 * Which switch port a MAC address is behind, from the switch's learned MAC table over SNMP (MacTable,
 * which also lists the methods tried). The port is named with ifAlias (the port description, when
 * set) or ifName.
 */
```

3. Блок констант (от `const METHODS = [` до `const CISCO_RESERVED_VLANS = [1002, 1003, 1004, 1005];` включительно) заменить на:

```php
    const METHODS = MacTable::METHODS;

    const OID_QBRIDGE_FDB_PORT = MacTable::OID_QBRIDGE_FDB_PORT;
    const OID_BRIDGE_FDB_PORT = MacTable::OID_BRIDGE_FDB_PORT;
    const OID_BASEPORT_IFINDEX = MacTable::OID_BASEPORT_IFINDEX;
    const OID_HUAWEI_L2IF_IFINDEX = MacTable::OID_HUAWEI_L2IF_IFINDEX;
    const OID_HUAWEI_FDB_PORT = MacTable::OID_HUAWEI_FDB_PORT;
    const OID_CISCO_VLAN_STATE = MacTable::OID_CISCO_VLAN_STATE;
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const CISCO_RESERVED_VLANS = MacTable::CISCO_RESERVED_VLANS;
```

4. Свойства и конструктор:

```php
    protected $snmp, $table, $lastMethod, $names;

    /** @param string $method "auto" or one of METHODS; with auto the preferred method goes first */
    public function __construct(SnmpClient $snmp, $method = 'auto', $preferred = null)
    {
        $this->snmp = $snmp;
        $this->table = new MacTable($snmp, $method, $preferred);
    }
```

5. В `portOf()` и `scan()` заменить вызовы: `$this->methods()` → `$this->table->methods()`, `$this->rowsOrNothing($method)` → `$this->table->rowsOrNothing($method)`, `$this->rows($method)` → `$this->table->rows($method)`, `$this->pick($rows)` → `MacTable::pick($rows)`, `$this->vlanOf(` → `MacTable::vlanOf(`.
6. Удалить методы `methods()`, `rowsOrNothing()`, `rows()`, `toIfIndex()`, `pick()`, `vlanOf()`, `index()`, `mac()`. Остаются `fromSettings()`, `forConfig()`, `config()`, `lastMethod()`, `portOf()`, `scan()`, `label()`.

- [ ] **Step 5: Тесты зелёные, старые не тронуты**

Run: `sync_dev && tests 'MacTableTest|SwitchPortFinderTest|SwitchSettingsTest' && cd "$REPO" && git status --short tests/`
Expected: `phpunit exit 0`, `OK (…)`; `git status` показывает в `tests/` только `?? tests/Unit/MacTableTest.php`.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add app/Services/Switch/MacTable.php app/Services/SwitchPortFinder.php tests/Unit/MacTableTest.php
git commit -q -m "The switch's MAC table gets a class of its own for the coming Ports page" -m "SwitchPortFinder reads it through MacTable and behaves as before; entries() gives the rows the page needs." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: SwitchSnapshot — порты, линк и MAC

**Files:**
- Create: `app/Services/Switch/SwitchSnapshot.php`
- Test: `tests/Unit/SwitchSnapshotTest.php`

**Interfaces:**
- Consumes: `MacTable::entries()` (задача 1).
- Produces: `App\Services\Switch\SwitchSnapshot` — `__construct(SnmpClient $snmp, MacTable $macs, ?Poe $poe = null, string $id = 'switch')`, `static forConfig(array $config): self` (id — адрес из настроек), `take(): array` →
  `['id' => string, 'model' => string, 'taken_at' => int (unix), 'duration' => float (с), 'mac_method' => ?string, 'poe' => bool, 'ports' => [ifIndex => ['ifIndex' => int, 'name' => string, 'alias' => string, 'up' => bool, 'speed' => ?int, 'macs' => [string, ...], 'poe' => ?array]]]`.
  `speed` — Мбит/с, как сообщает коммутатор: с линком — скорость соединения, без линка — обычно номинальная; `null`, если неизвестна. Потребители смотрят на `up`.
  Молчащий коммутатор — `SnmpError` с `timeout = true`; коммутатор, не назвавший ни одного интерфейса (неверная учётка v3 и т. п.), — `SnmpError` с `timeout = false`.
  Константы `OID_SYSDESCR`, `OID_SYSNAME`, `OID_IFDESCR`, `OID_IFTYPE`, `OID_IFSPEED`, `OID_IFOPERSTATUS`, `OID_IFNAME`, `OID_IFHIGHSPEED`, `OID_IFCONNECTOR`, `OID_IFALIAS`, `ETHERNET_TYPES`.
- Класс `Poe` появляется в задаче 3. Тип `?Poe` с `null` по умолчанию до неё не загружается; `forConfig()` до задачи 3 передаёт `null`, задача 3 это меняет.

- [ ] **Step 1: Падающий тест**

`tests/Unit/SwitchSnapshotTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\MacTable;
use App\Services\Switch\SwitchSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * One look at the switch for the Ports page: its physical ports, their link and speed, and the MAC
 * addresses learned on each.
 */
class SwitchSnapshotTest extends TestCase
{
    const MODULE = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    /** IF-MIB rows for [ifIndex => [name, ifType, ifConnectorPresent, ifOperStatus, ifHighSpeed]] */
    protected function interfaces(array $ports)
    {
        $t = [];
        foreach ($ports as $ifIndex => [$name, $type, $connector, $oper, $high])
        {
            $t[SwitchSnapshot::OID_IFNAME.'.'.$ifIndex] = $name;
            $t[SwitchSnapshot::OID_IFDESCR.'.'.$ifIndex] = 'description of '.$name;
            $t[SwitchSnapshot::OID_IFTYPE.'.'.$ifIndex] = $type;
            $t[SwitchSnapshot::OID_IFCONNECTOR.'.'.$ifIndex] = $connector;
            $t[SwitchSnapshot::OID_IFOPERSTATUS.'.'.$ifIndex] = $oper;
            $t[SwitchSnapshot::OID_IFHIGHSPEED.'.'.$ifIndex] = $high;
        }
        return $t;
    }

    protected function take(array $table)
    {
        $snmp = new FakeSnmp(['' => $table]);
        return (new SwitchSnapshot($snmp, new MacTable($snmp), null, '192.0.2.10'))->take();
    }

    public function test_physical_ethernet_ports_with_link_speed_and_macs()
    {
        $s = $this->take($this->interfaces([
            6 => ['GE0/0/1', 6, 1, 1, 1000],
            7 => ['GE0/0/2', 6, 1, 2, 1000],
            8 => ['GE0/0/3', 6, 1, 1, 1000],
            30 => ['XGE0/0/1', 6, 1, 1, 10000],
            50 => ['Vlanif1', 136, 2, 1, 1000],
            51 => ['Eth-Trunk1', 161, 2, 1, 2000],
            52 => ['NULL0', 1, 2, 1, 0],
        ]) + [
            SwitchSnapshot::OID_SYSDESCR.'.0' => "S5731-S24P4X\r\nHuawei Versatile Routing Platform Software",
            SwitchSnapshot::OID_IFALIAS.'.8' => 'stand 3',
            // HUAWEI-L2MAM: the module on GE0/0/3, the LAN behind the 10G uplink, the switch's own VLAN interface
            MacTable::OID_HUAWEI_FDB_PORT.'.'.self::MODULE_OID.'.10.0' => 8,
            MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.85.1.0' => 30,
            MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.86.1.0' => 50,
        ]);

        $this->assertSame([6, 7, 8, 30], array_keys($s['ports']), 'VLAN interfaces, trunks and NULL0 are not ports');
        $this->assertSame('S5731-S24P4X', $s['model']);
        $this->assertSame('huawei', $s['mac_method']);
        $this->assertSame('192.0.2.10', $s['id']);
        $this->assertFalse($s['poe']);
        $this->assertSame(['ifIndex' => 8, 'name' => 'GE0/0/3', 'alias' => 'stand 3', 'up' => true, 'speed' => 1000,
                           'macs' => [self::MODULE], 'poe' => null], $s['ports'][8]);
        $this->assertFalse($s['ports'][7]['up']);
        $this->assertSame(1000, $s['ports'][7]['speed'], 'what the switch says without a link');
        $this->assertSame(['00:11:22:33:44:55'], $s['ports'][30]['macs']);
        $this->assertSame(10000, $s['ports'][30]['speed']);
    }

    public function test_an_old_switch_without_if_x_table_gets_names_from_if_descr_and_speed_from_if_speed()
    {
        $s = $this->take([
            SwitchSnapshot::OID_IFDESCR.'.1' => 'Port 1',
            SwitchSnapshot::OID_IFTYPE.'.1' => 6,
            SwitchSnapshot::OID_IFOPERSTATUS.'.1' => 1,
            SwitchSnapshot::OID_IFSPEED.'.1' => 100000000,
            SwitchSnapshot::OID_IFDESCR.'.2' => 'Port 2',
            SwitchSnapshot::OID_IFTYPE.'.2' => 6,
            SwitchSnapshot::OID_IFOPERSTATUS.'.2' => 2,
            SwitchSnapshot::OID_SYSDESCR.'.0' => '',
            SwitchSnapshot::OID_SYSNAME.'.0' => 'old-switch',
        ]);

        $this->assertSame(['Port 1', 'Port 2'], array_column($s['ports'], 'name'));
        $this->assertSame(100, $s['ports'][1]['speed']);
        $this->assertNull($s['ports'][2]['speed']);
        $this->assertSame('old-switch', $s['model']);
    }

    public function test_connector_flags_are_ignored_when_no_interface_has_one()
    {
        $s = $this->take($this->interfaces([
            1 => ['ether1', 6, 2, 1, 1000],
            2 => ['ether2', 6, 2, 2, 1000],
        ]));

        $this->assertSame(['ether1', 'ether2'], array_column($s['ports'], 'name'));
    }

    public function test_mikrotik_ports_and_its_bridge_interface()
    {
        $s = $this->take([
            SwitchSnapshot::OID_IFNAME.'.1' => 'ether1', SwitchSnapshot::OID_IFTYPE.'.1' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.1' => 1,
            SwitchSnapshot::OID_IFNAME.'.9' => 'sfp-sfpplus1', SwitchSnapshot::OID_IFTYPE.'.9' => 6, SwitchSnapshot::OID_IFOPERSTATUS.'.9' => 2,
            SwitchSnapshot::OID_IFNAME.'.12' => 'bridge', SwitchSnapshot::OID_IFTYPE.'.12' => 209, SwitchSnapshot::OID_IFOPERSTATUS.'.12' => 1,
        ]);

        $this->assertSame(['ether1', 'sfp-sfpplus1'], array_column($s['ports'], 'name'));
    }

    public function test_a_silent_switch_throws()
    {
        $snmp = new FakeSnmp(['' => []]);
        $snmp->timeout = true;
        $this->expectException(SnmpError::class);
        (new SwitchSnapshot($snmp, new MacTable($snmp)))->take();
    }

    public function test_a_switch_that_lists_no_interfaces_is_an_error_not_an_empty_stand()
    {
        // e.g. SNMPv3 with a wrong user: every table answers with an error, none with a timeout
        $this->expectException(SnmpError::class);
        $this->expectExceptionMessage('The switch lists no interfaces');
        $this->take([]);
    }
}
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'SwitchSnapshotTest'`
Expected: падение — `Class "App\Services\Switch\SwitchSnapshot" not found`.

- [ ] **Step 3: `app/Services/Switch/SwitchSnapshot.php`**

```php
<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use Illuminate\Support\Carbon;

/**
 * One look at the switch for the Ports page: the physical ports with link and speed, the MAC
 * addresses learned on each (MacTable) and PoE where the switch tells it (Poe). Read only.
 */
class SwitchSnapshot
{
    const OID_SYSDESCR = '.1.3.6.1.2.1.1.1';
    const OID_SYSNAME = '.1.3.6.1.2.1.1.5';
    const OID_IFDESCR = '.1.3.6.1.2.1.2.2.1.2';
    const OID_IFTYPE = '.1.3.6.1.2.1.2.2.1.3';
    const OID_IFSPEED = '.1.3.6.1.2.1.2.2.1.5';           // bit/s
    const OID_IFOPERSTATUS = '.1.3.6.1.2.1.2.2.1.8';      // 1 up, 2 down
    const OID_IFNAME = '.1.3.6.1.2.1.31.1.1.1.1';
    const OID_IFHIGHSPEED = '.1.3.6.1.2.1.31.1.1.1.15';   // Mbit/s
    const OID_IFCONNECTOR = '.1.3.6.1.2.1.31.1.1.1.17';   // 1 a physical connector, 2 none
    const OID_IFALIAS = '.1.3.6.1.2.1.31.1.1.1.18';
    /* ethernetCsmacd; some switches report gigabitEthernet(117) */
    const ETHERNET_TYPES = [6, 117];

    protected $snmp, $macs, $poe, $id, $lastError;

    public function __construct(SnmpClient $snmp, MacTable $macs, ?Poe $poe = null, string $id = 'switch')
    {
        $this->snmp = $snmp;
        $this->macs = $macs;
        $this->poe = $poe;
        $this->id = $id;
    }

    /* The switch of these settings (SwitchPortFinder::config()), named by its address */
    public static function forConfig(array $config): self
    {
        $snmp = app(SnmpClient::class, ['config' => $config]);
        return new self($snmp, new MacTable($snmp, $config['method'] ?? 'auto', $config['detected'] ?? null), null, (string) $config['host']);
    }

    /**
     * ['id', 'model', 'taken_at' (unix time), 'duration' (seconds), 'mac_method', 'poe' (any PoE data),
     *  'ports' => [ifIndex => ['ifIndex', 'name', 'alias', 'up', 'speed' (Mbit/s as the switch reports
     *  it, also without a link; null when unknown), 'macs' => [...], 'poe' => Poe::read() entry or null]]].
     * Throws SnmpError when the switch does not answer or lists no interfaces.
     */
    public function take(): array
    {
        $started = microtime(true);
        $this->lastError = null;
        $c = ['type' => $this->column(self::OID_IFTYPE)];
        if (!$c['type'])
            throw new SnmpError($this->lastError ?: 'The switch lists no interfaces (IF-MIB ifType)');
        foreach (['name' => self::OID_IFNAME, 'descr' => self::OID_IFDESCR, 'alias' => self::OID_IFALIAS,
                  'connector' => self::OID_IFCONNECTOR, 'oper' => self::OID_IFOPERSTATUS,
                  'high' => self::OID_IFHIGHSPEED, 'speed' => self::OID_IFSPEED] as $key => $oid)
            $c[$key] = $this->column($oid);
        // some agents say "no connector" for every interface: then the flag tells nothing
        $connectors = in_array(1, array_map('intval', $c['connector']), true);

        $ports = [];
        foreach ($c['type'] as $ifIndex => $type)
        {
            if (!in_array((int) $type, self::ETHERNET_TYPES, true))
                continue;
            if ($connectors && (int) ($c['connector'][$ifIndex] ?? 2) !== 1)
                continue;   // no connector: a virtual interface
            $name = $this->text($c['name'][$ifIndex] ?? null);
            $ports[$ifIndex] = [
                'ifIndex' => $ifIndex,
                'name' => $name !== '' ? $name : ($this->text($c['descr'][$ifIndex] ?? null) ?: 'port '.$ifIndex),
                'alias' => $this->text($c['alias'][$ifIndex] ?? null),
                'up' => (int) ($c['oper'][$ifIndex] ?? 2) === 1,
                'speed' => $this->speed($c['high'][$ifIndex] ?? null, $c['speed'][$ifIndex] ?? null),
                'macs' => [],
                'poe' => null,
            ];
        }

        $table = $this->macs->entries();
        foreach ($table['rows'] as $row)
            if (isset($ports[$row['ifIndex']]) && !in_array($row['mac'], $ports[$row['ifIndex']]['macs'], true))
                $ports[$row['ifIndex']]['macs'][] = $row['mac'];

        $poe = $this->poe ? $this->poe->read(array_map(function ($port) { return $port['name']; }, $ports)) : [];
        foreach ($poe as $ifIndex => $entry)
            if (isset($ports[$ifIndex]))
                $ports[$ifIndex]['poe'] = $entry;

        return [
            'id' => $this->id,
            'model' => $this->model(),
            'taken_at' => Carbon::now()->getTimestamp(),
            'duration' => round(microtime(true) - $started, 3),
            'mac_method' => $table['method'],
            'poe' => (bool) $poe,
            'ports' => $ports,
        ];
    }

    /* [index => value] of a one-index column; [] when the switch does not have it; a silent switch throws */
    protected function column(string $oid): array
    {
        try
        {
            $values = $this->snmp->walk($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            $this->lastError = $e->getMessage();
            return [];
        }
        $column = [];
        foreach ($values as $key => $value)
            $column[(int) substr(strrchr($key, '.'), 1)] = $value;
        return $column;
    }

    protected function speed($high, $speed): ?int
    {
        if ((int) $high > 0)
            return (int) $high;
        if ((int) $speed > 0 && (int) $speed < 4294967295)
            return (int) round((int) $speed / 1000000);
        return null;
    }

    protected function text($value): string
    {
        return trim((string) $value);
    }

    /* The first line of sysDescr, otherwise sysName, otherwise the address */
    protected function model(): string
    {
        foreach ([self::OID_SYSDESCR, self::OID_SYSNAME] as $oid)
            foreach ($this->column($oid) as $value)
            {
                $lines = preg_split('/[\r\n]+/', trim((string) $value));
                if ($lines[0] !== '')
                    return mb_substr($lines[0], 0, 80);
            }
        return $this->id;
    }
}
```

- [ ] **Step 4: Тесты проходят**

Run: `sync_dev && tests 'SwitchSnapshotTest|MacTableTest'`
Expected: `phpunit exit 0`, `OK (10 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add app/Services/Switch/SwitchSnapshot.php tests/Unit/SwitchSnapshotTest.php
git commit -q -m "SwitchSnapshot: the switch's physical ports with link, speed and learned MACs in one look" -m "A switch that lists no interfaces (a wrong SNMPv3 user, say) is an error, not an empty stand." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Poe — PoE порта у Huawei, Cisco IOS и по RFC 3621

**Files:**
- Create: `app/Services/Switch/Poe.php`
- Modify: `app/Services/Switch/SwitchSnapshot.php` (`forConfig()` передаёт `new Poe($snmp)`)
- Test: `tests/Unit/PoeTest.php`, дополнение в `tests/Unit/SwitchSnapshotTest.php`

**Interfaces:**
- Produces: `App\Services\Switch\Poe` — `__construct(SnmpClient $snmp)`, `read(array $ports): array`: на входе `[ifIndex => name]` физических портов, на выходе `[ifIndex => ['state' => 'delivering'|'searching'|'fault'|'disabled'|'test'|'other', 'class' => ?int (0–4), 'watts' => ?float]]`. Порты, о которых коммутатор молчит, в ответ не попадают. Молчащий коммутатор — `SnmpError`, отсутствие таблиц — `[]`.
- Константы `OID_PETH_DETECTION`, `OID_PETH_CLASS`, `OID_HW_POWER_STATUS`, `OID_HW_CONSUMING`, `OID_CPE_POWER`, `OID_CPE_ENTPHY`, `OID_ENT_ALIAS`.

Запасное сопоставление по номеру (спека: «последнее число в имени внутри группы, если совпадение единственное») делается строже: номер должен быть единственным на всём коммутаторе. Какой член стека соответствует группе, у производителей по-разному; неоднозначный порт остаётся без PoE.

- [ ] **Step 1: Падающие тесты**

`tests/Unit/PoeTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Switch\Poe;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/**
 * PoE of the switch's ports: Huawei's own table by interface, Cisco IOS through ENTITY-MIB, and the
 * plain POWER-ETHERNET-MIB (RFC 3621) by port number.
 */
class PoeTest extends TestCase
{
    protected function read(array $table, array $ports)
    {
        return (new Poe(new FakeSnmp(['' => $table])))->read($ports);
    }

    public function test_huawei_gives_power_by_interface_in_milliwatts()
    {
        $poe = $this->read([
            Poe::OID_HW_CONSUMING.'.8' => 6900,
            Poe::OID_HW_POWER_STATUS.'.8' => 'Powered',
            Poe::OID_HW_CONSUMING.'.9' => 0,
            Poe::OID_HW_POWER_STATUS.'.9' => 'Detecting',
            Poe::OID_HW_POWER_STATUS.'.10' => 'Disabled',
        ], [8 => 'GE0/0/3', 9 => 'GE0/0/4', 10 => 'GE0/0/5', 30 => 'XGE0/0/1']);

        $this->assertSame([
            8 => ['state' => 'delivering', 'class' => null, 'watts' => 6.9],
            9 => ['state' => 'searching', 'class' => null, 'watts' => 0.0],
            10 => ['state' => 'disabled', 'class' => null, 'watts' => null],
        ], $poe);
    }

    public function test_cisco_ios_maps_poe_ports_to_interfaces_through_entity_mib()
    {
        $poe = $this->read([
            Poe::OID_PETH_DETECTION.'.1.3' => 3,           // deliveringPower
            Poe::OID_PETH_CLASS.'.1.3' => 5,               // class4
            Poe::OID_CPE_ENTPHY.'.1.3' => 1003,
            Poe::OID_ENT_ALIAS.'.1003.0' => '.1.3.6.1.2.1.2.2.1.1.10103',
            Poe::OID_CPE_POWER.'.1.3' => 7100,
            Poe::OID_PETH_DETECTION.'.1.4' => 2,           // searching
            Poe::OID_CPE_ENTPHY.'.1.4' => 1004,
            Poe::OID_ENT_ALIAS.'.1004.0' => '.1.3.6.1.2.1.2.2.1.1.10104',
        ], [10103 => 'Gi1/0/3', 10104 => 'Gi1/0/4']);

        $this->assertSame([
            10103 => ['state' => 'delivering', 'class' => 4, 'watts' => 7.1],
            10104 => ['state' => 'searching', 'class' => null, 'watts' => null],
        ], $poe);
    }

    public function test_a_poe_port_number_that_is_an_interface_index_is_that_interface()
    {
        // Cisco Business and many others: pethPsePortIndex = ifIndex
        $poe = $this->read([Poe::OID_PETH_DETECTION.'.1.5' => 2], [5 => 'gi5', 6 => 'gi6']);
        $this->assertSame([5 => ['state' => 'searching', 'class' => null, 'watts' => null]], $poe);
    }

    public function test_otherwise_the_last_number_of_the_port_name_when_it_is_unique()
    {
        $poe = $this->read([
            Poe::OID_PETH_DETECTION.'.1.3' => 3,
            Poe::OID_PETH_DETECTION.'.1.7' => 3,
        ], [10103 => 'GE0/0/3', 10107 => 'GE0/0/7', 10207 => 'GE1/0/7']);

        $this->assertSame([10103 => ['state' => 'delivering', 'class' => null, 'watts' => null]], $poe,
            'port 7 is ambiguous (GE0/0/7 and GE1/0/7) and stays out');
    }

    public function test_a_switch_without_poe_tables_has_no_poe()
    {
        $this->assertSame([], $this->read([], [1 => 'ether1']));
    }
}
```

В `tests/Unit/SwitchSnapshotTest.php` добавить в конец класса:

```php
    public function test_poe_lands_on_its_port()
    {
        $snmp = new FakeSnmp(['' => $this->interfaces([8 => ['GE0/0/3', 6, 1, 1, 1000]]) + [
            \App\Services\Switch\Poe::OID_HW_CONSUMING.'.8' => 6900,
        ]]);
        $s = (new SwitchSnapshot($snmp, new MacTable($snmp), new \App\Services\Switch\Poe($snmp)))->take();

        $this->assertTrue($s['poe']);
        $this->assertSame(6.9, $s['ports'][8]['poe']['watts']);
    }
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'PoeTest|SwitchSnapshotTest'`
Expected: падение — `Class "App\Services\Switch\Poe" not found`.

- [ ] **Step 3: `app/Services/Switch/Poe.php`**

```php
<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;

/**
 * PoE of the switch's physical ports. The plain POWER-ETHERNET-MIB (RFC 3621) numbers PoE ports by
 * group.port, leaves the mapping to interfaces to the vendor and has no power per port:
 *  - Huawei: HUAWEI-POE-MIB hwPoePortTable, indexed by interface, power in mW
 *  - Cisco IOS: CISCO-POWER-ETHERNET-EXT-MIB gives the port's physical entity, ENTITY-MIB its
 *    interface (entAliasMappingIdentifier); power in mW
 *  - the rest: the PoE port number is the interface index when such a port exists, otherwise the
 *    last number in a port's name when that number is unique on the switch (the members of a stack
 *    share numbers and stay out); no power
 * Checked against the user's switches when they arrive (ROADMAP item 1).
 */
class Poe
{
    const OID_PETH_DETECTION = '.1.3.6.1.2.1.105.1.1.1.6';            // pethPsePortDetectionStatus.<group>.<port>
    const OID_PETH_CLASS = '.1.3.6.1.2.1.105.1.1.1.10';               // pethPsePortPowerClassifications
    const OID_HW_POWER_STATUS = '.1.3.6.1.4.1.2011.5.25.195.3.1.7';   // hwPoePortPowerStatus.<ifIndex> (text)
    const OID_HW_CONSUMING = '.1.3.6.1.4.1.2011.5.25.195.3.1.10';     // hwPoePortConsumingPower.<ifIndex>, mW
    const OID_CPE_POWER = '.1.3.6.1.4.1.9.9.402.1.2.1.9';             // cpeExtPsePortPwrConsumption.<group>.<port>, mW
    const OID_CPE_ENTPHY = '.1.3.6.1.4.1.9.9.402.1.2.1.11';           // cpeExtPsePortEntPhyIndex.<group>.<port>
    const OID_ENT_ALIAS = '.1.3.6.1.2.1.47.1.3.2.1.2';                // entAliasMappingIdentifier.<entPhy>.<logical>
    const DETECTION = [1 => 'disabled', 2 => 'searching', 3 => 'delivering', 4 => 'fault', 5 => 'test', 6 => 'fault'];
    const CLASSES = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 5 => 4];

    protected $snmp;

    public function __construct(SnmpClient $snmp)
    {
        $this->snmp = $snmp;
    }

    /**
     * @param array $ports [ifIndex => name] of the physical ports
     * @return array [ifIndex => ['state' => delivering|searching|fault|disabled|test|other, 'class' => 0..4|null, 'watts' => float|null]]
     */
    public function read(array $ports): array
    {
        $huawei = $this->huawei($ports);
        if ($huawei)
            return $huawei;

        $detection = $this->table(self::OID_PETH_DETECTION);
        if (!$detection)
            return [];
        $classes = $this->table(self::OID_PETH_CLASS);
        $map = $this->cisco($detection) ?: $this->byNumber(array_keys($detection), $ports);
        $watts = $this->table(self::OID_CPE_POWER);

        $result = [];
        foreach ($map as $key => $ifIndex)
        {
            if (!isset($ports[$ifIndex]))
                continue;
            $result[$ifIndex] = [
                'state' => self::DETECTION[(int) $detection[$key]] ?? 'other',
                'class' => isset($classes[$key]) ? (self::CLASSES[(int) $classes[$key]] ?? null) : null,
                'watts' => isset($watts[$key]) ? round((int) $watts[$key] / 1000, 1) : null,
            ];
        }
        ksort($result);
        return $result;
    }

    protected function huawei(array $ports): array
    {
        $power = $this->table(self::OID_HW_CONSUMING);
        $status = $this->table(self::OID_HW_POWER_STATUS);
        $result = [];
        foreach ($ports as $ifIndex => $name)
        {
            $key = (string) $ifIndex;
            if (!isset($power[$key]) && !isset($status[$key]))
                continue;
            $watts = isset($power[$key]) ? round((int) $power[$key] / 1000, 1) : null;
            $result[$ifIndex] = ['state' => $this->huaweiState($status[$key] ?? null, $watts), 'class' => null, 'watts' => $watts];
        }
        return $result;
    }

    /* hwPoePortPowerStatus is text; its words are checked on a real S5731 (ROADMAP item 1) */
    protected function huaweiState($text, ?float $watts): string
    {
        $text = strtolower(trim((string) $text));
        if (strpos($text, 'fault') !== false || strpos($text, 'overload') !== false)
            return 'fault';
        if (strpos($text, 'disable') !== false || $text === 'off')
            return 'disabled';
        if ($watts > 0 || (strpos($text, 'power') !== false && strpos($text, 'off') === false))
            return 'delivering';
        return 'searching';
    }

    /* Cisco IOS: PoE port -> physical entity -> interface */
    protected function cisco(array $detection): array
    {
        $entities = $this->table(self::OID_CPE_ENTPHY);
        if (!$entities)
            return [];
        $interfaces = [];
        foreach ($this->table(self::OID_ENT_ALIAS) as $key => $target)
            if (preg_match('/\.(\d+)$/', (string) $target, $m))
                $interfaces[(int) explode('.', $key)[0]] = (int) $m[1];
        $map = [];
        foreach ($entities as $key => $entity)
            if (isset($detection[$key], $interfaces[(int) $entity]))
                $map[$key] = $interfaces[(int) $entity];
        return $map;
    }

    /* The rest: the port number is the interface index, or the last number in a port's name when unique */
    protected function byNumber(array $keys, array $ports): array
    {
        $named = [];
        foreach ($ports as $ifIndex => $name)
            if (preg_match('/(\d+)\D*$/', $name, $m))
                $named[(int) $m[1]][] = $ifIndex;
        $map = [];
        foreach ($keys as $key)
        {
            $port = (int) substr(strrchr('.'.$key, '.'), 1);
            if (isset($ports[$port]))
                $map[$key] = $port;
            elseif (isset($named[$port]) && count($named[$port]) === 1)
                $map[$key] = $named[$port][0];
        }
        return $map;
    }

    /* [index after the column, e.g. "1.3" or "8" => value]; [] when the switch has no such table */
    protected function table(string $oid): array
    {
        try
        {
            $values = $this->snmp->walk($oid);
        }
        catch (SnmpError $e)
        {
            if ($e->timeout)
                throw $e;
            return [];
        }
        $table = [];
        foreach ($values as $key => $value)
            $table[substr($key, strlen($oid) + 1)] = $value;
        return $table;
    }
}
```

- [ ] **Step 4: `forConfig()` подключает PoE**

В `app/Services/Switch/SwitchSnapshot.php`, в `forConfig()`, заменить `null, (string) $config['host']` на `new Poe($snmp), (string) $config['host']`.

- [ ] **Step 5: Тесты проходят**

Run: `sync_dev && tests 'PoeTest|SwitchSnapshotTest|MacTableTest|SwitchPortFinderTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add app/Services/Switch/Poe.php app/Services/Switch/SwitchSnapshot.php tests/Unit/PoeTest.php tests/Unit/SwitchSnapshotTest.php
git commit -q -m "PoE per port in the switch snapshot: Huawei's own table, Cisco IOS through ENTITY-MIB, RFC 3621 by port number" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: SnapshotCache — один снимок на все открытые страницы

**Files:**
- Create: `app/Services/Switch/SnapshotCache.php`
- Test: `tests/Feature/SnapshotCacheTest.php`

**Interfaces:**
- Consumes: снимок — массив с ключом `duration` (`SwitchSnapshot::take()`), ошибки — `SnmpError`.
- Produces: `App\Services\Switch\SnapshotCache` — `__construct(string $id, callable $take)`, `current(): array` → `['snapshot' => ?array, 'error' => ?['message' => string, 'since' => int], 'checked_at' => ?int]`. Константы `FRESH_SECONDS = 5`, `SLOW_POLL = 2.5`, `LOCK_WAIT = 1`. Ключи кэша: `switch-snapshot:<id>`, блокировка `switch-snapshot-lock:<id>`.

Тест блокировки идёт без `Carbon::setTestNow()`: `Lock::block()` ждёт по часам `now()` и при замороженном времени крутился бы вечно.

- [ ] **Step 1: Падающий тест**

`tests/Feature/SnapshotCacheTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Snmp\SnmpError;
use App\Services\Switch\SnapshotCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Every open Ports page shares one switch snapshot: asked once per freshness window, one poll at a
 * time, and a switch that stops answering leaves its last snapshot with the error.
 */
class SnapshotCacheTest extends TestCase
{
    protected $polls = 0, $duration = 0.2, $silent = false;

    protected function cache()
    {
        return new SnapshotCache('192.0.2.10', function () {
            $this->polls++;
            if ($this->silent)
                throw new SnmpError('No response from 192.0.2.10', true);
            return ['ports' => [], 'duration' => $this->duration, 'taken_at' => Carbon::now()->getTimestamp()];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_second_look_within_five_seconds_does_not_poll()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:04');
        $state = $this->cache()->current();

        $this->assertSame(1, $this->polls);
        $this->assertNotNull($state['snapshot']);
        $this->assertNull($state['error']);
    }

    public function test_after_five_seconds_it_polls_again()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:05');
        $this->cache()->current();

        $this->assertSame(2, $this->polls);
    }

    public function test_a_slow_switch_is_asked_twice_its_poll_time_apart()
    {
        $this->duration = 4.0;
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        Carbon::setTestNow('2026-10-12 10:00:07');
        $this->cache()->current();
        $this->assertSame(1, $this->polls, 'fresh for 8 s');
        Carbon::setTestNow('2026-10-12 10:00:08');
        $this->cache()->current();
        $this->assertSame(2, $this->polls);
    }

    public function test_a_switch_that_stops_answering_keeps_its_last_snapshot_with_the_error()
    {
        Log::spy();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->cache()->current();
        $this->silent = true;
        Carbon::setTestNow('2026-10-12 10:00:05');
        $state = $this->cache()->current();
        $this->assertNotNull($state['snapshot']);
        $this->assertSame(['message' => 'No response from 192.0.2.10', 'since' => Carbon::parse('2026-10-12 10:00:05')->getTimestamp()], $state['error']);

        Carbon::setTestNow('2026-10-12 10:00:07');
        $this->cache()->current();
        $this->assertSame(2, $this->polls, 'a silent switch is not asked more often either');

        Carbon::setTestNow('2026-10-12 10:00:10');
        $state = $this->cache()->current();
        $this->assertSame(Carbon::parse('2026-10-12 10:00:05')->getTimestamp(), $state['error']['since'], 'since the first failure');

        $this->silent = false;
        Carbon::setTestNow('2026-10-12 10:00:15');
        $this->assertNull($this->cache()->current()['error']);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('info')->once();
    }

    public function test_no_snapshot_yet_and_a_silent_switch_gives_the_error_only()
    {
        $this->silent = true;
        $state = $this->cache()->current();
        $this->assertNull($state['snapshot']);
        $this->assertSame('No response from 192.0.2.10', $state['error']['message']);
    }

    public function test_a_page_waiting_for_another_pages_poll_shows_what_there_is()
    {
        // real time here: a lock waits by the clock
        $lock = Cache::lock('switch-snapshot-lock:192.0.2.10', 60);
        $this->assertTrue($lock->get());
        $state = $this->cache()->current();
        $lock->release();

        $this->assertSame(0, $this->polls);
        $this->assertNull($state['snapshot']);
    }
}
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'SnapshotCacheTest'`
Expected: падение — `Class "App\Services\Switch\SnapshotCache" not found`.

- [ ] **Step 3: `app/Services/Switch/SnapshotCache.php`**

```php
<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpError;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The switch snapshot shared by every open Ports page: taken at most once per FRESH_SECONDS (twice
 * the poll's duration for a slow switch), one poll at a time; when the switch stops answering, the
 * last good snapshot stays together with the error and the time it began.
 */
class SnapshotCache
{
    const FRESH_SECONDS = 5;
    const SLOW_POLL = 2.5;
    const LOCK_WAIT = 1;
    const LOCK_SECONDS = 60;
    const KEEP_SECONDS = 86400;

    protected $id, $take;

    /** @param callable $take takes a new snapshot (SwitchSnapshot::take()), throws SnmpError */
    public function __construct(string $id, callable $take)
    {
        $this->id = $id;
        $this->take = $take;
    }

    /** ['snapshot' => the last good one or null, 'error' => null or ['message', 'since'], 'checked_at' => unix time or null] */
    public function current(): array
    {
        $state = $this->stored();
        if ($this->fresh($state))
            return $state;

        $lock = Cache::lock('switch-snapshot-lock:'.$this->id, self::LOCK_SECONDS);
        try
        {
            $lock->block(self::LOCK_WAIT);
        }
        catch (LockTimeoutException $e)
        {
            return $state;   // another page is asking the switch right now
        }
        try
        {
            $state = $this->stored();
            return $this->fresh($state) ? $state : $this->poll($state);
        }
        finally
        {
            $lock->release();
        }
    }

    protected function poll(array $state): array
    {
        $now = Carbon::now()->getTimestamp();
        try
        {
            $snapshot = ($this->take)();
            if ($state['error'])
                Log::info('Switch '.$this->id.' answers again');
            $state = ['snapshot' => $snapshot, 'error' => null, 'checked_at' => $now];
        }
        catch (SnmpError $e)
        {
            if (!$state['error'])
                Log::warning('Switch '.$this->id.' does not answer: '.$e->getMessage());
            $state['error'] = ['message' => $e->getMessage(), 'since' => $state['error']['since'] ?? $now];
            $state['checked_at'] = $now;
        }
        Cache::put('switch-snapshot:'.$this->id, $state, self::KEEP_SECONDS);
        return $state;
    }

    protected function stored(): array
    {
        $state = Cache::get('switch-snapshot:'.$this->id);
        return is_array($state) ? $state : ['snapshot' => null, 'error' => null, 'checked_at' => null];
    }

    /* Checked less than FRESH_SECONDS ago, or twice the last poll's duration for a slow switch */
    protected function fresh(array $state): bool
    {
        if (!$state['checked_at'])
            return false;
        $duration = (float) ($state['snapshot']['duration'] ?? 0);
        $life = $duration > self::SLOW_POLL ? 2 * $duration : self::FRESH_SECONDS;
        return Carbon::now()->getTimestamp() - $state['checked_at'] < $life;
    }
}
```

- [ ] **Step 4: Тесты проходят**

Run: `sync_dev && tests 'SnapshotCacheTest'`
Expected: `phpunit exit 0`, `OK (6 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add app/Services/Switch/SnapshotCache.php tests/Feature/SnapshotCacheTest.php
git commit -q -m "One switch snapshot for every open Ports page, kept when the switch stops answering" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: StandMap — плитки, раскладка, сводка

**Files:**
- Create: `app/Support/StandMap.php`
- Test: `tests/Feature/StandMapTest.php` (Feature: несохранённые модели `Cm` с датами и `now()` приложения)

**Interfaces:**
- Consumes: снимок из `SwitchSnapshot::take()`; модели `App\Models\Cm` (`isActive()`, `silentFor()`, `progressPercent()`, `phase`, `phase_detail`, `serial`, `mac`, `provisioning_complete_at`).
- Produces: `App\Support\StandMap` — `__construct(array $snapshot, iterable $modules, ?string $serverMac)`, `build(): array`, `static moduleState(Cm $cm): string` (`ok|run|fail|stale|unknown`), `static speedLabel(int $mbps): string` (`1000` → `1G`). Константы `UPLINK_MACS = 3`, `BLOCK = 12`.
  `build()` → `['blocks' => [['uplinks' => bool, 'rows' => 1|2, 'tiles' => [tile, ...]], ...], 'tiles' => [key => tile], 'summary' => ['modules' => int, 'ports' => int, 'counts' => ['ok', 'run', 'fail', 'stale', 'unknown', 'boot', 'foreign', 'free' => int], 'verdict' => 'running'|'failed'|'done'|'empty', 'busy' => int, 'composition' => string]]`.
  Плитка: `['key' => 'p<ifIndex>', 'name', 'alias', 'number' => ?int, 'up' => bool, 'speed' => ?int, 'poe' => ?array, 'macs' => [...], 'state' => 'module'|'boot'|'foreign'|'uplink'|'free', 'filter' => ok|run|fail|stale|unknown|boot|foreign|uplink|free, 'modules' => [Cm, ...] (худший первым), 'short' => string, 'sfp' => bool, 'mark' => string]`.

Решения сверх таблицы спеки (для модуля спека говорит «ровно один MAC»):
- **Несколько модулей на порту** — состояние «модуль», худший из них, отметка «×N»; сводка считает каждый.
- **Отметка идущего модуля без процента** — короткое имя шага: `EEPROM`, `PRE`, `WRITE` (сброс старых данных перед записью), `VERIFY`, `POST`; полное имя — в подробностях.
- **Гнёзда без PoE-таблиц** — к именам спеки добавлен префикс `sfp` (MikroTik: `sfp1`, `sfp-sfpplus1`). Скорость от 10 Гбит/с делает порт гнездом только при поднятом линке, как в спеке.
- **Вывод сводки:** модули «нет данных» не дают сказать «все готовы» и попадают в вывод «закончили, перезапустите».

- [ ] **Step 1: Падающий тест**

`tests/Feature/StandMapTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Support\StandMap;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The stand as the Ports page draws it: one tile per port in front-panel order, the module behind
 * each MAC address, and what is on the stand now.
 */
class StandMapTest extends TestCase
{
    const SERVER = '2c:cf:67:30:7d:a2';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function port(int $ifIndex, string $name, bool $up = true, array $macs = [], $poe = null, $speed = 1000)
    {
        return ['ifIndex' => $ifIndex, 'name' => $name, 'alias' => '', 'up' => $up, 'speed' => $speed, 'macs' => $macs, 'poe' => $poe];
    }

    protected function snapshot(array $ports)
    {
        $byIndex = [];
        foreach ($ports as $p)
            $byIndex[$p['ifIndex']] = $p;
        return ['id' => '192.0.2.10', 'model' => 'S5731', 'taken_at' => 0, 'duration' => 0.3, 'mac_method' => 'huawei',
                'poe' => (bool) array_filter($ports, function ($p) { return $p['poe'] !== null; }), 'ports' => $byIndex];
    }

    protected function module(string $serial, string $mac, array $attributes = [])
    {
        return new Cm(array_merge(['serial' => $serial, 'mac' => $mac, 'phase' => 'done',
            'provisioning_complete_at' => Carbon::now()->subMinute()], $attributes));
    }

    protected function build(array $ports, array $modules = [])
    {
        return (new StandMap($this->snapshot($ports), $modules, self::SERVER))->build();
    }

    public function test_every_kind_of_port()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $poe = ['state' => 'delivering', 'class' => 4, 'watts' => 6.9];
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], $poe),
            $this->port(2, 'GE0/0/2', true, [], $poe),                                // link, no MAC yet
            $this->port(3, 'GE0/0/3', true, ['3c:22:fb:91:0a:5e'], $poe),             // a laptop
            $this->port(4, 'GE0/0/4', false, ['e4:5f:01:00:00:04'], ['state' => 'searching', 'class' => null, 'watts' => null]),
            $this->port(30, 'XGE0/0/1', true, [self::SERVER, '00:11:22:33:44:55'], null, 10000),
        ], [
            $this->module('10000000e611110c', 'E4:5F:01:00:00:01'),
            $this->module('10000000aaaa0004', 'e4:5f:01:00:00:04'),
        ]);

        $t = $map['tiles'];
        $this->assertSame(['module', 'ok', '110C', '✓'], [$t['p1']['state'], $t['p1']['filter'], $t['p1']['short'], $t['p1']['mark']]);
        $this->assertSame(['boot', '···'], [$t['p2']['state'], $t['p2']['short']]);
        $this->assertSame(['foreign', 'MAC'], [$t['p3']['state'], $t['p3']['short']]);
        $this->assertSame('free', $t['p4']['state'], 'no link: free, even though the MAC is still in the switch table');
        $this->assertSame([], $t['p4']['modules']);
        $this->assertSame(['uplink', 'UP', true], [$t['p30']['state'], $t['p30']['short'], $t['p30']['sfp']]);
        $this->assertSame(1, $map['summary']['modules']);
        $this->assertSame(4, $map['summary']['ports']);
        $this->assertSame(['ok' => 1, 'run' => 0, 'fail' => 0, 'stale' => 0, 'unknown' => 0, 'boot' => 1, 'foreign' => 1, 'free' => 1], $map['summary']['counts']);
        $this->assertSame('4 × 1G PoE · 1 × SFP', $map['summary']['composition']);
    }

    public function test_module_states_like_the_dashboard()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $ports = [];
        for ($i = 1; $i <= 7; $i++)
            $ports[] = $this->port($i, 'ether'.$i, true, ['aa:00:00:00:00:0'.$i]);
        $map = $this->build($ports, [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => 'write', 'progress_bytes' => 430, 'progress_total' => 1000, 'progress_updated_at' => Carbon::now()->subSeconds(5)]),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'write', 'progress_bytes' => 120, 'progress_total' => 1000, 'progress_updated_at' => Carbon::now()->subMinutes(2)]),
            $this->module('10000000000000a3', 'aa:00:00:00:00:03', ['phase' => 'preinstall', 'phase_detail' => 'Flash EEPROM firmware (default/pieeprom-2026-09-23.bin)', 'progress_updated_at' => Carbon::now()]),
            $this->module('10000000000000a4', 'aa:00:00:00:00:04', ['phase' => 'failed']),
            $this->module('10000000000000a5', 'aa:00:00:00:00:05', ['phase' => null, 'provisioning_complete_at' => null]),
            $this->module('10000000000000a6', 'aa:00:00:00:00:06', ['phase' => null]),
            $this->module('10000000000000a7', 'aa:00:00:00:00:07', ['phase' => 'postinstall', 'phase_detail' => 'label', 'progress_updated_at' => Carbon::now()]),
        ]);

        $marks = array_map(function ($t) { return [$t['filter'], $t['mark']]; }, array_values($map['tiles']));
        $this->assertSame([['run', '43%'], ['stale', '!'], ['run', 'EEPROM'], ['fail', '✕'], ['unknown', '?'], ['ok', '✓'], ['run', 'POST']], $marks);
        $this->assertSame('running', $map['summary']['verdict']);
        $this->assertSame(4, $map['summary']['busy'], 'three running and one silent');
    }

    public function test_several_modules_behind_one_port_show_the_worst_with_their_count()
    {
        $map = $this->build([$this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01', 'aa:00:00:00:00:02'])], [
            $this->module('10000000000000a1', 'aa:00:00:00:00:01'),
            $this->module('10000000000000a2', 'aa:00:00:00:00:02', ['phase' => 'failed']),
        ]);

        $tile = $map['tiles']['p1'];
        $this->assertSame(['module', 'fail', '×2', 'A2'], [$tile['state'], $tile['filter'], $tile['mark'], substr($tile['short'], -2)]);
        $this->assertSame(['10000000000000a2', '10000000000000a1'], array_map(function ($cm) { return $cm->serial; }, $tile['modules']));
        $this->assertSame(2, $map['summary']['modules']);
        $this->assertSame(1, $map['summary']['counts']['ok']);
        $this->assertSame(1, $map['summary']['counts']['fail']);
    }

    public function test_a_module_moved_to_another_port_shows_on_the_new_one_only()
    {
        $map = $this->build([
            $this->port(3, 'GE0/0/3', false, ['aa:00:00:00:00:01']),   // the old port: the MAC has not aged out yet
            $this->port(7, 'GE0/0/7', true, ['aa:00:00:00:00:01']),
        ], [$this->module('10000000000000a1', 'aa:00:00:00:00:01')]);

        $this->assertSame('free', $map['tiles']['p3']['state']);
        $this->assertSame('module', $map['tiles']['p7']['state']);
        $this->assertSame(1, $map['summary']['modules']);
    }

    public function test_more_than_three_macs_make_an_uplink()
    {
        $map = $this->build([$this->port(9, 'GE0/0/9', true, ['00:00:00:00:00:01', '00:00:00:00:00:02', '00:00:00:00:00:03', '00:00:00:00:00:04'])]);
        $this->assertSame('uplink', $map['tiles']['p9']['state']);
    }

    public function test_eight_poe_ports_in_one_row_and_the_rest_as_uplinks()
    {
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 1; $i <= 8; $i++)
            $ports[] = $this->port($i, 'Gi1/0/'.$i, false, [], $poe);
        $ports[] = $this->port(9, 'Gi1/0/9', false);
        $ports[] = $this->port(10, 'Gi1/0/10', false);
        $map = $this->build($ports);

        $this->assertCount(2, $map['blocks']);
        $this->assertSame([false, 1, 8], [$map['blocks'][0]['uplinks'], $map['blocks'][0]['rows'], count($map['blocks'][0]['tiles'])]);
        $this->assertSame([true, 1, ['Gi1/0/9', 'Gi1/0/10']], [$map['blocks'][1]['uplinks'], $map['blocks'][1]['rows'], array_column($map['blocks'][1]['tiles'], 'name')]);
        $this->assertSame('SFP', $map['tiles']['p9']['mark'], 'an empty uplink cage');
        $this->assertSame(8, $map['summary']['ports']);
        $this->assertSame('8 × 1G PoE · 2 × SFP', $map['summary']['composition']);
    }

    public function test_forty_eight_ports_in_blocks_of_twelve_on_two_rows()
    {
        $poe = ['state' => 'searching', 'class' => null, 'watts' => null];
        $ports = [];
        for ($i = 48; $i >= 1; $i--)
            $ports[] = $this->port($i, 'GE0/0/'.$i, false, [], $poe);
        $map = $this->build($ports);

        $this->assertCount(4, $map['blocks']);
        $this->assertSame(2, $map['blocks'][0]['rows']);
        $this->assertSame(['GE0/0/1', 'GE0/0/2', 'GE0/0/3'], array_slice(array_column($map['blocks'][0]['tiles'], 'name'), 0, 3), 'natural order');
        $this->assertSame('GE0/0/13', $map['blocks'][1]['tiles'][0]['name']);
        $this->assertSame(12, $map['blocks'][0]['tiles'][11]['number']);
    }

    public function test_without_poe_names_and_speeds_tell_copper_from_cages()
    {
        $map = $this->build([
            $this->port(10, 'ether10', false),
            $this->port(2, 'ether2', false),
            $this->port(11, 'sfp-sfpplus1', false),
            $this->port(12, 'Te1/1/1', false),
            $this->port(13, 'GE0/0/25', true, [], null, 10000),
            $this->port(14, 'GE0/0/26', false, [], null, 10000),   // 10G without a link: copper by the spec's rule
        ]);

        $this->assertSame(['ether2', 'ether10', 'GE0/0/26'], array_column($map['blocks'][0]['tiles'], 'name'));
        $this->assertSame(['GE0/0/25', 'sfp-sfpplus1', 'Te1/1/1'], array_column($map['blocks'][1]['tiles'], 'name'));
        $this->assertSame('3 × 1G · 3 × SFP', $map['summary']['composition']);
    }

    public function test_verdicts()
    {
        $port = $this->port(1, 'GE0/0/1', true, ['aa:00:00:00:00:01']);
        $this->assertSame('done', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01')])['summary']['verdict']);
        $this->assertSame('failed', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => 'failed'])])['summary']['verdict']);
        $this->assertSame('failed', $this->build([$port], [$this->module('10000000000000a1', 'aa:00:00:00:00:01', ['phase' => null, 'provisioning_complete_at' => null])])['summary']['verdict'],
            'a module without data is not ready either');
        $this->assertSame('empty', $this->build([$this->port(1, 'GE0/0/1', false)])['summary']['verdict']);
    }
}
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'StandMapTest'`
Expected: падение — `Class "App\Support\StandMap" not found`.

- [ ] **Step 3: `app/Support/StandMap.php`**

```php
<?php

namespace App\Support;

use App\Models\Cm;

/**
 * The stand as the Ports page draws it: a SwitchSnapshot and the modules behind its MAC addresses as
 * port tiles laid out like the switch's front panel, and what is on the stand now.
 */
class StandMap
{
    /* More MAC addresses than this on one port make it an uplink */
    const UPLINK_MACS = 3;
    /* Main ports per block: six pairs, odd ones on top as on the case */
    const BLOCK = 12;
    /* Cages for a switch without PoE tables: 10G and faster names, and MikroTik's sfp ports */
    const CAGE_NAMES = '/^(xge|xgigabitethernet|25ge|40ge|100ge|te|fo|hu|twe|sfp)/i';
    /* Worst first: a port with several modules shows the first of these that one of them has */
    const SEVERITY = ['fail', 'stale', 'run', 'unknown', 'ok'];
    /* A running module's tile without a percent shows its step */
    const STEP_MARKS = ['preinstall' => 'PRE', 'write' => 'WRITE', 'verify' => 'VERIFY', 'postinstall' => 'POST'];

    protected $snapshot, $modules = [], $serverMac;

    /**
     * @param array $snapshot SwitchSnapshot::take()
     * @param iterable $modules Cm models; their mac in any case
     */
    public function __construct(array $snapshot, iterable $modules, ?string $serverMac)
    {
        $this->snapshot = $snapshot;
        foreach ($modules as $cm)
            if ($cm->mac)
                $this->modules[strtolower($cm->mac)] = $cm;
        $this->serverMac = $serverMac ? strtolower($serverMac) : null;
    }

    public function build(): array
    {
        $ports = array_values($this->snapshot['ports'] ?? []);
        usort($ports, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        $withPoe = (bool) array_filter($ports, function ($p) { return $p['poe'] !== null; });

        $main = $uplinks = [];
        foreach ($ports as $port)
        {
            $tile = $this->tile($port);
            $tile['sfp'] = $withPoe ? $port['poe'] === null : $this->cage($port);
            $tile['mark'] = $this->mark($tile);
            if ($tile['sfp'])
                $uplinks[] = $tile;
            else
                $main[] = $tile;
        }

        $rows = count($main) > self::BLOCK ? 2 : 1;
        $blocks = [];
        foreach ($rows === 1 ? [$main] : array_chunk($main, self::BLOCK) as $chunk)
            if ($chunk)
                $blocks[] = ['uplinks' => false, 'rows' => $rows, 'tiles' => $chunk];
        if ($uplinks)
            $blocks[] = ['uplinks' => true, 'rows' => $rows, 'tiles' => $uplinks];

        $tiles = [];
        foreach (array_merge($main, $uplinks) as $tile)
            $tiles[$tile['key']] = $tile;

        return ['blocks' => $blocks, 'tiles' => $tiles, 'summary' => $this->summary($main, $tiles, count($uplinks), $withPoe)];
    }

    /* What the dashboard says about a module, in one word */
    public static function moduleState(Cm $cm): string
    {
        if ($cm->isActive())
            return $cm->silentFor() ? 'stale' : 'run';
        if ($cm->phase === 'done')
            return 'ok';
        if ($cm->phase === 'failed')
            return 'fail';
        return $cm->provisioning_complete_at ? 'ok' : 'unknown';
    }

    /* 1000 -> "1G", 2500 -> "2.5G", 100 -> "100M" */
    public static function speedLabel(int $mbps): string
    {
        return $mbps >= 1000 ? ($mbps / 1000).'G' : $mbps.'M';
    }

    protected function tile(array $port): array
    {
        $macs = array_map('strtolower', $port['macs']);
        $modules = [];
        foreach ($macs as $mac)
            if (isset($this->modules[$mac]))
                $modules[] = $this->modules[$mac];
        usort($modules, function ($a, $b) {
            return array_search(self::moduleState($a), self::SEVERITY, true) - array_search(self::moduleState($b), self::SEVERITY, true);
        });

        if (!$port['up'])
            $state = 'free';   // a pulled module's MAC stays in the switch table for minutes
        elseif (($this->serverMac && in_array($this->serverMac, $macs, true)) || count($macs) > self::UPLINK_MACS)
            $state = 'uplink';
        elseif ($modules)
            $state = 'module';
        elseif ($macs)
            $state = 'foreign';
        else
            $state = 'boot';

        return [
            'key' => 'p'.$port['ifIndex'],
            'name' => $port['name'],
            'alias' => $port['alias'],
            'number' => preg_match('/(\d+)\D*$/', $port['name'], $m) ? (int) $m[1] : null,
            'up' => $port['up'],
            'speed' => $port['speed'],
            'poe' => $port['poe'],
            'macs' => $macs,
            'state' => $state,
            'filter' => $state === 'module' ? self::moduleState($modules[0]) : $state,
            'modules' => $state === 'module' ? $modules : [],
            'short' => $state === 'module' ? strtoupper(substr($modules[0]->serial, -4))
                : (['uplink' => 'UP', 'foreign' => 'MAC', 'boot' => '···'][$state] ?? ''),
        ];
    }

    protected function cage(array $port): bool
    {
        return preg_match(self::CAGE_NAMES, $port['name']) === 1
            || ($port['up'] && $port['speed'] !== null && $port['speed'] >= 10000);
    }

    /* The line under the serial: ✓, ✕, !, ?, percent, step, or ×N for several modules */
    protected function mark(array $tile): string
    {
        if ($tile['state'] === 'free')
            return $tile['sfp'] ? 'SFP' : '';
        if ($tile['state'] !== 'module')
            return '';
        if (count($tile['modules']) > 1)
            return '×'.count($tile['modules']);
        $cm = $tile['modules'][0];
        switch ($tile['filter'])
        {
            case 'ok': return '✓';
            case 'fail': return '✕';
            case 'stale': return '!';
            case 'unknown': return '?';
        }
        $percent = $cm->progressPercent();
        if ($percent !== null)
            return $percent.'%';
        if ($cm->phase === 'preinstall' && stripos((string) $cm->phase_detail, 'eeprom') !== false)
            return 'EEPROM';
        return self::STEP_MARKS[$cm->phase] ?? '';
    }

    protected function summary(array $main, array $tiles, int $cages, bool $withPoe): array
    {
        $counts = array_fill_keys(['ok', 'run', 'fail', 'stale', 'unknown', 'boot', 'foreign', 'free'], 0);
        $modules = 0;
        foreach ($tiles as $tile)
            foreach ($tile['modules'] as $cm)
            {
                $counts[self::moduleState($cm)]++;
                $modules++;
            }
        foreach ($main as $tile)
            if (in_array($tile['state'], ['boot', 'foreign', 'free'], true))
                $counts[$tile['state']]++;

        $busy = $counts['run'] + $counts['stale'] + $counts['boot'];
        if ($busy)
            $verdict = 'running';
        elseif ($counts['fail'] + $counts['unknown'])
            $verdict = 'failed';
        elseif ($modules)
            $verdict = 'done';
        else
            $verdict = 'empty';

        return ['modules' => $modules, 'ports' => count($main), 'counts' => $counts, 'verdict' => $verdict, 'busy' => $busy,
                'composition' => $this->composition($main, $cages, $withPoe)];
    }

    /* "24 × 1G PoE · 4 × SFP": the main ports with their usual speed, and the cages */
    protected function composition(array $main, int $cages, bool $withPoe): string
    {
        $speeds = array_count_values(array_filter(array_map(function ($t) { return $t['speed']; }, $main)));
        arsort($speeds);
        $kind = trim(($speeds ? self::speedLabel((int) array_key_first($speeds)) : '').($withPoe ? ' PoE' : ''));
        $parts = [];
        if ($main)
            $parts[] = $kind !== '' ? count($main).' × '.$kind : count($main).' ports';
        if ($cages)
            $parts[] = $cages.' × SFP';
        return implode(' · ', $parts);
    }
}
```

- [ ] **Step 4: Тесты проходят**

Run: `sync_dev && tests 'StandMapTest'`
Expected: `phpunit exit 0`, `OK (9 tests, …)`.

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add app/Support/StandMap.php tests/Feature/StandMapTest.php
git commit -q -m "StandMap: port tiles in front-panel order, the module behind each MAC, and the stand's summary" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: MAC провижинера для аплинка

**Files:**
- Modify: `app/Services/NetworkStatus.php`
- Test: `tests/Unit/NetworkStatusTest.php`

**Interfaces:**
- Produces: `NetworkStatus::serverMac(): ?string` — MAC интерфейса с адресом `SERVER_ADDRESS` (172.20.0.1) в нижнем регистре; `null`, если такого интерфейса нет.

- [ ] **Step 1: Падающие тесты** (в конец класса `NetworkStatusTest`; `setUp()` уже создаёт `eth0` с MAC `2c:cf:67:30:7d:a2`)

```php
    public function test_the_server_mac_is_that_of_the_interface_holding_the_modules_address()
    {
        $status = new NetworkStatus($this->root.'/net', $this->root.'/route', function () { return null; }, function () {
            return [
                'wlan1' => ['unicast' => [['family' => 2, 'address' => '192.168.25.30', 'netmask' => '255.255.255.0']]],
                'eth0' => ['unicast' => [['family' => 2, 'address' => '172.20.0.1', 'netmask' => '255.255.0.0']]],
            ];
        });
        $this->assertSame('2c:cf:67:30:7d:a2', $status->serverMac());
    }

    public function test_no_server_mac_without_the_modules_address()
    {
        $status = new NetworkStatus($this->root.'/net', $this->root.'/route', function () { return null; }, function () { return []; });
        $this->assertNull($status->serverMac());
    }
```

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'NetworkStatusTest'`
Expected: `phpunit exit 2` (или `1`), две ошибки `Call to undefined method App\Services\NetworkStatus::serverMac()`, остальные тесты класса зелёные.

- [ ] **Step 3: Метод в `app/Services/NetworkStatus.php`** (после метода `read()`)

```php
    /** MAC of the interface holding SERVER_ADDRESS (the modules' network), lower case; null when there is none */
    public function serverMac(): ?string
    {
        foreach ($this->ipv4(call_user_func($this->addresses)) as $name => $addresses) {
            foreach ($addresses as $address) {
                if (explode('/', $address)[0] === self::SERVER_ADDRESS) {
                    $mac = $this->value($this->net.'/'.$name.'/address');

                    return $mac ? strtolower($mac) : null;
                }
            }
        }

        return null;
    }
```

- [ ] **Step 4: Тесты проходят**

Run: `sync_dev && tests 'NetworkStatusTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add app/Services/NetworkStatus.php tests/Unit/NetworkStatusTest.php
git commit -q -m "NetworkStatus::serverMac(): the MAC the switch sees on the provisioner's port" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Страница Ports

**Files:**
- Create: `app/Http/Livewire/Ports.php`, `resources/views/livewire/ports.blade.php`, `resources/views/livewire/ports-tile.blade.php`
- Create: `public/fonts/jetbrains-mono-700.woff2`, `public/fonts/jetbrains-mono-800.woff2`, `public/fonts/JetBrainsMono-OFL.txt`
- Modify: `routes/web.php`, `resources/views/navigation-menu.blade.php`, `resources/css/app.css`, `public/css/app.css` (сборка)
- Test: `tests/Feature/PortsPageTest.php`

**Interfaces:**
- Consumes: `SwitchPortFinder::config()`; `SwitchSnapshot::forConfig()->take()` (задачи 2–3); `SnapshotCache::current()` (задача 4); `StandMap::build()`, `StandMap::UPLINK_MACS` (задача 5); `NetworkStatus::serverMac()` (задача 6); `Cm::canIdentify()`, `isIdentifying()`, `identify()`, `stopIdentify()`, `phaseLabel()`, `progressPercent()`, `isActive()`, `isStreaming()`, `bytesPerSecond()`, `secondsLeft()`; `FailureAdvice::for()` (`['step', 'advice']` или `null`; советует и упавшему, и молчащему); `ProvisioningStatus::speed()`, `ProvisioningStatus::duration()`.
- Produces: Livewire-компонент `ports` (`App\Http\Livewire\Ports`) на `/ports` (маршрут `ports`), публичное свойство `$selected` (ключ плитки), действия `select($key)`, `identify($id)`, `stopIdentify($id)`.

Тексты интерфейса (перевод строк спеки):

| Спека | Страница |
|---|---|
| На стенде N модулей · свободно M портов из K | `N module(s) on the stand · M of K ports free` |
| готово / идут / ошибка / нет связи / нет данных / загружается / чужое / свободно | `done / running / failed / silent / unknown / booting / foreign / free` |
| Ещё идут X, ошибок Y: партию пока не меняйте | `X still running, Y failed: keep the batch on the stand` |
| Все закончили, ошибок Y: перезапустите их или замените | `All finished, Y failed: restart or replace them` (и `, Z without data`, если такие есть) |
| Все готовы: можно менять партию | `All done: the batch can be replaced` |
| (модулей нет) | `No modules on the stand` |
| Показать все | `Show all` |
| Коммутатор не настроен… | `No switch is set up. Enter its address under Other settings → Ethernet switch to see the stand by its ports.` |
| Коммутатор не отвечает N с, показан снимок от HH:MM:SS | `The switch has not answered for N s (причина); showing what it said at HH:MM:SS.` |
| (снимка нет) | `Cannot read the switch: причина` |
| о прогонах этого модуля сведений нет | `Nothing is known about its runs` |

- [ ] **Step 1: Падающий тест**

`tests/Feature/PortsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Ports;
use App\Models\Cm;
use App\Models\Setting;
use App\Models\User;
use App\Services\NetworkStatus;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\SwitchSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/**
 * The Ports page: the switch's front panel with the module in each port, the summary and one port's details.
 */
class PortsPageTest extends TestCase
{
    use RefreshDatabase;

    const MODULE_MAC = 'e4:5f:01:62:9f:d3';
    const MODULE_OID = '228.95.1.98.159.211';

    protected $fake;

    protected function setUp(): void
    {
        parent::setUp();
        // the provisioner's MAC on the modules' network; the top bar's indicator reads nothing here
        $this->mock(NetworkStatus::class, function (MockInterface $m) {
            $m->shouldReceive('serverMac')->andReturn('2c:cf:67:30:7d:a2');
            $m->shouldReceive('current')->andReturn(null);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* A Huawei with three PoE ports, the module on GE0/0/3 and the provisioner behind XGE0/0/1 */
    protected function switchWithModule()
    {
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => '192.0.2.10']);
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'public']);
        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X'];
        foreach ([6 => ['GE0/0/1', 2], 7 => ['GE0/0/2', 2], 8 => ['GE0/0/3', 1], 30 => ['XGE0/0/1', 1]] as $i => [$name, $oper])
            $t += [
                SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => $oper,
                SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => $i === 30 ? 10000 : 1000,
            ];
        $t += [
            MacTable::OID_HUAWEI_FDB_PORT.'.'.self::MODULE_OID.'.10.0' => 8,
            MacTable::OID_HUAWEI_FDB_PORT.'.44.207.103.48.125.162.1.0' => 30,   // 2c:cf:67:30:7d:a2, the provisioner
            Poe::OID_HW_CONSUMING.'.8' => 6900, Poe::OID_HW_POWER_STATUS.'.8' => 'Powered',
            Poe::OID_HW_CONSUMING.'.6' => 0, Poe::OID_HW_POWER_STATUS.'.6' => 'Detecting',
            Poe::OID_HW_CONSUMING.'.7' => 0, Poe::OID_HW_POWER_STATUS.'.7' => 'Detecting',
        ];
        $this->fake = new FakeSnmp(['' => $t]);
        $fake = $this->fake;
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });
        return Cm::create(['serial' => '10000000e611110c', 'mac' => self::MODULE_MAC, 'phase' => 'done', 'timeline' => [],
            'provisioning_started_at' => now()->subMinutes(6), 'provisioning_complete_at' => now()->subMinute(),
            'phase_started_at' => now()->subMinute(), 'polled_at' => now()]);
    }

    public function test_the_page_is_in_the_top_bar_and_behind_the_login()
    {
        $this->get('/ports')->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->get('/dashboard')->assertSee('href="'.route('ports').'"', false);
        $this->get('/ports')->assertOk()->assertSeeLivewire('ports')->assertSee('No switch is set up');
    }

    public function test_without_a_switch_it_says_where_to_set_one_up()
    {
        Livewire::test(Ports::class)
            ->assertSee('No switch is set up')
            ->assertSee(route('settings'), false);
    }

    public function test_the_module_tile_and_the_summary()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class)
            ->assertSee('S5731-S24P4X')
            ->assertSee('3 × 1G PoE · 1 × SFP')
            ->assertSee('110C')
            ->assertSee('1 module on the stand')
            ->assertSee('2 of 3 ports free')
            ->assertSee('1 done')
            ->assertSee('All done: the batch can be replaced')
            ->assertSeeHtml('<span class="short">UP</span>');
    }

    public function test_a_port_shows_its_details_with_the_module_card_and_identify()
    {
        $cm = $this->switchWithModule();
        Livewire::test(Ports::class)
            ->call('select', 'p8')
            ->assertSee('GE0/0/3')
            ->assertSee(route('cm', $cm->serial), false)
            ->assertSee('delivering 6.9 W')
            ->call('identify', $cm->id);

        $this->assertTrue($cm->fresh()->isIdentifying());
    }

    public function test_the_switch_is_asked_once_per_freshness_window()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->switchWithModule();
        $page = Livewire::test(Ports::class);
        $walks = count($this->fake->walks);
        Carbon::setTestNow('2026-10-12 10:00:03');
        $page->call('$refresh');
        $this->assertSame($walks, count($this->fake->walks));
    }

    public function test_a_switch_that_stops_answering_keeps_its_last_picture_with_a_warning()
    {
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->switchWithModule();
        $page = Livewire::test(Ports::class);
        $this->fake->timeout = true;
        Carbon::setTestNow('2026-10-12 10:00:30');
        $page->call('$refresh')
            ->assertSee('The switch has not answered for 0 s')
            ->assertSee('110C');
    }

    public function test_a_switch_that_never_answered_says_so()
    {
        $this->switchWithModule();
        $this->fake->timeout = true;
        Livewire::test(Ports::class)->assertSee('Cannot read the switch: No response from 192.0.2.1');
    }
}
```

«0 s» в тесте молчания: ошибка начинается с первого неудачного опроса (10:00:30), сразу после него прошло 0 секунд. `FakeSnmp` при `timeout` сообщает `No response from 192.0.2.1`.

- [ ] **Step 2: Убедиться, что падает**

Run: `sync_dev && tests 'PortsPageTest'`
Expected: падение — `Class "App\Http\Livewire\Ports" not found` (или `Route [ports] not defined`).

- [ ] **Step 3: Шрифт JetBrains Mono (OFL) в `public/fonts`**

Архив качается в отдельный пустой каталог, Python запускается с `-I` и получает путь аргументом.

```bash
mkdir -p "$SP/jbm" && cd "$SP/jbm" && curl -fsSL -o jbm.zip https://github.com/JetBrains/JetBrainsMono/releases/download/v2.304/JetBrainsMono-2.304.zip
WANT=$(curl -fsSL https://api.github.com/repos/JetBrains/JetBrainsMono/releases/tags/v2.304 | python -I -c "import json,sys; a=[x for x in json.load(sys.stdin)['assets'] if x['name']=='JetBrainsMono-2.304.zip']; print((a[0].get('digest') or '').replace('sha256:',''))")
GOT=$(sha256sum jbm.zip | cut -d' ' -f1); echo "zip sha256 $GOT, GitHub says '${WANT:-no digest}'"
[ -z "$WANT" ] || [ "$WANT" = "$GOT" ] || { echo "CHECKSUM MISMATCH"; exit 1; }
python -I -c "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); [open(o,'wb').write(z.read(n)) for n,o in [('fonts/webfonts/JetBrainsMono-Bold.woff2','jetbrains-mono-700.woff2'),('fonts/webfonts/JetBrainsMono-ExtraBold.woff2','jetbrains-mono-800.woff2'),('OFL.txt','JetBrainsMono-OFL.txt')]]" jbm.zip
cp jetbrains-mono-700.woff2 jetbrains-mono-800.woff2 JetBrainsMono-OFL.txt "$REPO/public/fonts/" && ls -la "$REPO/public/fonts/" | grep -i jetbrains
```

Expected: строка с sha256 (если GitHub отдаёт digest — совпадает), три файла в `public/fonts`, каждый woff2 около 90 КБ. Если в архиве другие пути (`KeyError`), посмотреть их: `python -I -c "import zipfile,sys; print(*zipfile.ZipFile(sys.argv[1]).namelist(), sep='\n')" jbm.zip | grep -i -e woff2 -e ofl`, и взять Bold, ExtraBold и OFL оттуда.

- [ ] **Step 4: Стили в конец `resources/css/app.css`**

```css
/* Ports page: the switch's front panel (resources/views/livewire/ports.blade.php). JetBrains Mono (OFL,
   public/fonts/JetBrainsMono-OFL.txt) keeps 0/O and 1/l apart in the serial numbers. */
@font-face {
    font-family: 'JetBrains Mono';
    font-style: normal;
    font-weight: 700;
    src: url('../fonts/jetbrains-mono-700.woff2') format('woff2');
}
@font-face {
    font-family: 'JetBrains Mono';
    font-style: normal;
    font-weight: 800;
    src: url('../fonts/jetbrains-mono-800.woff2') format('woff2');
}
.ports-serial { font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.ports-chassis { background: #2b2f36; border-radius: 8px; padding: 10px 12px 12px; display: flex; flex-wrap: wrap; gap: 14px 18px; align-items: flex-start; }
.ports-model { width: 100%; display: flex; justify-content: space-between; gap: 12px; color: #c9ced6; font-size: 11px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
.ports-block { display: grid; grid-auto-flow: column; grid-template-rows: auto 50px 50px auto; gap: 4px 5px; }
.ports-block.one-row { grid-auto-flow: row; grid-template-rows: auto 50px; }
.ports-block.uplinks { padding-left: 14px; border-left: 1px solid #454b55; }
.ports-num { color: #c9ced6; font: 700 10px 'JetBrains Mono', ui-monospace, monospace; text-align: center; }
.ports-tile { position: relative; width: 50px; height: 50px; border-radius: 6px; border: 2px solid transparent; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; padding: 0; cursor: pointer; transition: opacity .15s ease, filter .15s ease; }
.ports-tile.cage { border-radius: 3px; border-style: dashed; }
.ports-tile .short { font: 800 13px/1 'JetBrains Mono', ui-monospace, monospace; letter-spacing: .02em; }
.ports-tile .mark { font-weight: 800; font-size: 10px; line-height: 1; }
.ports-tile .bolt { position: absolute; top: 2px; right: 3px; font-size: 10px; line-height: 1; }
.ports-tile.selected { box-shadow: 0 0 0 3px #2b2f36, 0 0 0 5px #fff; }
.ports-tile.dim { opacity: .22; filter: grayscale(.6); }
.ports-tile:focus-visible { outline: 3px solid #6366f1; outline-offset: 2px; }
.ports-ok { background: #d1fae5; color: #047857; border-color: #047857; }
.ports-run { background: #dbeafe; color: #1d4ed8; border-color: #1d4ed8; }
.ports-fail { background: #fee2e2; color: #b91c1c; border-color: #b91c1c; }
.ports-stale { background: #fef3c7; color: #b45309; border-color: #b45309; }
.ports-unknown { background: #f3f4f6; color: #4b5563; border-color: #9ca3af; }
.ports-boot { background: #cffafe; color: #0e7490; border-color: #0e7490; }
.ports-foreign { background: #ede9fe; color: #6d28d9; border-color: #6d28d9; }
.ports-uplink { background: #e5e7eb; color: #374151; border-color: #9ca3af; }
.ports-free { background: #3a3f48; color: #9aa1ac; border-color: #4a505a; }
.ports-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 4px 10px; font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; cursor: pointer; border: 1px solid transparent; }
.ports-chip .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
.ports-chip.pressed { box-shadow: 0 0 0 2px #fff, 0 0 0 4px currentColor; }
.ports-chip.faded { opacity: .45; }
.ports-chip.ports-free { border-color: #e5e7eb; background: #f9fafb; color: #6b7280; }
```

Цвета — палитра приложения: `green` в `tailwind.config.js` — это emerald (`#d1fae5`/`#047857`), «нет связи» — `#b45309`, как на дашборде.

- [ ] **Step 5: Компонент `app/Http/Livewire/Ports.php`**

```php
<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Services\NetworkStatus;
use App\Services\Switch\SnapshotCache;
use App\Services\Switch\SwitchSnapshot;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * The stand by the switch's ports (docs/superpowers/specs/2026-10-11-ports-page-design.md): the front
 * panel with the module in each port, the status filter (Alpine, in the browser) and one port's details.
 */
class Ports extends Component
{
    /** key of the port whose details are open, e.g. "p10003" */
    public $selected = null;

    public function select($key)
    {
        $this->selected = is_string($key) && preg_match('/^p\d+$/', $key) ? $key : null;
    }

    public function identify($id)
    {
        $cm = Cm::find($id);
        if ($cm && $cm->canIdentify())
            $cm->identify();
    }

    public function stopIdentify($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $cm->stopIdentify();
    }

    public function render()
    {
        $config = SwitchPortFinder::config();
        if (!$config['host'])
            return view('livewire.ports', ['configured' => false]);

        $state = (new SnapshotCache($config['host'], function () use ($config) {
            return SwitchSnapshot::forConfig($config)->take();
        }))->current();

        $map = null;
        if ($state['snapshot'])
            $map = (new StandMap($state['snapshot'], $this->modulesOn($state['snapshot']), app(NetworkStatus::class)->serverMac()))->build();

        return view('livewire.ports', [
            'configured' => true,
            'host' => $config['host'],
            'snapshot' => $state['snapshot'],
            'error' => $state['error'],
            'map' => $map,
            'tile' => $map['tiles'][$this->selected] ?? null,
            'now' => Carbon::now()->getTimestamp(),
        ]);
    }

    /* Modules behind ports with a link and few enough addresses to be devices, not uplinks */
    protected function modulesOn(array $snapshot)
    {
        $macs = [];
        foreach ($snapshot['ports'] as $port)
            if ($port['up'] && count($port['macs']) <= StandMap::UPLINK_MACS)
                foreach ($port['macs'] as $mac)
                    $macs[] = strtolower($mac);
        return $macs ? Cm::whereIn(DB::raw('lower(mac)'), $macs)->get() : [];
    }
}
```

- [ ] **Step 6: Плитка `resources/views/livewire/ports-tile.blade.php`**

```blade
@php($never = $t['state'] === 'uplink' || ($t['sfp'] && $t['state'] === 'free'))
<button type="button" wire:click="select('{{ $t['key'] }}')" wire:key="{{ $t['key'] }}"
        class="ports-tile ports-{{ $t['filter'] }} {{ $t['sfp'] ? 'cage' : '' }} {{ $selected === $t['key'] ? 'selected' : '' }}"
        x-bind:class="{ 'dim': dim('{{ $t['filter'] }}', {{ $never ? 'true' : 'false' }}) }"
        title="{{ $t['name'] }}{{ $t['alias'] !== '' ? ' · '.$t['alias'] : '' }}"
        aria-label="{{ $t['name'] }}: {{ $words[$t['filter']] ?? $t['filter'] }}">
    @if (($t['poe']['state'] ?? null) === 'delivering')<span class="bolt" aria-hidden="true">⚡</span>@endif
    <span class="short">{{ $t['short'] }}</span>
    <span class="mark">{{ $t['mark'] }}</span>
</button>
```

- [ ] **Step 7: Страница `resources/views/livewire/ports.blade.php`**

`x-data` стоит на второй строке корневого тега (CLAUDE.md, «Фронтенд»), `@php` — внутри корневого элемента.

```blade
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ports</h2>
</x-slot>
<div class="py-8" wire:poll.5s
     x-data="{ filter: [], toggle(s) { this.filter = this.filter.includes(s) ? this.filter.filter(x => x !== s) : this.filter.concat([s]) }, dim(s, never) { return this.filter.length > 0 && (never || !this.filter.includes(s)) } }">
@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
@php($words = ['ok' => 'done', 'run' => 'running', 'fail' => 'failed', 'stale' => 'silent', 'unknown' => 'unknown', 'boot' => 'booting', 'foreign' => 'foreign', 'free' => 'free'])
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 px-4 space-y-4">

@if (!$configured)
    <div class="bg-white shadow-xl sm:rounded-lg px-6 py-6 text-gray-700">
        No switch is set up. Enter its address under
        <a href="{{ route('settings') }}" class="text-blue-700 hover:underline">Other settings → Ethernet switch</a>
        to see the stand by its ports.
    </div>
@else
    <div class="text-sm text-gray-600">
        <span class="font-bold text-gray-900">{{ $snapshot['model'] ?? $host }}</span> · {{ $host }}
        @if ($snapshot) · polled {{ max(0, $now - $snapshot['taken_at']) }} s ago @endif
        @if ($snapshot && $snapshot['poe'])
            · PoE {{ number_format(array_sum(array_map(function ($p) { return $p['poe']['watts'] ?? 0; }, $snapshot['ports'])), 1) }} W
        @endif
    </div>

    @if ($error)
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-900 px-4 py-3 rounded" role="alert">
            @if ($snapshot)
                The switch has not answered for {{ max(0, $now - $error['since']) }} s ({{ $error['message'] }});
                showing what it said at {{ \Illuminate\Support\Carbon::createFromTimestamp($snapshot['taken_at'])->local()->format('H:i:s') }}.
            @else
                Cannot read the switch: {{ $error['message'] }}
            @endif
        </div>
    @endif

    @if ($map)
        @php($s = $map['summary'])
        @php($verdictColor = ['running' => 'bg-blue-100 text-blue-800', 'failed' => 'bg-red-100 text-red-800', 'done' => 'bg-green-100 text-green-800', 'empty' => 'bg-gray-100 text-gray-700'][$s['verdict']])
        <section class="bg-white shadow-xl sm:rounded-lg px-4 py-4 grid gap-3 md:grid-cols-[1fr_auto] items-center" aria-label="The stand now">
            <div class="font-bold md:col-span-2">
                {{ $s['modules'] }} {{ $s['modules'] === 1 ? 'module' : 'modules' }} on the stand
                <span class="text-gray-500 font-semibold">· {{ $s['counts']['free'] }} of {{ $s['ports'] }} ports free</span>
            </div>
            <div class="flex flex-wrap gap-2 items-center" role="group" aria-label="Show only">
                @foreach ($words as $key => $word)
                    @if ($s['counts'][$key])
                        <button type="button" class="ports-chip ports-{{ $key }}" x-on:click="toggle('{{ $key }}')"
                                x-bind:aria-pressed="filter.includes('{{ $key }}') ? 'true' : 'false'"
                                x-bind:class="{ 'pressed': filter.includes('{{ $key }}'), 'faded': filter.length > 0 && !filter.includes('{{ $key }}') }">
                            <span class="dot"></span>{{ $s['counts'][$key] }} {{ $word }}
                        </button>
                    @endif
                @endforeach
                <button type="button" x-show="filter.length > 0" x-cloak x-on:click="filter = []" class="text-sm font-semibold text-indigo-600 px-2">Show all</button>
            </div>
            <div class="text-sm font-bold rounded px-3 py-2 {{ $verdictColor }}">
                @if ($s['verdict'] === 'running')
                    {{ $s['busy'] }} still running, {{ $s['counts']['fail'] }} failed: keep the batch on the stand
                @elseif ($s['verdict'] === 'failed')
                    All finished, {{ $s['counts']['fail'] }} failed{{ $s['counts']['unknown'] ? ', '.$s['counts']['unknown'].' without data' : '' }}: restart or replace them
                @elseif ($s['verdict'] === 'done')
                    All done: the batch can be replaced
                @else
                    No modules on the stand
                @endif
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] items-start">
            <div class="bg-white shadow-xl sm:rounded-lg p-4 min-w-0">
                <div class="overflow-x-auto pb-1">
                    <div class="ports-chassis">
                        <div class="ports-model">
                            <span>{{ $snapshot['model'] }}</span>
                            <span>{{ $s['composition'] }}</span>
                        </div>
                        @foreach ($map['blocks'] as $block)
                            @if ($block['rows'] === 1)
                                <div class="ports-block one-row {{ $block['uplinks'] ? 'uplinks' : '' }}" style="grid-template-columns: repeat({{ count($block['tiles']) }}, 50px)">
                                    @foreach ($block['tiles'] as $t)<span class="ports-num">{{ $t['number'] }}</span>@endforeach
                                    @foreach ($block['tiles'] as $t)@include('livewire.ports-tile', ['t' => $t])@endforeach
                                </div>
                            @else
                                <div class="ports-block {{ $block['uplinks'] ? 'uplinks' : '' }}">
                                    @foreach (array_chunk($block['tiles'], 2) as $pair)
                                        <span class="ports-num">{{ $pair[0]['number'] }}</span>
                                        @include('livewire.ports-tile', ['t' => $pair[0]])
                                        @if (isset($pair[1]))
                                            @include('livewire.ports-tile', ['t' => $pair[1]])
                                            <span class="ports-num">{{ $pair[1]['number'] }}</span>
                                        @else
                                            <span></span><span></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-3">The last 4 characters of the serial and the result (✓, ✕, write %) per port; ⚡ the port powers its device; dashed: a cage for uplinks.</p>
            </div>

            <aside class="bg-white shadow-xl sm:rounded-lg p-4 text-sm" aria-live="polite">
                @if ($tile)
                    <h3 class="ports-serial font-bold text-base">{{ $tile['name'] }}</h3>
                    <div class="text-gray-500 mb-3">{{ $tile['alias'] !== '' ? $tile['alias'] : 'no description' }}</div>
                    @foreach ($tile['modules'] as $cm)
                        @php($advice = \App\Support\FailureAdvice::for($cm))
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 mb-3">
                            <dt class="text-gray-500">Module</dt>
                            <dd><a href="{{ route('cm', $cm->serial) }}" class="ports-serial font-bold text-blue-700 hover:underline">{{ $cm->serial }}</a></dd>
                            <dt class="text-gray-500">Step</dt>
                            <dd>
                                @if ($cm->phase)
                                    {{ $cm->phaseLabel() }}@if ($cm->isActive() && $cm->progressPercent() !== null) · {{ $cm->progressPercent() }}% @endif
                                @elseif ($cm->provisioning_complete_at)
                                    Finished; no details from before 1.6.6
                                @else
                                    Nothing is known about its runs
                                @endif
                            </dd>
                            @if ($cm->isStreaming() && $cm->bytesPerSecond())
                                <dt class="text-gray-500">Speed</dt>
                                <dd>{{ $fmt::speed($cm->bytesPerSecond()) }}@if ($cm->secondsLeft() !== null), {{ $fmt::duration($cm->secondsLeft()) }} left @endif</dd>
                            @endif
                            @if ($cm->phase === 'done' && $cm->provisioning_started_at && $cm->provisioning_complete_at)
                                <dt class="text-gray-500">Result</dt>
                                <dd>took {{ $fmt::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) }}@if ($cm->eeprom_result) · EEPROM {{ $cm->eeprom_result }} @endif</dd>
                            @endif
                        </dl>
                        @if ($advice)
                            <p class="mb-2 font-semibold" style="color: {{ $cm->phase === 'failed' ? '#b91c1c' : '#b45309' }}">{{ $advice['advice'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2 mb-3">
                            <a href="{{ route('cm', $cm->serial) }}" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Module card</a>
                            @if ($cm->canIdentify())
                                @if ($cm->isIdentifying())
                                    <button type="button" wire:click="stopIdentify({{ $cm->id }})" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Stop</button>
                                @else
                                    <button type="button" wire:click="identify({{ $cm->id }})" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Identify</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                    @if ($tile['state'] === 'foreign')
                        <p class="mb-2">Not a module: <span class="ports-serial">{{ implode(', ', $tile['macs']) }}</span></p>
                    @elseif ($tile['state'] === 'boot')
                        <p class="mb-2">Link up, nothing has talked to the server from this port yet.</p>
                    @elseif ($tile['state'] === 'uplink')
                        <p class="mb-2">Uplink: {{ count($tile['macs']) }} MAC {{ count($tile['macs']) === 1 ? 'address' : 'addresses' }} behind it.</p>
                    @endif
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-gray-500">Link</dt>
                        <dd>
                            @if (!$tile['up']) down
                            @elseif ($tile['speed']) {{ $tile['speed'] >= 1000 ? ($tile['speed'] / 1000).' Gbit/s' : $tile['speed'].' Mbit/s' }}
                            @else up
                            @endif
                        </dd>
                        <dt class="text-gray-500">PoE</dt>
                        <dd>
                            @if (!$tile['poe']) none
                            @elseif ($tile['poe']['state'] === 'delivering') delivering{{ $tile['poe']['watts'] !== null ? ' '.number_format($tile['poe']['watts'], 1).' W' : '' }}{{ $tile['poe']['class'] !== null ? ', class '.$tile['poe']['class'] : '' }}
                            @elseif ($tile['poe']['state'] === 'searching') looking for a device
                            @elseif ($tile['poe']['state'] === 'fault') fault
                            @elseif ($tile['poe']['state'] === 'disabled') off
                            @else {{ $tile['poe']['state'] }}
                            @endif
                        </dd>
                    </dl>
                @else
                    <p class="text-gray-500">Click a port for its details.</p>
                @endif
            </aside>
        </div>
    @endif
@endif
</div>
</div>
```

- [ ] **Step 8: Маршрут и меню**

`routes/web.php`: к импортам добавить `use App\Http\Livewire\Ports;`, после маршрута `/cms/{serial}` добавить:

```php
Route::middleware(['auth:sanctum', config('jetstream.auth_session'), 'verified'])->get('/ports', Ports::class)->name('ports');
```

`resources/views/navigation-menu.blade.php` — после блока со ссылкой `CMs` (`route('cms')`, верхнее меню) вставить:

```blade
                <div class="hidden space-x-8 sm:-my-px sm:ml-10 sm:flex">
                    <x-nav-link href="{{ route('ports') }}" :active="request()->routeIs('ports')">
                        {{ __('Ports') }}
                    </x-nav-link>
                </div>
```

Мобильное меню не трогается (спека, «Не входит»).

- [ ] **Step 9: Тесты проходят**

Run: `sync_dev && tests 'PortsPageTest'`
Expected: `phpunit exit 0`, `OK (7 tests, …)`.

- [ ] **Step 10: CSS и полный набор**

Сборка и копирование — в одной сессии стенда: `/tmp` там tmpfs.

```bash
sync_dev && cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s > "$REPO/public/css/app.css"
sh /root/bench/25-css.sh >&2 && cat /tmp/app.css
EOF
cd "$REPO" && for c in 'grid-cols-\[1fr_auto\]' 'lg\:grid-cols-\[minmax' 'grid-cols-\[auto_1fr\]' 'ports-tile' 'bg-yellow-100' 'border-yellow-500' 'jetbrains-mono-800'; do printf '%s:%s ' "$c" "$(grep -cF "$c" public/css/app.css)"; done; echo
sync_dev && tests_all
```

Expected: `25-css.sh` печатает `Done in …` и `app.css: N bytes`; все семь счётчиков равны 1 (минифицированный CSS — одна строка); `phpunit exit 0`.

- [ ] **Step 11: Commit**

```bash
cd "$REPO" && git add app/Http/Livewire/Ports.php resources/views/livewire/ports.blade.php resources/views/livewire/ports-tile.blade.php routes/web.php resources/views/navigation-menu.blade.php resources/css/app.css public/css/app.css public/fonts/jetbrains-mono-700.woff2 public/fonts/jetbrains-mono-800.woff2 public/fonts/JetBrainsMono-OFL.txt tests/Feature/PortsPageTest.php
git commit -q -m "The Ports page: the stand by the switch's ports, with a status filter and port details" -m "JetBrains Mono (OFL) for the serial numbers on the tiles." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: snmpsim — снимок через настоящий php-snmp

**Files:**
- Modify: `tests/fixtures/snmp/generate.py`; новые `tests/fixtures/snmp/{s5731,c9200,c9200@1,c9200@20,cbs350,crs}.snmprec`
- Modify: `tests/Feature/SnmpAgentTest.php` (новые тесты в конец, прежние не трогать)

**Interfaces:**
- Consumes: `SwitchSnapshot`, `MacTable`, `Poe`, `PhpSnmpClient` (значения `SNMP_VALUE_PLAIN`, OID-значения — числом).
- Produces: файлы данных; community выбирает файл (`c9200@20` — экземпляр VLAN 20 у Cisco IOS).

- [ ] **Step 1: Данные в `generate.py`** (перед строкой `def key(rec):`; `SYSDESCR` там уже с `.0`)

```python
# Ports page: whole switches with IF-MIB, MAC tables and PoE
IFTYPE = '1.3.6.1.2.1.2.2.1.3'
IFOPER = '1.3.6.1.2.1.2.2.1.8'
IFHIGH = '1.3.6.1.2.1.31.1.1.1.15'
IFCONN = '1.3.6.1.2.1.31.1.1.1.17'
PETH_DET = '1.3.6.1.2.1.105.1.1.1.6'
PETH_CLASS = '1.3.6.1.2.1.105.1.1.1.10'
HW_POE_STATUS = '1.3.6.1.4.1.2011.5.25.195.3.1.7'
HW_POE_POWER = '1.3.6.1.4.1.2011.5.25.195.3.1.10'
CPE_POWER = '1.3.6.1.4.1.9.9.402.1.2.1.9'
CPE_ENTPHY = '1.3.6.1.4.1.9.9.402.1.2.1.11'
ENT_ALIAS = '1.3.6.1.2.1.47.1.3.2.1.2'

def ports(spec):
    """spec: {ifIndex: (name, ifType, connector, oper, highSpeed)}"""
    recs = []
    for i, (name, typ, conn, oper, high) in spec.items():
        recs += [(f'{IFDESCR}.{i}', 4, name), (f'{IFNAME}.{i}', 4, name), (f'{IFALIAS}.{i}', 4, ''),
                 (f'{IFTYPE}.{i}', 2, typ), (f'{IFCONN}.{i}', 2, conn), (f'{IFOPER}.{i}', 2, oper), (f'{IFHIGH}.{i}', 66, high)]
    return recs

# Huawei S5731-S24P4X: 24 GE PoE + 4 XGE, HUAWEI-L2MAM and HUAWEI-POE-MIB by interface; the office LAN
# (00:11:22:33:44:55..59) behind XGE0/0/1
S5731 = {i: (f'GE0/0/{i}', 6, 1, 1 if i in (1, 3) else 2, 1000) for i in range(1, 25)}
S5731.update({24 + i: (f'XGE0/0/{i}', 6, 1, 1 if i == 1 else 2, 10000) for i in range(1, 5)})
S5731[50] = ('Vlanif1', 136, 2, 1, 1000)
FILES['s5731'] = [
    (SYSDESCR, 4, 'S5731-S24P4X'),
    (f'{HWFDB}.{MODULE}.10.0', 2, 3),
    (f'{HW_POE_STATUS}.3', 4, 'Powered'), (f'{HW_POE_POWER}.3', 2, 6900),
] + [(f'{HWFDB}.0.17.34.51.68.{85 + n}.1.0', 2, 25) for n in range(5)] \
  + [(f'{HW_POE_STATUS}.{i}', 4, 'Detecting') for i in range(1, 25) if i != 3] + ports(S5731)

# Cisco IOS (Catalyst 9200L-like): 8 Gi PoE + 2 Te, BRIDGE-MIB per VLAN, PoE through ENTITY-MIB
C9200 = {10100 + i: (f'Gi1/0/{i}', 6, 1, 1 if i == 5 else 2, 1000) for i in range(1, 9)}
C9200.update({10200 + i: (f'Te1/1/{i}', 6, 1, 1 if i == 1 else 2, 10000) for i in range(1, 3)})
FILES['c9200'] = [
    (SYSDESCR, 4, 'Cisco IOS Software, C9200L'),
    (f'{FDB}.{UPLINK_PEER}', 2, 9), (f'{BASE}.9', 2, 10201),
    (f'{VTP}.1.1', 2, 1), (f'{VTP}.1.20', 2, 1),
] + [(f'{PETH_DET}.1.{i}', 2, 3 if i == 5 else 2) for i in range(1, 9)] \
  + [(f'{PETH_CLASS}.1.5', 2, 5), (f'{CPE_POWER}.1.5', 66, 7100)] \
  + [(f'{CPE_ENTPHY}.1.{i}', 2, 1000 + i) for i in range(1, 9)] \
  + [(f'{ENT_ALIAS}.{1000 + i}.0', 6, f'1.3.6.1.2.1.2.2.1.1.{10100 + i}') for i in range(1, 9)] + ports(C9200)
FILES['c9200@1'] = [(f'{FDB}.{UPLINK_PEER}', 2, 9), (f'{BASE}.9', 2, 10201)]
FILES['c9200@20'] = [(f'{FDB}.{MODULE}', 2, 5), (f'{BASE}.5', 2, 10105)]

# Cisco Business CBS350-8P-2G: Q-BRIDGE with interface indexes, RFC 3621 port number = ifIndex
CBS350 = {i: (f'gi{i}', 6, 1, 1 if i == 2 else 2, 1000) for i in range(1, 11)}
FILES['cbs350'] = [
    (SYSDESCR, 4, 'CBS350-8P-2G 8-Port Gigabit PoE Managed Switch'),
    (f'{QFDB}.1.{MODULE}', 2, 2),
] + [(f'{PETH_DET}.1.{i}', 2, 3 if i == 2 else 2) for i in range(1, 9)] + ports(CBS350)

# MikroTik CRS309-like: ether ports, an sfp+ cage, the bridge interface; no PoE
CRS = {i: (f'ether{i}', 6, 1, 1 if i == 4 else 2, 1000) for i in range(1, 9)}
CRS[9] = ('sfp-sfpplus1', 6, 1, 2, 10000)
CRS[12] = ('bridge', 209, 2, 1, 1000)
FILES['crs'] = [
    (SYSDESCR, 4, 'RouterOS CRS309'),
    (f'{QFDB}.0.{MODULE}', 2, 4),
    (f'{BASE}.4', 2, 4),
] + ports(CRS)
```

- [ ] **Step 2: Тесты в конец `tests/Feature/SnmpAgentTest.php`**

```php
    protected function snapshot($community)
    {
        $snmp = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => $community]);
        return (new \App\Services\Switch\SwitchSnapshot($snmp, new \App\Services\Switch\MacTable($snmp), new \App\Services\Switch\Poe($snmp), 'snmpsim'))->take();
    }

    public function test_snapshot_of_a_huawei_s5731_with_poe_by_interface()
    {
        $s = $this->snapshot('s5731');
        $this->assertSame('S5731-S24P4X', $s['model']);
        $this->assertCount(28, $s['ports'], '24 GE and 4 XGE, not Vlanif1');
        $this->assertSame([self::MODULE], $s['ports'][3]['macs']);
        $this->assertSame(['state' => 'delivering', 'class' => null, 'watts' => 6.9], $s['ports'][3]['poe']);
        $this->assertSame('searching', $s['ports'][4]['poe']['state']);
        $this->assertNull($s['ports'][25]['poe']);
        $this->assertCount(5, $s['ports'][25]['macs'], 'the office LAN behind the uplink');
    }

    public function test_snapshot_of_a_cisco_ios_switch_with_poe_through_entity_mib()
    {
        $s = $this->snapshot('c9200');
        $this->assertSame([self::MODULE], $s['ports'][10105]['macs'], 'from the VLAN 20 instance');
        $this->assertSame(['state' => 'delivering', 'class' => 4, 'watts' => 7.1], $s['ports'][10105]['poe']);
        $this->assertSame(['00:11:22:33:44:55'], $s['ports'][10201]['macs']);
    }

    public function test_snapshot_of_a_cisco_business_switch_with_poe_by_interface_index()
    {
        $s = $this->snapshot('cbs350');
        $this->assertSame([self::MODULE], $s['ports'][2]['macs']);
        $this->assertSame('delivering', $s['ports'][2]['poe']['state']);
        $this->assertNull($s['ports'][9]['poe'], 'the uplink ports have no PoE');
    }

    public function test_snapshot_of_a_mikrotik_without_poe()
    {
        $s = $this->snapshot('crs');
        $this->assertSame(['ether1', 'ether2', 'ether3', 'ether4', 'ether5', 'ether6', 'ether7', 'ether8', 'sfp-sfpplus1'], array_column($s['ports'], 'name'));
        $this->assertFalse($s['poe']);
        $this->assertSame([self::MODULE], $s['ports'][4]['macs']);
    }
```

- [ ] **Step 3: Сгенерировать данные и прогнать на snmpsim**

snmpsim держит данные в `/tmp` стенда и живёт, пока жив стенд, поэтому запуск и тесты идут одним скриптом.

```bash
cd "$REPO" && python -I tests/fixtures/snmp/generate.py tests/fixtures/snmp && git status --short tests/fixtures/snmp
sync_dev && cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
sh /root/bench/07-snmpsim-run.sh > /root/snmpsim-start.log 2>&1; grep -A1 -- "--- v2c qbridge" /root/snmpsim-start.log
cd /root/dev && SNMPSIM_ENDPOINT=127.0.0.1:1161 DB_CONNECTION=sqlite DB_DATABASE=:memory: vendor/bin/phpunit --no-coverage --colors=never --filter SnmpAgentTest 2>&1 | tail -8
EOF
```

Expected: `generate.py` печатает прежние файлы и `s5731`, `c9200`, `c9200@1`, `c9200@20`, `cbs350`, `crs` с числом записей; `git status` показывает шесть новых `.snmprec` и изменённый `generate.py`, прежние `.snmprec` не меняются; snmpsim отвечает на `qbridge`; phpunit — `OK (11 tests, …)` (7 прежних и 4 новых).

- [ ] **Step 4: Commit**

```bash
cd "$REPO" && git add tests/fixtures/snmp tests/Feature/SnmpAgentTest.php
git commit -q -m "snmpsim switches for the Ports page: Huawei S5731, Cisco IOS, Cisco Business, MikroTik" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Документы, сборка, проверка в браузере

**Files:**
- Modify: `CLAUDE.md`, `debian/changelog`
- Стенд: `/root/bench/03-deb.sh`, snmpsim, Playwright

- [ ] **Step 1: CLAUDE.md**

В разделе «Данные и хранилище» после пункта про `SwitchPortFinder` («Порт модуля на коммутаторе находит …») добавить пункт:

```markdown
- Страница Ports (`/ports`, компонент `Ports`) показывает стенд по портам коммутатора. `App\Services\Switch\SwitchSnapshot` одним опросом читает физические порты (IF-MIB: Ethernet по `ifType` и `ifConnectorPresent`, если агент его сообщает), линк, скорость, MAC на портах (`MacTable` — та же таблица, что у `SwitchPortFinder`) и PoE (`Poe`: HUAWEI-POE-MIB по ifIndex, у Cisco IOS — CISCO-POWER-ETHERNET-EXT-MIB с ENTITY-MIB, у остальных — RFC 3621 по номеру порта). Коммутатор, не назвавший ни одного интерфейса (например, при неверной учётке v3), — ошибка, а не пустая панель. `SnapshotCache` хранит снимок 5 секунд (у медленного коммутатора — вдвое дольше опроса), опрашивает одним запросом за раз, при молчании показывает последний снимок с ошибкой и пишет в журнал Laravel строку на смену «отвечает/не отвечает». `App\Support\StandMap` раскладывает порты как на корпусе (PoE-порты блоками по 12, остальные — блок аплинков) и считает сводку. Порт без линка — свободный, даже если MAC ещё в таблице коммутатора; аплинк — порт с MAC провижинера (`NetworkStatus::serverMac()`) или больше 3 MAC. Фильтр по чипам сводки живёт в Alpine. Сопоставление PoE и эвристика «медь или гнездо» проверены только на snmpsim (`tests/fixtures/snmp`: `s5731`, `c9200`, `cbs350`, `crs`); на настоящих коммутаторах — пункт 1 ROADMAP.
```

В разделе «Тесты» фразу «поиск порта на коммутаторе (`Tests\Support\FakeSnmp`),» заменить на «поиск порта и снимок коммутатора для страницы Ports (`Tests\Support\FakeSnmp`), карту стенда (`StandMap`),».

- [ ] **Step 2: Changelog `2.1.0~dev1` и коммит**

```bash
cd "$REPO" && D=$(date -R) && { printf '%s\n' "cmprovision4 (2.1.0~dev1) unstable; urgency=medium" "" \
"  * Ports page: the stand by the switch's ports. Each port shows the module" \
"    plugged into it with its state (running, done, failed, silent), the" \
"    link and PoE; the summary counts what is on the stand and says when the" \
"    batch can be replaced, and its chips filter the ports. Read only: the" \
"    switch is asked over SNMP while the page is open, at most every 5 s." \
"" " -- nimbo78 <igor.sin.78@gmail.com>  $D" ""; cat debian/changelog; } > debian/changelog.new && mv debian/changelog.new debian/changelog && head -9 debian/changelog
git add CLAUDE.md debian/changelog && git commit -q -m "Docs and changelog for the Ports page (2.1.0~dev1)" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Expected: первые девять строк changelog — новая запись с датой вида `Sun, 11 Oct 2026 … +0300`.

- [ ] **Step 3: Сборка и установка на стенде**

```bash
git -C "$REPO" archive HEAD | w_raw sh -c 'rm -rf /root/src && mkdir -p /root/src && tar -x -C /root/src'
w sh /root/bench/03-deb.sh 2>&1 | grep -E "^=== build|phpunit in deb|install ok|: (active|inactive)|web:|FAILED"
```

Expected: `=== build 2.1.0~dev1`, `phpunit in deb: 0 | …`, `cmprovision4 2.1.0~dev1 install ok installed`, `nginx: active`, `php8.4-fpm: active`, `cmprovision-queue: active` (dnsmasq и rpiboot на стенде замаскированы), `web: 200`.

- [ ] **Step 4: Страница на snmpsim в браузере**

1. Фоновая сессия, чтобы стенд не засыпал, пока открыт браузер (Bash с `run_in_background`): `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 3600`.
2. snmpsim и данные. На стенде копия рабочих данных провижинера: каналы уведомлений выключаются первыми, строка sensor0 сохраняется в файл и возвращается в пункте 5. Пароль одноразовой учётки — свежий, создаётся на этом шаге и в репозиторий не попадает.

```bash
PW=$(openssl rand -base64 15 | tr -d '/+=' | cut -c1-16); echo "bench-only password: $PW"
sed "s/__PW__/$PW/" <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
sh /root/bench/07-snmpsim-run.sh > /root/snmpsim-start.log 2>&1; grep -A1 -- "--- v2c qbridge" /root/snmpsim-start.log
cd /var/lib/cmprovision && runuser -u www-data -- env HOME=/tmp php artisan tinker --execute='
App\Models\NotificationChannel::query()->update(["enabled" => false]);
foreach (["ip" => "127.0.0.1:1161", "snmp_version" => "2c", "snmp_community" => "s5731", "method" => "auto"] as $k => $v)
    App\Models\Setting::updateOrCreate(["key" => "ethernetswitch_".$k], ["value" => $v]);
App\Models\User::where("email", "operator@example.com")->delete();
App\Models\User::create(["name" => "Operator", "email" => "operator@example.com", "password" => Illuminate\Support\Facades\Hash::make("__PW__")]);
$cm = App\Models\Cm::where("serial", "10000000e611110c")->first();
if ($cm && !file_exists("/var/lib/cmprovision/storage/app/sensor0-before.json"))
    file_put_contents("/var/lib/cmprovision/storage/app/sensor0-before.json", json_encode($cm->getAttributes()));
App\Models\Cm::updateOrCreate(["serial" => "10000000e611110c"], ["mac" => "e4:5f:01:62:9f:d3", "phase" => "write", "phase_detail" => null,
    "progress_bytes" => 2700000000, "progress_total" => 6211764224, "progress_updated_at" => now(), "phase_started_at" => now()->subMinute(),
    "provisioning_started_at" => now()->subMinutes(2), "provisioning_complete_at" => null]);
echo "ok\n";'
EOF
```

Expected: ответ snmpsim на `qbridge`, `ok`.

3. Playwright (снимки сохраняются только в `.playwright-mcp/` репозитория): вход на `http://localhost/login` учёткой `operator@example.com`, затем `http://localhost/ports`. Проверить:
   - панель Huawei: 24 медных порта двумя рядами блоками по 12, четыре XGE отдельным пунктирным блоком; в шапке панели `S5731-S24P4X` и `24 × 1G PoE · 4 × SFP`;
   - порт 3 — плитка `110C` с процентом записи и молнией; порт 1 — `···`; XGE 1 — `UP` (за ним пять MAC офисной сети);
   - сводка `1 module on the stand`, чипы; щелчок по чипу `running` гасит остальные порты; через 6 секунд, после обновления `wire:poll`, фильтр всё ещё действует; `Show all` его снимает;
   - щелчок по порту 3 — подробности: `Module card` ведёт на `/cms/10000000e611110c`, `delivering 6.9 W`;
   - snmpsim остановить (скрипт на stdin: `pkill -f snmpsim-command-responder`), через 6 секунд — жёлтая плашка `The switch has not answered for … s` и прежняя панель; затем снова `07-snmpsim-run.sh`;
   - community `c9200`, затем `crs` (`App\Models\Setting::updateOrCreate(["key" => "ethernetswitch_snmp_community"], ["value" => "c9200"])` тем же способом через tinker, затем подождать 6 секунд): Cisco — 8 PoE-портов одной строкой и 2 гнезда, модуль на порту 5; MikroTik — 8 `ether` и одно гнездо, модуль на `ether4`;
   - ширина 1024 px — пункт `Ports` помещается в верхнее меню; ширина 390 px — панель прокручивается внутри своей рамки, страница целиком — нет.
4. Снимки (Huawei, фильтр, подробности, плашка, Cisco, MikroTik, телефон) перенести из `.playwright-mcp/` в `$SP/shots-ports` и отправить пользователю в Telegram (память `telegram-delivery`: инструмент `reply` плагина Telegram, `chat_id` из `allowFrom` в `~/.claude/channels/telegram/access.json`).
5. Убрать за собой — одним скриптом на stdin:

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
cd /var/lib/cmprovision && runuser -u www-data -- env HOME=/tmp php artisan tinker --execute='
$u = App\Models\User::where("email", "operator@example.com")->first();
if ($u) { $u->tokens()->delete(); Illuminate\Support\Facades\DB::table("sessions")->where("user_id", $u->id)->delete(); $u->delete(); }
App\Models\Setting::where("key", "like", "ethernetswitch_%")->delete();
$f = "/var/lib/cmprovision/storage/app/sensor0-before.json";
if (file_exists($f)) { $a = json_decode(file_get_contents($f), true); Illuminate\Support\Facades\DB::table("cms")->where("serial", $a["serial"])->update($a); unlink($f); }
Illuminate\Support\Facades\Cache::forget("switch-snapshot:127.0.0.1:1161");
echo "clean\n";'
EOF
```

Каналы уведомлений остаются выключенными: копия данных живёт на стенде до конца серии улучшений. Закрыть браузер, остановить фоновую сессию.

Expected: всё перечисленное видно, `clean`. Расхождение — исправление отдельным коммитом с тестом, затем шаги 2–4 этой задачи заново со сборкой `2.1.0~dev2`.

У WSL-стенда нет интерфейса с 172.20.0.1, поэтому `serverMac()` там `null` и аплинк виден только по числу MAC. Аплинк по MAC провижинера проверяет `PortsPageTest`, на железе — пункт 1 ROADMAP.

---

### Task 10: Выпуск 2.1.0

**Files:**
- Modify: `debian/changelog`, `ROADMAP.md`

- [ ] **Step 1: Документы выпуска**

- `debian/changelog`: заголовок `(2.1.0~devN)` → `(2.1.0)`, дата в строке ` -- nimbo78 …` — текущая из `date -R`.
- `ROADMAP.md`, раздел «Сделано», после абзаца «Проверено» выпуска 2.0.0 (перед `## Этап 2: что осталось`) — блок ниже. `<день выпуска>` — дата словами, как у 2.0.0 («11 октября 2026»); `<N>` — число из итоговой строки `tests_all` (`Tests: N, …`).

```markdown
**2.1.0** вышла <день выпуска>, тег `v2.1.0`. Страница Ports.

- **Карта стенда.** Порты коммутатора как на корпусе: в каждом — модуль с его состоянием (идёт, готово, ошибка, нет связи), линк и PoE. Порт без линка свободен, даже если MAC ещё в таблице коммутатора; аплинк к провижинеру отмечен.
- **Сводка с фильтром.** Сколько модулей на стенде, в каком они состоянии и можно ли менять партию; чипы сводки оставляют яркими только свои порты.
- **Только чтение.** Коммутатор опрашивается по SNMP, пока страница открыта, не чаще раза в 5 секунд и одним запросом на все открытые страницы.

Проверено:
- **Тесты.** <N> тестов на PHP 8.4; снимок коммутатора через настоящий php-snmp на snmpsim с данными Huawei S5731, Cisco IOS, Cisco Business и MikroTik.
- **Стенд.** Страница в браузере на данных snmpsim, снимки у вас в Telegram. На настоящих коммутаторах — когда приедут (пункт 1).
```

- В «Этап 2: что осталось», пункт «### 2. Коммутатор, остаток»: удалить строку «**Страница «Порты».** …», строку про перезапуск по PoE оставить.

- [ ] **Step 2: Полный набор, сборка и установка на провижинер**

```bash
sync_dev && tests_all
cd "$REPO" && git add debian/changelog ROADMAP.md && git commit -q -m "Changelog 2.1.0, ROADMAP: the Ports page" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
git archive HEAD | ssh -o BatchMode=yes -o ServerAliveInterval=30 claude@192.168.25.30 'rm -rf ~/cmprovision/build/cmprovision && mkdir -p ~/cmprovision/build/cmprovision && tar -x -C ~/cmprovision/build/cmprovision && cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d > ../build.log 2>&1; echo "build exit $?"; cd .. && V=$(ls cmprovision4_2.1.0_all.deb) && export DEBIAN_FRONTEND=noninteractive && sudo -E apt-get install -y -q ./$V < /dev/null > ~/install-2.1.log 2>&1; echo "apt exit $?, installed: $(dpkg-query -W -f="\${Version}" cmprovision4)"; systemctl is-active nginx php8.4-fpm cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot | tr "\n" " "; echo; curl -s -o /dev/null -w "web %{http_code}\n" http://127.0.0.1/login'
```

Expected: `phpunit exit 0`; `build exit 0`, `apt exit 0, installed: 2.1.0`, пять `active`, `web 200`. Коммутатора на провижинере нет — страница Ports там говорит `No switch is set up`. Путь провижининга не менялся, цикл sensor0 не нужен.

- [ ] **Step 3: Слияние, тег, push — только после «да» пользователя**

```bash
cd "$REPO" && printf 'Merge stage2: release 2.1.0\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>\n' > "$SP/merge-msg.txt"
git checkout -q main && git pull -q --ff-only origin main && git merge -q --no-ff stage2 -F "$SP/merge-msg.txt" && git tag -a v2.1.0 -m "cmprovision4 2.1.0" && git describe --tags --exact-match HEAD && head -1 debian/changelog
git push -q origin main v2.1.0 && git checkout -q stage2 && git merge -q --ff-only main && git push -q origin stage2 && git log --oneline -2 --decorate
```

Expected: `v2.1.0`, `cmprovision4 (2.1.0) unstable; urgency=medium`, `main` и `stage2` на коммите слияния.

- [ ] **Step 4: Память проекта**

Обновить `cmprovision-fork-plan.md` (выпуск 2.1.0, коммит слияния, нумерация ROADMAP) и `cmprovision-dev-environment.md` (данные snmpsim для страницы Ports; на стенде нет 172.20.0.1, поэтому аплинк по MAC сервера там не виден).
