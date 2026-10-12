# Перезапуск модуля по PoE — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Оператор перезапускает модуль кнопкой: коммутатор выключает PoE на порту модуля на 5 секунд и включает снова (карточка модуля, страница Ports, дашборд).

**Architecture:** В `SnmpClient` появляются `get()` и `set()`. Запись делает `PoeControl` с отдельной учёткой на запись. Объект для записи даёт `Poe::admin()`; порты сопоставляются так же, как при чтении PoE. Решение, какой порт можно перезапускать, принимается по свежему снимку стенда: новый сервис `Stand` берёт снимок и карту, `StandMap::restartBlocker()` проверяет порт. Цикл «выключить, пауза, включить» выполняет `PoeRestart` синхронно в запросе, под замком на весь стенд. До выключения он пишет строку в `poe_outages`. Порт, который не включился, показывает плашка `PoeOutages` на дашборде и странице Ports.

**Tech Stack:** Laravel 13 в раскладке Laravel 8, Livewire 3.8 и Alpine, php-snmp, PHPUnit 12, `Illuminate\Support\Sleep`, snmpsim 1.2.2 на WSL-стенде.

**Spec:** `docs/superpowers/specs/2026-10-12-poe-restart-design.md`

## Global Constraints

- Тексты интерфейса на английском, дословно как в спецификации:
  - «Restart», «Restart failed (N)», «Restarting…»;
  - «Power on», «Forget»;
  - «Write access (PoE restart)», «Check write access»;
  - подтверждения из раздела «Интерфейс».
- Значения: выключено 5 с (`PoeRestart::OFF_SECONDS = 5`), шаг включения 0,5 с (`STAGGER_MS = 500`), три попытки включения с секундой между ними (`PoeControl::TRIES = 3`), замок и время запроса 120 с (`PoeRestart::LOCK_SECONDS = 120`). Строка без ошибки старше 120 с — порт остался выключенным (`PoeOutage::STUCK_SECONDS = 120`).
- Объекты: `hwPoePortEnable` `.1.3.6.1.4.1.2011.5.25.195.3.1.3.<ifIndex>`, `pethPsePortAdminEnable` `.1.3.6.1.2.1.105.1.1.1.3.<группа>.<порт>`. В обоих 1 значит включено, 2 — выключено, тип INTEGER (`i`).
- Перезапускаются только порты, где нет ничего, кроме наших модулей или загружающейся Raspberry Pi: `StandMap::restartBlocker()` возвращает `null`.
- Секреты на запись (community, пароли v3) никогда не попадают в браузер и хранятся только для того же адреса и версии SNMP, как секреты чтения.
- Цвета только из токенов (`TokenGuardTest`): никаких цветов палитры, `dark:` и hex в `style`.
- Blade:
  - в `ports.blade.php` только однострочные `@php(...)`;
  - в шаблонах Livewire не ставить `@if` внутри HTML-тега, вместо этого выражение `{{ }}`.
- Livewire: публичные методы — действия. Помощники делать `protected`.
- Код в OneDrive без `vendor/` и `node_modules/`, PHP-тесты на WSL-стенде (PHP 8.4). Коммиты заканчиваются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Без «да» пользователя не пушить.

## Review Focus

1. Перезапуск, пока страница Ports опрашивает коммутатор. Строка `poe_outages` в первые 5 секунд не должна считаться «порт остался выключенным», а снимок, снятый до выключения, не должен её закрыть. Тесты: задача 5 (`stayedOff`, `closeSeenOn`), задача 7 (плашки нет во время перезапуска).
2. Два оператора жмут Restart одновременно, или php-fpm перезапустился между выключением и включением. Второй получает «A restart is already running», брошенный порт появляется в плашке через 2 минуты. Тесты: задача 6 (замок), задача 5 (строка старше 2 минут).
3. Не тот порт: модуль переставили, MAC модуля виден и на аплинке, модуля нет на коммутаторе. Перезапускается порт, где модуль сейчас, а не аплинк; без модуля — причина. Тесты: задача 4 (`Stand::tileOf`), задача 6 (переставленный модуль).
4. Секреты на запись. Они не попадают в HTML и пропадают при новом адресе коммутатора. Тесты: задача 3.
5. Массовый перезапуск с частичными сбоями. Один порт не выключился, другой перезапустился: оба в ответе, исправный прошёл цикл, паузы между включениями есть. Тест: задача 6.

## Карта файлов

- `app/Services/Snmp/SnmpClient.php`, `PhpSnmpClient.php` — `get()` и `set()`.
- `tests/Support/FakeSnmp.php` — `get()` и `set()`, история `sets`, отказы `refuse`.
- `tests/Support/PoeStand.php` (новый) — тестовый стенд Huawei с правом записи.
- `app/Services/Switch/Poe.php` — `OID_HW_ENABLE`, `OID_PETH_ADMIN`, `admin()`.
- `app/Services/Switch/PoeControl.php` (новый) — запись: выключить, включить с проверкой, прочитать, проверить права.
- `app/Services/SwitchPortFinder.php` — `config()['write']`.
- `app/Http/Livewire/SwitchSettings.php`, `resources/views/livewire/switch-settings.blade.php` — блок прав на запись.
- `app/Support/StandMap.php` — поля плитки `ifIndex` и `others`, методы `restartBlocker()` и `poeText()`.
- `app/Services/Switch/Stand.php` (новый) — снимок и карта стенда для любых страниц, `tileOf()`.
- `database/migrations/2026_10_12_100000_create_poe_outages_table.php`, `app/Models/PoeOutage.php` (новые).
- `app/Services/Switch/PoeRestart.php` (новый) — цикл перезапуска, ответы, тексты подтверждений.
- `app/Http/Livewire/PoeOutages.php`, `resources/views/livewire/poe-outages.blade.php` (новые) — плашка.
- `app/Http/Livewire/Ports.php`, `resources/views/livewire/ports.blade.php` — кнопки страницы Ports.
- `app/Http/Livewire/CmCard.php`, `resources/views/livewire/cm-card.blade.php` — строка «Power (PoE)».
- `app/Http/Livewire/ProvisioningStatus.php`, `resources/views/livewire/provisioning-status.blade.php`, `resources/views/dashboard.blade.php` — кнопки и плашка на дашборде.
- `tests/fixtures/snmp/{s5731,c9200,cbs350}.snmprec`, `tests/Feature/SnmpAgentTest.php` — запись на snmpsim.
- `CLAUDE.md`, `README.md`, `ROADMAP.md`, `debian/changelog`.

## Команды

```bash
DEV=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad/wsl-bench
cd "/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
. "$DEV/dev.sh" && sync_dev >/dev/null && tests 'ClassA|ClassB'   # PHP-тесты на стенде (регулярка по именам)
bash "$DEV/suite-ok.sh"                                           # весь набор PHP и JS
bash "$DEV/snmpagent-ok.sh"                                       # snmpsim и SnmpAgentTest
```

Стенд WSL засыпает без открытой сессии. На время работы в фоне держится `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 7200`.

---

### Task 1: Чтение и запись одного объекта SNMP

**Files:**
- Modify: `app/Services/Snmp/SnmpClient.php`
- Modify: `app/Services/Snmp/PhpSnmpClient.php`
- Modify: `tests/Support/FakeSnmp.php`
- Create: `tests/Unit/FakeSnmpTest.php`

**Interfaces:**
- Produces:
  - `SnmpClient::get($oid)` возвращает значение объекта, бросает `SnmpError`;
  - `SnmpClient::set($oid, $type, $value): void`, бросает `SnmpError`;
  - `FakeSnmp::$sets` — список `[oid, type, value]`;
  - `FakeSnmp::$refuse` — `[oid => message]` или `["oid=value" => message]`; сообщение `timeout` означает таймаут.

- [ ] **Step 1: Тест тестового коммутатора**

`tests/Unit/FakeSnmpTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Snmp\SnmpError;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeSnmp;

/** The test switch keeps what is written, reads it back, records every write and refuses on demand */
class FakeSnmpTest extends TestCase
{
    const OID = '.1.3.6.1.2.1.105.1.1.1.3.1.4';

    public function test_a_write_is_kept_read_back_and_recorded()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->set(self::OID, 'i', 2);
        $this->assertSame(2, $snmp->get(self::OID));
        $this->assertSame([[self::OID, 'i', 2]], $snmp->sets);
    }

    public function test_a_refused_write_throws_and_changes_nothing()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->refuse[self::OID.'=1'] = 'noAccess';
        $snmp->set(self::OID, 'i', 2);
        try
        {
            $snmp->set(self::OID, 'i', 1);
            $this->fail('switching on should be refused');
        }
        catch (SnmpError $e)
        {
            $this->assertSame('noAccess', $e->getMessage());
            $this->assertFalse($e->timeout);
        }
        $this->assertSame(2, $snmp->get(self::OID));
        $this->assertCount(2, $snmp->sets, 'a refused write is still an attempt');
    }

    public function test_a_timeout_and_a_missing_object()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $snmp->refuse[self::OID] = 'timeout';
        try
        {
            $snmp->set(self::OID, 'i', 2);
            $this->fail('the write should time out');
        }
        catch (SnmpError $e)
        {
            $this->assertTrue($e->timeout);
        }
        $this->expectException(SnmpError::class);
        $snmp->get('.1.3.6.1.2.1.1.5.0');
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `. "$DEV/dev.sh" && sync_dev >/dev/null && tests 'FakeSnmpTest'`
Expected: `phpunit exit 255` или ошибки `Call to undefined method Tests\Support\FakeSnmp::set()`.

- [ ] **Step 3: Интерфейс, тестовый и настоящий клиенты**

`app/Services/Snmp/SnmpClient.php` после `walk()`:

```php
    /**
     * The value of one object (an instance OID such as ".1.3.6.1.2.1.105.1.1.1.3.1.4"). Throws SnmpError when the
     * agent does not answer, refuses or has no such object.
     */
    public function get($oid);

    /**
     * Writes one object; $type is php-snmp's one-letter type ("i" for INTEGER). Throws SnmpError when the agent does
     * not answer or refuses (noAccess, notWritable, wrongValue, an unknown user).
     */
    public function set($oid, $type, $value);
```

`tests/Support/FakeSnmp.php`, весь класс:

```php
/** A switch answering from a table of OIDs, per VLAN instance like Cisco IOS does; writes go to the table */
class FakeSnmp implements SnmpClient
{
    public $tables, $walks = [], $timeout = false;
    public $sets = [];     // every write as [oid, type, value], refused ones too
    public $refuse = [];   // oid => message, or "oid=value" => message: that write throws; the message "timeout" times out

    public function __construct(array $tables)
    {
        $this->tables = $tables;   // ['' => [oid => value], '20' => [...]] keyed by VLAN instance
    }

    public function walk($oid, $vlan = null)
    {
        $this->walks[] = [$oid, $vlan];
        if ($this->timeout)
            throw new SnmpError('No response from 192.0.2.1', true);
        $result = [];
        foreach ($this->tables[(string) $vlan] ?? [] as $key => $value)
            if (strpos($key, $oid.'.') === 0)
                $result[$key] = $value;
        return $result;
    }

    public function get($oid)
    {
        if ($this->timeout)
            throw new SnmpError('No response from 192.0.2.1', true);
        if (!array_key_exists($oid, $this->tables[''] ?? []))
            throw new SnmpError('No Such Instance currently exists at this OID');
        return $this->tables[''][$oid];
    }

    public function set($oid, $type, $value)
    {
        $this->sets[] = [$oid, $type, $value];
        $why = $this->refuse[$oid.'='.$value] ?? $this->refuse[$oid] ?? null;
        if ($this->timeout || $why === 'timeout')
            throw new SnmpError('No response from 192.0.2.1', true);
        if ($why !== null)
            throw new SnmpError($why);
        $this->tables[''][$oid] = $value;
    }
}
```

`app/Services/Snmp/PhpSnmpClient.php` после `walk()`:

```php
    public function get($oid)
    {
        $session = $this->session(null);
        try
        {
            $value = @$session->get($oid);
            $this->refused($session, $value);
            return $value;
        }
        finally
        {
            $session->close();
        }
    }

    public function set($oid, $type, $value)
    {
        $session = $this->session(null);
        try
        {
            $this->refused($session, @$session->set($oid, $type, (string) $value));
        }
        finally
        {
            $session->close();
        }
    }

    /* A get or a set that did not work as SnmpError: no answer, or the agent's own word (noAccess, notWritable...) */
    protected function refused(\SNMP $session, $result)
    {
        $errno = $session->getErrno();
        if ($result !== false && $errno == \SNMP::ERRNO_NOERROR)
            return;
        if ($errno == \SNMP::ERRNO_TIMEOUT)
            throw new SnmpError($session->getError() ?: 'No response from '.$this->config['host'], true);
        throw new SnmpError($session->getError() ?: 'SNMP error '.$errno);
    }
```

- [ ] **Step 4: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'FakeSnmpTest|PoeTest|PortsPageTest|SwitchSnapshotTest|SwitchSettingsTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/Snmp/SnmpClient.php app/Services/Snmp/PhpSnmpClient.php tests/Support/FakeSnmp.php tests/Unit/FakeSnmpTest.php
git commit -m "SNMP: get and set one object; the test switch keeps writes and refuses on demand" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: Объект для записи и PoeControl

**Files:**
- Modify: `app/Services/Switch/Poe.php`
- Create: `app/Services/Switch/PoeControl.php`
- Modify: `app/Services/SwitchPortFinder.php` (`config()`)
- Modify: `tests/Unit/PoeTest.php`
- Create: `tests/Feature/PoeControlTest.php`

**Interfaces:**
- Consumes: `SnmpClient::get()`, `set()`, `FakeSnmp::$sets`, `$refuse` (Task 1).
- Produces:
  - `Poe::OID_HW_ENABLE`, `Poe::OID_PETH_ADMIN`; `Poe::admin(array $ports): array` — `[ifIndex => oid]`, на вход `[ifIndex => name]`;
  - `PoeControl::ON = 1`, `OFF = 2`, `TRIES = 3`;
  - `PoeControl::writable(array $config): bool`, `PoeControl::forConfig(array $config): ?PoeControl`;
  - `switchOff(string $oid): void` (бросает `SnmpError`), `switchOnChecked(string $oid, int $on = 1): ?string` (null — включено, иначе причина);
  - `value(string $oid): int`, `check(array $admin): ?int` (ifIndex порта, на котором проверили);
  - `SwitchPortFinder::config()['write']` с ключами `community`, `user`, `auth_protocol`, `auth_password`, `priv_protocol`, `priv_password`.

- [ ] **Step 1: Тесты**

В `tests/Unit/PoeTest.php` перед `test_a_switch_without_poe_tables_has_no_poe`:

```php
    public function test_the_object_that_switches_poe_follows_the_same_mapping()
    {
        // Huawei: hwPoePortEnable by interface
        $huawei = (new Poe(new FakeSnmp(['' => [Poe::OID_HW_POWER_STATUS.'.8' => 'Powered', Poe::OID_HW_CONSUMING.'.9' => 0]])))
            ->admin([8 => 'GE0/0/3', 9 => 'GE0/0/4', 30 => 'XGE0/0/1']);
        $this->assertSame([8 => Poe::OID_HW_ENABLE.'.8', 9 => Poe::OID_HW_ENABLE.'.9'], $huawei);

        // Cisco IOS: the POWER-ETHERNET-MIB group.port of the interface, through ENTITY-MIB
        $cisco = (new Poe(new FakeSnmp(['' => [
            Poe::OID_PETH_DETECTION.'.1.3' => 3,
            Poe::OID_CPE_ENTPHY.'.1.3' => 1003,
            Poe::OID_ENT_ALIAS.'.1003.0' => '.1.3.6.1.2.1.2.2.1.1.10103',
        ]])))->admin([10103 => 'Gi1/0/3']);
        $this->assertSame([10103 => Poe::OID_PETH_ADMIN.'.1.3'], $cisco);

        // the rest: by port number
        $this->assertSame([5 => Poe::OID_PETH_ADMIN.'.1.5'],
            (new Poe(new FakeSnmp(['' => [Poe::OID_PETH_DETECTION.'.1.5' => 2]])))->admin([5 => 'gi5']));
        $this->assertSame([], (new Poe(new FakeSnmp(['' => []])))->admin([1 => 'ether1']));
    }
```

`tests/Feature/PoeControlTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** Writing a port's PoE with the switch's write credentials (Settings, "Write access") */
class PoeControlTest extends TestCase
{
    use RefreshDatabase;

    const OID = Poe::OID_HW_ENABLE.'.8';

    protected function settings(array $values)
    {
        foreach ($values as $key => $value)
            Setting::updateOrCreate(['key' => 'ethernetswitch_'.$key], ['value' => $value]);
        return SwitchPortFinder::config();
    }

    public function test_write_access_needs_a_write_community_or_a_write_user()
    {
        $this->assertFalse(PoeControl::writable($this->settings(['ip' => '192.0.2.10', 'snmp_community' => 'public'])));
        $this->assertTrue(PoeControl::writable($this->settings(['snmp_write_community' => 'private'])));
        $this->assertFalse(PoeControl::writable($this->settings(['snmp_version' => '3'])), 'v3 needs a write user');
        $this->assertTrue(PoeControl::writable($this->settings(['snmp_write_user' => 'cmprov-rw'])));
        $this->assertFalse(PoeControl::writable($this->settings(['ip' => ''])), 'no switch');
    }

    public function test_the_control_talks_with_the_write_credentials()
    {
        $config = $this->settings(['ip' => '192.0.2.10', 'snmp_community' => 'public', 'snmp_write_community' => 'private']);
        $seen = null;
        $this->app->bind(SnmpClient::class, function ($app, $params) use (&$seen) {
            $seen = $params['config'];
            return new FakeSnmp(['' => []]);
        });
        $this->assertNotNull(PoeControl::forConfig($config));
        $this->assertSame(['192.0.2.10', 'private'], [$seen['host'], $seen['community']]);
        $this->assertNull(PoeControl::forConfig($this->settings(['snmp_write_community' => ''])));
    }

    public function test_off_on_and_read_back()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1]]);
        $control = new PoeControl($snmp);
        $control->switchOff(self::OID);
        $this->assertSame(PoeControl::OFF, $control->value(self::OID));
        $this->assertNull($control->switchOnChecked(self::OID));
        $this->assertSame([[self::OID, 'i', PoeControl::OFF], [self::OID, 'i', PoeControl::ON]], $snmp->sets);
    }

    public function test_switching_on_tries_three_times_a_second_apart()
    {
        Sleep::fake();
        $snmp = new FakeSnmp(['' => [self::OID => 2]]);
        $snmp->refuse[self::OID.'=1'] = 'noAccess';
        $this->assertSame('noAccess', (new PoeControl($snmp))->switchOnChecked(self::OID));
        $this->assertCount(3, $snmp->sets);
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(1)->second()]);
    }

    public function test_check_writes_a_value_back_unchanged()
    {
        $snmp = new FakeSnmp(['' => [self::OID => 1, Poe::OID_HW_ENABLE.'.9' => 2]]);
        $this->assertSame(8, (new PoeControl($snmp))->check([8 => self::OID, 9 => Poe::OID_HW_ENABLE.'.9']));
        $this->assertSame([[self::OID, 'i', 1]], $snmp->sets);
        $this->assertNull((new PoeControl($snmp))->check([]));
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeTest|PoeControlTest'`
Expected: падения на `Poe::OID_HW_ENABLE` (undefined constant) и `Class "App\Services\Switch\PoeControl" not found`.

- [ ] **Step 3: Реализация**

В `app/Services/Switch/Poe.php` после `OID_ENT_ALIAS`:

```php
    const OID_HW_ENABLE = '.1.3.6.1.4.1.2011.5.25.195.3.1.3';         // hwPoePortEnable.<ifIndex>: enabled(1), disabled(2), read-write
    const OID_PETH_ADMIN = '.1.3.6.1.2.1.105.1.1.1.3';                 // pethPsePortAdminEnable.<group>.<port>: true(1), false(2), read-write
```

В `Poe.php` после `read()`:

```php
    /**
     * The object that switches each PoE port on (1) or off (2), found the way read() finds the ports: Huawei's
     * hwPoePortEnable by interface, otherwise the POWER-ETHERNET-MIB's pethPsePortAdminEnable by group.port.
     * Asked only for a restart or a write check (PoeControl), not for the Ports page's snapshot.
     * @param array $ports [ifIndex => name] of the physical ports
     * @return array [ifIndex => oid]; [] for a switch without PoE tables
     */
    public function admin(array $ports): array
    {
        $admin = [];
        foreach (array_keys($this->huawei($ports)) as $ifIndex)
            $admin[$ifIndex] = self::OID_HW_ENABLE.'.'.$ifIndex;
        if ($admin)
            return $admin;

        $detection = $this->table(self::OID_PETH_DETECTION);
        if (!$detection)
            return [];
        foreach ($this->cisco($detection) ?: $this->byNumber(array_keys($detection), $ports) as $key => $ifIndex)
            if (isset($ports[$ifIndex]))
                $admin[$ifIndex] = self::OID_PETH_ADMIN.'.'.$key;
        ksort($admin);
        return $admin;
    }
```

`app/Services/Switch/PoeControl.php`:

```php
<?php

namespace App\Services\Switch;

use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use Illuminate\Support\Sleep;

/**
 * Switches a port's PoE off and on with the switch's write credentials (Settings, "Write access (PoE restart)").
 * The object comes from Poe::admin(); in both MIBs 1 is on and 2 is off (HUAWEI-POE-MIB hwPoePortEnable,
 * POWER-ETHERNET-MIB pethPsePortAdminEnable). Both keep the value over a reboot of the switch.
 */
class PoeControl
{
    const ON = 1;
    const OFF = 2;
    const TRIES = 3;

    protected $snmp;

    public function __construct(SnmpClient $snmp)
    {
        $this->snmp = $snmp;
    }

    /* Write access is set for the switch: a write community (v2c) or a write user (v3) */
    public static function writable(array $config): bool
    {
        if (!$config['host'])
            return false;
        $write = $config['write'] ?? [];
        return $config['version'] === '3' ? ($write['user'] ?? '') !== '' : ($write['community'] ?? '') !== '';
    }

    /* A control talking with the write credentials of these settings (SwitchPortFinder::config()), null without them */
    public static function forConfig(array $config): ?self
    {
        if (!self::writable($config))
            return null;
        return new self(app(SnmpClient::class, ['config' => array_merge($config, $config['write'])]));
    }

    public function switchOff(string $oid): void
    {
        $this->snmp->set($oid, 'i', self::OFF);
    }

    /* Switch on: up to TRIES writes a second apart, each read back. Null when it is on, otherwise why not */
    public function switchOnChecked(string $oid, int $on = self::ON): ?string
    {
        $error = null;
        for ($try = 1; $try <= self::TRIES; $try++)
        {
            if ($try > 1)
                Sleep::for(1)->second();
            try
            {
                $this->snmp->set($oid, 'i', $on);
                $value = $this->value($oid);
                if ($value === $on)
                    return null;
                $error = 'the switch reads '.$value.' after writing '.$on;
            }
            catch (SnmpError $e)
            {
                $error = $e->getMessage();
            }
        }
        return $error;
    }

    public function value(string $oid): int
    {
        return (int) $this->snmp->get($oid);
    }

    /**
     * Write access without a change: the first PoE port's value is written back as it is.
     * @param array $admin Poe::admin() of the switch
     * @return int|null the ifIndex it used, null when the switch has no PoE ports; throws SnmpError
     */
    public function check(array $admin): ?int
    {
        foreach ($admin as $ifIndex => $oid)
        {
            $this->snmp->set($oid, 'i', $this->value($oid));
            return $ifIndex;
        }
        return null;
    }
}
```

В `app/Services/SwitchPortFinder.php`, `config()`, после `'detected' => $get('method_detected', null),`:

```php
            // PoE restart (PoeControl): its own community (v2c) or user (v3), kept like the read ones
            'write' => [
                'community' => $get('snmp_write_community'),
                'user' => $get('snmp_write_user'),
                'auth_protocol' => $get('snmp_write_auth_protocol'),
                'auth_password' => $get('snmp_write_auth_password'),
                'priv_protocol' => $get('snmp_write_priv_protocol'),
                'priv_password' => $get('snmp_write_priv_password'),
            ],
```

- [ ] **Step 4: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeTest|PoeControlTest|SwitchSettingsTest|PortsPageTest|SwitchPortFinderTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/Switch/Poe.php app/Services/Switch/PoeControl.php app/Services/SwitchPortFinder.php tests/Unit/PoeTest.php tests/Feature/PoeControlTest.php
git commit -m "PoE: the object that switches a port, and PoeControl with the switch's write credentials" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Права на запись в настройках коммутатора

**Files:**
- Create: `tests/Support/PoeStand.php`
- Modify: `app/Http/Livewire/SwitchSettings.php`
- Modify: `resources/views/livewire/switch-settings.blade.php`
- Create: `tests/Feature/SwitchWriteAccessTest.php`

**Interfaces:**
- Consumes: `PoeControl::writable()`, `forConfig()`, `check()`, `Poe::admin()`, `config()['write']` (Task 2).
- Produces:
  - свойства `SwitchSettings`: `writeCommunity`, `writeUser`, `writeAuthProtocol`, `writeAuthPassword`, `writePrivProtocol`, `writePrivPassword`;
  - действия `checkWrite()` и `forgetWrite()`; флаги `stored['write_community']`, `stored['write_auth_password']`, `stored['write_priv_password']`;
  - трейт `Tests\Support\PoeStand`: `mockNetwork()`, `stand(array $settings = ['snmp_write_community' => 'private']): Cm`, `secondModule(): Cm`, свойство `$fake`.

- [ ] **Step 1: Тестовый стенд и тесты**

`tests/Support/PoeStand.php`:

```php
<?php

namespace Tests\Support;

use App\Models\Cm;
use App\Models\Setting;
use App\Services\NetworkStatus;
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\SwitchSnapshot;
use Mockery\MockInterface;

/** A Huawei stand for PoE restart tests: PoE on GE0/0/1-3 (ifIndex 6-8), the provisioner behind XGE0/0/1 */
trait PoeStand
{
    protected $fake;

    /* The provisioner's MAC on the modules' network; the top bar's indicator reads nothing */
    protected function mockNetwork(): void
    {
        $this->mock(NetworkStatus::class, function (MockInterface $m) {
            $m->shouldReceive('serverMac')->andReturn('2c:cf:67:30:7d:a2');
            $m->shouldReceive('current')->andReturn(null);
        });
    }

    /* The switch with write access (unless $settings says otherwise) and a failed module on GE0/0/3 */
    protected function stand(array $settings = ['snmp_write_community' => 'private']): Cm
    {
        $this->mockNetwork();
        Setting::create(['key' => 'ethernetswitch_ip', 'value' => '192.0.2.10']);
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'public']);
        foreach ($settings as $key => $value)
            Setting::create(['key' => 'ethernetswitch_'.$key, 'value' => $value]);

        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X'];
        foreach ([6 => 'GE0/0/1', 7 => 'GE0/0/2', 8 => 'GE0/0/3', 30 => 'XGE0/0/1'] as $i => $name)
            $t += [
                SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => 1,
                SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => $i === 30 ? 10000 : 1000,
            ];
        $t += [
            MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.211.10.0' => 8,          // e4:5f:01:62:9f:d3, the module
            MacTable::OID_HUAWEI_FDB_PORT.'.44.207.103.48.125.162.1.0' => 30,        // 2c:cf:67:30:7d:a2, the provisioner
        ];
        foreach ([6, 7, 8] as $i)
            $t += [
                Poe::OID_HW_POWER_STATUS.'.'.$i => $i === 8 ? 'Powered' : 'Detecting',
                Poe::OID_HW_CONSUMING.'.'.$i => $i === 8 ? 6900 : 0,
                Poe::OID_HW_ENABLE.'.'.$i => 1,
            ];
        $this->fake = new FakeSnmp(['' => $t]);
        $fake = $this->fake;
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });

        return Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'GE0/0/3',
            'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }

    /* A second failed module, on GE0/0/2 */
    protected function secondModule(): Cm
    {
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.212.10.0'] = 7;   // e4:5f:01:62:9f:d4
        return Cm::create(['serial' => '10000000e611110d', 'mac' => 'e4:5f:01:62:9f:d4', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'GE0/0/2',
            'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }
}
```

`tests/Feature/SwitchWriteAccessTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\SwitchSettings;
use App\Services\Switch\Poe;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The switch settings' write access for PoE restarts: its own secrets, kept like the read ones, and a check */
class SwitchWriteAccessTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function saved()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')
            ->set('writeCommunity', 'rw-secret')->call('save')->assertHasNoErrors();
    }

    public function test_a_write_community_is_saved_and_never_shown()
    {
        $this->saved();
        $this->assertSame('rw-secret', SwitchPortFinder::config()['write']['community']);
        Livewire::test(SwitchSettings::class)
            ->assertDontSeeHtml('rw-secret')
            ->assertSeeHtml('leave empty to keep the stored write community');
    }

    public function test_a_kept_write_community_stays_and_another_switch_does_not_get_it()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->set('community', 'public')->call('save');
        $this->assertSame('rw-secret', SwitchPortFinder::config()['write']['community'], 'left empty: kept');
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.99')->set('community', 'public')->call('save');
        $this->assertSame('', SwitchPortFinder::config()['write']['community'], 'a new address never receives it');
    }

    public function test_a_v3_write_user_with_its_own_passwords()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->set('writeAuthPassword', 'rwauth123')
            ->set('writePrivProtocol', 'AES')->set('writePrivPassword', 'rwpriv123')->call('save')->assertHasNoErrors();
        $w = SwitchPortFinder::config()['write'];
        $this->assertSame(['cmprov-rw', 'SHA', 'rwauth123', 'AES', 'rwpriv123'],
            [$w['user'], $w['auth_protocol'], $w['auth_password'], $w['priv_protocol'], $w['priv_password']]);
    }

    public function test_a_write_user_needs_its_password()
    {
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('version', '3')->set('user', 'cmprov')
            ->set('writeUser', 'cmprov-rw')->set('writeAuthProtocol', 'SHA')->call('save')
            ->assertHasErrors(['writeAuthPassword']);
    }

    public function test_check_write_access_writes_a_port_back_as_it_is()
    {
        $this->stand([]);
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access works (GE0/0/1).');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.6', 'i', 1]], $this->fake->sets);
    }

    public function test_check_write_access_shows_the_switch_refusal()
    {
        $this->stand([]);
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.6'] = 'noAccess';
        Livewire::test(SwitchSettings::class)->set('host', '192.0.2.10')->set('community', 'public')->set('writeCommunity', 'rw')
            ->call('checkWrite')
            ->assertSee('Write access does not work: noAccess');
    }

    public function test_remove_write_access_keeps_the_read_one()
    {
        $this->saved();
        Livewire::test(SwitchSettings::class)->call('forgetWrite');
        $this->assertSame('', SwitchPortFinder::config()['write']['community']);
        $this->assertSame('public', SwitchPortFinder::config()['community']);
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'SwitchWriteAccessTest'`
Expected: падения: свойство `writeCommunity` не существует, метода `checkWrite` нет.

- [ ] **Step 3: Компонент**

`app/Http/Livewire/SwitchSettings.php`.

Импорты:

```php
use App\Services\Snmp\SnmpClient;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\SwitchSnapshot;
```

Свойства после `$method = 'auto';`:

```php
    // PoE restart: write credentials of their own; secrets never go to the browser either
    public $writeCommunity = '', $writeUser = '', $writeAuthProtocol = '', $writeAuthPassword = '', $writePrivProtocol = '', $writePrivPassword = '';
```

В `mount()` в конец:

```php
        $w = $c['write'];
        $this->writeUser = $w['user'];
        $this->writeAuthProtocol = $w['auth_protocol'];
        $this->writePrivProtocol = $w['priv_protocol'];
        $this->stored += ['write_community' => $w['community'] !== '', 'write_auth_password' => $w['auth_password'] !== '',
                          'write_priv_password' => $w['priv_password'] !== ''];
```

В `save()` в массив `$values` после `'method' => $this->method,`:

```php
            'snmp_write_community' => $config['write']['community'], 'snmp_write_user' => $config['write']['user'],
            'snmp_write_auth_protocol' => $config['write']['auth_protocol'], 'snmp_write_auth_password' => $config['write']['auth_password'],
            'snmp_write_priv_protocol' => $config['write']['priv_protocol'], 'snmp_write_priv_password' => $config['write']['priv_password'],
```

В `save()` строку `$this->community = $this->authPassword = $this->privPassword = '';` заменить на:

```php
        $this->community = $this->authPassword = $this->privPassword = '';
        $this->writeCommunity = $this->writeAuthPassword = $this->writePrivPassword = '';
```

В `rules()` перед `return $rules;`:

```php
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
```

В `formConfig()` в возвращаемый массив после `'detected' => null,`:

```php
            'write' => [
                'community' => $this->writeCommunity !== '' ? $this->writeCommunity : ($same ? $stored['write']['community'] : ''),
                'user' => trim($this->writeUser),
                'auth_protocol' => $this->writeAuthProtocol,
                'auth_password' => $this->writeAuthPassword !== '' ? $this->writeAuthPassword : ($same ? $stored['write']['auth_password'] : ''),
                'priv_protocol' => $this->writePrivProtocol,
                'priv_password' => $this->writePrivPassword !== '' ? $this->writePrivPassword : ($same ? $stored['write']['priv_password'] : ''),
            ],
```

Новые действия после `save()`:

```php
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
```

- [ ] **Step 4: Шаблон**

`resources/views/livewire/switch-settings.blade.php`. Перед `<button wire:click="test()"` вставить:

```blade
    <div class="mb-4 border-t border-pline pt-4">
        <div class="font-bold">Write access (PoE restart)</div>
        <p class="text-sm text-fg-muted mb-3">Lets cmprovision switch a module's PoE port off and on again. Empty: no PoE restarts.</p>
        @if ($version === '2c')
            <label class="{{ $label }}">Write community</label>
            <input type="password" wire:model="writeCommunity" class="{{ $input }}" autocomplete="new-password"
                   placeholder="{{ ($stored['write_community'] ?? false) ? 'leave empty to keep the stored write community' : 'empty: no PoE restarts' }}">
            @error('writeCommunity') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        @else
            <div class="mb-4">
                <label class="{{ $label }}">Write user</label>
                <input type="text" wire:model="writeUser" class="{{ $input }}" placeholder="empty: no PoE restarts">
                @error('writeUser') <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="flex mb-4">
                <div style="min-width: 9rem; margin-right: 1rem">
                    <label class="{{ $label }}">Authentication</label>
                    <select wire:model.live="writeAuthProtocol" class="{{ $input }}">
                        <option value="">none</option>
                        @foreach (\App\Services\Snmp\PhpSnmpClient::authProtocols() as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                    </select>
                </div>
                <div class="w-full">
                    <label class="{{ $label }}">Authentication password</label>
                    <input type="password" wire:model="writeAuthPassword" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ ($stored['write_auth_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                    @error('writeAuthPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="flex">
                <div style="min-width: 9rem; margin-right: 1rem">
                    <label class="{{ $label }}">Privacy</label>
                    <select wire:model.live="writePrivProtocol" class="{{ $input }}">
                        <option value="">none</option>
                        @foreach (\App\Services\Snmp\PhpSnmpClient::PRIV_PROTOCOLS as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                    </select>
                </div>
                <div class="w-full">
                    <label class="{{ $label }}">Privacy password</label>
                    <input type="password" wire:model="writePrivPassword" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ ($stored['write_priv_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                    @error('writePrivPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif
        @if (($stored['write_community'] ?? false) || $writeUser !== '')
            <button type="button" wire:click="forgetWrite" wire:confirm="Remove the write access? PoE restarts stop working."
                    class="cmp-btn cmp-btn-secondary cmp-btn-small mt-3">Remove write access</button>
        @endif
    </div>
```

Строку `<button wire:click="save()" class="cmp-btn ml-2">Save</button>` заменить на:

```blade
    <button wire:click="checkWrite()" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary ml-2">Check write access</button>
    <button wire:click="save()" class="cmp-btn ml-2">Save</button>
```

Строку `<span wire:loading wire:target="test" ...>asking the switch...</span>` заменить на:

```blade
    <span wire:loading wire:target="test,checkWrite" class="text-sm text-fg-muted ml-2">asking the switch...</span>
```

- [ ] **Step 5: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'SwitchWriteAccessTest|SwitchSettingsTest|TokenGuardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Коммит**

```bash
git add tests/Support/PoeStand.php tests/Feature/SwitchWriteAccessTest.php app/Http/Livewire/SwitchSettings.php resources/views/livewire/switch-settings.blade.php
git commit -m "Settings: write access for PoE restarts, kept like the read secrets, with a check that changes nothing" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 4: Какой порт можно перезапускать; общий снимок стенда

**Files:**
- Modify: `app/Support/StandMap.php`
- Create: `app/Services/Switch/Stand.php`
- Modify: `app/Http/Livewire/Ports.php`
- Modify: `tests/Feature/StandMapTest.php`

**Interfaces:**
- Produces:
  - у плитки `StandMap` новые поля `ifIndex` (int) и `others` (сколько MAC на порту не принадлежат модулям);
  - `StandMap::restartBlocker(array $tile): ?string` — null, если перезапускать можно, иначе причина: `"<port> has no link"`, `"no PoE on <port>"`, `"<port> is the uplink"`, `"other devices on <port>"`;
  - `StandMap::poeText(?array $poe): string`;
  - `Stand::LOOKUP_MACS = 48`;
  - `Stand::current(): ?array` — `['config', 'snapshot', 'error', 'map']`;
  - `Stand::tileOf(?array $map, Cm $cm): ?array`.

- [ ] **Step 1: Тесты**

В `tests/Feature/StandMapTest.php` добавить `use App\Services\Switch\Stand;` и тесты:

```php
    public function test_only_ports_with_nothing_but_our_modules_or_a_booting_pi_may_be_restarted()
    {
        $poe = ['state' => 'delivering', 'class' => null, 'watts' => 6.9];
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], $poe),                         // our module alone
            $this->port(2, 'GE0/0/2', true, ['e4:5f:01:00:00:02', '00:11:22:33:44:55'], $poe),    // a module and a camera
            $this->port(3, 'GE0/0/3', true, ['e4:5f:01:00:00:03']),                               // no PoE
            $this->port(4, 'GE0/0/4', true, ['00:11:22:33:44:66'], $poe),                         // someone else's device
            $this->port(5, 'GE0/0/5', false, ['e4:5f:01:00:00:05'], $poe),                        // no link
            $this->port(6, 'GE0/0/6', true, [self::SERVER], $poe),                                // the provisioner
            $this->port(7, 'GE0/0/7', true, ['d8:3a:dd:00:00:07'], $poe) + ['changed' => 20],     // a Pi that has not asked yet
        ], [$this->module('1000000000000001', 'e4:5f:01:00:00:01'), $this->module('1000000000000002', 'e4:5f:01:00:00:02'),
            $this->module('1000000000000003', 'e4:5f:01:00:00:03'), $this->module('1000000000000005', 'e4:5f:01:00:00:05')]);
        $why = function ($ifIndex) use ($map) { return StandMap::restartBlocker($map['tiles']['p'.$ifIndex]); };

        $this->assertNull($why(1));
        $this->assertSame('other devices on GE0/0/2', $why(2));
        $this->assertSame('no PoE on GE0/0/3', $why(3));
        $this->assertSame('other devices on GE0/0/4', $why(4));
        $this->assertSame('GE0/0/5 has no link', $why(5));
        $this->assertSame('GE0/0/6 is the uplink', $why(6));
        $this->assertNull($why(7));
        $this->assertSame([1, 2], [$map['tiles']['p2']['others'], $map['tiles']['p2']['ifIndex']]);
    }

    public function test_a_module_is_found_on_its_own_port_not_on_the_uplink()
    {
        $module = $this->module('1000000000000001', 'E4:5F:01:00:00:01');
        $map = $this->build([
            $this->port(1, 'GE0/0/1', true, ['e4:5f:01:00:00:01'], ['state' => 'delivering', 'class' => null, 'watts' => 6.9]),
            $this->port(30, 'XGE0/0/1', true, [self::SERVER, 'e4:5f:01:00:00:01']),
        ], [$module]);
        $this->assertSame('GE0/0/1', Stand::tileOf($map, $module)['name']);
        $this->assertNull(Stand::tileOf($map, $this->module('1000000000000009', 'e4:5f:01:00:00:09')));
        $this->assertNull(Stand::tileOf(null, $module));
    }

    public function test_poe_in_words()
    {
        $this->assertSame('delivering 6.9 W, class 4', StandMap::poeText(['state' => 'delivering', 'class' => 4, 'watts' => 6.9]));
        $this->assertSame('looking for a device', StandMap::poeText(['state' => 'searching', 'class' => null, 'watts' => null]));
        $this->assertSame('off', StandMap::poeText(['state' => 'disabled', 'class' => null, 'watts' => null]));
        $this->assertSame('none', StandMap::poeText(null));
    }
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'StandMapTest'`
Expected: `Call to undefined method App\Support\StandMap::restartBlocker()` и `Class "App\Services\Switch\Stand" not found`.

- [ ] **Step 3: StandMap**

В `app/Support/StandMap.php`, `tile()`, в возвращаемый массив после `'key' => 'p'.$port['ifIndex'],`:

```php
            'ifIndex' => $port['ifIndex'],
```

и после `'mac_count' => $port['mac_count'] ?? count($macs),`:

```php
            'others' => $others,   // addresses that are not modules (a PoE restart needs none)
```

Новые методы после `speedLabel()`:

```php
    /** Why this port's PoE may not be switched off and on (a PoE restart), null when it may: nothing on the port but
        our modules, or a Raspberry Pi that has not asked the server yet */
    public static function restartBlocker(array $tile): ?string
    {
        if ($tile['state'] === 'free')
            return $tile['name'].' has no link';
        if (!$tile['poe'])
            return 'no PoE on '.$tile['name'];
        if ($tile['state'] === 'uplink')
            return $tile['name'].' is the uplink';
        if (($tile['state'] === 'module' && $tile['others'] === 0) || ($tile['state'] === 'boot' && $tile['macs']))
            return null;
        return 'other devices on '.$tile['name'];
    }

    /** A port's PoE in words, as the module card shows it */
    public static function poeText(?array $poe): string
    {
        if (!$poe)
            return 'none';
        switch ($poe['state'])
        {
            case 'delivering':
                return 'delivering'.($poe['watts'] !== null ? ' '.number_format($poe['watts'], 1).' W' : '')
                    .($poe['class'] !== null ? ', class '.$poe['class'] : '');
            case 'searching':
                return 'looking for a device';
            case 'fault':
                return 'fault';
            case 'disabled':
                return 'off';
            default:
                return $poe['state'];
        }
    }
```

- [ ] **Step 4: Stand и Ports**

`app/Services/Switch/Stand.php`:

```php
<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Services\NetworkStatus;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Facades\DB;

/**
 * The stand as the Ports page shows it, for every page that needs it (the Ports page, the module card, a PoE
 * restart): the switch's snapshot (SnapshotCache, shared by all pages, at most FRESH_SECONDS old) and its map.
 */
class Stand
{
    /* Modules are looked for on ports with at most this many MAC addresses: a desktop switch full of
       modules, not the office network behind an uplink */
    const LOOKUP_MACS = 48;

    /** ['config' => SwitchPortFinder::config(), 'snapshot', 'error', 'map'] (snapshot and map are null until the
        switch has answered once), or null without a switch */
    public static function current(): ?array
    {
        $config = SwitchPortFinder::config();
        if (!$config['host'])
            return null;
        $state = (new SnapshotCache($config['host'], function () use ($config) {
            return SwitchSnapshot::forConfig($config)->take();
        }))->current();
        $map = $state['snapshot']
            ? (new StandMap($state['snapshot'], self::modulesOn($state['snapshot']), app(NetworkStatus::class)->serverMac()))->build()
            : null;
        return ['config' => $config, 'snapshot' => $state['snapshot'], 'error' => $state['error'], 'map' => $map];
    }

    /** The port a module is on now, by its MAC: never an uplink, and the port with the fewest addresses when the
        switch shows the MAC on several; null when the switch does not show it */
    public static function tileOf(?array $map, Cm $cm): ?array
    {
        if (!$map || !$cm->mac)
            return null;
        $mac = strtolower($cm->mac);
        $found = null;
        foreach ($map['tiles'] as $tile)
            if ($tile['state'] !== 'uplink' && in_array($mac, $tile['macs'], true)
                && (!$found || $tile['mac_count'] < $found['mac_count']))
                $found = $tile;
        return $found;
    }

    /* Modules behind ports with a link and few enough addresses to be devices, not uplinks */
    protected static function modulesOn(array $snapshot)
    {
        $macs = [];
        foreach ($snapshot['ports'] as $port)
            if ($port['up'] && ($port['mac_count'] ?? count($port['macs'])) <= self::LOOKUP_MACS)
                foreach ($port['macs'] as $mac)
                    $macs[strtolower($mac)] = true;
        $modules = [];
        foreach (array_chunk(array_keys($macs), 500) as $chunk)
            foreach (Cm::whereIn(DB::raw('lower(mac)'), $chunk)->get() as $cm)
                $modules[] = $cm;
        return $modules;
    }
}
```

В `app/Http/Livewire/Ports.php` убрать константу `LOOKUP_MACS`, метод `modulesOn()` и неиспользуемые импорты (`NetworkStatus`, `SnapshotCache`, `SwitchSnapshot`, `SwitchPortFinder`, `StandMap`, `DB`). Добавить `use App\Services\Switch\Stand;`. `render()` целиком:

```php
    public function render()
    {
        $stand = Stand::current();
        if (!$stand)
            return view('livewire.ports', ['configured' => false]);

        return view('livewire.ports', [
            'configured' => true,
            'host' => $stand['config']['host'],
            'snapshot' => $stand['snapshot'],
            'error' => $stand['error'],
            'map' => $stand['map'],
            'tile' => $stand['map']['tiles'][$this->selected] ?? null,
            'now' => Carbon::now()->getTimestamp(),
        ]);
    }
```

- [ ] **Step 5: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'StandMapTest|PortsPageTest|TokenGuardTest'`
Expected: `phpunit exit 0`. Страница Ports после переноса ведёт себя как раньше.

- [ ] **Step 6: Коммит**

```bash
git add app/Support/StandMap.php app/Services/Switch/Stand.php app/Http/Livewire/Ports.php tests/Feature/StandMapTest.php
git commit -m "Stand: the switch's snapshot and map for any page; which port a PoE restart may touch" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: Таблица poe_outages

**Files:**
- Create: `database/migrations/2026_10_12_100000_create_poe_outages_table.php`
- Create: `app/Models/PoeOutage.php`
- Create: `tests/Feature/PoeOutageTest.php`

**Interfaces:**
- Produces:
  - модель `PoeOutage` с полями `switch_host`, `if_index`, `port`, `oid`, `on_value`, `cm_serial`, `user`, `off_at`, `on_at`, `error`;
  - `PoeOutage::STUCK_SECONDS = 120`; scope `stayedOff()` — открытые строки с ошибкой или старше `STUCK_SECONDS`, по `off_at`;
  - `PoeOutage::closeSeenOn(?array $snapshot, string $host): void`.

- [ ] **Step 1: Тест**

`tests/Feature/PoeOutageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\PoeOutage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Ports whose PoE cmprovision switched off for a restart, and which of them stayed off */
class PoeOutageTest extends TestCase
{
    use RefreshDatabase;

    protected function outage(array $attributes = [])
    {
        return PoeOutage::create(array_merge(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'cm_serial' => '10000000e611110c',
            'user' => 'igor', 'off_at' => now()], $attributes));
    }

    protected function snapshot($takenAt, $state)
    {
        return ['taken_at' => $takenAt, 'ports' => [8 => ['poe' => ['state' => $state, 'class' => null, 'watts' => null]]]];
    }

    public function test_a_port_stayed_off_when_switching_on_failed_or_the_restart_stopped_midway()
    {
        $this->outage();                                                                         // a restart in its dark 5 s
        $failed = $this->outage(['error' => 'noAccess']);
        $stuck = $this->outage(['off_at' => now()->subMinutes(3)]);                              // php-fpm restarted midway
        $this->outage(['off_at' => now()->subMinutes(10), 'on_at' => now()->subMinutes(10)]);    // done long ago
        $this->assertSame([$stuck->id, $failed->id], PoeOutage::stayedOff()->pluck('id')->all());
    }

    public function test_a_port_the_switch_shows_with_poe_on_again_is_closed()
    {
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        $other = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute(), 'switch_host' => '192.0.2.99']);
        PoeOutage::closeSeenOn($this->snapshot(now()->getTimestamp(), 'delivering'), '192.0.2.10');
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertNull($other->fresh()->on_at, 'a row of another switch stays');
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'msg' => 'PoE on GE0/0/3 is on again (seen on the switch)']);
    }

    public function test_a_snapshot_from_before_the_restart_or_a_port_still_off_closes_nothing()
    {
        $outage = $this->outage(['error' => 'noAccess']);
        PoeOutage::closeSeenOn($this->snapshot(now()->subMinute()->getTimestamp(), 'delivering'), '192.0.2.10');
        PoeOutage::closeSeenOn($this->snapshot(now()->addSecond()->getTimestamp(), 'disabled'), '192.0.2.10');
        PoeOutage::closeSeenOn(null, '192.0.2.10');
        $this->assertNull($outage->fresh()->on_at);
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeOutageTest'`
Expected: `Class "App\Models\PoeOutage" not found`.

- [ ] **Step 3: Миграция и модель**

`database/migrations/2026_10_12_100000_create_poe_outages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PoE restarts (docs/superpowers/specs/2026-10-12-poe-restart-design.md): a row per port before cmprovision switches
   its PoE off, closed (on_at) once it is on again or the operator forgot it; an open row with an error, or older
   than a restart takes, is a port left off */
class CreatePoeOutagesTable extends Migration
{
    public function up()
    {
        Schema::create('poe_outages', function (Blueprint $table) {
            $table->id();
            $table->string('switch_host');
            $table->unsignedInteger('if_index');
            $table->string('port');
            $table->string('oid');                       // the object that switches the port; on_value turns it on
            $table->unsignedTinyInteger('on_value');
            $table->string('cm_serial')->nullable();
            $table->string('user')->nullable();
            $table->timestamp('off_at');
            $table->timestamp('on_at')->nullable();
            $table->text('error')->nullable();           // why it could not be switched on again
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('poe_outages');
    }
}
```

`app/Models/PoeOutage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A port whose PoE cmprovision switched off for a restart (App\Services\Switch\PoeRestart). on_at: on again, or
   forgotten by the operator */
class PoeOutage extends Model
{
    /* An open row without an error is a restart in its dark seconds for this long; after it, the restart stopped midway */
    const STUCK_SECONDS = 120;

    protected $fillable = ['switch_host', 'if_index', 'port', 'oid', 'on_value', 'cm_serial', 'user', 'off_at', 'on_at', 'error'];

    protected $casts = ['off_at' => 'datetime', 'on_at' => 'datetime', 'if_index' => 'integer', 'on_value' => 'integer'];

    /* Ports left off: switching on failed, or the restart stopped midway */
    public function scopeStayedOff($query)
    {
        return $query->whereNull('on_at')->where(function ($q) {
            $q->whereNotNull('error')->orWhere('off_at', '<', now()->subSeconds(self::STUCK_SECONDS));
        })->orderBy('off_at');
    }

    /* A port left off that the switch shows with PoE on again (switched on at the switch): its row closes. Only a
       snapshot taken after the port went off counts, and only for the switch it belongs to. */
    public static function closeSeenOn(?array $snapshot, string $host): void
    {
        if (!$snapshot)
            return;
        foreach (self::stayedOff()->where('switch_host', $host)->get() as $outage)
        {
            $poe = $snapshot['ports'][$outage->if_index]['poe'] ?? null;
            if ($poe && $poe['state'] !== 'disabled' && $snapshot['taken_at'] > $outage->off_at->getTimestamp())
            {
                $outage->update(['on_at' => now(), 'error' => null]);
                Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'info',
                    'msg' => 'PoE on '.$outage->port.' is on again (seen on the switch)']);
            }
        }
    }
}
```

- [ ] **Step 4: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeOutageTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

```bash
git add database/migrations/2026_10_12_100000_create_poe_outages_table.php app/Models/PoeOutage.php tests/Feature/PoeOutageTest.php
git commit -m "poe_outages: a row per port before its PoE goes off; ports left off and closed by the switch's word" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: PoeRestart

**Files:**
- Create: `app/Services/Switch/PoeRestart.php`
- Create: `tests/Feature/PoeRestartTest.php`

**Interfaces:**
- Consumes:
  - `PoeControl::forConfig()`, `switchOff()`, `switchOnChecked()`, `ON` (Task 2);
  - `Poe::admin()` (Task 2);
  - `Stand::current()`, `Stand::tileOf()`, `StandMap::restartBlocker()`, плитки с `ifIndex` (Task 4);
  - `PoeOutage` (Task 5); `Cm::isRunning()`, `Cm::silentFor()`; `Cmlog`.
- Produces:
  - `PoeRestart::OFF_SECONDS = 5`, `STAGGER_MS = 500`, `LOCK_SECONDS = 120`;
  - `PoeRestart::modules(iterable $cms, ?string $user): array` и `PoeRestart::ports(array $keys, ?string $user): array`. Оба возвращают `['restarted' => [port => serial|null], 'skipped' => [what => why], 'failed' => [port => why], 'stayed_off' => [port => why], 'busy' => bool, 'error' => ?string]`;
  - `PoeRestart::message(array $result): array` — `['text' => string, 'error' => bool]`;
  - `PoeRestart::confirm(?Cm $cm, ?string $port): string`, `PoeRestart::confirmFailed(int $count): string`.

- [ ] **Step 1: Тест**

`tests/Feature/PoeRestartTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\PoeOutage;
use App\Services\Switch\MacTable;
use App\Services\Switch\Poe;
use App\Services\Switch\PoeControl;
use App\Services\Switch\PoeRestart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** A PoE restart: off, five dark seconds, on again and read back; one at a time; never a port with other devices */
class PoeRestartTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    const OID8 = Poe::OID_HW_ENABLE.'.8';
    const OID7 = Poe::OID_HW_ENABLE.'.7';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    public function test_a_module_is_switched_off_and_back_on()
    {
        $cm = $this->stand();
        $result = PoeRestart::modules([$cm], 'igor');

        $this->assertSame(['GE0/0/3' => '10000000e611110c'], $result['restarted']);
        $this->assertSame([[self::OID8, 'i', PoeControl::OFF], [self::OID8, 'i', PoeControl::ON]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(PoeRestart::OFF_SECONDS)->seconds()]);
        $outage = PoeOutage::sole();
        $this->assertSame(['192.0.2.10', 8, 'GE0/0/3', self::OID8, 1, '10000000e611110c', 'igor'],
            [$outage->switch_host, $outage->if_index, $outage->port, $outage->oid, $outage->on_value, $outage->cm_serial, $outage->user]);
        $this->assertNotNull($outage->on_at);
        $this->assertDatabaseHas('cmlogs', ['cm' => '10000000e611110c', 'board' => 'GE0/0/3', 'loglevel' => 'info',
                                            'msg' => 'PoE restart of GE0/0/3 by igor: off for 5 s']);
        $this->assertSame(['text' => 'Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.', 'error' => false],
            PoeRestart::message($result));
    }

    public function test_without_write_access_nothing_is_written()
    {
        $cm = $this->stand([]);
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'No write access to the switch: set it under Settings → Ethernet switch.', 'error' => true],
            PoeRestart::message($result));
    }

    public function test_a_port_shared_with_another_device_is_left_alone()
    {
        $cm = $this->stand();
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.17.34.51.68.85.10.0'] = 8;   // 00:11:22:33:44:55 next to the module
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['10000000e611110c' => 'other devices on GE0/0/3'], $result['skipped']);
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'Not restarted: 10000000e611110c (other devices on GE0/0/3).', 'error' => true],
            PoeRestart::message($result));
    }

    public function test_a_refused_switch_off_changes_nothing()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=2'] = 'noAccess';
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['failed']);
        $this->assertSame(0, PoeOutage::count());
        Sleep::assertNeverSlept();
        $this->assertDatabaseHas('cmlogs', ['loglevel' => 'error',
            'msg' => 'PoE restart of GE0/0/3 by igor failed: the switch did not switch it off: noAccess']);
        $this->assertSame('The switch did not switch off GE0/0/3: noAccess.', PoeRestart::message($result)['text']);
    }

    public function test_a_port_that_does_not_come_back_on_stays_listed()
    {
        $cm = $this->stand();
        $this->fake->refuse[self::OID8.'=1'] = 'noAccess';
        $result = PoeRestart::modules([$cm], 'igor');

        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['stayed_off']);
        $this->assertCount(4, $this->fake->sets, 'off once, on three times');
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(1)->second(), Sleep::for(1)->second()]);
        $this->assertSame('noAccess', PoeOutage::sole()->error);
        $this->assertSame(1, PoeOutage::stayedOff()->count());
        $this->assertDatabaseHas('cmlogs', ['loglevel' => 'error', 'msg' => 'PoE on GE0/0/3 stays OFF after a restart by igor: noAccess']);
        $this->assertSame(['text' => 'PoE on GE0/0/3 stays OFF: noAccess. Use Power on.', 'error' => true], PoeRestart::message($result));
    }

    public function test_one_restart_at_a_time()
    {
        $cm = $this->stand();
        Cache::lock('poe-restart', PoeRestart::LOCK_SECONDS)->get();
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertTrue($result['busy']);
        $this->assertSame([], $this->fake->sets);
        $this->assertSame(['text' => 'A restart is already running.', 'error' => true], PoeRestart::message($result));
    }

    public function test_several_modules_go_off_together_and_come_back_one_by_one()
    {
        $cm = $this->stand();
        $second = $this->secondModule();
        $result = PoeRestart::modules([$cm, $second], 'igor');

        $this->assertSame(['GE0/0/3' => '10000000e611110c', 'GE0/0/2' => '10000000e611110d'], $result['restarted']);
        $this->assertSame([[self::OID8, 'i', 2], [self::OID7, 'i', 2], [self::OID8, 'i', 1], [self::OID7, 'i', 1]], $this->fake->sets);
        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(500)->milliseconds()]);
        $this->assertSame('Restarted 2 ports: GE0/0/3, GE0/0/2.', PoeRestart::message($result)['text']);
    }

    public function test_one_port_refusing_does_not_stop_the_others()
    {
        $cm = $this->stand();
        $second = $this->secondModule();
        $this->fake->refuse[self::OID8.'=2'] = 'noAccess';
        $result = PoeRestart::modules([$cm, $second], 'igor');

        $this->assertSame(['GE0/0/3' => 'noAccess'], $result['failed']);
        $this->assertSame(['GE0/0/2' => '10000000e611110d'], $result['restarted']);
        $this->assertSame(['text' => 'Restarted GE0/0/2 (10000000e611110d): PoE was off for 5 s. The switch did not switch off GE0/0/3: noAccess.',
                           'error' => true], PoeRestart::message($result));
    }

    public function test_a_moved_module_is_restarted_where_it_is_now()
    {
        $cm = $this->stand();
        $cm->update(['provisioning_board' => 'GE0/0/1']);   // where it was provisioned; the switch sees it on GE0/0/3 now
        $this->assertSame(['GE0/0/3' => '10000000e611110c'], PoeRestart::modules([$cm], 'igor')['restarted']);
    }

    public function test_a_module_the_switch_does_not_show_is_not_restarted()
    {
        $this->stand();
        $away = Cm::create(['serial' => '10000000e611ffff', 'mac' => 'e4:5f:01:00:ff:ff', 'phase' => 'failed']);
        $this->assertSame(['10000000e611ffff' => 'not seen on the switch'], PoeRestart::modules([$away], 'igor')['skipped']);
    }

    public function test_a_silent_switch_restarts_nothing()
    {
        $cm = $this->stand();
        $this->fake->timeout = true;
        $result = PoeRestart::modules([$cm], 'igor');
        $this->assertSame([], $this->fake->sets);
        $this->assertStringStartsWith('The switch does not answer', PoeRestart::message($result)['text']);
    }

    public function test_the_ports_page_restarts_by_port()
    {
        $this->stand();
        $this->assertSame(['GE0/0/3' => '10000000e611110c'], PoeRestart::ports(['p8'], 'igor')['restarted']);
        $this->assertSame(['GE0/0/1' => 'other devices on GE0/0/1'], PoeRestart::ports(['p6'], 'igor')['skipped'],
            'a link without any address: nothing tells it is ours');
    }

    public function test_what_the_operator_is_asked()
    {
        $cm = $this->stand();
        $this->assertSame('Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again.',
            PoeRestart::confirm($cm, 'GE0/0/3'));
        $cm->phase = 'done';
        $this->assertSame('Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots the system it was flashed with.',
            PoeRestart::confirm($cm, 'GE0/0/3'));
        $cm->forceFill(['phase' => 'write', 'provisioning_started_at' => now(), 'progress_updated_at' => now()]);
        $this->assertSame('Restart 10000000e611110c? PoE goes off for 5 s; its run stops and starts over.', PoeRestart::confirm($cm, null));
        $this->assertSame('Restart the Raspberry Pi on GE0/0/7? PoE goes off for 5 s.', PoeRestart::confirm(null, 'GE0/0/7'));
        $this->assertSame('Restart 3 modules (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(3));
        $this->assertSame('Restart 1 module (failed or without a report)? PoE goes off for 5 s on each port.', PoeRestart::confirmFailed(1));
    }
}
```

Порт 6 (GE0/0/1) в тестовом стенде поднят и без единого MAC. Его возраст линка неизвестен, поэтому плитка — `boot` без MAC, и перезапуск запрещён как «other devices». Это закрепляет правило спецификации «`boot` без MAC — не наш».

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeRestartTest'`
Expected: `Class "App\Services\Switch\PoeRestart" not found`.

- [ ] **Step 3: Реализация**

`app/Services/Switch/PoeRestart.php`:

```php
<?php

namespace App\Services\Switch;

use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\PoeOutage;
use App\Services\Snmp\SnmpClient;
use App\Services\Snmp\SnmpError;
use App\Services\SwitchPortFinder;
use App\Support\StandMap;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * PoE restart of modules (docs/superpowers/specs/2026-10-12-poe-restart-design.md). One at a time for the whole
 * stand, only on ports with nothing but our modules or a booting Pi (StandMap::restartBlocker()), checked on a
 * fresh snapshot. Each port gets a poe_outages row before its PoE goes off, then OFF_SECONDS of darkness, then the
 * ports are switched on one after the other (STAGGER_MS apart, three tries each, read back). What happened goes
 * to cmlogs and back to the operator.
 */
class PoeRestart
{
    const OFF_SECONDS = 5;
    const STAGGER_MS = 500;
    const LOCK_SECONDS = 120;

    protected $control, $host, $user;

    public function __construct(PoeControl $control, string $host, ?string $user)
    {
        $this->control = $control;
        $this->host = $host;
        $this->user = $user;
    }

    /** Restart the ports these modules are on now */
    public static function modules(iterable $cms, ?string $user): array
    {
        return self::start($user, function (array $map) use ($cms) {
            $tiles = $skipped = [];
            foreach ($cms as $cm)
            {
                $tile = Stand::tileOf($map, $cm);
                $why = $tile ? StandMap::restartBlocker($tile) : 'not seen on the switch';
                if ($why !== null)
                    $skipped[$cm->serial] = $why;
                else
                    $tiles[$tile['key']] = $tile + ['serial' => $cm->serial];
            }
            return [$tiles, $skipped];
        });
    }

    /** Restart these ports (StandMap tile keys such as "p8") */
    public static function ports(array $keys, ?string $user): array
    {
        return self::start($user, function (array $map) use ($keys) {
            $tiles = $skipped = [];
            foreach ($keys as $key)
            {
                $tile = $map['tiles'][$key] ?? null;
                $why = $tile ? StandMap::restartBlocker($tile) : 'no such port';
                if ($why !== null)
                    $skipped[$tile['name'] ?? $key] = $why;
                else
                    $tiles[$key] = $tile + ['serial' => $tile['modules'] ? $tile['modules'][0]->serial : null];
            }
            return [$tiles, $skipped];
        });
    }

    /** What the operator reads after a restart: ['text', 'error' => something did not work] */
    public static function message(array $r): array
    {
        if ($r['busy'])
            return ['text' => 'A restart is already running.', 'error' => true];
        if ($r['error'])
            return ['text' => $r['error'].'.', 'error' => true];
        $parts = [];
        if (count($r['restarted']) === 1)
        {
            $port = array_key_first($r['restarted']);
            $serial = $r['restarted'][$port];
            $parts[] = 'Restarted '.$port.($serial ? ' ('.$serial.')' : '').': PoE was off for '.self::OFF_SECONDS.' s.';
        }
        elseif ($r['restarted'])
            $parts[] = 'Restarted '.count($r['restarted']).' ports: '.implode(', ', array_keys($r['restarted'])).'.';
        foreach ($r['stayed_off'] as $port => $why)
            $parts[] = 'PoE on '.$port.' stays OFF: '.$why.'. Use Power on.';
        foreach ($r['failed'] as $port => $why)
            $parts[] = 'The switch did not switch off '.$port.': '.$why.'.';
        foreach ($r['skipped'] as $what => $why)
            $parts[] = 'Not restarted: '.$what.' ('.$why.').';
        return ['text' => $parts ? implode(' ', $parts) : 'Nothing to restart.',
                'error' => $r['stayed_off'] || $r['failed'] || !$r['restarted']];
    }

    /** The question before restarting one module, or the Raspberry Pi on a port when $cm is null */
    public static function confirm(?Cm $cm, ?string $port): string
    {
        $on = $port ? ' on '.$port : '';
        if (!$cm)
            return 'Restart the Raspberry Pi'.$on.'? PoE goes off for '.self::OFF_SECONDS.' s.';
        if ($cm->phase === 'done')
            $then = 'the module boots the system it was flashed with.';
        elseif ($cm->isRunning() && $cm->silentFor() === null)
            $then = 'its run stops and starts over.';
        else
            $then = 'the module boots from the network and is provisioned again.';
        return 'Restart '.$cm->serial.$on.'? PoE goes off for '.self::OFF_SECONDS.' s; '.$then;
    }

    public static function confirmFailed(int $count): string
    {
        return 'Restart '.$count.' '.($count === 1 ? 'module' : 'modules').' (failed or without a report)? PoE goes off for '
            .self::OFF_SECONDS.' s on each port.';
    }

    protected static function start(?string $user, callable $choose): array
    {
        $result = ['restarted' => [], 'skipped' => [], 'failed' => [], 'stayed_off' => [], 'busy' => false, 'error' => null];
        $config = SwitchPortFinder::config();
        $control = PoeControl::forConfig($config);
        if (!$control)
            return ['error' => 'No write access to the switch: set it under Settings → Ethernet switch'] + $result;

        $lock = Cache::lock('poe-restart', self::LOCK_SECONDS);
        if (!$lock->get())
            return ['busy' => true] + $result;
        try
        {
            @set_time_limit(self::LOCK_SECONDS);
            $stand = Stand::current();
            if (!$stand['map'] || $stand['error'])
                return ['error' => 'The switch does not answer'.($stand['error'] ? ': '.$stand['error']['message'] : '')] + $result;
            [$tiles, $result['skipped']] = $choose($stand['map']);
            if (!$tiles)
                return $result;
            $names = array_map(function ($port) { return $port['name']; }, $stand['snapshot']['ports']);
            $admin = (new Poe(app(SnmpClient::class, ['config' => $config])))->admin($names);
            return (new self($control, $config['host'], $user))->run($tiles, $admin, $result);
        }
        finally
        {
            $lock->release();
        }
    }

    protected function run(array $tiles, array $admin, array $result): array
    {
        $off = [];
        foreach ($tiles as $tile)
        {
            $oid = $admin[$tile['ifIndex']] ?? null;
            if ($oid === null)
            {
                $result['skipped'][$tile['name']] = 'no PoE object to write on '.$tile['name'];
                continue;
            }
            $outage = PoeOutage::create(['switch_host' => $this->host, 'if_index' => $tile['ifIndex'], 'port' => $tile['name'],
                'oid' => $oid, 'on_value' => PoeControl::ON, 'cm_serial' => $tile['serial'], 'user' => $this->user, 'off_at' => now()]);
            try
            {
                $this->control->switchOff($oid);
                $off[] = [$outage, $tile];
            }
            catch (SnmpError $e)
            {
                $outage->delete();
                $result['failed'][$tile['name']] = $e->getMessage();
                $this->log($tile, 'error', 'PoE restart of '.$tile['name'].' by '.$this->who().' failed: the switch did not switch it off: '.$e->getMessage());
            }
        }
        if (!$off)
            return $result;

        Sleep::for(self::OFF_SECONDS)->seconds();
        foreach ($off as $n => [$outage, $tile])
        {
            if ($n > 0)
                Sleep::for(self::STAGGER_MS)->milliseconds();
            $error = $this->control->switchOnChecked($outage->oid, $outage->on_value);
            if ($error === null)
            {
                $outage->update(['on_at' => now()]);
                $result['restarted'][$tile['name']] = $tile['serial'];
                $this->log($tile, 'info', 'PoE restart of '.$tile['name'].' by '.$this->who().': off for '.self::OFF_SECONDS.' s');
            }
            else
            {
                $outage->update(['error' => $error]);
                $result['stayed_off'][$tile['name']] = $error;
                $this->log($tile, 'error', 'PoE on '.$tile['name'].' stays OFF after a restart by '.$this->who().': '.$error);
            }
        }
        return $result;
    }

    protected function log(array $tile, string $level, string $message): void
    {
        Cmlog::create(['cm' => $tile['serial'], 'board' => $tile['name'], 'loglevel' => $level, 'msg' => $message]);
    }

    protected function who(): string
    {
        return $this->user ?: 'someone';
    }
}
```

- [ ] **Step 4: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeRestartTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

```bash
git add app/Services/Switch/PoeRestart.php tests/Feature/PoeRestartTest.php
git commit -m "PoeRestart: off, five dark seconds, on again and read back; one restart at a time" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Плашка «PoE stayed off»

**Files:**
- Create: `app/Http/Livewire/PoeOutages.php`
- Create: `resources/views/livewire/poe-outages.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Modify: `resources/views/livewire/ports.blade.php`
- Modify: `app/Http/Livewire/Ports.php`
- Create: `tests/Feature/PoeOutagesTest.php`

**Interfaces:**
- Consumes: `PoeOutage::stayedOff()`, `closeSeenOn()` (Task 5), `PoeControl::forConfig()`, `switchOnChecked()` (Task 2), `Stand::current()` (Task 4), трейт `PoeStand` (Task 3).
- Produces: компонент `livewire:poe-outages` с действиями `powerOn($id)` и `forget($id)`, свойства `$message` и `$messageIsError`.

- [ ] **Step 1: Тест**

`tests/Feature/PoeOutagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\PoeOutages;
use App\Http\Livewire\Ports;
use App\Models\PoeOutage;
use App\Models\User;
use App\Services\Switch\Poe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** A port a restart left off: shown on the dashboard and the Ports page with Power on and Forget until it is on */
class PoeOutagesTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    protected function outage(array $attributes = [])
    {
        return PoeOutage::create(array_merge(['switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'GE0/0/3',
            'oid' => Poe::OID_HW_ENABLE.'.8', 'on_value' => 1, 'cm_serial' => '10000000e611110c', 'user' => 'igor',
            'off_at' => now()], $attributes));
    }

    public function test_a_port_left_off_is_shown_on_the_dashboard_and_the_ports_page()
    {
        $this->mockNetwork();
        config(['app.display_timezone' => 'UTC']);
        $this->outage(['error' => 'noAccess', 'off_at' => Carbon::parse('2026-10-12 11:05:00')]);
        $user = User::factory()->create();
        foreach (['/dashboard', '/ports'] as $url)
        {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('PoE on GE0/0/3 stayed off after a restart at 11:05 (by igor): noAccess', $html, $url);
            $this->assertStringContainsString('Power on', $html, $url);
        }
    }

    public function test_a_restart_in_its_dark_seconds_is_not_a_port_left_off()
    {
        $this->outage();
        Livewire::test(PoeOutages::class)->assertDontSee('stayed off');
    }

    public function test_power_on_switches_the_port_on_and_closes_it()
    {
        Sleep::fake();
        $this->stand();
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 2;
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::actingAs(User::factory()->create(['name' => 'anna']))->test(PoeOutages::class)
            ->call('powerOn', $outage->id)
            ->assertSee('PoE on GE0/0/3 is on again.')
            ->assertDontSee('stayed off');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', 1]], $this->fake->sets);
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'msg' => 'PoE on GE0/0/3 switched on by anna']);
    }

    public function test_power_on_that_fails_keeps_the_port_listed_with_the_new_reason()
    {
        Sleep::fake();
        $this->stand();
        $this->fake->refuse[Poe::OID_HW_ENABLE.'.8'] = 'genError';
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::test(PoeOutages::class)->call('powerOn', $outage->id)->assertSee('PoE on GE0/0/3 is still off: genError');
        $this->assertSame('genError', $outage->fresh()->error);
        $this->assertNull($outage->fresh()->on_at);
    }

    public function test_power_on_does_not_write_to_another_switch()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess', 'switch_host' => '192.0.2.99']);
        Livewire::test(PoeOutages::class)->call('powerOn', $outage->id)->assertSee('The switch has changed: switch GE0/0/3 on at the switch.');
        $this->assertSame([], $this->fake->sets);
    }

    public function test_forget_closes_without_writing()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess']);
        Livewire::test(PoeOutages::class)
            ->assertSeeHtml('wire:confirm="The port may still be off on the switch. Forget it?"')
            ->call('forget', $outage->id);
        $this->assertNotNull($outage->fresh()->on_at);
        $this->assertSame([], $this->fake->sets);
        $this->assertDatabaseHas('cmlogs', ['board' => 'GE0/0/3', 'loglevel' => 'warning']);
    }

    public function test_the_ports_page_closes_a_port_the_switch_shows_with_poe_on()
    {
        $this->stand();
        $outage = $this->outage(['error' => 'noAccess', 'off_at' => now()->subMinute()]);
        Livewire::test(Ports::class);
        $this->assertNotNull($outage->fresh()->on_at);
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeOutagesTest'`
Expected: `Unable to find component: [poe-outages]` или `Class "App\Http\Livewire\PoeOutages" not found`.

- [ ] **Step 3: Компонент и шаблон**

`app/Http/Livewire/PoeOutages.php`:

```php
<?php

namespace App\Http\Livewire;

use App\Models\Cmlog;
use App\Models\PoeOutage;
use App\Services\Switch\PoeControl;
use App\Services\SwitchPortFinder;
use Livewire\Component;

/* Ports a PoE restart left off (PoeOutage::stayedOff()), on the dashboard and the Ports page until they are on again */
class PoeOutages extends Component
{
    public $message = null, $messageIsError = false;

    public function powerOn($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        $config = SwitchPortFinder::config();
        if ($config['host'] !== $outage->switch_host)
            return $this->say('The switch has changed: switch '.$outage->port.' on at the switch.', true);
        $control = PoeControl::forConfig($config);
        if (!$control)
            return $this->say('No write access to the switch: set it under Settings → Ethernet switch.', true);

        $error = $control->switchOnChecked($outage->oid, $outage->on_value);
        if ($error !== null)
        {
            $outage->update(['error' => $error]);
            return $this->say('PoE on '.$outage->port.' is still off: '.$error, true);
        }
        $outage->update(['on_at' => now(), 'error' => null]);
        Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'info',
            'msg' => 'PoE on '.$outage->port.' switched on by '.$this->who()]);
        $this->say('PoE on '.$outage->port.' is on again.');
    }

    public function forget($id)
    {
        $outage = PoeOutage::whereNull('on_at')->find($id);
        if (!$outage)
            return;
        $outage->update(['on_at' => now()]);
        Cmlog::create(['cm' => $outage->cm_serial, 'board' => $outage->port, 'loglevel' => 'warning',
            'msg' => 'PoE outage of '.$outage->port.' forgotten by '.$this->who().'; the port may still be off']);
        $this->say('Forgotten: check '.$outage->port.' at the switch.');
    }

    public function render()
    {
        return view('livewire.poe-outages', ['outages' => PoeOutage::stayedOff()->get()]);
    }

    protected function say($text, $error = false)
    {
        $this->message = $text;
        $this->messageIsError = $error;
    }

    protected function who()
    {
        return auth()->user()->name ?? 'someone';
    }
}
```

`resources/views/livewire/poe-outages.blade.php`:

```blade
{{-- display: contents, so an empty list takes no gap in the page's column --}}
<div wire:poll.10s class="contents">
    @if ($message)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $messageIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $message }}</div>
    @endif
    @foreach ($outages as $o)
        <div wire:key="poe-outage-{{ $o->id }}" class="bg-neg-bg border-l-4 border-neg-bar text-neg px-4 py-3 rounded flex flex-wrap items-center gap-3" role="alert">
            <span class="font-semibold">{{ 'PoE on '.$o->port.' stayed off after a restart at '.$o->off_at->local()->format('H:i').($o->user ? ' (by '.$o->user.')' : '').($o->error ? ': '.$o->error : '') }}</span>
            <button type="button" wire:click="powerOn({{ $o->id }})" wire:loading.attr="disabled" class="cmp-btn cmp-btn-small ml-auto">Power on</button>
            <button type="button" wire:click="forget({{ $o->id }})" wire:confirm="The port may still be off on the switch. Forget it?" class="cmp-btn cmp-btn-secondary cmp-btn-small">Forget</button>
        </div>
    @endforeach
</div>
```

`resources/views/dashboard.blade.php`: сразу после `<div class="cmp-page">`:

```blade
        <livewire:poe-outages />
```

`resources/views/livewire/ports.blade.php`: сразу после строки `<div class="cmp-page">`:

```blade
    <livewire:poe-outages />
```

`app/Http/Livewire/Ports.php`: добавить `use App\Models\PoeOutage;`. В `render()` после `if (!$stand) return ...;`:

```php
        // a port left off that the switch shows with PoE on again was switched on at the switch
        PoeOutage::closeSeenOn($stand['snapshot'], $stand['config']['host']);
```

- [ ] **Step 4: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeOutagesTest|PortsPageTest|ProvisioningStatusTest|TokenGuardTest|PageLayoutTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 5: Коммит**

```bash
git add app/Http/Livewire/PoeOutages.php resources/views/livewire/poe-outages.blade.php resources/views/dashboard.blade.php resources/views/livewire/ports.blade.php app/Http/Livewire/Ports.php tests/Feature/PoeOutagesTest.php
git commit -m "PoE stayed off: a banner on the dashboard and the Ports page with Power on and Forget" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 8: Кнопки на странице Ports

**Files:**
- Modify: `app/Http/Livewire/Ports.php`
- Modify: `resources/views/livewire/ports.blade.php`
- Modify: `tests/Feature/PortsPageTest.php`

**Interfaces:**
- Consumes: `PoeRestart::ports()`, `modules()`, `message()`, `confirm()`, `confirmFailed()` (Task 6); `PoeControl::writable()` (Task 2); `StandMap::restartBlocker()`, `moduleState()`, `Stand::current()` (Task 4).
- Produces:
  - действия `Ports::restart($key)` и `restartFailed()`;
  - свойства `$restartMessage` и `$restartError`;
  - данные шаблона `writable`, `failed`, `restart` (`['blocker', 'confirm']` для выбранного порта).

- [ ] **Step 1: Тесты**

В `tests/Feature/PortsPageTest.php` добавить `use Illuminate\Support\Sleep;`. Помощник и тесты:

```php
    protected function writeAccess()
    {
        Setting::create(['key' => 'ethernetswitch_snmp_write_community', 'value' => 'private']);
        $this->fake->tables[''][Poe::OID_HW_ENABLE.'.8'] = 1;
    }

    public function test_a_port_with_our_module_can_be_restarted_from_its_details()
    {
        Sleep::fake();
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 'p8')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots the system it was flashed with."')
            ->call('restart', 'p8')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
        $this->assertSame([[Poe::OID_HW_ENABLE.'.8', 'i', 2], [Poe::OID_HW_ENABLE.'.8', 'i', 1]], $this->fake->sets);
    }

    public function test_without_write_access_there_is_no_restart()
    {
        $this->switchWithModule();
        Livewire::test(Ports::class)->call('select', 'p8')
            ->assertDontSeeHtml('wire:click="restart(')
            ->assertDontSee('No PoE restart');
    }

    public function test_a_port_that_may_not_be_restarted_says_why()
    {
        $this->switchWithModule();
        $this->writeAccess();
        Livewire::test(Ports::class)->call('select', 'p30')->assertSee('No PoE restart: no PoE on XGE0/0/1');
    }

    public function test_failed_modules_on_the_stand_have_one_button()
    {
        Sleep::fake();
        $cm = $this->switchWithModule();
        $cm->update(['phase' => 'failed', 'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_complete_at' => null]);
        $this->writeAccess();
        Livewire::test(Ports::class)
            ->assertSee('Restart failed (1)')
            ->assertSeeHtml('wire:confirm="Restart 1 module (failed or without a report)? PoE goes off for 5 s on each port."')
            ->call('restartFailed')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PortsPageTest'`
Expected: падают 3 новых теста: нет кнопок, нет метода `restart`. Тест без прав на запись проходит.

- [ ] **Step 3: Компонент**

`app/Http/Livewire/Ports.php`: добавить `use App\Services\Switch\PoeControl;`, `use App\Services\Switch\PoeRestart;`, `use App\Support\StandMap;`.

Свойства после `$selected`:

```php
    public $restartMessage = null, $restartError = false;
```

Действия после `stopIdentify()`:

```php
    /* Switch the port's PoE off and on (PoeRestart); the port is checked again on a fresh snapshot */
    public function restart($key)
    {
        $this->report(PoeRestart::ports([(string) $key], auth()->user()->name ?? null));
    }

    /* Every failed or silent module on a port that may be restarted */
    public function restartFailed()
    {
        $stand = Stand::current();
        $cms = $stand && $stand['map'] ? self::failedOnStand($stand['map']) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }
```

В `render()` возвращаемый `view(...)` для подключённого коммутатора заменить на:

```php
        $tile = $stand['map']['tiles'][$this->selected] ?? null;
        $writable = PoeControl::writable($stand['config']);
        return view('livewire.ports', [
            'configured' => true,
            'host' => $stand['config']['host'],
            'snapshot' => $stand['snapshot'],
            'error' => $stand['error'],
            'map' => $stand['map'],
            'tile' => $tile,
            'now' => Carbon::now()->getTimestamp(),
            'writable' => $writable,
            'failed' => $writable && $stand['map'] ? count(self::failedOnStand($stand['map'])) : 0,
            'restart' => $tile ? ['blocker' => StandMap::restartBlocker($tile),
                                  'confirm' => PoeRestart::confirm($tile['modules'][0] ?? null, $tile['name'])] : null,
        ]);
```

Помощники в конец класса:

```php
    /* Failed and silent modules on ports that may be restarted */
    protected static function failedOnStand(array $map): array
    {
        $cms = [];
        foreach ($map['tiles'] as $tile)
            if (StandMap::restartBlocker($tile) === null)
                foreach ($tile['modules'] as $cm)
                    if (in_array(StandMap::moduleState($cm), ['fail', 'stale'], true))
                        $cms[] = $cm;
        return $cms;
    }

    protected function report(array $result): void
    {
        ['text' => $this->restartMessage, 'error' => $this->restartError] = PoeRestart::message($result);
    }
```

- [ ] **Step 4: Шаблон**

`resources/views/livewire/ports.blade.php`. В этом шаблоне только однострочные `@php(...)`.

1. Сразу после `<livewire:poe-outages />` (Task 7):

```blade
    @if ($restartMessage)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $restartError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $restartMessage }}</div>
    @endif
```

2. В сводке сразу после закрывающего `</div>` блока вердикта (перед `</section>`):

```blade
            @if ($writable && $failed)
                <button type="button" wire:click="restartFailed" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirmFailed($failed) }}" wire:loading.attr="disabled"
                        class="cmp-btn cmp-btn-danger cmp-btn-small justify-self-start md:col-span-2">
                    <span wire:loading.remove wire:target="restartFailed">Restart failed ({{ $failed }})</span>
                    <span wire:loading wire:target="restartFailed">Restarting…</span>
                </button>
            @endif
```

3. В панели порта сразу после блока, который кончается так:

```blade
                    @elseif ($tile['state'] === 'uplink')
                        <p class="mb-2">Uplink: {{ $tile['mac_count'] }} MAC {{ $tile['mac_count'] === 1 ? 'address' : 'addresses' }} behind it.</p>
                    @endif
```

вставить:

```blade
                    @if ($writable && $restart['blocker'] === null)
                        <div class="mb-3">
                            <button type="button" wire:click="restart('{{ $tile['key'] }}')" wire:confirm="{{ $restart['confirm'] }}" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small">
                                <span wire:loading.remove wire:target="restart">Restart</span>
                                <span wire:loading wire:target="restart">Restarting…</span>
                            </button>
                            <span class="text-xs text-fg-muted ml-2">PoE off for 5 s, then on</span>
                        </div>
                    @elseif ($writable)
                        <p class="mb-3 text-xs text-fg-muted">No PoE restart: {{ $restart['blocker'] }}</p>
                    @endif
```

- [ ] **Step 5: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PortsPageTest|PoeOutagesTest|TokenGuardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Livewire/Ports.php resources/views/livewire/ports.blade.php tests/Feature/PortsPageTest.php
git commit -m "Ports: restart a port from its details, and the failed modules of the stand at once" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: Строка «Power (PoE)» в карточке модуля

**Files:**
- Modify: `app/Http/Livewire/CmCard.php`
- Modify: `resources/views/livewire/cm-card.blade.php`
- Create: `tests/Feature/PoeRestartPagesTest.php`

**Interfaces:**
- Consumes: `Stand::current()`, `tileOf()`, `StandMap::restartBlocker()`, `poeText()` (Task 4); `PoeControl::writable()` (Task 2); `PoeRestart::modules()`, `message()`, `confirm()` (Task 6); `PoeStand` (Task 3).
- Produces: действие `CmCard::restart()`, свойства `$restartMessage` и `$restartError`, данные шаблона `power` (`['port', 'poe', 'blocker', 'confirm']` или null без коммутатора).

- [ ] **Step 1: Тест**

`tests/Feature/PoeRestartPagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\CmCard;
use App\Models\Cm;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** The Restart buttons of the module card and the dashboard */
class PoeRestartPagesTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_the_card_shows_the_port_and_restarts_the_module()
    {
        Sleep::fake();
        $cm = $this->stand();
        Livewire::test(CmCard::class, ['serial' => $cm->serial])
            ->assertSee('Power (PoE)')
            ->assertSee('GE0/0/3 · delivering 6.9 W')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again."')
            ->call('restart')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }

    public function test_the_card_says_why_there_is_no_restart()
    {
        $cm = $this->stand([]);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertSee('no restart: no write access (Settings → Ethernet switch)');
        Setting::create(['key' => 'ethernetswitch_snmp_write_community', 'value' => 'private']);
        $away = Cm::create(['serial' => '10000000e611ffff', 'mac' => 'e4:5f:01:00:ff:ff', 'phase' => 'failed']);
        Livewire::test(CmCard::class, ['serial' => $away->serial])->assertSee('no restart: not seen on the switch');
    }

    public function test_a_card_without_a_switch_has_no_power_row()
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'phase' => 'done']);
        Livewire::test(CmCard::class, ['serial' => $cm->serial])->assertDontSee('Power (PoE)');
    }
}
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeRestartPagesTest'`
Expected: падают первые два теста: нет «Power (PoE)», нет метода `restart`.

- [ ] **Step 3: Компонент**

`app/Http/Livewire/CmCard.php`: добавить импорты `App\Services\Switch\PoeControl`, `App\Services\Switch\PoeRestart`, `App\Services\Switch\Stand`, `App\Support\StandMap`.

Свойство после `public $serial;`:

```php
    public $restartMessage = null, $restartError = false;
```

Действие после `stopIdentify()`:

```php
    /* Switch the PoE of the module's port off and on (PoeRestart) */
    public function restart()
    {
        $cm = Cm::where('serial', $this->serial)->firstOrFail();
        ['text' => $this->restartMessage, 'error' => $this->restartError] =
            PoeRestart::message(PoeRestart::modules([$cm], auth()->user()->name ?? null));
    }
```

В `render()` в массив данных после `'zone' => ...`:

```php
            'power' => $this->power($cm),
```

Помощник в конец класса:

```php
    /* The module's port and its PoE on the switch's snapshot, and why it may not be restarted; null without a switch */
    protected function power(Cm $cm): ?array
    {
        $stand = Stand::current();
        if (!$stand)
            return null;
        $tile = Stand::tileOf($stand['map'], $cm);
        if (!PoeControl::writable($stand['config']))
            $blocker = 'no write access (Settings → Ethernet switch)';
        elseif (!$stand['map'] || $stand['error'])
            $blocker = 'the switch does not answer';
        elseif (!$tile)
            $blocker = 'not seen on the switch';
        else
            $blocker = StandMap::restartBlocker($tile);
        return ['port' => $tile['name'] ?? null, 'poe' => $tile['poe'] ?? null, 'blocker' => $blocker,
                'confirm' => PoeRestart::confirm($cm, $tile['name'] ?? null)];
    }
```

- [ ] **Step 4: Шаблон**

`resources/views/livewire/cm-card.blade.php`.

1. Сразу после `<div class="contents">`:

```blade
        @if ($restartMessage)
            <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $restartError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $restartMessage }}</div>
        @endif
```

2. Перед строкой `<tr><td class="text-fg-muted">MAC</td><td class="font-mono">{{ $cm->mac }}</td></tr>`:

```blade
                @if ($power)
                <tr>
                    <td class="text-fg-muted">Power (PoE)</td>
                    <td>
                        @if ($power['port']){{ $power['port'] }} · {{ \App\Support\StandMap::poeText($power['poe']) }}@endif
                        @if ($power['blocker'] === null)
                            <button wire:click="restart" wire:confirm="{{ $power['confirm'] }}" wire:loading.attr="disabled" class="ml-2 cmp-btn cmp-btn-secondary cmp-btn-small">
                                <span wire:loading.remove wire:target="restart">Restart</span>
                                <span wire:loading wire:target="restart">Restarting…</span>
                            </button>
                        @else
                            <span class="ml-2 text-fg-muted">no restart: {{ $power['blocker'] }}</span>
                        @endif
                    </td>
                </tr>
                @endif
```

- [ ] **Step 5: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeRestartPagesTest|ModuleCardTest|TokenGuardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Livewire/CmCard.php resources/views/livewire/cm-card.blade.php tests/Feature/PoeRestartPagesTest.php
git commit -m "Module card: its port and PoE, a Restart button or why not" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Кнопки на дашборде

**Files:**
- Modify: `app/Http/Livewire/ProvisioningStatus.php`
- Modify: `resources/views/livewire/provisioning-status.blade.php`
- Modify: `tests/Feature/PoeRestartPagesTest.php`

**Interfaces:**
- Consumes: `PoeRestart::modules()`, `message()`, `confirm()`, `confirmFailed()` (Task 6); `PoeControl::writable()` (Task 2); `BatchSummary::current()`, `$summary->silent`.
- Produces:
  - действия `ProvisioningStatus::restart($id)` и `restartFailed()`;
  - свойства `$restartMessage` и `$restartError`;
  - данные шаблона `writable` и `failedToRestart` (упавшие и замолчавшие модули открытой партии, 0 без прав на запись).

- [ ] **Step 1: Тесты**

В `tests/Feature/PoeRestartPagesTest.php` добавить `use App\Http\Livewire\ProvisioningStatus;`, `use App\Models\Image;`, `use App\Models\NotificationBatch;`, `use App\Models\Project;`. Помощник и тесты:

```php
    /* An active project and its open batch with the module */
    protected function batchWith(Cm $cm)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subMinutes(30), 'last_event_at' => now()]);
        $cm->update(['notification_batch_id' => $batch->id, 'project_id' => $project->id]);
        return $batch;
    }

    public function test_the_dashboard_restarts_the_failed_modules_of_the_batch()
    {
        Sleep::fake();
        $cm = $this->stand();
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Restart failed (1)')
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/3? PoE goes off for 5 s; the module boots from the network and is provisioned again."')
            ->call('restartFailed')
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }

    public function test_without_write_access_the_dashboard_has_no_restart()
    {
        $cm = $this->stand([]);
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertDontSee('Restart failed')
            ->assertDontSeeHtml('wire:click="restart(');
    }

    public function test_a_row_restart_finds_the_module_where_it_is_now()
    {
        Sleep::fake();
        $cm = $this->stand();
        $cm->update(['provisioning_board' => 'GE0/0/1']);
        $this->batchWith($cm);
        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('wire:confirm="Restart 10000000e611110c on GE0/0/1? PoE goes off for 5 s;')
            ->call('restart', $cm->id)
            ->assertSee('Restarted GE0/0/3 (10000000e611110c): PoE was off for 5 s.');
    }
```

- [ ] **Step 2: Запустить, увидеть падение**

Run: `sync_dev >/dev/null && tests 'PoeRestartPagesTest'`
Expected: падают два новых теста с кнопками. Тест без прав на запись проходит.

- [ ] **Step 3: Компонент**

`app/Http/Livewire/ProvisioningStatus.php`: импорты `App\Services\Switch\PoeControl`, `App\Services\Switch\PoeRestart`, `App\Services\SwitchPortFinder`.

Свойство в начале класса после констант:

```php
    public $restartMessage = null, $restartError = false;
```

Действия после `newBatch()`:

```php
    /* Switch the PoE of a module's port off and on (PoeRestart); its port is looked up on a fresh snapshot */
    public function restart($id)
    {
        $cm = Cm::find($id);
        if ($cm)
            $this->report(PoeRestart::modules([$cm], auth()->user()->name ?? null));
    }

    /* The failed and silent modules of the open batch */
    public function restartFailed()
    {
        $summary = BatchSummary::current();
        $cms = $summary ? $summary->modules->filter(function ($cm) { return $cm->phase === 'failed' || $cm->silentFor() !== null; }) : [];
        $this->report(PoeRestart::modules($cms, auth()->user()->name ?? null));
    }

    protected function report(array $result): void
    {
        ['text' => $this->restartMessage, 'error' => $this->restartError] = PoeRestart::message($result);
    }
```

В `render()` перед `return view('livewire.provisioning-status', [`:

```php
        $writable = PoeControl::writable(SwitchPortFinder::config());
```

и в массив данных шаблона после `'activeCount' => ...`:

```php
            'writable' => $writable,
            'failedToRestart' => $writable && $summary && $summary->open ? $summary->counts['failed'] + $summary->silent : 0,
```

- [ ] **Step 4: Шаблон**

`resources/views/livewire/provisioning-status.blade.php`.

1. Сразу после открывающего корневого `<div ... class="flex flex-col gap-[var(--gap)]">`:

```blade
    @if ($restartMessage)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $restartError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $restartMessage }}</div>
    @endif
```

2. Блок кнопки New batch:

```blade
                @if ($summary->open)
                    <button type="button" wire:click="newBatch" wire:confirm="Close this batch? The next module starts a new one, here and in the bots." class="cmp-btn cmp-btn-secondary ml-auto">New batch</button>
                @endif
```

заменить на:

```blade
                @if ($summary->open)
                    <div class="ml-auto flex flex-wrap gap-2">
                        @if ($failedToRestart)
                            <button type="button" wire:click="restartFailed" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirmFailed($failedToRestart) }}" wire:loading.attr="disabled" class="cmp-btn cmp-btn-danger">
                                <span wire:loading.remove wire:target="restartFailed">Restart failed ({{ $failedToRestart }})</span>
                                <span wire:loading wire:target="restartFailed">Restarting…</span>
                            </button>
                        @endif
                        <button type="button" wire:click="newBatch" wire:confirm="Close this batch? The next module starts a new one, here and in the bots." class="cmp-btn cmp-btn-secondary">New batch</button>
                    </div>
                @endif
```

3. В последней ячейке строки (`<td class="text-right whitespace-nowrap">`) перед `@if ($m->canIdentify())`:

```blade
                            @if ($writable && ($m->phase === 'failed' || $silent))
                                <button type="button" wire:click="restart({{ $m->id }})" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirm($m, $m->provisioning_board) }}" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small mr-1">Restart</button>
                            @endif
```

- [ ] **Step 5: Запустить, увидеть зелёное**

Run: `sync_dev >/dev/null && tests 'PoeRestartPagesTest|ProvisioningStatusTest|TokenGuardTest'`
Expected: `phpunit exit 0`.

- [ ] **Step 6: Коммит**

```bash
git add app/Http/Livewire/ProvisioningStatus.php resources/views/livewire/provisioning-status.blade.php tests/Feature/PoeRestartPagesTest.php
git commit -m "Dashboard: Restart failed (N) for the batch and Restart on a failed or silent row" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: Запись на snmpsim

**Files:**
- Modify: `tests/fixtures/snmp/s5731.snmprec`, `c9200.snmprec`, `cbs350.snmprec`
- Modify: `tests/Feature/SnmpAgentTest.php`

**Interfaces:**
- Consumes: `PhpSnmpClient::get()`, `set()` (Task 1); `Poe::admin()`, `PoeControl` (Task 2).

- [ ] **Step 1: Убедиться, что writecache snmpsim принимает запись**

Скрипт `scratchpad/compact/poe_writable.py` (писать через Write, запускать `python -I`):

```python
"""Writable PoE objects for snmpsim (writecache variation module): hwPoePortEnable in s5731, pethPsePortAdminEnable
in c9200 and cbs350, one per port the PoE status rows name, initial value 1 (on); the files stay in OID order."""
from pathlib import Path

FIX = Path('tests/fixtures/snmp')
JOBS = [('s5731', '1.3.6.1.4.1.2011.5.25.195.3.1.7', '1.3.6.1.4.1.2011.5.25.195.3.1.3'),
        ('c9200', '1.3.6.1.2.1.105.1.1.1.6', '1.3.6.1.2.1.105.1.1.1.3'),
        ('cbs350', '1.3.6.1.2.1.105.1.1.1.6', '1.3.6.1.2.1.105.1.1.1.3')]


def oid(line):
    return [int(part) for part in line.split('|', 1)[0].split('.')]


for name, status, admin in JOBS:
    path = FIX / f'{name}.snmprec'
    lines = [l for l in path.read_text(encoding='utf-8').splitlines() if l]
    rows = [admin + l.split('|', 1)[0][len(status):] + '|2:writecache|value=1' for l in lines if l.startswith(status + '.')]
    assert rows, name
    assert not any(l.startswith(admin + '.') for l in lines), f'{name} already has the admin rows'
    merged = sorted(lines + rows, key=oid)
    path.write_text('\n'.join(merged) + '\n', encoding='utf-8', newline='\n')
    print(name, 'added', len(rows))
```

Run: `python -I scratchpad/compact/poe_writable.py` из корня репозитория (путь к скрипту — полный путь к рабочей папке).
Expected: `s5731 added N`, `c9200 added M`, `cbs350 added K`, все больше 0.

Затем на стенде:

```bash
sync_dev >/dev/null
printf '%s\n' 'sh /root/bench/07-snmpsim-run.sh >/root/snmpsim-start.log 2>&1; snmpset -v2c -c s5731 127.0.0.1:1161 1.3.6.1.4.1.2011.5.25.195.3.1.3.3 i 2; snmpget -v2c -c s5731 -On 127.0.0.1:1161 1.3.6.1.4.1.2011.5.25.195.3.1.3.3' | w_raw sh -s
```

Expected: обе команды печатают `INTEGER: 2`. Если `snmpset` отвечает `notWritable` или ошибкой, writecache не подошёл. Тогда шаги 2–4 не выполняются: Ruling в журнал, правки данных откатываются (`git checkout tests/fixtures/snmp`), в ROADMAP (Task 12) пишется, что запись проверена только на `FakeSnmp`.

- [ ] **Step 2: Тест**

В `tests/Feature/SnmpAgentTest.php` добавить `use App\Services\Switch\Poe;` и `use App\Services\Switch\PoeControl;`, затем тест:

```php
    public function test_poe_goes_off_and_on_through_php_snmp_on_each_vendor()
    {
        foreach (['s5731', 'c9200', 'cbs350'] as $community)
        {
            $snapshot = $this->snapshot($community);
            $client = new PhpSnmpClient(['host' => getenv('SNMPSIM_ENDPOINT'), 'community' => $community]);
            $admin = (new Poe($client))->admin(array_map(function ($port) { return $port['name']; }, $snapshot['ports']));
            $this->assertNotEmpty($admin, $community.': the PoE ports have their objects');
            $oid = reset($admin);
            $control = new PoeControl($client);
            $control->switchOff($oid);
            $this->assertSame(PoeControl::OFF, $control->value($oid), $community);
            $this->assertNull($control->switchOnChecked($oid), $community);
            $this->assertSame(PoeControl::ON, $control->value($oid), $community);
        }
    }
```

- [ ] **Step 3: Запустить**

Run: `bash "$DEV/snmpagent-ok.sh"`
Expected: `OK (...)` без пропусков, код возврата 0. Без шага 1 тест падает на `notWritable`.

- [ ] **Step 4: Коммит**

```bash
git add tests/fixtures/snmp/s5731.snmprec tests/fixtures/snmp/c9200.snmprec tests/fixtures/snmp/cbs350.snmprec tests/Feature/SnmpAgentTest.php
git commit -m "snmpsim: writable PoE objects; PoE off and on through the real php-snmp on each vendor" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 12: Документы, версия, проверка на стенде

**Files:**
- Modify: `CLAUDE.md`, `README.md`, `ROADMAP.md`, `debian/changelog`

- [ ] **Step 1: CLAUDE.md**

В разделе «Данные и хранилище», в пункте о `settings`, строку `метод и последний сработавший метод — \`SwitchPortFinder::config()\`)` дополнить. Итог:

```markdown
метод и последний сработавший метод — `SwitchPortFinder::config()`; права на запись для перезапуска по PoE — `ethernetswitch_snmp_write_*`, в `config()['write']`)
```

После пункта о странице Ports (он кончается словами «на настоящих коммутаторах — пункт 1 ROADMAP.») добавить пункт:

```markdown
- Перезапуск по PoE (спека `docs/superpowers/specs/2026-10-12-poe-restart-design.md`). Запись идёт через `SnmpClient::set()` отдельной учёткой: Write community для v2c или свой пользователь v3 (`PoeControl::forConfig()`). Без неё кнопок нет. Объект для записи даёт `Poe::admin()` с тем же сопоставлением портов, что у чтения: `hwPoePortEnable.<ifIndex>` у Huawei, `pethPsePortAdminEnable.<группа>.<порт>` у остальных; 1 — включено, 2 — выключено. Оба значения сохраняются при перезагрузке коммутатора.
  - Какой порт можно перезапускать, решает `StandMap::restartBlocker()` по свежему снимку (`App\Services\Switch\Stand`, тот же `SnapshotCache`). На порту должны быть только наши модули (поле плитки `others` равно 0) или загружающаяся Raspberry Pi. Модуль ищется по MAC (`Stand::tileOf()`), а не по `provisioning_board`.
  - `PoeRestart` работает под замком `poe-restart` на весь стенд. До выключения пишет строку в `poe_outages`, затем выключает, ждёт 5 секунд и включает порты по одному с шагом 0,5 с. На включение три попытки с чтением обратно.
  - Открытая строка с ошибкой или старше 2 минут — порт остался выключенным (`PoeOutage::stayedOff()`). Его показывает плашка `PoeOutages` на дашборде и странице Ports с кнопками Power on и Forget. Страница Ports закрывает строку, если снимок, снятый после выключения, показывает PoE включённым.
  - Кнопки: карточка модуля (строка «Power (PoE)»), панель порта и сводка Ports, дашборд («Restart failed (N)» и Restart в строке упавшего или замолчавшего модуля). Строки журнала `cmlogs` пишутся с именем оператора.
```

В разделе «Тесты» после `итог партии (\`BatchSummary\`, \`BatchIndicator\`),` вставить:

```markdown
перезапуск по PoE (`PoeRestartTest`, `PoeOutagesTest`, `PoeRestartPagesTest`, `SwitchWriteAccessTest`; `FakeSnmp` умеет `get`, `set` и отказы `refuse`, стенд для них — трейт `Tests\Support\PoeStand`),
```

- [ ] **Step 2: README**

Перед строкой `## Upgrading ##` вставить:

```markdown
## Switch: port lookup, the Ports page and PoE restart ##

Settings → Ethernet switch takes the switch's address and SNMP read access (a community for v2c, a user for v3). With it a module gets its switch port as its board, and the Ports page shows the stand port by port.

Write access is optional and only used to restart modules over PoE: a write community for v2c, or a user of its own for v3. **Check write access** writes one PoE port's current value back, so it changes nothing. With write access, the module card, the Ports page and the dashboard get a **Restart** button: the switch turns the port's PoE off for 5 seconds and on again. It is offered only on ports where nothing but the provisioner's modules is. A port that does not come back on is listed on the dashboard and the Ports page with **Power on** until it does.

```

- [ ] **Step 3: ROADMAP**

В «Этап 2: что осталось», пункт «1. Проверка новинок на железе», после пункта про страницу Ports:

```markdown
- Перезапуск по PoE на тех же коммутаторах: «Check write access», перезапуск одного порта, что коммутатор пишет в свой журнал. Держит ли Cisco IOS запись `pethPsePortAdminEnable`; если нет, запасной путь — `cpeExtPsePortEnable` из CISCO-POWER-ETHERNET-EXT-MIB.
```

- [ ] **Step 4: changelog 2.3.0~dev1**

Дату взять командой `python -c "import email.utils; print(email.utils.formatdate(localtime=True))"`. В начало `debian/changelog`:

```
cmprovision4 (2.3.0~dev1) unstable; urgency=medium

  * PoE restart: with write access to the switch (a write community, or a
    v3 user of its own, under Settings → Ethernet switch), the module card,
    the Ports page and the dashboard restart a module: its port's PoE goes
    off for 5 s and on again. Only ports with nothing but our modules on
    them; one restart at a time; "Restart failed (N)" for the failed and
    silent modules of the batch.
  * A port that does not come back on is listed on the dashboard and the
    Ports page with Power on until it is on again.
  * Check write access in the switch settings writes a port's value back
    unchanged.

 -- nimbo78 <igor.sin.78@gmail.com>  <дата из команды выше>

```

- [ ] **Step 5: Весь набор**

Run: `bash "$DEV/suite-ok.sh"`
Expected: `php rc=0 js rc=0`.

- [ ] **Step 6: Проверка на стенде (Playwright, snmpsim)**

1. Стенд держится фоновым `sleep`. Запустить snmpsim: `printf '%s\n' 'sh /root/bench/07-snmpsim-run.sh' | w_raw sh -s`.
2. Выложить изменённые файлы в `/var/lib/cmprovision` (`31-put.sh`, как в 2.2.1), затем `php artisan migrate --force` от www-data и перезапуск php-fpm.
3. Скрипт стенда через Write и `run-mk2.sh`:
   - каналы уведомлений выключены;
   - коммутатор `127.0.0.1:1161`, community `s5731`, write community `s5731`;
   - модуль с MAC с одного из портов s5731, упавший, в открытой партии;
   - одноразовая учётка со свежим паролем.
4. В Playwright проверить:
   - Settings → Check write access;
   - Ports → порт модуля → Restart → подтверждение → зелёное сообщение;
   - карточку модуля (строка Power (PoE));
   - дашборд (Restart failed (1));
   - снимки в тёмной и светлой теме.
5. Убрать за собой: модуль, партию, учётку, настройки `ethernetswitch_*`, скрипты в `/tmp`.

Expected: всё работает, в консоли браузера нет ошибок, `poe_outages` закрыты.

- [ ] **Step 7: Коммит**

```bash
git add CLAUDE.md README.md ROADMAP.md debian/changelog
git commit -m "Docs and changelog for PoE restart; 2.3.0~dev1" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 13: Выпуск 2.3.0

Только после «да» пользователя.

- [ ] `debian/changelog`: `2.3.0~devN` → `2.3.0`, дата. `ROADMAP.md`: запись 2.3.0 в «Сделано»; пункт «2. Коммутатор, остаток» убрать, нумерация дальше сдвигается.
- [ ] Весь набор на стенде, код возврата 0.
- [ ] Слияние, тег и push:
  ```bash
  git checkout main && git merge --ff-only origin/main
  git merge --no-ff stage2 -m "Merge stage2: release 2.3.0" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
  git tag -a v2.3.0 -m "cmprovision4 2.3.0"
  git rev-parse --short 'v2.3.0^{commit}'   # должен совпасть с коммитом слияния
  git checkout stage2 && git merge --ff-only main
  git push origin main stage2 v2.3.0
  ```
  Сообщения слияния — только через `-m`: `git merge -F -` не читает stdin.
- [ ] Установка: если wlanpi-da2 вернулся, собрать на нём из тега, проверить, что модули не прошиваются, `apt-get install`, проверить службы и `curl` страниц. Если нет — записать в память, что установка ждёт его возвращения.
