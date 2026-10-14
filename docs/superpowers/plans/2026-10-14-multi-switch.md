# Несколько коммутаторов на стенде — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Стенд из нескольких коммутаторов: каждый модуль получает порт своего коммутатора как плату «sysName порт», страница Ports показывает блок на коммутатор со строкой о нём внутри, перезапуск по PoE и уведомления знают коммутатор.

**Architecture:** Коммутаторы живут в таблице `ethernet_switches` (модель `EthernetSwitch`, её `config()` — тот массив, что принимают `SwitchSnapshot`, `PoeControl` и `SnmpClient`). `Stand::current()` снимает каждый коммутатор через свой `SnapshotCache` и строит одну карту `StandMap` по всем снимкам: порт-линк к соседу узнаётся по собственным MAC соседа, каждый MAC модуля достаётся ровно одному порту стенда. Порт модуля при старте (`PortLookup`), страница Ports, карточка модуля, перезапуск по PoE и дашборд берут всё из этой карты; у перезапуска свой замок на коммутатор.

**Tech Stack:** Laravel 13 в раскладке Laravel 8, Livewire 3.8 и Alpine, php-snmp, PHPUnit 12, SQLite, snmpsim 1.2.2 на WSL-стенде.

**Spec:** `docs/superpowers/specs/2026-10-14-multi-switch-design.md`

## Global Constraints

- Плата — `EthernetSwitch::board($port)` = «hostname порт» всегда, и с одним коммутатором. Hostname = `sysName` до первой точки, IP-адрес как есть, без `sysName` — адрес коммутатора. Порт в плате — описание (`ifAlias`), иначе имя порта.
- Тексты интерфейса и журнала, где назван порт, говорят «hostname порт» (`$tile['label']`): перезапуск, подтверждения, `cmlogs.board`, плашка PoE, строка Power (PoE).
- Ключ плитки — `s<id>p<ifIndex>`, где id — `ethernet_switches.id`.
- Значения: пауза после ошибки опроса `SnapshotCache::ERROR_SECONDS = 30`; ожидание чужого опроса при старте модуля `PortLookup::WAIT = 10` (страница — `SnapshotCache::LOCK_WAIT = 1`, как сейчас); замок перезапуска `poe-restart:<id>` на `PoeRestart::LOCK_SECONDS = 600`; снимок для перезапуска не старше `PoeRestart::SNAPSHOT_SECONDS = 30`; `ProvisioningStatus::MAX_ROWS = 100`.
- Ключи `ethernetswitch_*` миграция копирует и не удаляет; код 2.4 их не читает.
- Секреты (community, пароли v3, учётка записи) никогда не попадают в браузер; пустое поле секрета оставляет сохранённый только для той же строки с тем же адресом и версией SNMP.
- Цвета только из токенов (`TokenGuardTest`); корпус и плитки Ports (`ports-*`) остаются в своих цветах.
- Blade: в `ports.blade.php` только однострочные `@php(...)`; в шаблонах Livewire не ставить `@if` внутри HTML-тега.
- Livewire: публичные методы — действия; помощники `protected`; объекты моделей в шаблон — данными `render()`.
- Код в OneDrive без `vendor/`; PHP-тесты на WSL-стенде (PHP 8.4). Скрипты правки писать инструментом Write и запускать файлом (`python -I <file>`), сырые строки r"""...""". Коммиты на `stage2` с последней строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Без «да» пользователя не пушить и не выпускать.
- Версия на время работы — `2.4.0~dev1` в `debian/changelog` (задача 9).

## Review Focus

1. Цепочка: модуль на втором коммутаторе виден и на линке первого. Плата и плитка — порт второго, линк — аплинк «→ sw02», не плата и без Restart. Тесты: задача 3 (`test_a_chain_puts_each_module_on_its_own_switch`), задача 5 (`test_a_module_behind_the_link_gets_the_port_of_its_own_switch`).
2. Сосед молчит: его модули не получают плату «sw01 Gi0/24», а страница и старт модуля не ждут его таймаут каждые 5 секунд. Тесты: задача 2 (`test_a_failed_poll_is_not_repeated_for_thirty_seconds`), задача 3 (`test_a_remembered_link_holds_while_the_neighbour_is_silent`), задача 5 (`test_a_silent_neighbour_leaves_its_modules_without_a_board`).
3. База 2.3 после обновления: одна строка коммутатора со всеми учётками и `method_detected`, ключи на месте; без ключей — ни одной строки. Тест: задача 1 (`test_the_2_3_settings_become_the_first_switch_and_stay`).
4. Два коммутатора с одинаковыми именами портов и даже одинаковым `sysName`: ключи плиток и итоги перезапуска не смешиваются, предупреждение видно. Тесты: задача 3 (`test_ports_of_two_switches_never_share_a_key`), задача 4 (`test_two_switches_with_the_same_port_names_restart_apart`), задача 6 (`test_two_switches_with_one_name_are_warned_about`).
5. Секреты при правке одного из коммутаторов не уходят другому и не попадают в HTML. Тест: задача 7 (`test_a_secret_stays_with_its_own_switch`).

## Карта файлов

- `database/migrations/2026_10_14_100000_create_ethernet_switches_table.php` (новый) — таблица, колонки `cms` и `poe_outages`, копия ключей 2.3.
- `app/Models/EthernetSwitch.php` (новый) — строка коммутатора: `config()`, `fillConfig()`, `hostname()`, `board()`.
- `app/Support/ArpTable.php` (новый) — MAC адреса коммутатора в ARP провижинера.
- `app/Services/Switch/SwitchSnapshot.php` — `own_macs` (базовый MAC, `ifPhysAddress`, ARP).
- `app/Services/Switch/SnapshotCache.php` — пауза после ошибки, `current($notOlderThan, $wait)`.
- `app/Support/StandMap.php` — карта по нескольким коммутаторам: линки, один порт на модуль, ключи и подписи плиток.
- `app/Services/Switch/Stand.php` — состояние каждого коммутатора, общая карта, запоминание `sys_name`, `base_mac`, `own_macs`, `links`, `method_detected`.
- `app/Services/Switch/PortLookup.php` (новый) — порт модуля при старте.
- `app/Services/Switch/PoeRestart.php`, `app/Models/PoeOutage.php`, `app/Http/Livewire/PoeOutages.php` — перезапуск и перерывы по коммутатору.
- `app/Http/Livewire/{Ports,CmCard,ProvisioningStatus,SwitchSettings,Labels}.php`, `app/Http/Controllers/ScriptExecuteController.php`, `app/Console/Commands/ConfigureEthernetSwitch.php`, `app/Services/SwitchPortFinder.php`, `app/Services/Notifier.php`, `app/Models/Cm.php`.
- `resources/views/livewire/{ports,ports-tile,switch-settings,poe-outages,cm-card,provisioning-status,editlabel}.blade.php`.
- Тесты: `tests/Support/{FakeSnmp,FakeSwitches,PoeStand}.php`, `tests/Feature/{EthernetSwitchTest,ConfigureEthernetSwitchTest,SnapshotCacheTest,StandMapTest,PortLookupTest,PortsPageTest,PoeRestartTest,PoeOutagesTest,PoeRestartPagesTest,SwitchSettingsTest,SwitchWriteAccessTest,PoeControlTest,ScriptExecuteRobustnessTest,NotificationsTest,SnmpAgentTest}.php`, `tests/Unit/{ArpTableTest,SwitchSnapshotTest,SwitchPortFinderTest}.php`, `tests/fixtures/snmp/*`.
- `debian/preinst`, `debian/changelog`, `CLAUDE.md`, `README.md`, `ROADMAP.md`, `docker/README.md`, `docs/superpowers/specs/2026-10-12-poe-restart-design.md`.

## Команды

```bash
DEV=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad/wsl-bench
cd "/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
bash "$DEV/tests-ok.sh" 'ClassA|ClassB'   # синхронизация и PHP-тесты по регулярке; полный вывод /tmp/phpunit-filter.log на стенде
bash "$DEV/suite-ok.sh"                   # весь набор PHP и JS
bash "$DEV/snmpagent-ok.sh"               # snmpsim и SnmpAgentTest
```

Перед каждой задачей прочитать текущие версии файлов из списка Files: код ниже написан по состоянию на 7f8d672 и сверяется с ним при правке.

---

### Task 1: Таблица коммутаторов

**Files:**
- Create: `database/migrations/2026_10_14_100000_create_ethernet_switches_table.php`
- Create: `app/Models/EthernetSwitch.php`
- Modify: `app/Services/SwitchPortFinder.php` (`config()` читает первую строку таблицы — временно, до задачи 7)
- Modify: `app/Http/Livewire/SwitchSettings.php` (`save()`, `forgetWrite()` и выключение пишут первую строку)
- Modify: `app/Http/Controllers/ScriptExecuteController.php` (`method_detected` пишется в строку)
- Modify: `app/Console/Commands/ConfigureEthernetSwitch.php`
- Modify: `app/Models/Cm.php`, `app/Models/PoeOutage.php` (fillable)
- Create: `tests/Feature/EthernetSwitchTest.php`, `tests/Feature/ConfigureEthernetSwitchTest.php`
- Modify: `tests/Support/PoeStand.php`, `tests/Feature/{PortsPageTest,PoeControlTest,PoeRestartPagesTest,ScriptExecuteRobustnessTest,SwitchSettingsTest,SwitchWriteAccessTest}.php` (коммутатор — строкой таблицы вместо ключей `settings`)

**Interfaces:**
- Produces:
  - `EthernetSwitch` (таблица `ethernet_switches`), поля из спеки; `own_macs`, `links` — массивы;
  - `EthernetSwitch::config(): array` — `['id', 'host', 'version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol', 'priv_password', 'method', 'detected', 'sys_name', 'write' => ['community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol', 'priv_password']]`;
  - `EthernetSwitch::emptyConfig(): array` — тот же вид с пустым `host`;
  - `EthernetSwitch::fillConfig(array $config): static` — обратное `config()` без `detected`;
  - `EthernetSwitch::shortName(?string $sysName): ?string`, `hostname(): string`, `board(string $port): string`;
  - миграция: `copyLegacySettings(): void` (публичный метод анонимного класса);
  - колонки `cms.switch_id`, `cms.switch_name`, `cms.switch_port`, `poe_outages.switch_id` (без внешних ключей; обнуляются при удалении коммутатора событием модели).

- [ ] **Step 1: Тесты модели и миграции**

`tests/Feature/EthernetSwitchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\PoeOutage;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The switches of the stand: a row each, made from the 2.3 settings on upgrade */
class EthernetSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function migration()
    {
        return require database_path('migrations/2026_10_14_100000_create_ethernet_switches_table.php');
    }

    public function test_the_2_3_settings_become_the_first_switch_and_stay()
    {
        foreach (['ip' => '172.20.233.77', 'snmp_version' => '2c', 'snmp_community' => 'public', 'method' => 'auto',
                  'method_detected' => 'bridge', 'snmp_write_community' => 'private'] as $key => $value)
            Setting::create(['key' => 'ethernetswitch_'.$key, 'value' => $value]);

        $this->migration()->copyLegacySettings();

        $switch = EthernetSwitch::sole();
        $this->assertSame(['172.20.233.77', '2c', 'public', 'auto', 'bridge', 'private'],
            [$switch->host, $switch->snmp_version, $switch->community, $switch->method, $switch->method_detected, $switch->write_community]);
        $this->assertSame(6, Setting::where('key', 'like', 'ethernetswitch_%')->count(), 'a 2.3.x after a rollback still finds them');
    }

    public function test_no_switch_without_an_address_in_the_2_3_settings()
    {
        Setting::create(['key' => 'ethernetswitch_snmp_community', 'value' => 'public']);
        $this->migration()->copyLegacySettings();
        $this->assertSame(0, EthernetSwitch::count());
    }

    public function test_config_is_what_the_snmp_classes_take()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10', 'snmp_version' => '3', 'user' => 'ro', 'auth_protocol' => 'SHA',
            'auth_password' => 'auth-secret', 'priv_protocol' => 'AES', 'priv_password' => 'priv-secret', 'method' => 'qbridge',
            'method_detected' => 'qbridge', 'write_user' => 'rw', 'write_auth_protocol' => 'SHA', 'write_auth_password' => 'w-secret',
            'sys_name' => 'sw01']);

        $c = $switch->config();
        $this->assertSame(['id', 'host', 'version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol',
            'priv_password', 'method', 'detected', 'sys_name', 'write'], array_keys($c));
        $this->assertSame([$switch->id, '192.0.2.10', '3', '', 'ro', 'qbridge'], [$c['id'], $c['host'], $c['version'], $c['community'], $c['user'], $c['detected']]);
        $this->assertSame(['community' => '', 'user' => 'rw', 'auth_protocol' => 'SHA', 'auth_password' => 'w-secret',
            'priv_protocol' => '', 'priv_password' => ''], $c['write']);
        $this->assertSame('', EthernetSwitch::emptyConfig()['host']);

        $copy = (new EthernetSwitch)->fillConfig($c);
        $this->assertSame(['192.0.2.10', '3', 'priv-secret', 'w-secret', null], [$copy->host, $copy->snmp_version,
            $copy->priv_password, $copy->write_auth_password, $copy->method_detected]);
    }

    public function test_the_hostname_is_sysname_before_its_domain()
    {
        $this->assertSame('t-iof-sw01-provision', EthernetSwitch::shortName('t-iof-sw01-provision.corp.example.com'));
        $this->assertSame('Switch', EthernetSwitch::shortName(' Switch '));
        $this->assertSame('172.20.233.77', EthernetSwitch::shortName('172.20.233.77'));
        $this->assertNull(EthernetSwitch::shortName(''));
        $this->assertNull(EthernetSwitch::shortName(null));

        $switch = new EthernetSwitch(['host' => '192.0.2.10']);
        $this->assertSame('192.0.2.10', $switch->hostname(), 'no sysName yet: the address');
        $switch->sys_name = 'sw02.corp.example.com';
        $this->assertSame('sw02 Gi1/0/3', $switch->board('Gi1/0/3'));
    }

    public function test_a_deleted_switch_leaves_modules_and_outages_without_it()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10']);
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3', 'switch_id' => $switch->id,
            'switch_name' => 'sw01', 'switch_port' => 'GE0/0/3', 'provisioning_board' => 'sw01 GE0/0/3']);
        $outage = PoeOutage::create(['switch_id' => $switch->id, 'switch_host' => '192.0.2.10', 'if_index' => 8,
            'port' => 'sw01 GE0/0/3', 'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'off_at' => now()]);

        $switch->delete();

        $this->assertNull($cm->fresh()->switch_id);
        $this->assertSame('sw01 GE0/0/3', $cm->fresh()->provisioning_board);
        $this->assertNull($outage->fresh()->switch_id);
    }
}
```

`tests/Feature/ConfigureEthernetSwitchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Services\Snmp\SnmpClient;
use App\Services\SwitchPortFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSnmp;
use Tests\TestCase;

/** artisan ethernetswitch:configure adds a v2c switch or changes the one at that address; it never drops write access */
class ConfigureEthernetSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $fake = new FakeSnmp(['' => [
            SwitchPortFinder::OID_BRIDGE_FDB_PORT.'.228.95.1.98.159.211' => 5,
            SwitchPortFinder::OID_BASEPORT_IFINDEX.'.5' => 5,
            SwitchPortFinder::OID_IFNAME.'.5' => 'GE0/0/5',
        ]]);
        $this->app->bind(SnmpClient::class, function () use ($fake) { return $fake; });
    }

    public function test_a_new_address_adds_a_switch()
    {
        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '192.0.2.10')
            ->expectsQuestion('SNMP v2c community name', 'public')
            ->assertExitCode(0);

        $switch = EthernetSwitch::sole();
        $this->assertSame(['192.0.2.10', '2c', 'public', 'bridge'], [$switch->host, $switch->snmp_version, $switch->community, $switch->method_detected]);
    }

    public function test_a_known_address_keeps_its_write_access()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'old', 'write_community' => 'private']);

        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '192.0.2.10')
            ->expectsQuestion('SNMP v2c community name', 'public')
            ->assertExitCode(0);

        $switch = EthernetSwitch::sole();
        $this->assertSame(['public', 'private'], [$switch->community, $switch->write_community]);
    }

    public function test_an_empty_address_lists_and_remove_deletes_one()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'sys_name' => 'sw01']);
        EthernetSwitch::create(['host' => '192.0.2.11']);

        $this->artisan('ethernetswitch:configure')
            ->expectsQuestion('Ethernet switch IP-address (leave empty to list the switches)', '')
            ->expectsOutputToContain('192.0.2.10')
            ->assertExitCode(0);
        $this->assertSame(2, EthernetSwitch::count(), 'Enter alone removes nothing');

        $this->artisan('ethernetswitch:configure', ['--remove' => '192.0.2.11'])->assertExitCode(0);
        $this->assertSame(['192.0.2.10'], EthernetSwitch::pluck('host')->all());
    }
}
```

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'EthernetSwitchTest|ConfigureEthernetSwitchTest'`
Expected: FAIL — `Class "App\Models\EthernetSwitch" not found`, нет файла миграции.

- [ ] **Step 3: Миграция**

`database/migrations/2026_10_14_100000_create_ethernet_switches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* The switches of the stand (docs/superpowers/specs/2026-10-14-multi-switch-design.md). The 2.3 settings become the
   first one and stay where they were: a 2.3.x installed back over this database still finds its switch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethernet_switches', function (Blueprint $table) {
            $table->id();
            $table->string('host')->unique();
            $table->string('snmp_version')->default('2c');
            foreach (['community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol', 'priv_password',
                      'write_community', 'write_user', 'write_auth_protocol', 'write_auth_password', 'write_priv_protocol',
                      'write_priv_password'] as $column)
                $table->string($column)->nullable();
            $table->string('method')->default('auto');
            $table->string('method_detected')->nullable();
            $table->string('sys_name')->nullable();
            $table->string('base_mac')->nullable()->unique();
            $table->text('own_macs')->nullable();   // JSON
            $table->text('links')->nullable();      // JSON: ifIndex => id of the neighbour
            $table->timestamps();
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->unsignedBigInteger('switch_id')->nullable()->index();
            $table->string('switch_name')->nullable();
            $table->string('switch_port')->nullable();
        });
        Schema::table('poe_outages', function (Blueprint $table) {
            $table->unsignedBigInteger('switch_id')->nullable()->index();
        });
        $this->copyLegacySettings();
    }

    public function down(): void
    {
        Schema::table('poe_outages', function (Blueprint $table) {
            $table->dropIndex(['switch_id']);
            $table->dropColumn('switch_id');
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->dropIndex(['switch_id']);
            $table->dropColumn(['switch_id', 'switch_name', 'switch_port']);
        });
        Schema::dropIfExists('ethernet_switches');
    }

    /* The one switch of 2.3 (settings ethernetswitch_*) as the first row; the keys are not removed */
    public function copyLegacySettings(): void
    {
        $get = function ($key) {
            $value = DB::table('settings')->where('key', 'ethernetswitch_'.$key)->value('value');
            return $value === null || $value === '' ? null : $value;
        };
        if ($get('ip') === null || DB::table('ethernet_switches')->where('host', $get('ip'))->exists())
            return;
        DB::table('ethernet_switches')->insert([
            'host' => $get('ip'), 'snmp_version' => $get('snmp_version') ?? '2c', 'community' => $get('snmp_community'),
            'user' => $get('snmp_user'), 'auth_protocol' => $get('snmp_auth_protocol'), 'auth_password' => $get('snmp_auth_password'),
            'priv_protocol' => $get('snmp_priv_protocol'), 'priv_password' => $get('snmp_priv_password'),
            'write_community' => $get('snmp_write_community'), 'write_user' => $get('snmp_write_user'),
            'write_auth_protocol' => $get('snmp_write_auth_protocol'), 'write_auth_password' => $get('snmp_write_auth_password'),
            'write_priv_protocol' => $get('snmp_write_priv_protocol'), 'write_priv_password' => $get('snmp_write_priv_password'),
            'method' => $get('method') ?? 'auto', 'method_detected' => $get('method_detected'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
```

- [ ] **Step 4: Модель**

`app/Models/EthernetSwitch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A switch of the stand (docs/superpowers/specs/2026-10-14-multi-switch-design.md): how to reach it by SNMP, read and
   write, and what it last said about itself */
class EthernetSwitch extends Model
{
    const SECRETS = ['community', 'auth_password', 'priv_password', 'write_community', 'write_auth_password', 'write_priv_password'];

    protected $table = 'ethernet_switches';

    protected $fillable = ['host', 'snmp_version', 'community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol',
        'priv_password', 'write_community', 'write_user', 'write_auth_protocol', 'write_auth_password', 'write_priv_protocol',
        'write_priv_password', 'method', 'method_detected', 'sys_name', 'base_mac', 'own_macs', 'links'];

    protected $hidden = self::SECRETS;

    protected $casts = ['own_macs' => 'array', 'links' => 'array'];

    protected static function booted()
    {
        // what a module or a PoE outage was found on stays in their text; the link goes
        static::deleting(function (EthernetSwitch $switch) {
            Cm::where('switch_id', $switch->id)->update(['switch_id' => null]);
            PoeOutage::where('switch_id', $switch->id)->update(['switch_id' => null]);
        });
    }

    /** What SwitchSnapshot::forConfig(), PoeControl::forConfig() and the SnmpClient binding take */
    public function config(): array
    {
        $s = function ($value) { return (string) $value; };
        return [
            'id' => $this->id, 'host' => $s($this->host), 'version' => $this->snmp_version ?: '2c',
            'community' => $s($this->community), 'user' => $s($this->user),
            'auth_protocol' => $s($this->auth_protocol), 'auth_password' => $s($this->auth_password),
            'priv_protocol' => $s($this->priv_protocol), 'priv_password' => $s($this->priv_password),
            'method' => $this->method ?: 'auto', 'detected' => $this->method_detected, 'sys_name' => $this->sys_name,
            'write' => [
                'community' => $s($this->write_community), 'user' => $s($this->write_user),
                'auth_protocol' => $s($this->write_auth_protocol), 'auth_password' => $s($this->write_auth_password),
                'priv_protocol' => $s($this->write_priv_protocol), 'priv_password' => $s($this->write_priv_password),
            ],
        ];
    }

    public static function emptyConfig(): array
    {
        return (new self)->config();
    }

    /** The other way round: the SNMP settings of a config() array (what the switch said about itself stays) */
    public function fillConfig(array $config): self
    {
        $w = $config['write'] ?? [];
        $this->fill([
            'host' => $config['host'], 'snmp_version' => $config['version'] ?? '2c', 'community' => $config['community'] ?? '',
            'user' => $config['user'] ?? '', 'auth_protocol' => $config['auth_protocol'] ?? '', 'auth_password' => $config['auth_password'] ?? '',
            'priv_protocol' => $config['priv_protocol'] ?? '', 'priv_password' => $config['priv_password'] ?? '',
            'method' => $config['method'] ?? 'auto',
            'write_community' => $w['community'] ?? '', 'write_user' => $w['user'] ?? '',
            'write_auth_protocol' => $w['auth_protocol'] ?? '', 'write_auth_password' => $w['auth_password'] ?? '',
            'write_priv_protocol' => $w['priv_protocol'] ?? '', 'write_priv_password' => $w['priv_password'] ?? '',
        ]);
        return $this;
    }

    /** sysName without its domain ("sw01" of "sw01.corp.example.com"), an IP address as it is; null without one */
    public static function shortName(?string $sysName): ?string
    {
        $name = trim((string) $sysName);
        if ($name === '')
            return null;
        if (filter_var($name, FILTER_VALIDATE_IP))
            return $name;
        $short = strstr($name, '.', true);
        return $short !== false && $short !== '' ? $short : $name;
    }

    /** The name boards and texts use: the switch's own name, its address before it said one */
    public function hostname(): string
    {
        return self::shortName($this->sys_name) ?? (string) $this->host;
    }

    /** "sw01 Gi0/3": a module's board */
    public function board(string $port): string
    {
        return $this->hostname().' '.$port;
    }
}
```

`app/Models/Cm.php`: добавить в `$fillable` `'switch_id', 'switch_name', 'switch_port'`. `app/Models/PoeOutage.php`: добавить в `$fillable` `'switch_id'`.

- [ ] **Step 5: Временный мост и запись в строку**

`app/Services/SwitchPortFinder.php` — `config()` (до задачи 7 ею пользуются остальные классы):

```php
    /* The first switch of the stand (until every caller knows several) */
    public static function config()
    {
        $switch = EthernetSwitch::orderBy('id')->first();
        return $switch ? $switch->config() : EthernetSwitch::emptyConfig();
    }
```

`app/Http/Livewire/SwitchSettings.php`:
- `save()`: пустой адрес — `EthernetSwitch::query()->delete()` вместо удаления ключей; иначе
  ```php
  $switch = EthernetSwitch::orderBy('id')->first() ?? new EthernetSwitch;
  $before = $switch->exists ? $switch->config() : EthernetSwitch::emptyConfig();
  $switch->fillConfig($config);
  if ($before['host'] !== $config['host'] || $before['method'] !== $this->method)
      $switch->method_detected = null;
  $switch->save();
  ```
- `forgetWrite()`: у первой строки обнулить `write_*` и сохранить.

`app/Http/Controllers/ScriptExecuteController.php` — вместо `Setting::updateOrCreate(['key' => 'ethernetswitch_method_detected'], ...)`:

```php
                    EthernetSwitch::whereKey($switchConfig['id'])->update(['method_detected' => $finder->lastMethod()]);
```

`app/Console/Commands/ConfigureEthernetSwitch.php`:

```php
    protected $signature = 'ethernetswitch:configure {--remove= : address of a switch to remove}';

    protected $description = 'Add or change an Ethernet switch of the stand by SNMP v2c (SNMPv3, write access and the lookup method: settings page)';

    public function handle()
    {
        if ($this->option('remove') !== null)
        {
            $removed = EthernetSwitch::where('host', $this->option('remove'))->first();
            if (!$removed)
            {
                $this->error('No switch at '.$this->option('remove').'.');
                return 1;
            }
            $removed->delete();
            $this->info('Removed '.$removed->hostname().' ('.$removed->host.').');
            return 0;
        }

        $ip = $this->ask('Ethernet switch IP-address (leave empty to list the switches)');
        if (!$ip)
        {
            $rows = EthernetSwitch::orderBy('id')->get()->map(function ($s) { return [$s->hostname(), $s->host, $s->snmp_version]; })->all();
            $rows ? $this->table(['Name', 'Address', 'SNMP'], $rows) : $this->line('No switches.');
            $this->line('Nothing changed. Remove one with --remove=<address>, change SNMPv3 and write access on the settings page.');
            return 0;
        }
        // asking the switch, the table and the error stay as they are; at the end:
        $switch = EthernetSwitch::firstOrNew(['host' => $ip]);
        $switch->fill(['snmp_version' => '2c', 'community' => $community, 'method' => 'auto', 'method_detected' => $scan['method']])->save();
        $this->info('Communication successful ('.SwitchPortFinder::METHODS[$scan['method']].'). '.($switch->wasRecentlyCreated ? 'Added' : 'Changed').' '.$ip.'.');
        return 0;
    }
```

- [ ] **Step 6: Тесты, которые заводили коммутатор ключами**

В `tests/Support/PoeStand.php::stand()` вместо трёх `Setting::create(...)`:

```php
        $write = [];
        foreach ($settings as $key => $value)
            $write[preg_replace('/^snmp_/', '', $key)] = $value;   // snmp_write_community -> write_community
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'public'] + $write);
```

Так же в `PortsPageTest` (строки 54-55, 241, 276, 357), `PoeControlTest` (хелпер с `Setting::updateOrCreate`), `PoeRestartPagesTest:47`, `ScriptExecuteRobustnessTest:50-51`, `SwitchSettingsTest` (хелпер `value($key)` читает `EthernetSwitch::first()?->{колонка}`, проверка «0 ключей» — `EthernetSwitch::count()`), `SwitchWriteAccessTest` (без изменений: `SwitchPortFinder::config()` пока мост).

- [ ] **Step 7: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'EthernetSwitchTest|ConfigureEthernetSwitchTest|PortsPageTest|PoeControlTest|PoeRestartPagesTest|PoeRestartTest|PoeOutagesTest|ScriptExecuteRobustnessTest|SwitchSettingsTest|SwitchWriteAccessTest'`
Expected: PASS. Затем `bash "$DEV/suite-ok.sh"` — PASS.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_10_14_100000_create_ethernet_switches_table.php app/Models/EthernetSwitch.php app/Models/Cm.php app/Models/PoeOutage.php app/Services/SwitchPortFinder.php app/Http/Livewire/SwitchSettings.php app/Http/Controllers/ScriptExecuteController.php app/Console/Commands/ConfigureEthernetSwitch.php tests
git commit -F <message file>   # "Switches: a table of their own, the 2.3 settings copied into it"
```

---

### Task 2: Свои MAC коммутатора; пауза после ошибки; подставные коммутаторы по адресу

**Files:**
- Create: `app/Support/ArpTable.php`, `tests/Unit/ArpTableTest.php`
- Modify: `app/Services/Switch/SwitchSnapshot.php`, `tests/Unit/SwitchSnapshotTest.php`
- Modify: `app/Services/Switch/SnapshotCache.php`, `tests/Feature/SnapshotCacheTest.php`
- Modify: `tests/Support/FakeSnmp.php`; Create: `tests/Support/FakeSwitches.php`

**Interfaces:**
- Produces:
  - `ArpTable::$path` (`/proc/net/arp`), `ArpTable::macOf(string $host): ?string` — MAC в нижнем регистре через двоеточия;
  - `SwitchSnapshot::OID_IFPHYSADDRESS = '.1.3.6.1.2.1.2.2.1.6'`; снимок получает `'own_macs' => string[]` (базовый MAC, `ifPhysAddress`, ARP, без повторов и нулей);
  - `SnapshotCache::ERROR_SECONDS = 30`; `SnapshotCache::current(?int $notOlderThan = null, float $wait = self::LOCK_WAIT): array`;
  - `FakeSnmp::__construct(array $tables, string $host = '192.0.2.1')`;
  - трейт `Tests\Support\FakeSwitches::bindSwitches(array $fakes)` — `[host => FakeSnmp]`.

- [ ] **Step 1: Тесты**

`tests/Unit/ArpTableTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\ArpTable;
use PHPUnit\Framework\TestCase;

/** The MAC a switch answers the provisioner from, as its ARP cache keeps it */
class ArpTableTest extends TestCase
{
    protected $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'arp');
        file_put_contents($this->file, "IP address       HW type     Flags       HW address            Mask     Device\n"
            ."172.20.233.77    0x1         0x2         00:57:D2:97:A1:C0     *        eth0\n"
            ."172.20.233.78    0x1         0x0         00:00:00:00:00:00     *        eth0\n"
            ."192.168.25.1     0x1         0x2         48:a9:8a:b1:95:74     *        wlan0\n");
        ArpTable::$path = $this->file;
    }

    protected function tearDown(): void
    {
        ArpTable::$path = '/proc/net/arp';
        unlink($this->file);
        parent::tearDown();
    }

    public function test_the_mac_of_an_address()
    {
        $this->assertSame('00:57:d2:97:a1:c0', ArpTable::macOf('172.20.233.77'));
        $this->assertSame('00:57:d2:97:a1:c0', ArpTable::macOf('172.20.233.77:161'), 'an SNMP port after the address');
    }

    public function test_no_mac_for_an_incomplete_entry_or_an_unknown_address()
    {
        $this->assertNull(ArpTable::macOf('172.20.233.78'));
        $this->assertNull(ArpTable::macOf('172.20.233.79'));
        ArpTable::$path = '/nonexistent/arp';
        $this->assertNull(ArpTable::macOf('172.20.233.77'));
    }
}
```

`tests/Unit/SwitchSnapshotTest.php` — новый тест:

```php
    public function test_own_macs_are_the_base_mac_the_interfaces_and_the_arp_entry()
    {
        $arp = tempnam(sys_get_temp_dir(), 'arp');
        file_put_contents($arp, "IP address HW type Flags HW address Mask Device\n192.0.2.10 0x1 0x2 00:57:d2:97:a1:c0 * eth0\n");
        ArpTable::$path = $arp;
        try
        {
            $s = $this->take($this->interfaces([1 => ['Gi0/1', 6, 1, 1, 1000]]) + [
                SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0' => hex2bin('0057d297a180'),
                SwitchSnapshot::OID_IFPHYSADDRESS.'.1' => hex2bin('0057d297a181'),
                SwitchSnapshot::OID_IFPHYSADDRESS.'.900' => hex2bin('0057d297a1c0'),   // Vl1
                SwitchSnapshot::OID_IFPHYSADDRESS.'.901' => '',                        // a tunnel: none
            ]);
        }
        finally
        {
            ArpTable::$path = '/proc/net/arp';
            unlink($arp);
        }
        $this->assertSame(['00:57:d2:97:a1:80', '00:57:d2:97:a1:81', '00:57:d2:97:a1:c0'], $s['own_macs']);
    }
```

`tests/Feature/SnapshotCacheTest.php` — новые тесты (`Carbon::setTestNow` как в файле):

```php
    public function test_a_failed_poll_is_not_repeated_for_thirty_seconds()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $polls = 0;
        $cache = new SnapshotCache('192.0.2.10', function () use (&$polls) { $polls++; throw new SnmpError('No response', true); });
        $cache->current();
        Carbon::setTestNow('2026-10-14 10:00:20');
        $state = $cache->current();
        $this->assertSame(1, $polls, 'a silent switch is not asked again on every page poll');
        $this->assertSame('No response', $state['error']['message']);
        Carbon::setTestNow('2026-10-14 10:00:31');
        $cache->current();
        $this->assertSame(2, $polls);
    }

    public function test_a_snapshot_older_than_asked_for_is_taken_again_unless_the_switch_is_silent()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $polls = 0;
        $cache = new SnapshotCache('192.0.2.10', function () use (&$polls) { $polls++; return ['taken_at' => now()->getTimestamp(), 'duration' => 0.1, 'ports' => []]; });
        $cache->current();
        Carbon::setTestNow('2026-10-14 10:00:02');
        $cache->current();
        $this->assertSame(1, $polls, 'fresh for five seconds');
        $cache->current(now()->getTimestamp() - 1);
        $this->assertSame(2, $polls, 'a module that came after the snapshot needs a newer one');
    }
```

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'ArpTableTest|SwitchSnapshotTest|SnapshotCacheTest'`
Expected: FAIL — нет `App\Support\ArpTable`, нет `OID_IFPHYSADDRESS`, лишний опрос.

- [ ] **Step 3: ArpTable**

`app/Support/ArpTable.php`:

```php
<?php

namespace App\Support;

/* The provisioner's ARP cache (/proc/net/arp, readable by www-data like /proc/net/route): the MAC a switch of the
   stand answers from, so its neighbour's port towards it can be told by that address */
class ArpTable
{
    public static $path = '/proc/net/arp';

    /** The MAC of an address ("172.20.233.77", "172.20.233.77:161" or a name), null when the cache has none */
    public static function macOf(string $host): ?string
    {
        $ip = self::ip($host);
        if ($ip === null)
            return null;
        $lines = @file(self::$path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_slice($lines, 1) as $line)
        {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) >= 4 && $cols[0] === $ip && (hexdec($cols[2]) & 0x2) && $cols[3] !== '00:00:00:00:00:00')
                return strtolower($cols[3]);
        }
        return null;
    }

    protected static function ip(string $host): ?string
    {
        if (preg_match('/^([^:]+):\d+$/', $host, $m))
            $host = $m[1];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))
            return $host;
        $ip = gethostbyname($host);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : null;
    }
}
```

- [ ] **Step 4: own_macs в снимке**

`app/Services/Switch/SwitchSnapshot.php`: константа `OID_IFPHYSADDRESS`; в `take()` после `$identity = $this->identity($classes);` и в массив снимка `'own_macs' => $this->ownMacs($identity['mac'])`; метод:

```php
    /* The addresses the switch itself answers from: base MAC, its interfaces' (SVI and ports) and the ARP entry of its
       address. A neighbour's port towards it shows one of them, while the switch never lists them on its own ports */
    protected function ownMacs(?string $base): array
    {
        $macs = $base ? [$base] : [];
        foreach ($this->column(self::OID_IFPHYSADDRESS) as $value)
            if (is_string($value) && strlen($value) === 6 && trim($value, "\0") !== '')
                $macs[] = implode(':', str_split(bin2hex($value), 2));
        $arp = ArpTable::macOf($this->id);
        if ($arp)
            $macs[] = $arp;
        return array_values(array_unique($macs));
    }
```

(Прочитать `column()`: если он приводит значения к тексту, читать `ifPhysAddress` тем же путём, что `value($oid, false)` — шесть октетов как есть.)

- [ ] **Step 5: SnapshotCache**

`app/Services/Switch/SnapshotCache.php`:

```php
    /* A switch whose last poll failed is asked again after this long: every page poll and module start would wait for its timeout */
    const ERROR_SECONDS = 30;

    /** $notOlderThan: a snapshot taken before this unix time is taken again (unless the switch is in its pause after
        an error); $wait: how long to wait for another request's poll before taking the stored state */
    public function current(?int $notOlderThan = null, float $wait = self::LOCK_WAIT): array
    {
        $state = $this->stored();
        if ($this->fresh($state, $notOlderThan))
            return $state;
        $lock = Cache::lock('switch-snapshot-lock:'.$this->id, self::LOCK_SECONDS);
        try
        {
            $lock->block($wait);
        }
        catch (LockTimeoutException $e)
        {
            return $state;
        }
        try
        {
            $state = $this->stored();
            return $this->fresh($state, $notOlderThan) ? $state : $this->poll($state);
        }
        finally
        {
            $lock->release();
        }
    }

    protected function fresh(array $state, ?int $notOlderThan = null): bool
    {
        if (!$state['checked_at'])
            return false;
        $age = Carbon::now()->getTimestamp() - $state['checked_at'];
        $duration = max((float) ($state['snapshot']['duration'] ?? 0), (float) ($state['took'] ?? 0));
        $life = $duration > self::SLOW_POLL ? 2 * $duration : self::FRESH_SECONDS;
        if ($state['error'])
            return $age < max(self::ERROR_SECONDS, $life);
        if ($notOlderThan !== null && $state['checked_at'] < $notOlderThan)
            return false;
        return $age < $life;
    }
```

- [ ] **Step 6: Подставные коммутаторы по адресу**

`tests/Support/FakeSnmp.php`: конструктор `__construct(array $tables, string $host = '192.0.2.1')` хранит `$this->host`; сообщения таймаута — `'No response from '.$this->host`.

`tests/Support/FakeSwitches.php`:

```php
<?php

namespace Tests\Support;

use App\Services\Snmp\SnmpClient;

/* Several switches in one test: each answers from its own FakeSnmp, picked by the address in the client's config */
trait FakeSwitches
{
    /** @param FakeSnmp[] $fakes host => fake */
    protected function bindSwitches(array $fakes): void
    {
        $this->app->bind(SnmpClient::class, function ($app, $params) use ($fakes) {
            $host = $params['config']['host'] ?? '';
            if (!isset($fakes[$host]))
                throw new \RuntimeException('No fake switch at '.$host);
            return $fakes[$host];
        });
    }
}
```

- [ ] **Step 7: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'ArpTableTest|SwitchSnapshotTest|SnapshotCacheTest|PortsPageTest|PoeRestartTest'`
Expected: PASS (снимок старых тестов получает `own_macs`, на них никто не смотрит).

- [ ] **Step 8: Commit** — «Switch snapshot: its own MACs; a silent switch is asked again after 30 s».

---

### Task 3: Карта стенда по нескольким коммутаторам

**Files:**
- Modify: `app/Support/StandMap.php`
- Modify: `app/Services/Switch/Stand.php` (только строка, где строится карта: `StandMap::of(...)`)
- Modify: `resources/views/livewire/ports.blade.php` (состав корпуса берётся у коммутатора)
- Modify: `tests/Feature/StandMapTest.php` (ключи `p<n>` → `s1p<n>`, новые тесты)
- Modify: `tests/Feature/PortsPageTest.php`, `tests/Feature/PoeRestartTest.php`, `tests/Feature/PoeRestartPagesTest.php`, `tests/Feature/PoeOutagesTest.php` (ключи `p<n>` → `s1p<n>` там, где они названы)

**Interfaces:**
- Consumes: снимок с `own_macs` (Task 2).
- Produces:
  - `new StandMap(array $parts, iterable $modules, ?string $serverMac)`, где часть — `['id' => int, 'name' => string, 'snapshot' => ?array, 'answering' => bool, 'own_macs' => string[], 'links' => [ifIndex => id соседа]]`;
  - `StandMap::of(array $snapshot, iterable $modules, ?string $serverMac): self` — один коммутатор, id 1, имя `identity.name` или id снимка;
  - `build(): array` → `['switches' => [id => ['id', 'name', 'blocks', 'composition', 'links' => [ifIndex => id соседа]]], 'tiles' => [key => tile], 'summary' => [...без composition], 'owners' => [mac => ['key' => ?string, 'seen' => key[]]], 'blocks' => blocks первого коммутатора]`;
  - плитка: `key` (`s<id>p<ifIndex>`), `switch_id`, `switch` (имя), `label` («имя порт»), `board_port` (описание порта, иначе имя), `board` («имя board_port»), `link` (имя соседа или null), прочие поля как сейчас; `macs` — только адреса этого порта, без модулей других портов;
  - `StandMap::restartBlocker()`: для линка — «<label> is the link to <сосед>».

- [ ] **Step 1: Тесты**

В `tests/Feature/StandMapTest.php` хелпер `build()` → `StandMap::of($this->snapshot($ports), $modules, self::SERVER)->build()`; ключи `p<n>` → `s1p<n>`. Новые тесты (хелпер `part()` собирает часть):

```php
    protected function part(int $id, string $name, array $ports, array $ownMacs = [], array $links = [], bool $answering = true)
    {
        return ['id' => $id, 'name' => $name, 'snapshot' => $ports === null ? null : $this->snapshot($ports),
                'answering' => $answering, 'own_macs' => $ownMacs, 'links' => $links];
    }

    public function test_a_chain_puts_each_module_on_its_own_switch()
    {
        // sw01 Gi0/24 leads to sw02: its table holds sw02's modules and sw02's own SVI MAC
        $m1 = $this->module('10000000000000a1', 'e4:5f:01:00:00:a1');
        $m2 = $this->module('10000000000000a2', 'e4:5f:01:00:00:a2');
        $m3 = $this->module('10000000000000a3', 'e4:5f:01:00:00:a3');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(1, 'Gi0/1', true, [$m1->mac]),
                                    $this->port(24, 'Gi0/24', true, [$m2->mac, $m3->mac, '00:57:d2:97:a1:c0'])]),
            $this->part(2, 'sw02', [$this->port(1, 'Gi0/1', true, [$m2->mac]), $this->port(2, 'Gi0/2', true, [$m3->mac])],
                        ['00:57:d2:97:a1:80', '00:57:d2:97:a1:c0']),
        ], [$m1, $m2, $m3], self::SERVER))->build();

        $link = $map['tiles']['s1p24'];
        $this->assertSame(['uplink', 'sw02', []], [$link['state'], $link['link'], $link['modules']]);
        $this->assertSame('sw01 Gi0/24 is the link to sw02', StandMap::restartBlocker($link));
        $this->assertSame([$m2->serial], array_map(function ($cm) { return $cm->serial; }, $map['tiles']['s2p1']['modules']));
        $this->assertSame('s2p1', $map['owners'][$m2->mac]['key']);
        $this->assertSame(['s1p24', 's2p1'], $map['owners'][$m2->mac]['seen']);
        $this->assertSame(3, $map['summary']['modules'], 'each module once');
        $this->assertSame([24 => 2], $map['switches'][1]['links']);
    }

    public function test_a_remembered_link_holds_while_the_neighbour_is_silent()
    {
        $m2 = $this->module('10000000000000a2', 'e4:5f:01:00:00:a2');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(24, 'Gi0/24', true, [$m2->mac])], [], [24 => 2]),
            $this->part(2, 'sw02', null, ['00:57:d2:97:a1:c0'], [], false),
        ], [$m2], self::SERVER))->build();

        $this->assertSame(['uplink', 'sw02'], [$map['tiles']['s1p24']['state'], $map['tiles']['s1p24']['link']]);
        $this->assertNull($map['owners'][$m2->mac]['key'], 'behind the link: no port of its own');
        $this->assertSame([], $map['switches'][2]['blocks']);
    }

    public function test_a_module_seen_alike_on_two_switches_is_on_neither()
    {
        $m = $this->module('10000000000000a4', 'e4:5f:01:00:00:a4');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$m->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$m->mac])]),
        ], [$m], self::SERVER))->build();

        $this->assertNull($map['owners'][$m->mac]['key']);
        $this->assertSame([[], []], [$map['tiles']['s1p5']['modules'], $map['tiles']['s2p3']['modules']]);
        $this->assertSame(0, $map['summary']['modules']);
    }

    public function test_the_port_where_only_modules_are_wins_a_tie()
    {
        $m = $this->module('10000000000000a5', 'e4:5f:01:00:00:a5');
        $n = $this->module('10000000000000a6', 'e4:5f:01:00:00:a6');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$m->mac, $n->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$m->mac, 'aa:00:00:00:00:01'])]),
        ], [$m, $n], self::SERVER))->build();

        $this->assertSame('s1p5', $map['owners'][$m->mac]['key']);
        $this->assertSame(0, $map['tiles']['s2p3']['others'] - 1, 'the module that belongs elsewhere is no other device here');
    }

    public function test_ports_of_two_switches_never_share_a_key()
    {
        $map = (new StandMap([
            $this->part(1, 'Switch', [$this->port(5, 'GE0/0/5', false)]),
            $this->part(2, 'Switch', [$this->port(5, 'GE0/0/5', false)]),
        ], [], self::SERVER))->build();

        $this->assertSame(['s1p5', 's2p5'], array_keys($map['tiles']));
        $this->assertSame(['Switch GE0/0/5', 2], [$map['tiles']['s2p5']['label'], $map['tiles']['s2p5']['switch_id']]);
    }

    public function test_a_module_moved_to_the_other_switch_leaves_its_old_port_to_the_new_one()
    {
        // x went from sw01 Gi0/5 to sw02 Gi0/3; sw01 still lists it on Gi0/5, where y is plugged in now
        $x = $this->module('10000000000000a7', 'e4:5f:01:00:00:a7');
        $y = $this->module('10000000000000a8', 'e4:5f:01:00:00:a8');
        $map = (new StandMap([
            $this->part(1, 'sw01', [$this->port(5, 'Gi0/5', true, [$x->mac, $y->mac])]),
            $this->part(2, 'sw02', [$this->port(3, 'Gi0/3', true, [$x->mac])]),
        ], [$x, $y], self::SERVER))->build();

        $this->assertSame(['s2p3', 's1p5'], [$map['owners'][$x->mac]['key'], $map['owners'][$y->mac]['key']]);
        $this->assertSame(0, $map['tiles']['s1p5']['others'], 'x belongs to sw02: y alone may be restarted');
        $this->assertNull(StandMap::restartBlocker($map['tiles']['s1p5'] + ['poe' => ['state' => 'delivering']]));
    }

    public function test_the_board_takes_the_port_description()
    {
        $port = $this->port(3, 'Gi0/3', true);
        $port['alias'] = 'rack 3';
        $map = (new StandMap([$this->part(1, 'sw01', [$port])], [], self::SERVER))->build();
        $this->assertSame(['sw01 rack 3', 'rack 3', 'sw01 Gi0/3'],
            [$map['tiles']['s1p3']['board'], $map['tiles']['s1p3']['board_port'], $map['tiles']['s1p3']['label']]);
    }
```

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'StandMapTest'`
Expected: FAIL — нет `StandMap::of`, конструктор ждёт снимок.

- [ ] **Step 3: StandMap**

Структура `build()`:

```php
    public function __construct(array $parts, iterable $modules, ?string $serverMac)
    {
        $this->parts = [];
        foreach ($parts as $part)
            $this->parts[$part['id']] = $part + ['own_macs' => [], 'links' => [], 'answering' => (bool) ($part['snapshot'] ?? null)];
        foreach ($modules as $cm)
            if ($cm->mac)
                $this->modules[strtolower($cm->mac)] = $cm;
        $this->serverMac = $serverMac ? strtolower($serverMac) : null;
    }

    /** One switch (id 1), named by what it says about itself */
    public static function of(array $snapshot, iterable $modules, ?string $serverMac): self
    {
        return new self([['id' => 1, 'name' => $snapshot['identity']['name'] ?? ($snapshot['id'] ?? ''), 'snapshot' => $snapshot,
                          'answering' => true]], $modules, $serverMac);
    }

    public function build(): array
    {
        $ports = [];   // key => ['part' => ..., 'port' => ..., 'macs' => lowercase]
        foreach ($this->parts as $part)
            foreach ($part['snapshot']['ports'] ?? [] as $port)
                $ports['s'.$part['id'].'p'.$port['ifIndex']] = ['part' => $part, 'port' => $port, 'macs' => array_map('strtolower', $port['macs'])];

        $links = $this->links($ports);
        $uplinks = [];
        foreach ($ports as $key => $p)
            $uplinks[$key] = isset($links[$key]) || ($this->serverMac && in_array($this->serverMac, $p['macs'], true))
                || $this->foreign($p) > self::UPLINK_MACS;
        $owners = $this->owners($ports, $uplinks);

        $tiles = $switches = [];
        $allMain = $allAside = [];
        foreach ($this->parts as $id => $part)
        {
            $mine = array_filter($ports, function ($p) use ($id) { return $p['part']['id'] === $id; });
            // the layout of one switch: the code of today's build() with $this->tile($key, $p, ...) for each port
            [$blocks, $main, $aside, $withPoe] = $this->layout($mine, $owners, $links, $uplinks);
            foreach (array_merge($main, $aside) as $tile)
                $tiles[$tile['key']] = $tile;
            $allMain = array_merge($allMain, $main);
            $allAside = array_merge($allAside, $aside);
            $found = [];
            foreach ($links as $key => $neighbour)
                if ($mine[$key] ?? null)
                    $found[$mine[$key]['port']['ifIndex']] = $neighbour;
            $switches[$id] = ['id' => $id, 'name' => $part['name'], 'blocks' => $blocks,
                              'composition' => $main || $aside ? $this->composition($main, $aside, $withPoe) : '', 'links' => $found];
        }
        $first = reset($switches);
        return ['switches' => $switches, 'tiles' => $tiles, 'summary' => $this->summary($allMain, $tiles),
                'owners' => $owners, 'blocks' => $first ? $first['blocks'] : []];
    }

    /* A port towards another switch of the stand: it shows one of that switch's own MACs, or it was found so before and
       that switch is silent now */
    protected function links(array $ports): array
    {
        $links = [];
        foreach ($ports as $key => $p)
        {
            if (!$p['port']['up'])
                continue;
            foreach ($this->parts as $id => $other)
                if ($id !== $p['part']['id'] && (array_intersect($p['macs'], $other['own_macs'])
                        || (!$other['answering'] && ($p['part']['links'][$p['port']['ifIndex']] ?? null) === $id)))
                {
                    $links[$key] = $id;
                    break;
                }
        }
        return $links;
    }

    /* Every module MAC seen anywhere: the port it belongs to (fewest addresses; on a tie the one where all others are
       modules too; otherwise none) and every port that lists it */
    protected function owners(array $ports, array $uplinks): array
    {
        $owners = [];
        foreach ($this->modules as $mac => $cm)
        {
            $seen = $candidates = [];
            foreach ($ports as $key => $p)
                if (in_array($mac, $p['macs'], true))
                {
                    $seen[] = $key;
                    if ($p['port']['up'] && !$uplinks[$key])
                        $candidates[$key] = $p['port']['mac_count'] ?? count($p['macs']);
                }
            if (!$seen)
                continue;
            $owner = null;
            if ($candidates)
            {
                $fewest = array_keys($candidates, min($candidates), true);
                if (count($fewest) > 1)
                    $fewest = array_values(array_filter($fewest, function ($key) use ($ports) { return $this->foreign($ports[$key]) === 0; }));
                $owner = count($fewest) === 1 ? $fewest[0] : null;
            }
            $owners[$mac] = ['key' => $owner, 'seen' => $seen];
        }
        return $owners;
    }

    /* Addresses on a port that are not modules */
    protected function foreign(array $p): int
    {
        $modules = count(array_filter($p['macs'], function ($mac) { return isset($this->modules[$mac]); }));
        return ($p['port']['mac_count'] ?? count($p['macs'])) - $modules;
    }
```

`tile()` получает `(string $key, array $p, array $owners, array $links, array $uplinks)`:
- модули порта — MAC модулей, чей `owners[mac]['key'] === $key` (затем `present()`, сортировка как сейчас);
- MAC модулей других портов (или без порта) не входят ни в `modules`, ни в `macs`, ни в `others`: `others = mac_count − свои модули − чужие модули`;
- состояние: `!up` → `free`; `$uplinks[$key]` → `uplink`; есть модули → `module`; иначе по оставшимся `macs` как сейчас (`boot`/`foreign`/`quiet`);
- новые поля: `'key' => $key, 'switch_id' => $p['part']['id'], 'switch' => $p['part']['name'], 'label' => trim($p['part']['name'].' '.$port['name']), 'board_port' => $port['alias'] !== '' ? $port['alias'] : $port['name'], 'board' => trim($p['part']['name'].' '.($port['alias'] !== '' ? $port['alias'] : $port['name'])), 'link' => isset($links[$key]) ? $this->parts[$links[$key]]['name'] : null`;
- `short` у линка — `'→'`.

`layout()` — сегодняшний код раскладки (`usort`, `$withPoe`, `aside`, блоки по `BLOCK`), применённый к портам одного коммутатора, возвращает `[$blocks, $main, $aside, $withPoe]`. `summary($main, $tiles)` — как сейчас, без `composition`. `restartBlocker()` перед проверкой аплинка:

```php
        if ($tile['link'] ?? null)
            return $tile['label'].' is the link to '.$tile['link'];
```

`Stand::current()`: `new StandMap(...)` → `StandMap::of($state['snapshot'], ...)` (до задачи 4).

`ports.blade.php`: `{{ $s['composition'] }}` → `{{ reset($map['switches'])['composition'] }}` (до задачи 6).

- [ ] **Step 4: Ключи в остальных тестах**

`p<n>` → `s1p<n>` в `PortsPageTest`, `PoeRestartTest`, `PoeRestartPagesTest`, `PoeOutagesTest` (вызовы `select('p8')`, `ports(['p8'])`, ожидания ключей). `Ports::select()` принимает `/^s\d+p\d+$/`.

- [ ] **Step 5: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'StandMapTest|PortsPageTest|PoeRestartTest|PoeRestartPagesTest|PoeOutagesTest'`
Expected: PASS.

- [ ] **Step 6: Commit** — «Stand map over several switches: links between them, each module on one port».

---

### Task 4: Stand по всем коммутаторам; перезапуск, карточка, плашка и дашборд по коммутатору

**Files:**
- Modify: `app/Services/Switch/Stand.php`
- Modify: `app/Services/Switch/PoeRestart.php`
- Modify: `app/Models/PoeOutage.php`, `app/Http/Livewire/PoeOutages.php`
- Modify: `app/Http/Livewire/CmCard.php`, `app/Http/Livewire/Ports.php`, `app/Http/Livewire/ProvisioningStatus.php`
- Modify: `tests/Support/PoeStand.php` (sysName `sw01`, второй коммутатор), `tests/Feature/{PoeRestartTest,PoeOutagesTest,PoeRestartPagesTest,PortsPageTest,ProvisioningStatusTest}.php`
- Create: `tests/Feature/StandTest.php`

**Interfaces:**
- Consumes: `EthernetSwitch` (Task 1), `SnapshotCache::current($notOlderThan, $wait)` (Task 2), `new StandMap($parts, …)` (Task 3).
- Produces:
  - `Stand::current(?int $notOlderThan = null, ?float $wait = null, iterable $extra = []): ?array` → `['switches' => [id => ['switch' => EthernetSwitch, 'config' => array, 'snapshot' => ?array, 'error' => ?array, 'checked_at' => ?int]], 'map' => ?array]`; `$extra` — модули без строки в базе (старт нового модуля);
  - `Stand::answering(array $state): bool`; `Stand::tileOf(?array $map, Cm $cm): ?array` — плитка-владелец;
  - после каждого удачного опроса строка коммутатора получает `sys_name`, `base_mac`, `own_macs`, `method_detected` из снимка и `links` из карты;
  - `PoeRestart::LOCK` = `'poe-restart'`, замок коммутатора — `PoeRestart::LOCK.':'.$id`;
  - итог `PoeRestart`: `restarted`, `unconfirmed`, `failed`, `stayed_off` — `[ключ плитки => ['label' => string, 'serial' => ?string, 'why' => ?string]]`; `skipped` — `[что => почему]`; `busy` — `false` или имя коммутатора;
  - `PoeOutage::closeSeenOn(SnmpClient $snmp, EthernetSwitch $switch)`; `PoeOutage::forSwitch(EthernetSwitch $switch)` (scope).

- [ ] **Step 1: Тесты**

`tests/Support/PoeStand.php`: в таблицу фейка `SwitchSnapshot::OID_SYSNAME.'.0' => 'sw01'`; новый метод:

```php
    /* A second switch at 192.0.2.11 (sw02) with a failed module on its GE0/0/3; both answer from their own fakes */
    protected function secondSwitch(array $columns = ['write_community' => 'private']): Cm
    {
        $t = [SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5731-S24P4X', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw02'];
        foreach ([6 => 'GE0/0/1', 8 => 'GE0/0/3'] as $i => $name)
            $t += [SwitchSnapshot::OID_IFNAME.'.'.$i => $name, SwitchSnapshot::OID_IFTYPE.'.'.$i => 6,
                   SwitchSnapshot::OID_IFCONNECTOR.'.'.$i => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.'.$i => 1,
                   SwitchSnapshot::OID_IFHIGHSPEED.'.'.$i => 1000,
                   Poe::OID_HW_POWER_STATUS.'.'.$i => $i === 8 ? 'Powered' : 'Detecting',
                   Poe::OID_HW_CONSUMING.'.'.$i => $i === 8 ? 6900 : 0, Poe::OID_HW_ENABLE.'.'.$i => 1];
        $t[MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0'] = 8;   // e4:5f:01:62:9f:d5
        $this->fake2 = new FakeSnmp(['' => $t], '192.0.2.11');
        EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'public'] + $columns);
        $this->bindSwitches(['192.0.2.10' => $this->fake, '192.0.2.11' => $this->fake2]);
        return Cm::create(['serial' => '10000000e611110e', 'mac' => 'e4:5f:01:62:9f:d5', 'phase' => 'failed',
            'phase_detail' => 'Missing eMMC/SD card.', 'provisioning_board' => 'sw02 GE0/0/3', 'switch_name' => 'sw02',
            'switch_port' => 'GE0/0/3', 'provisioning_started_at' => now()->subMinutes(6), 'phase_started_at' => now()->subMinutes(5)]);
    }
```

(трейт подключает `FakeSwitches`, свойство `protected $fake2`.)

`tests/Feature/StandTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\EthernetSwitch;
use App\Services\Switch\Stand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PoeStand;
use Tests\TestCase;

/** Every switch of the stand in one map; each keeps what it said about itself */
class StandTest extends TestCase
{
    use RefreshDatabase, PoeStand;

    public function test_every_switch_is_read_and_remembers_what_it_said()
    {
        $this->stand();
        $this->secondSwitch();

        $stand = Stand::current();

        $this->assertSame([1, 2], array_keys($stand['switches']));
        $this->assertSame(['sw01', 'sw02'], array_column($stand['map']['switches'], 'name'));
        $this->assertArrayHasKey('s2p8', $stand['map']['tiles']);
        $this->assertSame(['sw01', 'huawei'], [EthernetSwitch::find(1)->sys_name, EthernetSwitch::find(1)->method_detected]);
    }

    public function test_a_silent_switch_does_not_hide_the_other()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->timeout = true;

        $stand = Stand::current();

        $this->assertFalse(Stand::answering($stand['switches'][2]));
        $this->assertTrue(Stand::answering($stand['switches'][1]));
        $this->assertSame('module', $stand['map']['tiles']['s1p8']['state']);
    }
}
```

`tests/Feature/PoeRestartTest.php` — ожидания под новый вид итога (ключ плитки, `label`, `sw01 GE0/0/3` в текстах) и новые тесты:

```php
    public function test_two_switches_with_the_same_port_names_restart_apart()
    {
        $one = $this->stand();
        $two = $this->secondSwitch();

        $result = PoeRestart::modules([$one, $two], 'Igor');

        $this->assertSame(['s1p8', 's2p8'], array_keys($result['restarted']));
        $this->assertSame(['sw01 GE0/0/3', 'sw02 GE0/0/3'], array_column($result['restarted'], 'label'));
        $this->assertSame(1, PoeOutage::where('switch_id', 2)->count());
        $this->assertStringContainsString('sw01 GE0/0/3, sw02 GE0/0/3', PoeRestart::message($result)['text']);
    }

    public function test_a_silent_switch_skips_only_its_own_ports()
    {
        $one = $this->stand();
        $two = $this->secondSwitch();
        Stand::current();                 // both answered once
        $this->fake2->timeout = true;
        Carbon::setTestNow(now()->addSeconds(6));

        $result = PoeRestart::modules([$one, $two], 'Igor');

        $this->assertSame(['s1p8'], array_keys($result['restarted']));
        $this->assertSame('sw02 does not answer', $result['skipped'][$two->serial]);
    }

    public function test_a_restart_on_one_switch_leaves_the_other_free()
    {
        $one = $this->stand();
        $two = $this->secondSwitch();
        $lock = Cache::lock(PoeRestart::LOCK.':2', 60);
        $lock->get();
        try
        {
            $this->assertSame(['s1p8'], array_keys(PoeRestart::modules([$one], 'Igor')['restarted']));
            $this->assertSame('sw02', PoeRestart::modules([$two], 'Igor')['busy']);
        }
        finally
        {
            $lock->release();
        }
    }
```

`tests/Feature/PoeOutagesTest.php`:

```php
    protected function leftOff(array $columns): PoeOutage
    {
        return PoeOutage::create($columns + ['if_index' => 8, 'port' => 'sw02 GE0/0/3', 'oid' => Poe::OID_HW_ENABLE.'.8',
            'on_value' => 1, 'off_at' => now()->subMinutes(5), 'error' => 'timeout']);
    }

    public function test_power_on_finds_the_switch_of_the_row()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->tables[''][Poe::OID_HW_ENABLE.'.8'] = 2;
        $outage = $this->leftOff(['switch_id' => 2, 'switch_host' => '192.0.2.11']);

        Livewire::test(PoeOutages::class)->call('powerOn', $outage->id)->assertSee('PoE on sw02 GE0/0/3 is on again.');

        $this->assertSame(['.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'i', 1], end($this->fake2->sets));
        $this->assertSame([], $this->fake->sets, 'the other switch is not touched');
    }

    public function test_a_row_from_before_2_4_finds_its_switch_by_address_and_one_of_a_removed_switch_says_so()
    {
        $this->stand();
        $this->secondSwitch();
        $old = $this->leftOff(['switch_host' => '192.0.2.11']);
        $gone = $this->leftOff(['switch_host' => '192.0.2.99']);

        Livewire::test(PoeOutages::class)->call('powerOn', $old->id)->assertSee('is on again')
            ->call('powerOn', $gone->id)->assertSee('The switch is gone: switch sw02 GE0/0/3 on at the switch.');
    }

    public function test_rows_close_only_on_their_own_switch()
    {
        $this->stand();
        $this->secondSwitch();
        $two = $this->leftOff(['switch_id' => 2, 'switch_host' => '192.0.2.11']);

        PoeOutage::closeSeenOn($this->fake, EthernetSwitch::find(1));   // sw01 reads its own .8 as on
        $this->assertNull($two->fresh()->on_at);
        PoeOutage::closeSeenOn($this->fake2, EthernetSwitch::find(2));
        $this->assertNotNull($two->fresh()->on_at);
    }
```

`tests/Feature/ProvisioningStatusTest.php`:

```php
    public function test_restart_is_offered_by_the_write_access_of_the_module_s_switch()
    {
        $this->stand(['snmp_write_community' => 'private']);                 // sw01 may write
        $this->secondSwitch(['write_community' => null]);                    // sw02 may not
        $batch = NotificationBatch::startNew();
        $one = Cm::where('serial', '10000000e611110c')->first();
        $one->update(['switch_id' => 1, 'notification_batch_id' => $batch->id]);
        $two = Cm::where('serial', '10000000e611110e')->first();
        $two->update(['switch_id' => 2, 'notification_batch_id' => $batch->id]);

        $html = Livewire::test(ProvisioningStatus::class)->html();

        $this->assertStringContainsString('restart('.$one->id.')', $html);
        $this->assertStringNotContainsString('restart('.$two->id.')', $html);
        $this->assertStringContainsString('Restart failed (1)', $html);
    }

    public function test_a_stand_of_two_24_port_switches_fits_the_panel()
    {
        $batch = NotificationBatch::startNew();
        for ($i = 1; $i <= 60; $i++)
            Cm::create(['serial' => sprintf('1000000000%06d', $i), 'mac' => sprintf('e4:5f:01:00:%02x:%02x', intdiv($i, 256), $i % 256),
                'phase' => 'write', 'provisioning_started_at' => now()->subMinutes($i), 'notification_batch_id' => $batch->id]);

        $html = Livewire::test(ProvisioningStatus::class)->html();
        $this->assertStringContainsString('1000000000000060', $html, 'the first module of 60 is not cut off');
    }
```

(Если в `ProvisioningStatusTest` нет `PoeStand`, подключить трейт; `NotificationBatch::startNew()` — существующий метод.)

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'StandTest|PoeRestartTest|PoeOutagesTest|ProvisioningStatusTest'`
Expected: FAIL.

- [ ] **Step 3: Stand**

```php
    public static function current(?int $notOlderThan = null, ?float $wait = null, iterable $extra = []): ?array
    {
        $switches = EthernetSwitch::orderBy('id')->get();
        if ($switches->isEmpty())
            return null;
        $states = [];
        foreach ($switches as $switch)
        {
            $config = $switch->config();
            $state = (new SnapshotCache($config['host'], function () use ($config) {
                return SwitchSnapshot::forConfig($config)->take();
            }))->current($notOlderThan, $wait ?? SnapshotCache::LOCK_WAIT);
            if ($state['snapshot'] && !$state['error'])
                self::remember($switch, $state['snapshot']);
            $states[$switch->id] = ['switch' => $switch, 'config' => $switch->config(), 'snapshot' => $state['snapshot'],
                                    'error' => $state['error'], 'checked_at' => $state['checked_at']];
        }
        $parts = [];
        foreach ($states as $id => $state)
            $parts[] = ['id' => $id, 'name' => $state['switch']->hostname(), 'snapshot' => $state['snapshot'],
                        'answering' => self::answering($state), 'own_macs' => $state['switch']->own_macs ?? [],
                        'links' => $state['switch']->links ?? []];
        $snapshots = array_filter(array_column($states, 'snapshot'));
        $map = $snapshots ? (new StandMap($parts, array_merge(self::modulesOn($snapshots), is_array($extra) ? $extra : iterator_to_array($extra)),
                                          app(NetworkStatus::class)->serverMac()))->build() : null;
        if ($map)
            foreach ($states as $id => $state)
                if (self::answering($state) && ($state['switch']->links ?? []) != $map['switches'][$id]['links'])
                    $state['switch']->forceFill(['links' => $map['switches'][$id]['links']])->save();
        return ['switches' => $states, 'map' => $map];
    }

    public static function answering(array $state): bool
    {
        return $state['snapshot'] !== null && $state['error'] === null;
    }

    /* What the switch said about itself; a base MAC another row holds already stays unset */
    protected static function remember(EthernetSwitch $switch, array $snapshot): void
    {
        $switch->sys_name = $snapshot['identity']['name'] ?? $switch->sys_name;
        $switch->own_macs = $snapshot['own_macs'] ?? $switch->own_macs;
        if ($snapshot['mac_method'] ?? null)
            $switch->method_detected = $snapshot['mac_method'];
        $mac = $snapshot['identity']['mac'] ?? null;
        if ($mac && !EthernetSwitch::where('base_mac', $mac)->whereKeyNot($switch->id)->exists())
            $switch->base_mac = $mac;
        if ($switch->isDirty())
            $switch->save();
    }

    public static function tileOf(?array $map, Cm $cm): ?array
    {
        $key = $map && $cm->mac ? ($map['owners'][strtolower($cm->mac)]['key'] ?? null) : null;
        return $key ? $map['tiles'][$key] : null;
    }
```

`modulesOn(array $snapshots)` — как сейчас, по портам всех снимков.

- [ ] **Step 4: PoeRestart по коммутаторам**

`start($user, $choose)`:

```php
        $stand = Stand::current();
        if (!$stand)
            return ['error' => 'No switch is set up'] + $result;
        if (!$stand['map'])
            return ['error' => 'No switch answers'] + $result;
        [$tiles, $result['skipped']] = $choose($stand['map']);
        $groups = [];
        foreach ($tiles as $key => $tile)
        {
            $why = self::switchBlocker($stand['switches'][$tile['switch_id']] ?? null);
            if ($why !== null)
                $result['skipped'][$tile['serial'] ?? $tile['label']] = $why;
            else
                $groups[$tile['switch_id']][$key] = $tile;
        }
        if (!$groups)
            return $result;
        ksort($groups);
        $locks = [];
        foreach (array_keys($groups) as $id)
        {
            $lock = Cache::lock(self::LOCK.':'.$id, self::LOCK_SECONDS);
            if (!$lock->get())
            {
                foreach ($locks as $held)
                    $held->release();
                return ['busy' => $stand['switches'][$id]['switch']->hostname()] + $result;
            }
            $locks[] = $lock;
        }
        try
        {
            @set_time_limit(self::LOCK_SECONDS);
            $runs = [];
            foreach ($groups as $id => $group)
            {
                $state = $stand['switches'][$id];
                $names = array_map(function ($port) { return $port['name']; }, $state['snapshot']['ports']);
                try
                {
                    $admin = (new Poe(app(SnmpClient::class, ['config' => $state['config']])))->admin($names);
                }
                catch (\Throwable $e)
                {
                    foreach ($group as $tile)
                        $result['skipped'][$tile['serial'] ?? $tile['label']] = $state['switch']->hostname().' does not answer: '.$e->getMessage();
                    continue;
                }
                $runs[] = [new self(PoeControl::forConfig($state['config']), $state['switch'], $user), $group, $admin];
            }
            return self::runAll($runs, $result);
        }
        finally
        {
            foreach ($locks as $held)
                $held->release();
        }
```

```php
    /* Why no port of this switch is restarted now; null when they may be */
    protected static function switchBlocker(?array $state): ?string
    {
        if (!$state)
            return 'the switch is gone';
        $name = $state['switch']->hostname();
        if (!PoeControl::writable($state['config']))
            return 'no write access on '.$name;
        if (!Stand::answering($state))
            return $name.' does not answer';
        $age = now()->getTimestamp() - (int) $state['checked_at'];
        return $age > self::SNAPSHOT_SECONDS ? $name.' was last read '.$age.' s ago' : null;
    }
```

`runAll($runs, $result)`: для каждого запуска выключить его порты (`switchOff`), затем одна пауза `OFF_SECONDS`, затем каждый запуск включает свои порты с шагом `STAGGER_MS` между портами одного коммутатора. Экземпляр: `__construct(PoeControl $control, EthernetSwitch $switch, ?string $user)`; строка `poe_outages` получает `switch_id`, `switch_host`, `port => $tile['label']`. Все `$tile['name']` в итогах, сообщениях и `log()` → `$tile['label']`; итоги ключуются `$tile['key']` с `['label', 'serial'|'why']`. `message()` читает `label`; `busy` — «A restart is already running on <имя>.» `ports(array $keys)` пропускает ключ без плитки с «no such port».

`StandMap::restartBlocker()`: все тексты с `$tile['label']` вместо `$tile['name']`.

- [ ] **Step 5: Плашка, карточка, страница, дашборд**

`PoeOutage`:

```php
    /* Rows of this switch: by its id, and rows from before 2.4 by its address */
    public function scopeForSwitch($query, EthernetSwitch $switch)
    {
        return $query->where(function ($q) use ($switch) {
            $q->where('switch_id', $switch->id)->orWhere(function ($q) use ($switch) {
                $q->whereNull('switch_id')->where('switch_host', $switch->host);
            });
        });
    }

    public static function closeSeenOn(SnmpClient $snmp, EthernetSwitch $switch): void
    {
        foreach (self::stayedOff()->forSwitch($switch)->get() as $outage) { /* как сейчас */ }
    }
```

`PoeOutages::powerOn()`:

```php
        $switch = $outage->switch_id ? EthernetSwitch::find($outage->switch_id) : EthernetSwitch::where('host', $outage->switch_host)->first();
        if (!$switch)
            return $this->say('The switch is gone: switch '.$outage->port.' on at the switch.', true);
        $control = PoeControl::forConfig($switch->config());
        if (!$control)
            return $this->say('No write access on '.$switch->hostname().': set it under Settings → Ethernet switches.', true);
```

`CmCard::power()`:

```php
        $stand = Stand::current();
        if (!$stand)
            return null;
        $tile = Stand::tileOf($stand['map'], $cm);
        $state = $tile ? $stand['switches'][$tile['switch_id']] : null;
        $silent = implode(', ', array_map(function ($s) { return $s['switch']->hostname(); },
            array_filter($stand['switches'], function ($s) { return !Stand::answering($s); })));
        if (!$tile)
            $blocker = $silent !== '' ? 'not seen on the switches ('.$silent.' does not answer)' : 'not seen on the switch';
        elseif (!PoeControl::writable($state['config']))
            $blocker = 'no write access on '.$tile['switch'].' (Settings → Ethernet switches)';
        elseif (!Stand::answering($state))
            $blocker = $tile['switch'].' does not answer';
        else
            $blocker = StandMap::restartBlocker($tile);
        return ['port' => $tile['label'] ?? null, 'poe' => $tile['poe'] ?? null, 'blocker' => $blocker,
                'confirm' => PoeRestart::confirm($cm, $tile['label'] ?? null)];
```

`powerPending` — `!$this->powerLoaded && EthernetSwitch::exists()`.

`Ports::render()` (временный вид до задачи 6): `closeSeenOn` для каждого отвечающего коммутатора; `header`, `snapshot`, `error` — первого; `writable` — у любого коммутатора есть запись; `confirm` с `$tile['label']`.

`ProvisioningStatus`: `MAX_ROWS = 100`; кнопка Restart и счётчик учитывают модуль, если у его коммутатора (`switch_id`) есть запись, а без `switch_id` — если запись есть хоть у одного коммутатора:

```php
        $writable = EthernetSwitch::all()->filter(function ($s) { return PoeControl::writable($s->config()); })->modelKeys();
        $canRestart = function (Cm $m) use ($writable) {
            return $m->switch_id ? in_array($m->switch_id, $writable, true) : (bool) $writable;
        };
```

- [ ] **Step 6: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'StandTest|StandMapTest|PoeRestartTest|PoeOutagesTest|PoeRestartPagesTest|PortsPageTest|ProvisioningStatusTest|CmCard'`
Expected: PASS. Затем `bash "$DEV/suite-ok.sh"`.

- [ ] **Step 7: Commit** — «Stand of several switches: restart, outages, module card and dashboard by switch».

---

### Task 5: Порт модуля при старте — через карту стенда

**Files:**
- Create: `app/Services/Switch/PortLookup.php`, `tests/Feature/PortLookupTest.php`
- Modify: `app/Http/Controllers/ScriptExecuteController.php`
- Modify: `app/Services/SwitchPortFinder.php` (убрать `portOf()`, `label()`, `lastMethod()`, `fromSettings()`; `scan()` остаётся)
- Modify: `tests/Unit/SwitchPortFinderTest.php` (тесты методов через `scan()`; тесты выбора порта уехали в `StandMapTest` и `PortLookupTest`)
- Modify: `tests/Feature/ScriptExecuteRobustnessTest.php`

**Interfaces:**
- Consumes: `Stand::current($notOlderThan, $wait, $extra)`, `map['owners']`, плитки с `board`, `switch_id`, `link`.
- Produces: `PortLookup::WAIT = 10`; `PortLookup::find(string $serial, string $mac, int $since): array` → `['switch' => ?EthernetSwitch, 'port' => ?string, 'board' => ?string, 'warning' => ?string]`.

- [ ] **Step 1: Тесты**

`tests/Feature/PortLookupTest.php` (через `PoeStand` и `secondSwitch()`; модуль на старте — без строки в базе):

```php
    public function test_a_module_on_the_second_switch_gets_its_port()
    {
        $this->stand();
        $this->secondSwitch();
        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());
        $this->assertSame(['sw02 GE0/0/3', 'GE0/0/3', 2, null],
            [$found['board'], $found['port'], $found['switch']->id, $found['warning']]);
    }

    public function test_a_module_behind_the_link_gets_the_port_of_its_own_switch()
    {
        $this->stand();
        $this->secondSwitch(['own_macs' => ['00:57:d2:97:a1:c0']]);
        // sw01's GE0/0/2 leads to sw02: sw02's SVI MAC and its module are there; on sw02 the module is alone on GE0/0/3
        // and sw02 lists one more device, so both ports would hold two addresses without the link
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.10.0'] = 7;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.0.87.210.151.161.192.10.0'] = 7;
        $this->fake2->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.170.0.0.0.0.1.10.0'] = 8;   // aa:00:00:00:00:01 beside it

        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());

        $this->assertSame('sw02 GE0/0/3', $found['board'], 'without the link sw01 GE0/0/2 would tie and win');
        $this->assertSame('sw02', Stand::current()['map']['tiles']['s1p7']['link']);
    }

    public function test_a_silent_neighbour_leaves_its_modules_without_a_board()
    {
        $this->stand();
        $this->secondSwitch(['links' => null]);
        EthernetSwitch::find(1)->forceFill(['links' => [30 => 2]])->save();   // XGE0/0/1 was found leading to sw02
        $this->fake2->timeout = true;
        $this->fake->tables[''][MacTable::OID_HUAWEI_FDB_PORT.'.228.95.1.98.159.213.1.0'] = 30;
        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:62:9f:d5', now()->getTimestamp());
        $this->assertNull($found['board']);
        $this->assertStringContainsString('seen behind sw01 XGE0/0/1 (link to sw02)', $found['warning']);
        $this->assertStringContainsString('sw02 does not answer', $found['warning']);
    }

    public function test_a_silent_switch_is_named_even_when_the_other_found_the_port()
    {
        $this->stand();
        $this->secondSwitch();
        $this->fake2->timeout = true;
        $found = PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp());
        $this->assertSame('sw01 GE0/0/3', $found['board']);
        $this->assertStringContainsString('sw02 does not answer', $found['warning']);
    }

    public function test_an_unknown_mac_names_the_switches_asked()
    {
        $this->stand();
        $found = PortLookup::find('10000000e611110f', 'e4:5f:01:00:00:99', now()->getTimestamp());
        $this->assertNull($found['board']);
        $this->assertSame('Switch port unknown: sw01 does not know MAC e4:5f:01:00:00:99', $found['warning']);
    }

    public function test_starts_close_together_read_the_switch_once()
    {
        $this->stand();
        PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp());
        $walks = count($this->fake->walks);
        PortLookup::find('10000000e611110c', 'e4:5f:01:62:9f:d3', now()->getTimestamp() - 1);
        $this->assertSame($walks, count($this->fake->walks));
    }
```

`ScriptExecuteRobustnessTest::test_unreachable_snmp_switch_does_not_stop_provisioning` — коммутатор строкой `EthernetSwitch::create(['host' => '192.0.2.1', 'community' => 'public'])`; ожидания прежние (плата пуста, предупреждение с `192.0.2.1`).

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'PortLookupTest'`
Expected: FAIL — нет `PortLookup`.

- [ ] **Step 3: PortLookup**

```php
<?php

namespace App\Services\Switch;

use App\Models\Cm;

/* The port of a module that has just asked for its script: the stand map's port for its MAC (Stand, the map every page
   uses), as a board "hostname port"; a warning names switches that did not answer */
class PortLookup
{
    /* A module start waits this long for another request's poll of a switch; a page waits a second */
    const WAIT = 10;

    public static function find(string $serial, string $mac, int $since): array
    {
        $mac = strtolower($mac);
        $module = new Cm(['serial' => $serial, 'mac' => $mac]);
        $stand = Stand::current(null, self::WAIT, [$module]);
        if (!$stand)
            return ['switch' => null, 'port' => null, 'board' => null, 'warning' => null];
        if (!isset($stand['map']['owners'][$mac]))
            $stand = Stand::current($since, self::WAIT, [$module]);   // the switches learnt it after their last poll

        $silent = [];
        foreach ($stand['switches'] as $state)
            if (!Stand::answering($state))
                $silent[] = $state['switch']->hostname().' does not answer'.($state['error'] ? ' ('.$state['error']['message'].')' : '');
        $silentText = $silent ? implode('; ', $silent) : null;
        $owner = $stand['map']['owners'][$mac] ?? null;
        $tile = $owner && $owner['key'] ? $stand['map']['tiles'][$owner['key']] : null;

        if ($tile && !($silent && $tile['others'] > 0))
        {
            $switch = $stand['switches'][$tile['switch_id']]['switch'];
            return ['switch' => $switch, 'port' => $tile['board_port'], 'board' => $tile['board'], 'warning' => $silentText];
        }
        if ($owner && !$owner['key'] && !$silent && count($owner['seen']) > 1)
            return ['switch' => null, 'port' => null, 'board' => '[multiple ports]', 'warning' => null];
        if ($owner)
        {
            $where = implode(', ', array_map(function ($key) use ($stand) {
                $t = $stand['map']['tiles'][$key];
                return $t['label'].($t['link'] ? ' (link to '.$t['link'].')' : '');
            }, $owner['seen']));
            return ['switch' => null, 'port' => null, 'board' => null,
                    'warning' => 'Switch port unknown: MAC '.$mac.' seen behind '.$where.($silentText ? '; '.$silentText : '')];
        }
        $asked = array_map(function ($s) { return $s['switch']->hostname(); },
            array_filter($stand['switches'], [Stand::class, 'answering']));
        return ['switch' => null, 'port' => null, 'board' => null,
                'warning' => 'Switch port unknown: '.($asked ? implode(', ', $asked).' '.(count($asked) === 1 ? 'does' : 'do').' not know MAC '.$mac : 'no switch answers')
                    .($silentText ? '; '.$silentText : '')];
    }
}
```

- [ ] **Step 4: Старт модуля**

`ScriptExecuteController::startProvisoning()`: блок с `SwitchPortFinder` заменить:

```php
        $switchWarning = null;
        $found = ['switch' => null, 'port' => null, 'board' => null];
        if ($req->query('mac') && EthernetSwitch::exists())
        {
            try
            {
                $found = PortLookup::find((string) $this->serial, (string) $req->query('mac'), (int) LARAVEL_START);
                $switchWarning = $found['warning'];
            }
            catch (\Throwable $e)
            {
                $switchWarning = 'Switch port unknown: '.$e->getMessage();
            }
            $board = $found['board'];
        }
        elseif ($jumper) { /* как сейчас */ }
```

и в создании `Cm`: `'switch_id' => $found['switch']->id ?? null, 'switch_name' => $found['switch'] ? $found['switch']->hostname() : null, 'switch_port' => $found['port']`.

- [ ] **Step 5: SwitchPortFinder и его тесты**

Убрать `portOf()`, `label()`, `lastMethod()`, `fromSettings()` и их поля; `config()` остаётся мостом до задачи 7. В `SwitchPortFinderTest` тесты методов (Q-BRIDGE, BRIDGE, Cisco IOS по VLAN, HUAWEI-L2IF, HUAWEI-L2MAM, фиксированный метод) проверяют `scan()['ports'][$mac]`; тесты аплинка, описания порта и незнакомого MAC удалить — их поведение держат `StandMapTest` (`test_a_chain_…`, `test_the_board_takes_the_port_description`) и `PortLookupTest`.

- [ ] **Step 6: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'PortLookupTest|SwitchPortFinderTest|ScriptExecute|ProvisioningTest'`, затем `bash "$DEV/suite-ok.sh"`.
Expected: PASS.

- [ ] **Step 7: Commit** — «Module start: its port from the stand map, the board with the switch's name».

---

### Task 6: Страница Ports — блок на коммутатор

**Files:**
- Modify: `app/Http/Livewire/Ports.php`, `resources/views/livewire/ports.blade.php`, `resources/views/livewire/ports-tile.blade.php`, `resources/css/app.css` (если нужны новые правила шапки блока)
- Modify: `tests/Feature/PortsPageTest.php`

**Interfaces:**
- Consumes: `Stand::current()`, `map['switches']`, плитки с `switch`, `label`, `link`.
- Produces: `Ports::header(array $state, string $now)` → строка «hostname · S/N … · MAC … · IP · модель ревизия · polled N s ago · PoE W» для блока; `Ports::select()` принимает `s<id>p<ifIndex>`.

- [ ] **Step 1: Тесты**

Файл подключает трейт `FakeSwitches`; хелпер второго коммутатора:

```php
    /* A second Huawei at 192.0.2.11 called sw02, with a device on GE0/0/3; the first one is called sw01 */
    protected function secondFakeSwitch(bool $silent = false): FakeSnmp
    {
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw01';
        $second = new FakeSnmp(['' => [
            SwitchSnapshot::OID_SYSDESCR.'.0' => 'S5735-S24P4X', SwitchSnapshot::OID_SYSNAME.'.0' => 'sw02',
            SwitchSnapshot::OID_IFNAME.'.8' => 'GE0/0/3', SwitchSnapshot::OID_IFTYPE.'.8' => 6,
            SwitchSnapshot::OID_IFCONNECTOR.'.8' => 1, SwitchSnapshot::OID_IFOPERSTATUS.'.8' => 1,
            SwitchSnapshot::OID_IFHIGHSPEED.'.8' => 1000,
        ]], '192.0.2.11');
        $second->timeout = $silent;
        EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'public']);
        $this->bindSwitches(['192.0.2.10' => $this->fake, '192.0.2.11' => $second]);
        return $second;
    }

    public function test_each_switch_has_its_block_with_its_line_inside()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch();
        Livewire::test(Ports::class)
            ->assertSeeInOrder(['sw01 · 192.0.2.10 · S5731-S24P4X', 'sw02 · 192.0.2.11 · S5735-S24P4X'])
            ->assertSeeHtml('wire:key="s2p8"');
    }

    public function test_a_silent_switch_keeps_its_last_snapshot_in_its_block()
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        Livewire::test(Ports::class);                 // both answer once
        $second->timeout = true;
        Carbon::setTestNow('2026-10-14 10:00:40');
        Livewire::test(Ports::class)
            ->assertSee('not answering since')
            ->assertSee('No response from 192.0.2.11')
            ->assertSeeHtml('wire:key="s2p8"');      // its last snapshot's ports stay
    }

    public function test_a_switch_that_never_answered_shows_a_placeholder()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch(true);
        Livewire::test(Ports::class)
            ->assertSee('Cannot read the switch: No response from 192.0.2.11')
            ->assertDontSeeHtml('wire:key="s2p8"')
            ->assertSeeHtml('wire:key="s1p8"');
    }

    public function test_two_switches_with_one_name_are_warned_about()
    {
        $this->switchWithModule();
        $second = $this->secondFakeSwitch();
        $this->fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'Switch';
        $second->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'Switch';
        Livewire::test(Ports::class)
            ->assertSee('Two switches are called Switch: their boards look the same. Rename one on the switch.');
    }

    public function test_a_port_of_the_second_switch_opens_its_details()
    {
        $this->switchWithModule();
        $this->secondFakeSwitch();
        Livewire::test(Ports::class)
            ->call('select', 's2p8')->assertSet('selected', 's2p8')->assertSee('sw02 GE0/0/3')
            ->call('select', 'p8')->assertSet('selected', null);
    }
```

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'PortsPageTest'`
Expected: FAIL.

- [ ] **Step 3: Компонент**

`render()`:
- `closeSeenOn` для каждого отвечающего коммутатора;
- в шаблон: `switches` — список блоков `[ 'state' => состояние Stand, 'part' => map['switches'][id] ?? null, 'line' => self::header($state, $now) ]`, отсортированный по имени (`strnatcasecmp`), затем по id; `duplicates` — имена, которые носят два и больше коммутатора; `map`, `tile`, `writable` (у любого), `failed`, `restart`;
- `header(array $state, int $now)`: `implode(' · ', array_filter([hostname, 'S/N '.serial, 'MAC '.mac, host, trim(model.' '.revision), 'polled N s ago', 'PoE W']))` — серийный номер, MAC, модель только если коммутатор их назвал; PoE — сумма ватт портов, если в снимке есть PoE.

`select($key)`: `preg_match('/^s\d+p\d+$/', $key)`.

- [ ] **Step 4: Шаблон**

- Верхняя строка над блоками убирается; сводка с чипами остаётся одна.
- Предупреждение `duplicates`: «Two switches are called <имя>: their boards look the same. Rename one on the switch.» (`bg-warn-bg`).
- Для каждого коммутатора — карточка с корпусом `ports-chassis`; `ports-model` слева — строка `line`, справа — `composition`; состояния:
  - свежий: блоки как сейчас;
  - замолчал со снимком: в строке после «polled…» — «As of HH:MM, not answering since HH:MM: <ошибка>», блоки последнего снимка;
  - без снимка: в корпусе текст «Asking the switch…» или «Cannot read the switch: <ошибка>».
- Вердикт сводки: приставка «As of …» появляется, если хоть один коммутатор не отвечает сейчас, и называет его.
- `ports-tile`: `title` и `aria-label` начинаются с `$t['label']`; у линка `short` «→», в `title` «link to <сосед>».
- Панель порта: заголовок — `$tile['label']`.

- [ ] **Step 5: Тесты зелёные; CSS**

Run: `bash "$DEV/tests-ok.sh" 'PortsPageTest|TokenGuardTest|PageLayoutTest'`; при новых классах — `/root/bench/25-css.sh` и сравнение md5 с `public/css/app.css`.
Expected: PASS.

- [ ] **Step 6: Commit** — «Ports: a block for each switch with its line inside».

---

### Task 7: Список коммутаторов в Settings

**Files:**
- Modify: `app/Http/Livewire/SwitchSettings.php`, `resources/views/livewire/switch-settings.blade.php`
- Modify: `app/Services/SwitchPortFinder.php` (убрать `config()`)
- Modify: `tests/Feature/{SwitchSettingsTest,SwitchWriteAccessTest,PoeControlTest}.php`

**Interfaces:**
- Consumes: `EthernetSwitch`, `SnapshotCache` (последний ответ без опроса), `PoeOutage::stayedOff()->forSwitch()`, `PoeRestart::LOCK`.
- Produces: действия Livewire `add()`, `edit(int $id)`, `cancel()`, `test()`, `save()`, `checkWrite()`, `forgetWrite()`, `delete(int $id)`; `#[Locked] public $editingId`.

- [ ] **Step 1: Тесты**

```php
    public function test_switches_are_listed_and_a_second_one_is_added()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'public', 'sys_name' => 'sw01']);
        Livewire::test(SwitchSettings::class)
            ->assertSee('sw01')->assertSee('192.0.2.10')
            ->call('add')->set('host', '192.0.2.11')->set('community', 'public')->call('save')->assertHasNoErrors();
        $this->assertSame(['192.0.2.10', '192.0.2.11'], EthernetSwitch::orderBy('id')->pluck('host')->all());
    }

    public function test_a_secret_stays_with_its_own_switch()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'community' => 'secret-one']);
        $two = EthernetSwitch::create(['host' => '192.0.2.11', 'community' => 'secret-two']);
        $page = Livewire::test(SwitchSettings::class)->call('edit', $two->id);
        $this->assertStringNotContainsString('secret-two', $page->html());
        $this->assertStringNotContainsString('secret-one', $page->html());
        $page->set('method', 'bridge')->call('save')->assertHasNoErrors();
        $this->assertSame('secret-two', $two->fresh()->community, 'left empty: kept for its own switch');
        $page->call('edit', $two->id)->set('host', '192.0.2.12')->call('save')->assertHasErrors(['community']);
    }

    public function test_the_same_switch_under_another_address_is_refused()
    {
        EthernetSwitch::create(['host' => '192.0.2.10', 'base_mac' => '00:57:d2:97:a1:80', 'sys_name' => 'sw01']);
        $fake = $this->fakeSwitch();   // answers any address: here 192.0.2.20, the same switch
        $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        Livewire::test(SwitchSettings::class)->call('add')->set('host', '192.0.2.20')->set('community', 'public')->call('save')
            ->assertHasErrors(['host'])->assertSee('This is sw01 (192.0.2.10) under another address');
    }

    public function test_a_switch_with_a_port_left_off_cannot_be_deleted()
    {
        $switch = EthernetSwitch::create(['host' => '192.0.2.10']);
        PoeOutage::create(['switch_id' => $switch->id, 'switch_host' => '192.0.2.10', 'if_index' => 8, 'port' => 'sw01 GE0/0/3',
            'oid' => '.1.3.6.1.4.1.2011.5.25.195.3.1.3.8', 'on_value' => 1, 'off_at' => now()->subMinutes(5), 'error' => 'timeout']);
        Livewire::test(SwitchSettings::class)->call('delete', $switch->id)->assertSee('Switch them on (Power on) or forget them first');
        $this->assertSame(1, EthernetSwitch::count());
    }

    public function test_test_and_save_read_the_name_and_base_mac()
    {
        $fake = $this->fakeSwitch();
        $fake->tables[''][SwitchSnapshot::OID_SYSNAME.'.0'] = 'sw03.corp.example.com';
        $fake->tables[''][SwitchSnapshot::OID_BRIDGE_ADDRESS.'.0'] = hex2bin('0057d297a180');
        Livewire::test(SwitchSettings::class)
            ->call('add')->set('host', '192.0.2.13')->set('community', 'public')->call('save')->assertHasNoErrors();
        $switch = EthernetSwitch::sole();
        $this->assertSame(['sw03.corp.example.com', '00:57:d2:97:a1:80', 'sw03'], [$switch->sys_name, $switch->base_mac, $switch->hostname()]);
    }
```

`tests/Feature/SwitchWriteAccessTest.php` (там уже `PoeStand`):

```php
    public function test_check_write_access_takes_its_own_switch_lock()
    {
        $this->stand();
        $lock = Cache::lock(PoeRestart::LOCK.':1', 60);
        $lock->get();
        try
        {
            Livewire::test(SwitchSettings::class)->call('edit', 1)->call('checkWrite')->assertSee('A restart is running');
        }
        finally
        {
            $lock->release();
        }
        Livewire::test(SwitchSettings::class)->call('edit', 1)->call('checkWrite')->assertSee('Write access works');
    }
```

`SwitchWriteAccessTest` и `PoeControlTest` — вместо `SwitchPortFinder::config()` читать `EthernetSwitch::first()->config()`.

- [ ] **Step 2: Запустить, убедиться в провале**

Run: `bash "$DEV/tests-ok.sh" 'SwitchSettingsTest|SwitchWriteAccessTest'`
Expected: FAIL.

- [ ] **Step 3: Компонент**

- `mount()`: форма закрыта; `render()` отдаёт `switches` (строки с `hostname()`, адресом, версией, `writable`, последним ответом из `SnapshotCache` без опроса: `Cache::get('switch-snapshot:'.$host)`).
- `add()`, `edit($id)` (форма из строки, секреты пустые, `stored` — какие секреты есть), `cancel()`.
- `formConfig()`: секрет из поля или — если адрес и версия совпадают с редактируемой строкой — из этой строки.
- `test()` и `save()`: после проверки таблицы MAC (`scan()`) читают `sysName.0` и `dot1dBaseBridgeAddress.0` тем же клиентом; `save()` отказывает, если `base_mac` есть у другой строки: «This is <hostname> (<host>) under another address»; сохраняет `sys_name`, `base_mac`; смена адреса сбрасывает `method_detected`, `base_mac`, `own_macs`, `links`.
- `checkWrite()` берёт `Cache::lock(PoeRestart::LOCK.':'.$id, 60)`; `forgetWrite()` чистит `write_*` редактируемой строки.
- `delete($id)`: при `PoeOutage::stayedOff()->forSwitch($switch)->exists()` — «Switch them on (Power on) or forget them first»; иначе удаляет.
- `SwitchPortFinder::config()` удалить; `grep -rn "SwitchPortFinder::config" app tests` пусто.

- [ ] **Step 4: Шаблон**

Список (таблица `cmp-table`): Name, Address, SNMP, Write access, State, Actions (Test, Check write access, Edit, Delete с `wire:confirm`); предупреждение об одинаковых именах; «Add switch»; форма — нынешние поля в карточке «New switch» / «Edit switch».

- [ ] **Step 5: Тесты зелёные**

Run: `bash "$DEV/tests-ok.sh" 'SwitchSettingsTest|SwitchWriteAccessTest|PoeControlTest|FormFieldsTest|TokenGuardTest'`, затем `bash "$DEV/suite-ok.sh"`.
Expected: PASS.

- [ ] **Step 6: Commit** — «Settings: a list of switches».

---

### Task 8: Уведомления и этикетки

**Files:**
- Modify: `app/Services/Notifier.php`, `app/Http/Controllers/ScriptExecuteController.php` (`printLabel()`), `app/Http/Livewire/Labels.php` (`printTestLabel()`), `resources/views/livewire/editlabel.blade.php` (подсказка плейсхолдеров)
- Modify: `tests/Feature/NotificationsTest.php`, тест этикеток (`tests/Feature/Label*Test.php`)

**Interfaces:**
- Consumes: `cms.switch_name`, `cms.switch_port` (Task 5).
- Produces: в JSON webhook поля `switch`, `port`; плейсхолдеры этикетки `$switch`, `$port`.

- [ ] **Step 1: Тесты**

`tests/Feature/NotificationsTest.php`:

```php
    public function test_the_webhook_names_the_switch_and_the_port()
    {
        $this->activeProject();
        $this->channel('webhook', ['url' => 'https://collector.line.local/x']);
        Http::fake();
        $cm = Cm::create(['serial' => '1000000000000c31', 'mac' => 'e4:5f:01:00:00:31', 'phase' => 'done',
            'provisioning_board' => 'sw02 GE0/0/3', 'switch_name' => 'sw02', 'switch_port' => 'GE0/0/3',
            'provisioning_started_at' => now()->subMinutes(5), 'provisioning_complete_at' => now()]);

        (new Notifier)->notify($cm, 'completed');

        Http::assertSent(function (Request $r) {
            return $r['board'] === 'sw02 GE0/0/3' && $r['switch'] === 'sw02' && $r['port'] === 'GE0/0/3';
        });
    }
```

`tests/Feature/LabelPrintingTest.php`:

```php
    public function test_the_test_label_fills_the_switch_and_the_port()
    {
        Livewire::test(Labels::class)
            ->set('printer_type', 'command')->set('print_command', 'cat $file')
            ->set('template', '$switch|$port|$provisionboard')->set('file_extension', 'txt')
            ->call('printTestLabel')
            ->assertSee('Command output: sw01|Gi0/3|sw01 Gi0/3');
    }

    public function test_a_module_label_fills_the_switch_and_the_port()
    {
        $this->projectPrintingWith('cat $file');
        Label::first()->update(['template' => '$serial|$switch|$port']);
        $this->start('1000000000000e01');   // no switch: both empty
        $this->assertSame(1, Cmlog::where('msg', 'like', '%1000000000000e01||%')->count());
    }
```

- [ ] **Step 2: Провал** — `bash "$DEV/tests-ok.sh" 'NotificationsTest|Label'` → FAIL.

- [ ] **Step 3: Реализация**

`Notifier::payload()`: `'switch' => $cm->switch_name, 'port' => $cm->switch_port`. `printLabel()`: `str_replace(['$provisionboard', '$switch', '$port'], [$cm->provisioning_board, $cm->switch_name, $cm->switch_port], $label)` (сначала длинное имя). `printTestLabel()`: те же плейсхолдеры с образцами `sw01 Gi0/3`, `sw01`, `Gi0/3` (вместо `000 (0)`). Подсказка в `editlabel.blade.php` перечисляет `$switch` и `$port`.

- [ ] **Step 4: Зелёные** — тот же запуск → PASS.

- [ ] **Step 5: Commit** — «Notifications and labels: the switch and the port apart».

---

### Task 9: Пакет, snmpsim, документы, версия

**Files:**
- Modify: `debian/preinst` (снимок и при обновлении с версии ниже `2.4.0~`)
- Create: `tests/fixtures/snmp/chaina.snmprec`, `tests/fixtures/snmp/chainb.snmprec` (через `tests/fixtures/snmp/generate.py`)
- Modify: `tests/Feature/SnmpAgentTest.php` (пара цепочки)
- Modify: `CLAUDE.md`, `README.md`, `ROADMAP.md`, `docker/README.md`, `docs/superpowers/specs/2026-10-12-poe-restart-design.md`, `debian/changelog` (`2.4.0~dev1`)

- [ ] **Step 1: preinst**

Условие снимка: `dpkg --compare-versions "$2" lt "2.4.0~"` вместо `lt "2.0.0~"`; текст сообщений — «before 2.4» там, где сказано про 2.0. Проверка: `sh -n debian/preinst`; на стенде `/root/bench/28-snapshot-guards.sh` (снимок при обновлении с 2.3.3 берётся).

- [ ] **Step 2: snmpsim**

`generate.py`: `chaina` — коммутатор с `sysName` sw01, модулем на Gi0/1 и портом Gi0/24, где MAC модуля B и SVI-MAC B (`00:57:d2:97:a1:c0`); `chainb` — sw02 с `ifPhysAddress` Vl1 = `00:57:d2:97:a1:c0` и модулем B на Gi0/3. `SnmpAgentTest::test_a_chain_of_two_switches_puts_the_module_on_its_own_switch` снимает оба через `PhpSnmpClient` (одна точка, две community), строит `new StandMap([...])` и проверяет: Gi0/24 sw01 — линк к sw02, модуль B на sw02 Gi0/3.

Run: `bash "$DEV/snmpagent-ok.sh"` → `OK (13 tests …)`.

- [ ] **Step 3: Документы**

- `CLAUDE.md`: ключи `ethernetswitch_*` → таблица `ethernet_switches` (модель `EthernetSwitch`, ключи 2.3 остаются для отката); поиск порта — `PortLookup` через `Stand`; линки и «один порт на модуль» в `StandMap`; `SnapshotCache` с паузой 30 с после ошибки; перезапуск по PoE с замком на коммутатор; тесты (`FakeSwitches`, `PoeStand::secondSwitch()`).
- `README.md`: список коммутаторов в Settings; плата «hostname порт»; откат через `cmprovision-restore` снимка; после отката и нового обновления проверить Settings.
- `ROADMAP.md`: пункт 2.4.0 в «Сделано» (после проверки); новый пункт «2.5: 48 модулей разом» со списком из спеки («Не входит»).
- `docker/README.md`: snmpsim на двух точках для стенда с двумя коммутаторами.
- Спека PoE: заметка «с 2.4.0 Power on и закрытие строк — по коммутатору строки (`switch_id`)».
- `debian/changelog`: запись `2.4.0~dev1`.

- [ ] **Step 4: Полный набор и CSS**

Run: `bash "$DEV/suite-ok.sh"`, `bash "$DEV/snmpagent-ok.sh"`, `/root/bench/25-css.sh` и сравнение md5.
Expected: всё PASS, CSS совпадает (или пересобран и закоммичен).

- [ ] **Step 5: Commit** — «2.4.0~dev1: preinst snapshot before 2.4, snmpsim chain, docs».

---

### Task 10: Проверка на стенде, ревью, выпуск

- [ ] **Step 1: Стенд WSL.** Поставить сборку на стенд (или разложить файлы), snmpsim на `127.0.0.1:1161` и `:1162` (`chaina`, `chainb`), два коммутатора в Settings, временная учётка. Playwright: страница Ports с двумя блоками и строками внутри, светлая и тёмная тема; Settings со списком; карточка модуля со строкой Power (PoE). Убрать за собой учётку и коммутаторы.
- [ ] **Step 2: Ревью.** Свежий ревьюер по всей ветке от 7f8d672 (суперсила requesting-code-review, самая сильная модель). Critical и Important — исправить с тестами, Minor — в итог.
- [ ] **Step 3: Выпуск — только по слову пользователя.** `debian/changelog` 2.4.0, ROADMAP, слияние в `main` (`--no-ff -m "Merge stage2: release 2.4.0"`), тег `v2.4.0` на слиянии с проверкой цели, пуш `main`, `stage2`, тега; сборка на wlanpi-da2 из тега, проверка, что ничего не прошивается, установка; проверка на живом 2960CX: строка коммутатора внутри блока, плата нового модуля «t-iof-sw01-provision Gi0/3».
