# `/scriptexecute` только для сети стенда (2.5.1) — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** адрес модулей `/scriptexecute` отвечает только сети стенда `172.20.0.0/16` и самому серверу, остальным — 403 с причиной; модули перестают заводить сессию на каждый запрос; путь CM3/CM3+ по USB убирается.

**Architecture:** маршрут переезжает из `routes/web.php` в новый `routes/module.php`, который `RouteServiceProvider` подключает с middleware `stand` вместо группы `web`. Middleware `App\Http\Middleware\StandNetworkOnly` смотрит на сырой `REMOTE_ADDR` соединения и пропускает `NetworkStatus::STAND_NETWORK` и loopback. Перезапись `%usb0` в контроллере, блок dhcpcd в `postinst`, секция `[pi3]` и `cmdline.txt.ipv6ll` уходят вместе с CM3.

**Tech Stack:** PHP 8.3+ (тесты на 8.4), Laravel 13 (раскладка Laravel 8: `App\Http\Kernel::$routeMiddleware`, `RouteServiceProvider`), Symfony `IpUtils`, Debian-пакет (debhelper 13), nginx и php-fpm, стенд WSL `cmprov-trixie`.

**Spec:** `docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md`

## Global Constraints

- Сеть стенда — ровно `172.20.0.0/16` (константа `NetworkStatus::STAND_NETWORK`), loopback — `127.0.0.0/8` и `::1`; больше ничего не пропускается, включая любой IPv6 link-local.
- Адрес клиента — сырой `REMOTE_ADDR` (`$request->server('REMOTE_ADDR')`), никогда не `$request->ip()`; `X-Forwarded-For` на решение не влияет.
- `::ffff:a.b.c.d` сводится к `a.b.c.d` до проверки.
- Отказ — HTTP 403, `Content-Type: text/plain`, тело ровно `cmprovision answers /scriptexecute only for the stand network 172.20.0.0/16 and for this server itself; this request came from <адрес>.` и перевод строки; при пустом адресе вместо него — `an unknown address`.
- Отклонённый запрос до контроллера не доходит и ничего не пишет; отказы не логируются.
- Маршрут `/scriptexecute` — без группы `web` (нет `StartSession`, `VerifyCsrfToken`), строки `scriptexecute` в `VerifyCsrfToken::$except` нет.
- Сайт nginx (`debian/cmprovision`, conffile) не меняется. Служба `cmprovision-rpiboot` остаётся. Загрузочные бинарники CM3 в `scriptexecute/` не трогаются.
- Новых зависимостей нет: `IpUtils` — из `symfony/http-foundation`, которая уже стоит у Laravel.
- Код, строки ответа и комментарии — по-английски, в стиле соседнего кода; прозу документов — как в них (CLAUDE.md, ROADMAP — по-русски, README — по-английски).
- Версия `2.5.1~dev1` для сборок стенда, `2.5.1` при выпуске; снимок перед обновлением не нужен (`preinst` снимает его при обновлении с версий ниже 2.5.0).
- Коммиты кончаются строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; слияние, тег, сборка и установка на wlanpi-da2 и пуш — только по слову пользователя (задача 5).

## Review Focus

1. Из офиса отклоняется любой запрос модуля, а не только старт: прогресс, опрос `identify`, завершение, отчёты с логом и с загрузчиком — и ни один не меняет строку модуля и журнал (`test_no_kind_of_module_request_is_answered_to_another_network`, `test_reports_from_another_network_leave_the_module_alone`, задача 2).
2. Адрес в непривычной записи: `::ffff:127.0.0.1` и `::FFFF:172.20.5.5` проходят, `fe80::1%usb0`, `localhost` и пустой адрес — нет (таблица `addresses`, задача 1).
3. Тот же адрес под другим путём: `/scriptexecute/` отклоняется из офиса и работает со стенда (`test_a_trailing_slash_is_the_same_endpoint`, задача 2); `/index.php/scriptexecute` через настоящий nginx — 403 (проверка на стенде, задача 4).
4. После удаления перезаписи `%usb0` скрипт модуля называет сервер так, как модуль к нему пришёл: `http://172.20.0.1/…` для стенда и `http://[::1]/…` для локального запроса по IPv6 (`test_the_script_names_the_server_as_the_module_reached_it`, задача 3).
5. Другие методы HTTP из офиса (`HEAD`, `OPTIONS`, `PUT`, `PATCH`, `DELETE`: маршрут `any`) — тоже 403 без записи в базу (`test_every_method_is_refused_to_another_network`, задача 2).

---

## Карта файлов

- `app/Http/Middleware/StandNetworkOnly.php` (новый) — проверка адреса и ответ 403.
- `app/Services/NetworkStatus.php` — константа `STAND_NETWORK`.
- `routes/module.php` (новый), `routes/web.php`, `app/Providers/RouteServiceProvider.php`, `app/Http/Kernel.php`, `app/Http/Middleware/VerifyCsrfToken.php` — маршрут модулей без группы `web`.
- `app/Http/Controllers/ScriptExecuteController.php` — `moduleServer()` без `%usb0`.
- `debian/postinst` — без блока dhcpcd; `scriptexecute/config.txt` — без `[pi3]`; `scriptexecute/cmdline.txt.ipv6ll` — удаляется.
- `debian/changelog`, `ROADMAP.md`, `README.md`, `CLAUDE.md`.
- Тесты: `tests/Feature/StandNetworkTest.php` (новый), `tests/Unit/PackagingTest.php`.

## Команды

```bash
SP=/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad
DEV=$SP/wsl-bench
cd "/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
bash "$DEV/tests-ok.sh" 'ClassA|ClassB'   # синхронизирует /root/dev (rsync --delete) и гоняет PHP-тесты по регулярке; exit 0 только при «phpunit exit 0»
bash "$DEV/suite-ok.sh"                   # весь набор PHP и JS
```

Стенд засыпает без открытой сессии: перед работой — фоновый `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 14400` (Bash с `run_in_background`). Команды внутри стенда — скриптом через stdin: `cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s` (в аргументах `wsl.exe` переменные `$X` раскрываются раньше времени). Правки документов — Python-скриптом: файл пишется через Write в `$SP`, запускается `python -I <скрипт> "$PWD"` из корня репозитория (heredoc в Bash портит обратные косые черты).

---

### Task 1: Проверка адреса

**Files:**
- Create: `app/Http/Middleware/StandNetworkOnly.php`
- Modify: `app/Services/NetworkStatus.php:17-18` (константы в начале класса)
- Test: `tests/Feature/StandNetworkTest.php` (новый)

**Interfaces:**
- Consumes: —
- Produces: класс `App\Http\Middleware\StandNetworkOnly` с методами `handle(Illuminate\Http\Request $request, Closure $next)` и `public static function allows(?string $address): bool`, константой `LOOPBACK = ['127.0.0.0/8', '::1']`; константа `App\Services\NetworkStatus::STAND_NETWORK = '172.20.0.0/16'`; класс теста `Tests\Feature\StandNetworkTest` с провайдером `addresses()`.

- [ ] **Step 1: Write the failing test**

Создать `tests/Feature/StandNetworkTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\StandNetworkOnly;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /scriptexecute takes no login and the provisioner is reachable from the office over Wi-Fi, so the endpoint answers
 * only the stand network 172.20.0.0/16 and this server itself
 * (docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md).
 */
class StandNetworkTest extends TestCase
{
    public static function addresses(): array
    {
        return [
            'a module' => ['172.20.5.5', true],
            'the first lease' => ['172.20.0.2', true],
            'the last lease' => ['172.20.255.254', true],
            'a module on an IPv6 socket' => ['::ffff:172.20.5.5', true],
            'the mapped prefix in capitals' => ['::FFFF:172.20.5.5', true],
            'this server' => ['127.0.0.1', true],
            'this server by its host name' => ['127.0.1.1', true],
            'this server over IPv6' => ['::1', true],
            'this server on an IPv6 socket' => ['::ffff:127.0.0.1', true],
            'the office Wi-Fi' => ['192.168.68.50', false],
            'the office on an IPv6 socket' => ['::ffff:192.168.68.50', false],
            'the network after the stand' => ['172.21.0.1', false],
            'the network before the stand' => ['172.19.255.255', false],
            'IPv6 link-local' => ['fe80::1', false],
            'IPv6 link-local with its zone' => ['fe80::1%usb0', false],
            'a global IPv6 address' => ['2001:db8::1', false],
            'no address' => ['', false],
            'no address at all' => [null, false],
            'a name, not an address' => ['localhost', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_the_stand_network_and_this_server_get_through($address, $allowed)
    {
        $this->assertSame($allowed, StandNetworkOnly::allows($address));
    }

    public function test_a_refusal_names_the_stand_network_and_where_the_request_came_from()
    {
        $request = Request::create('/scriptexecute', 'GET', [], [], [], ['REMOTE_ADDR' => '192.168.68.50']);
        $response = (new StandNetworkOnly)->handle($request, function () {
            $this->fail('a refused request never reaches the controller');
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame("cmprovision answers /scriptexecute only for the stand network 172.20.0.0/16 and for this server itself;"
                          ." this request came from 192.168.68.50.\n", $response->getContent());
    }

    public function test_a_refusal_without_an_address_says_so()
    {
        $request = Request::create('/scriptexecute', 'GET', [], [], [], ['REMOTE_ADDR' => '']);
        $response = (new StandNetworkOnly)->handle($request, function () {
            $this->fail('a refused request never reaches the controller');
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringEndsWith("this request came from an unknown address.\n", $response->getContent());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest'`
Expected: `phpunit exit 2`, ошибки `Error: Class "App\Http\Middleware\StandNetworkOnly" not found` во всех 21 тестах.

- [ ] **Step 3: Write minimal implementation**

В `app/Services/NetworkStatus.php` после константы `SERVER_ADDRESS` добавить:

```php
    /** the stand network, dnsmasq's range (etc/dnsmasq.conf): /scriptexecute answers only it and this server (StandNetworkOnly) */
    const STAND_NETWORK = '172.20.0.0/16';
```

Создать `app/Http/Middleware/StandNetworkOnly.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Services\NetworkStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The modules' endpoint takes no login, so it answers only the stand network and this server itself
 * (docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md). The client is the connection's own address:
 * a forwarded header never counts, and the office cannot fake a TCP source, since the answer to its SYN would go to the
 * stand. The address a request came to proves nothing (Linux takes a packet for any of its addresses on any
 * interface), and IPv6 link-local is refused: the Wi-Fi uplinks have it too.
 */
class StandNetworkOnly
{
    const LOOPBACK = ['127.0.0.0/8', '::1'];

    public function handle(Request $request, Closure $next)
    {
        $address = (string) $request->server('REMOTE_ADDR');
        if (self::allows($address))
            return $next($request);

        return new Response('cmprovision answers /scriptexecute only for the stand network '.NetworkStatus::STAND_NETWORK
            .' and for this server itself; this request came from '.($address === '' ? 'an unknown address' : $address).".\n",
            403, ['Content-Type' => 'text/plain']);
    }

    /* A module of the stand, or this server; an IPv4 client on an IPv6 socket comes as ::ffff:a.b.c.d */
    public static function allows(?string $address): bool
    {
        $address = (string) $address;
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $m))
            $address = $m[1];

        return $address !== '' && IpUtils::checkIp($address, array_merge([NetworkStatus::STAND_NETWORK], self::LOOPBACK));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest'`
Expected: `phpunit exit 0`, `OK (21 tests, …)`; в выводе нет PHP-предупреждений (`php84-errors.log` пуст): `IpUtils` молча отвечает «нет» на `fe80::1%usb0` и `localhost`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/StandNetworkOnly.php app/Services/NetworkStatus.php tests/Feature/StandNetworkTest.php
git commit -q -F - <<'EOF'
The stand check for /scriptexecute

StandNetworkOnly lets through the stand network 172.20.0.0/16 and this
server itself by the connection's own address, and answers anything else
with a plain-text 403 that says why.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Маршрут модулей без группы `web`

**Files:**
- Create: `routes/module.php`
- Modify: `routes/web.php:14` (импорт) и `routes/web.php:46-47` (маршрут)
- Modify: `app/Providers/RouteServiceProvider.php:40-49`
- Modify: `app/Http/Kernel.php:55-66` (`$routeMiddleware`)
- Modify: `app/Http/Middleware/VerifyCsrfToken.php:14-16`
- Modify: `README.md` (раздел CM4), `CLAUDE.md` (абзац о `/scriptexecute`, список мест с подсетью, раздел тестов)
- Test: `tests/Feature/StandNetworkTest.php`

**Interfaces:**
- Consumes: `StandNetworkOnly`, `StandNetworkOnly::allows()` и `NetworkStatus::STAND_NETWORK` из задачи 1.
- Produces: алиас middleware `stand`; файл `routes/module.php`; в `StandNetworkTest` — `use RefreshDatabase`, константы `SERIAL = '10000000e611110c'`, `START` (старт модуля с `mac`, `model`, `storagesize`), `OFFICE = '192.168.68.50'`, помощники `protected function from(string $address)` (ставит `REMOTE_ADDR`), `protected function activeProject(): Project` (активный проект без образа) и `protected function module(): Cm`.

- [ ] **Step 1: Write the failing tests**

В `tests/Feature/StandNetworkTest.php` заменить всё от первой строки до строки `{` сразу после `class StandNetworkTest extends TestCase` включительно на блок ниже. Методы задачи 1 (`addresses()` и три теста) остаются под ним без изменений:

```php
<?php

namespace Tests\Feature;

use App\Http\Middleware\StandNetworkOnly;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Cm;
use App\Models\Cmlog;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\Middleware\StartSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /scriptexecute takes no login and the provisioner is reachable from the office over Wi-Fi, so the endpoint answers
 * only the stand network 172.20.0.0/16 and this server itself
 * (docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md).
 */
class StandNetworkTest extends TestCase
{
    use RefreshDatabase;

    const SERIAL = '10000000e611110c';
    const START = '/scriptexecute?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3';
    const OFFICE = '192.168.68.50';

    protected function from(string $address)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address]);
    }

    /* the active project, without an image: a module still gets its script */
    protected function activeProject(): Project
    {
        $project = Project::create(['name' => 'stage1-test', 'device' => 'cm4', 'storage' => '/dev/mmcblk0',
                                    'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function module(): Cm
    {
        return Cm::where('serial', self::SERIAL)->firstOrFail();
    }
```

В конец класса (перед последней `}`) добавить:

```php
    public function test_a_module_on_the_stand_network_gets_its_script()
    {
        $this->activeProject();
        $this->from('172.20.5.5')->get(self::START)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('export SERIAL="'.self::SERIAL.'"', false);
        $this->assertSame('172.20.5.5', Cmlog::where('cm', self::SERIAL)->value('ip'));
    }

    public function test_this_server_itself_is_answered()
    {
        $this->activeProject();
        $this->from('127.0.0.1')->get(self::START)->assertOk();
        $this->from('::1')->get(self::START)->assertOk();
    }

    public static function refusedAddresses(): array
    {
        return [
            'the office Wi-Fi' => [self::OFFICE],
            'the network after the stand' => ['172.21.0.1'],
            'the network before the stand' => ['172.19.255.255'],
            'IPv6 link-local' => ['fe80::1'],
            'a global IPv6 address' => ['2001:db8::1'],
            'the office on an IPv6 socket' => ['::ffff:192.168.68.50'],
            'no address' => [''],
        ];
    }

    #[DataProvider('refusedAddresses')]
    public function test_another_network_gets_the_reason_and_nothing_is_stored($address)
    {
        $this->activeProject();
        $this->from($address)->get(self::START)
            ->assertForbidden()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('only for the stand network 172.20.0.0/16', false);
        $this->assertSame(0, Cm::count());
        $this->assertSame(0, Cmlog::count());
    }

    public function test_a_forwarded_header_does_not_open_the_door()
    {
        $this->activeProject();
        $this->from(self::OFFICE)->withHeaders(['X-Forwarded-For' => '172.20.5.5'])->get(self::START)->assertForbidden();
        $this->assertSame(0, Cm::count());
    }

    public function test_reports_from_another_network_leave_the_module_alone()
    {
        $this->activeProject();
        $this->from('172.20.5.5')->get(self::START)->assertOk();
        $before = $this->module()->toArray();
        $logs = Cmlog::count();

        $this->from(self::OFFICE)->post('/scriptexecute?serial='.self::SERIAL.'&retcode=2&phase=postinstall',
            ['log' => UploadedFile::fake()->createWithContent('post.log', "Verification failed\n")])->assertForbidden();
        $this->from(self::OFFICE)->post('/scriptexecute?serial='.self::SERIAL,
            ['eeprom_version' => UploadedFile::fake()->createWithContent('eeprom_version', "2021/02/16 13:19:00\n")])->assertForbidden();

        $this->assertSame($before, $this->module()->toArray());
        $this->assertSame($logs, Cmlog::count());
    }

    public static function moduleQueries(): array
    {
        return [
            'progress' => ['&progress=write&sectors=2048'],
            'identify poll' => ['&identify=poll'],
            'completion' => ['&alldone=1&temp=48.7%27C&verify=0'],
        ];
    }

    #[DataProvider('moduleQueries')]
    public function test_no_kind_of_module_request_is_answered_to_another_network($query)
    {
        $this->activeProject();
        $this->from('172.20.5.5')->get(self::START)->assertOk();
        $before = $this->module()->toArray();
        $logs = Cmlog::count();

        $this->from(self::OFFICE)->get('/scriptexecute?serial='.self::SERIAL.$query)->assertForbidden();

        $this->assertSame($before, $this->module()->toArray());
        $this->assertSame($logs, Cmlog::count());
    }

    public static function methods(): array
    {
        return [['HEAD'], ['OPTIONS'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    #[DataProvider('methods')]
    public function test_every_method_is_refused_to_another_network($method)
    {
        $this->activeProject();
        $this->from(self::OFFICE)->call($method, self::START)->assertForbidden();
        $this->assertSame(0, Cm::count());
    }

    public function test_a_trailing_slash_is_the_same_endpoint()
    {
        $this->activeProject();
        $this->from(self::OFFICE)->get('/scriptexecute/?serial='.self::SERIAL)->assertForbidden();
        $this->assertSame(0, Cm::count());
        $this->from('172.20.5.5')->get('/scriptexecute/?serial='.self::SERIAL.'&model=CM4&storagesize=62500000&mac=e4:5f:01:62:9f:d3')
            ->assertOk();
    }

    public function test_the_module_endpoint_runs_without_the_web_group()
    {
        $router = app('router');
        $route = $router->getRoutes()->match(Request::create('/scriptexecute', 'GET'));
        $middleware = $router->gatherRouteMiddleware($route);

        $this->assertContains(StandNetworkOnly::class, $middleware);
        // a module has no session, cookies or CSRF token: each of its requests used to write a row into sessions
        $this->assertNotContains(StartSession::class, $middleware);
        $this->assertNotContains(VerifyCsrfToken::class, $middleware);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest'`
Expected: `phpunit exit 1`. Падают 19 тестов: семь строк `refusedAddresses`, пять строк `methods`, три строки `moduleQueries`, `test_a_forwarded_header_does_not_open_the_door`, `test_reports_from_another_network_leave_the_module_alone`, `test_a_trailing_slash_is_the_same_endpoint` (все ждут 403, а получают 200) и `test_the_module_endpoint_runs_without_the_web_group`. Проходят 21 тест задачи 1 и два охранных теста этой задачи (`test_a_module_on_the_stand_network_gets_its_script`, `test_this_server_itself_is_answered`): они стерегут то, что уже работает.

- [ ] **Step 3: Write the implementation**

Создать `routes/module.php`:

```php
<?php

use App\Http\Controllers\ScriptExecuteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Module Routes
|--------------------------------------------------------------------------
|
| What the compute modules call: curl in the utility OS on the stand network. Loaded by the RouteServiceProvider with
| the "stand" middleware (App\Http\Middleware\StandNetworkOnly) instead of the "web" group: a module has no session,
| cookies or CSRF token.
|
*/

Route::any('/scriptexecute', ScriptExecuteController::class);
```

В `app/Providers/RouteServiceProvider.php` в `boot()` между группой `api` и группой `web` вставить:

```php
            Route::middleware('stand')
                ->namespace($this->namespace)
                ->group(base_path('routes/module.php'));
```

В `app/Http/Kernel.php` в `$routeMiddleware` после строки `'signed' => …` вставить:

```php
        'stand' => \App\Http\Middleware\StandNetworkOnly::class,
```

В `routes/web.php` удалить строку 14 `use App\Http\Controllers\ScriptExecuteController;`, а в конце файла — пустую строку 46 и строку 47 `Route::any('/scriptexecute', ScriptExecuteController::class);` (файл кончается строкой маршрута `/settings`).

В `app/Http/Middleware/VerifyCsrfToken.php` заменить

```php
    protected $except = [
        'scriptexecute'
    ];
```

на

```php
    protected $except = [];
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest'`
Expected: `phpunit exit 0`, `OK (42 tests, …)`.

- [ ] **Step 5: Документы**

Записать в `$SP/plan251-t2-docs.py` и выполнить `python -I "$SP/plan251-t2-docs.py" "$PWD"`:

```python
"""2.5.1, task 2: README and CLAUDE.md say /scriptexecute answers only the stand network."""
import pathlib
import sys

REPO = pathlib.Path(sys.argv[1])
WARNING = ("**WARNING:** Do NOT connect eth0 to your office/public network, since it may also provision other Raspberry Pi "
           "devices. Instead, use the wireless LAN interface to access the web interface, and your local network.\n")
EDITS = {
    'README.md': [(
        "Make sure eth0 connects to an Ethernet switch that only has the compute modules you wish to program connected.\n\n" + WARNING,
        "Make sure eth0 connects to an Ethernet switch that only has the compute modules you wish to program connected.\n\n" + WARNING
        + "\nThe modules' endpoint `/scriptexecute` takes no login, so it answers only the stand network 172.20.0.0/16 and the "
          "server itself: a request from any other network, the office included, gets 403.\n",
    )],
    'CLAUDE.md': [
        ("Маршрут намеренно открыт без аутентификации и исключён из CSRF (`VerifyCsrfToken::$except`): клиент — `curl` на модуле в изолированной сети.",
         "Маршрут открыт без входа, но только для сети стенда: `routes/module.php` подключается в `RouteServiceProvider` "
         "с middleware `stand` (`App\\Http\\Middleware\\StandNetworkOnly`) вместо группы `web`, поэтому у модуля нет ни сессии "
         "(раньше каждый его запрос писал строку в `sessions`), ни cookie, ни проверки CSRF. Пропускаются "
         "`NetworkStatus::STAND_NETWORK` (`172.20.0.0/16`) и loopback (`127.0.0.0/8`, `::1`: репетиция, дымовой тест стенда "
         "и `simulate-modules.sh` ходят на `127.0.0.1`) по сырому `REMOTE_ADDR` соединения, а не по `$request->ip()`; "
         "остальным, в том числе IPv6 link-local (он есть и у Wi-Fi-аплинков), — 403 `text/plain` с причиной. Обратный "
         "прокси перед nginx стал бы адресом клиента сам и открыл бы маршрут всем."),
        ("`Host::firstAvailableIP()`, `NetworkStatus::SERVER_ADDRESS` и README.",
         "`Host::firstAvailableIP()`, `NetworkStatus::SERVER_ADDRESS`, `NetworkStatus::STAND_NETWORK` (проверка `StandNetworkOnly`) и README."),
        ("- Тесты покрывают провижининг: скрипт модуля и его отчёты, прогресс,",
         "- Тесты покрывают провижининг: скрипт модуля и его отчёты, доступ к `/scriptexecute` только из сети стенда (`StandNetworkTest`), прогресс,"),
    ],
}

for name, edits in EDITS.items():
    path = REPO / name
    text = path.read_text(encoding='utf-8')
    for old, new in edits:
        count = text.count(old)
        if count != 1:
            sys.exit(f'{name}: expected one match, found {count}: {old[:70]!r}')
        text = text.replace(old, new)
    path.write_text(text, encoding='utf-8', newline='\n')
    print(f'{name}: {len(edits)} edits')
```

Expected: `README.md: 1 edits`, `CLAUDE.md: 3 edits`. (Предупреждение `**WARNING:**` есть и в разделе CM3, но строка про eth0 перед ним — только в разделе CM4, поэтому совпадение одно.)

- [ ] **Step 6: Весь набор**

Run: `bash "$DEV/suite-ok.sh"`
Expected: `php rc=0 js rc=0`. Старые тесты модулей не меняются: тестовые запросы Laravel приходят с `127.0.0.1`.

- [ ] **Step 7: Commit**

```bash
git add routes/module.php routes/web.php app/Providers/RouteServiceProvider.php app/Http/Kernel.php \
        app/Http/Middleware/VerifyCsrfToken.php tests/Feature/StandNetworkTest.php README.md CLAUDE.md
git commit -q -F - <<'EOF'
/scriptexecute only for the stand network, without the web group

The modules' route moves to routes/module.php behind the stand
middleware: another network gets a plain-text 403, and a module request
no longer starts a session (it wrote a row into sessions every time) or
passes the CSRF check.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Без CM3 по USB

**Files:**
- Modify: `app/Http/Controllers/ScriptExecuteController.php:117-128` (`moduleServer()`)
- Modify: `debian/postinst:172-178` (блок dhcpcd)
- Modify: `scriptexecute/config.txt:12-16` (секция `[pi3]`)
- Delete: `scriptexecute/cmdline.txt.ipv6ll`
- Modify: `README.md` (строка 8, разделы CM3), `CLAUDE.md` (обзор, пункт CM3, плейсхолдеры)
- Test: `tests/Feature/StandNetworkTest.php`, `tests/Unit/PackagingTest.php`

**Interfaces:**
- Consumes: из задачи 2 — `StandNetworkTest::from()`, `activeProject()`, константа `START`.
- Produces: `ScriptExecuteController::moduleServer(Request $req)` возвращает `(string) $req->server('HTTP_HOST')` без изменений.

- [ ] **Step 1: Write the failing tests**

В `tests/Feature/StandNetworkTest.php` в конец класса (перед последней `}`) добавить:

```php
    public static function serverAddresses(): array
    {
        return [
            'the stand address' => ['172.20.5.5', 'http://172.20.0.1', '172.20.0.1'],
            'this server over IPv6' => ['::1', 'http://[::1]', '[::1]'],
        ];
    }

    #[DataProvider('serverAddresses')]
    public function test_the_script_names_the_server_as_the_module_reached_it($from, $base, $server)
    {
        $this->activeProject();
        $script = $this->from($from)->get($base.self::START)->assertOk()->getContent();

        $this->assertStringContainsString('export SERVER="'.$server.'"', $script);
        $this->assertStringContainsString('-G "http://'.$server.'/scriptexecute"', $script);
        // CM3 over USB is gone (2.5.1): nothing rewrites the server into a link-local address of usb0
        $this->assertStringNotContainsString('%usb0', $script);
    }
```

В `tests/Unit/PackagingTest.php` в конец класса (перед последней `}`) добавить:

```php
    public function test_no_trace_of_the_cm3_usb_network()
    {
        // CM3 reached the server only over USB with IPv6 link-local addresses, which /scriptexecute no longer answers (2.5.1)
        $this->assertStringNotContainsString('dhcpcd', $this->file('debian/postinst'));
        $this->assertStringNotContainsString('[pi3]', $this->file('scriptexecute/config.txt'));
        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/scriptexecute/cmdline.txt.ipv6ll');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest|PackagingTest'`
Expected: `phpunit exit 1`. Падают `test_the_script_names_the_server_as_the_module_reached_it` со строкой `this server over IPv6` (в скрипте `export SERVER="[::1%usb0]"`) и `test_no_trace_of_the_cm3_usb_network` (в `postinst` есть `dhcpcd`). Строка `the stand address` проходит: она стережёт обычный путь CM4.

- [ ] **Step 3: Write the implementation**

В `app/Http/Controllers/ScriptExecuteController.php` заменить

```php
    /* Address of this server as the module reaches it: from the CM's side an IPv6 link-local
       address ends in %usb0 */
    protected function moduleServer(Request $req)
    {
        $server = (string) $req->server('HTTP_HOST');
        if ($server !== '' && $server[0] == '[')
        {
            $server = substr($server, 0, -1).'%usb0]';
        }
        return $server;
    }
```

на

```php
    /* Address of this server as the module reached it, for the links in the module's script */
    protected function moduleServer(Request $req)
    {
        return (string) $req->server('HTTP_HOST');
    }
```

Удалить из git файл: `git rm -q scriptexecute/cmdline.txt.ipv6ll`.

Записать в `$SP/plan251-t3-cm3.py` и выполнить `python -I "$SP/plan251-t3-cm3.py" "$PWD"`:

```python
"""2.5.1, task 3: CM3 over USB goes away (postinst, scriptexecute/config.txt, README, CLAUDE.md)."""
import pathlib
import re
import sys

REPO = pathlib.Path(sys.argv[1])


def replace_once(name, text, old, new):
    count = text.count(old)
    if count != 1:
        sys.exit(f'{name}: expected one match, found {count}: {old[:70]!r}')
    return text.replace(old, new)


def edit(name, change):
    path = REPO / name
    text = change(path.read_text(encoding='utf-8'))
    path.write_text(text, encoding='utf-8', newline='\n')
    print(f'{name}: edited')


def postinst(text):
    return replace_once('debian/postinst', text,
        '    # The Ethernet-over-USB method used for provisioning CM3 requires server has predicatable IPv6 link-local addresses\n'
        '    if grep -q "slaac private" /etc/dhcpcd.conf; then\n'
        '        echo "Changing dhcpcd settings to let server have predictable IPv6 link-local address"\n'
        '        sed -i "s#slaac private#slaac hwaddr#" "/etc/dhcpcd.conf"\n'
        '        deb-systemd-invoke restart dhcpcd || true\n'
        '    fi\n'
        '\n', '')


def config_txt(text):
    return replace_once('scriptexecute/config.txt', text,
        '[pi3]\n# Use USB over Ethernet on CM3\ndtoverlay=dwc2,dr_mode=peripheral\ncmdline=cmdline.txt.ipv6ll\n\n', '')


def readme(text):
    text = replace_once('README.md', text,
        'Compute Module 3, 3+ and 4 are supported out-of-the-box.',
        'Compute Module 4 is supported out-of-the-box; Compute Module 3 and 3+ are not supported since 2.5.1 '
        '(for them use v1.6.11 of this fork on Raspberry Pi OS with dhcpcd, or upstream raspberrypi/cmprovision).')
    start = text.index('## Provisioning Compute Module 3 and 3+ Devices ##\n')
    end = text.index('## Provisioning Raspberry Pi 4 boards ##')
    if not start < end:
        sys.exit('README.md: the CM3 sections are not before the Pi 4 section')
    return text[:start] + text[end:]


def claude_md(text):
    text = replace_once('CLAUDE.md', text,
        'Raspberry Pi Compute Module 3/3+/4 (и Pi 4 с включённой сетевой загрузкой).',
        'Raspberry Pi Compute Module 4 (и Pi 4 с включённой сетевой загрузкой); CM3/3+ с 2.5.1 не поддерживаются.')
    text, count = re.subn(r'^- \*\*CM3/3\+ — по USB\.\*\*.*$',
        '- **CM4 по USB.** Модуль, загруженный по USB (bootmode 3: новый CM4 или переключённый джампером), получает '
        '`scriptexecute` от `rpiboot -l -d scriptexecute` (сервис `cmprovision-rpiboot`), а к серверу идёт по Ethernet, '
        'как при сетевой загрузке. CM3/3+ с 2.5.1 не поддерживаются: у них нет Ethernet, а USB-гаджету с IPv6 link-local '
        '`/scriptexecute` не отвечает; секции `[pi3]`, файла `cmdline.txt.ipv6ll` и правки dhcpcd в `postinst` больше нет, '
        'загрузочные файлы CM3 в `scriptexecute/` остались как есть.',
        text, flags=re.M)
    if count != 1:
        sys.exit(f'CLAUDE.md: expected one CM3 bullet, found {count}')
    return replace_once('CLAUDE.md', text,
        '`scriptexecute/cmdline.txt` и `cmdline.txt.ipv6ll` (их подставляет initramfs)',
        '`scriptexecute/cmdline.txt` (их подставляет initramfs)')


edit('debian/postinst', postinst)
edit('scriptexecute/config.txt', config_txt)
edit('README.md', readme)
edit('CLAUDE.md', claude_md)
```

Expected: четыре строки `…: edited`. `scriptexecute/config.txt` после правки кончается `dtoverlay=spi-gpio40-45\n\n`, как кончался до неё.

- [ ] **Step 4: Run tests to verify they pass**

Run: `bash "$DEV/tests-ok.sh" 'StandNetworkTest|PackagingTest'`
Expected: `phpunit exit 0`, `OK (53 tests, …)` (44 в `StandNetworkTest`, 9 в `PackagingTest`).

- [ ] **Step 5: Следов CM3 не осталось**

Run: `git grep -n -i -I "ipv6ll\|slaac\|usb0\|cm3\|compute module 3" -- app routes resources scriptexecute debian/postinst`
Expected: одна строка — `app/Support/FailureAdvice.php:82: … is a CM4 (a CM3 has no EEPROM) …`: это совет оператору, а не путь CM3.

Run: `sh -n debian/postinst && echo "postinst: syntax OK"`
Expected: `postinst: syntax OK`.

- [ ] **Step 6: Весь набор**

Run: `bash "$DEV/suite-ok.sh"`
Expected: `php rc=0 js rc=0`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ScriptExecuteController.php debian/postinst scriptexecute/config.txt \
        tests/Feature/StandNetworkTest.php tests/Unit/PackagingTest.php README.md CLAUDE.md
git commit -q -F - <<'EOF'
Drop CM3 over USB

CM3 and CM3+ reached the server only through a USB gadget with IPv6
link-local addresses, which /scriptexecute no longer answers. Gone: the
%usb0 rewrite of the server address, the dhcpcd block of postinst (no
dhcpcd on Trixie), the [pi3] section with cmdline.txt.ipv6ll and the CM3
sections of the README. rpiboot stays: CM4 boots over USB through it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 4: Версия 2.5.1~dev1, ROADMAP и проверка на стенде

**Files:**
- Modify: `debian/changelog` (новая верхняя запись)
- Modify: `ROADMAP.md` (этап 2, пункт 2)

**Interfaces:**
- Consumes: всё из задач 1–3.
- Produces: пакет `cmprovision4_2.5.1~dev1_all.deb` на стенде WSL (`/root/own/last.deb`), установленный и проверенный; ROADMAP с нумерацией 2.5.1–2.5.3.

- [ ] **Step 1: Версия и ROADMAP**

Записать в `$SP/plan251-t4-version.py` и выполнить `python -I "$SP/plan251-t4-version.py" "$PWD"`:

```python
"""2.5.1, task 4: changelog 2.5.1~dev1 and the ROADMAP numbering 2.5.1-2.5.3."""
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


changelog = REPO / 'debian/changelog'
text = changelog.read_text(encoding='utf-8')
if not text.startswith('cmprovision4 (2.5.0) '):
    sys.exit('debian/changelog: the top entry is not 2.5.0')
date = email.utils.format_datetime(datetime.datetime.now().astimezone())
entry = ('cmprovision4 (2.5.1~dev1) unstable; urgency=medium\n\n'
         '  * /scriptexecute answers only the stand network 172.20.0.0/16 and the\n'
         '    server itself: from the office network it returns 403 with the reason.\n'
         '    The modules\' route runs without the web middleware group, so a module\n'
         '    request no longer writes a row into the sessions table.\n'
         '  * Compute Module 3 and 3+ are no longer supported: they reached the\n'
         '    server only over USB with IPv6 link-local addresses. CM4 still boots\n'
         '    over USB through rpiboot.\n\n'
         f' -- nimbo78 <igor.sin.78@gmail.com>  {date}\n\n')
changelog.write_text(entry + text, encoding='utf-8', newline='\n')
print('debian/changelog: 2.5.1~dev1')

roadmap = REPO / 'ROADMAP.md'
text = roadmap.read_text(encoding='utf-8')
text = replace_once('ROADMAP.md', text,
    '2.5.0 (48 модулей разом) вышла. Дальше две части по очереди, у каждой своя спека, план и выпуск:\n\n'
    '**2.5.1 — сеть провижинера:**\n'
    '- `/scriptexecute` закрыт от офисной сети;\n'
    '- **встроенный Wi-Fi провижинера — холодный запасной** (просьба 14 октября 2026):',
    '2.5.0 (48 модулей разом) вышла. Дальше по очереди, у каждой части своя спека, план и выпуск; как собрана сеть '
    'провижинера — [docs/provisioner-network.md](docs/provisioner-network.md).\n\n'
    '**2.5.1 — `/scriptexecute` только для сети стенда** (в работе, спека '
    '`docs/superpowers/specs/2026-10-15-stand-only-scriptexecute-design.md`): адрес модулей отвечает только '
    '`172.20.0.0/16` и самому серверу, маршрут без группы `web` (запрос модуля больше не пишет строку в `sessions`), '
    'поддержка CM3/CM3+ по USB убрана.\n\n'
    '**2.5.2 — встроенный Wi-Fi провижинера холодным запасным** (просьба 14 октября 2026):')
text = replace_once('ROADMAP.md', text,
    'и уходит, когда она вернулась;\n- **управление Wi-Fi из веб-интерфейса** (просьба 14 октября 2026):',
    'и уходит, когда она вернулась.\n\n**2.5.3 — управление Wi-Fi из веб-интерфейса** (просьба 14 октября 2026):')
text = replace_once('ROADMAP.md', text,
    '**2.5.2 — удалённое затирание сенсоров** (просьба 14 октября 2026),',
    '**Удалённое затирание сенсоров** (просьба 14 октября 2026; было 2.5.2, выйдет после 2.5.3),')
roadmap.write_text(text, encoding='utf-8', newline='\n')
print('ROADMAP.md: 2.5.1-2.5.3')
```

Expected: `debian/changelog: 2.5.1~dev1`, `ROADMAP.md: 2.5.1-2.5.3`. Проверка: `head -1 debian/changelog` → `cmprovision4 (2.5.1~dev1) unstable; urgency=medium`.

- [ ] **Step 2: Commit**

```bash
git add debian/changelog ROADMAP.md
git commit -q -F - <<'EOF'
2.5.1~dev1: the version and the roadmap

The provisioner network comes as three releases: 2.5.1 /scriptexecute
only for the stand network, 2.5.2 the built-in Wi-Fi as a cold spare,
2.5.3 Wi-Fi from the web UI; the sensor wipe follows them.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 3: Сборка пакета на стенде**

Стенд должен не спать (фоновый `sleep 14400`, см. «Команды»).

```bash
git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | sed 's#^#./#' | tar cf - -T - \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/31-put.sh /tmp/src-wt.tar
MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh /root/bench/13-build-wt.sh
```

Expected: `built 2.5.1~dev1: <размер> bytes` (сборка идёт несколько минут: `composer install --no-dev` берёт пакеты из кэша composer стенда). Если сборка упала — `tail -30 /root/own/build.log` на стенде.

- [ ] **Step 4: Установка и проверки внутри стенда**

```bash
cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s 2>&1 | grep -v "systemd user session"
APP=/var/lib/cmprovision; DB=$APP/database/database.sqlite
# the smoke test counts the ERROR lines of the whole laravel.log: the number before the install is the baseline
echo "laravel.log ERROR lines before: $(grep -c 'production.ERROR' $APP/storage/logs/laravel.log 2>/dev/null || echo 0)"
DEBIAN_FRONTEND=noninteractive apt-get install -y /root/own/last.deb > /tmp/i-251.log 2>&1 || { tail -20 /tmp/i-251.log; exit 1; }
q() { runuser -u www-data -- sqlite3 -readonly -cmd '.timeout 5000' "$DB" "$1"; }
echo "installed: $(dpkg-query -W -f='${Version}' cmprovision4)"
echo "routes: $(ls $APP/routes | tr '\n' ' ')"
[ -e $APP/scriptexecute/cmdline.txt.ipv6ll ] && echo "cmdline.txt.ipv6ll: STILL THERE" || echo "cmdline.txt.ipv6ll: gone"
echo "[pi3] lines in config.txt: $(grep -c '\[pi3\]' $APP/scriptexecute/config.txt)"
s0=$(q 'select count(*) from sessions;')
for i in 1 2 3; do curl -s -o /dev/null -w "loopback poll: %{http_code}\n" 'http://127.0.0.1/scriptexecute?serial=1000000012345678&identify=poll'; done
echo "sessions written by module requests: $(( $(q 'select count(*) from sessions;') - s0 ))"
echo "this server by its own non-loopback address: $(curl -s -o /dev/null -w '%{http_code}' "http://$(hostname -I | cut -d' ' -f1)/scriptexecute?serial=x")"
EOF
```

Expected:
- `laravel.log ERROR lines before: N` — запомнить N для шага 5
- `installed: 2.5.1~dev1`
- `routes: api.php channels.php console.php module.php web.php`
- `cmdline.txt.ipv6ll: gone`, `[pi3] lines in config.txt: 0`
- три строки `loopback poll: 200`
- `sessions written by module requests: 0`
- `this server by its own non-loopback address: 403` (источником будет адрес стенда в сети WSL, а не loopback)

- [ ] **Step 5: Дымовой тест стенда**

```bash
{ printf "PW='%s'\n" "$(cat "$SP/bench-web-password.txt")"; echo 'export PW; sh /root/bench/05-smoke.sh'; } \
  | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s > "$SP/smoke-2.5.1.log" 2>&1
grep -E "GET /scriptexecute|preinstall report|eeprom_version|postinstall:|alldone|sh -n|ERROR lines|error.log new lines" "$SP/smoke-2.5.1.log"
```

Expected: `GET /scriptexecute: 200 text/plain; charset=UTF-8`, `sh -n: syntax OK`, `preinstall report: 200`, `eeprom_version:    200`, `postinstall:       200`, `alldone:           200`, `laravel.log ERROR lines: N` — то же N, что в шаге 4 (новых ошибок нет); новые строки `error.log` nginx — только PHP Deprecated или их нет.

- [ ] **Step 6: Запрос с Windows — из «чужой» сети**

```bash
WSLIP=$(MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- hostname -I | tr -d '\r' | cut -d' ' -f1)
curl -s -i "http://$WSLIP/scriptexecute?serial=x" | sed -n '1p;$p'
curl -s -o /dev/null -w '/index.php/scriptexecute: %{http_code}\n' "http://$WSLIP/index.php/scriptexecute?serial=x"
curl -s -o /dev/null -w '/scriptexecute/: %{http_code}\n' "http://$WSLIP/scriptexecute/?serial=x"
```

Expected: `HTTP/1.1 403 Forbidden`; последняя строка — `cmprovision answers /scriptexecute only for the stand network 172.20.0.0/16 and for this server itself; this request came from 172.17.96.1.` (адрес шлюза NAT WSL; 15 октября сеть стенда была `172.17.96.0/20`); `/index.php/scriptexecute: 403`; `/scriptexecute/: 403`. Если адрес в ответе попал в `172.20.0.0/16`, запрос пройдёт с 200 — это подсеть WSL, а не ошибка проверки; тогда записать это в итог.

- [ ] **Step 7: Записать итог проверки**

В ledger задачи — версии, строки ожидаемого вывода шагов 4–6 и путь `$SP/smoke-2.5.1.log`. Коммита нет: файлы репозитория в этих шагах не менялись.

---

### Task 5: Выпуск 2.5.1 — только по слову пользователя

Перед этой задачей — финальное ревью всей ветки (процесс исполнения плана). По порядку выпуска, который задал пользователь, сначала исправляются и мелочи ревью, каждая с тестом, затем выпуск: коммит выпуска, локальное слияние и тег, сборка и установка на wlanpi-da2 из тега, и только если установка прошла — пуш. **Остановиться и спросить пользователя перед шагом 1.**

**Files:**
- Modify: `debian/changelog` (2.5.1~dev1 → 2.5.1), `ROADMAP.md` (2.5.1 — в «Сделано»)
- Create: `$SP/prov-install251.sh` (вне репозитория)

**Interfaces:**
- Consumes: ветка `stage2` после задач 1–4 и ревью.
- Produces: тег `v2.5.1` на слиянии в `main`; пакет `cmprovision4_2.5.1_all.deb`, установленный на wlanpi-da2.

- [ ] **Step 1: Коммит выпуска**

Записать в `$SP/plan251-t5-release.py` и выполнить `python -I "$SP/plan251-t5-release.py" "$PWD" "<дата выпуска в виде «15 октября 2026»>" "<число PHP-тестов>" "<сколько из них пропущено>" "<число JS-тестов>"` (числа — из последнего `suite-ok.sh`):

```python
"""2.5.1, task 5: the release entry in debian/changelog and ROADMAP."""
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
text = replace_once('debian/changelog', text, 'cmprovision4 (2.5.1~dev1) unstable;', 'cmprovision4 (2.5.1) unstable;')
date = email.utils.format_datetime(datetime.datetime.now().astimezone())
text, count = re.subn(r'^ -- nimbo78 <igor\.sin\.78@gmail\.com>  .*$', f' -- nimbo78 <igor.sin.78@gmail.com>  {date}', text, count=1, flags=re.M)
if count != 1:
    sys.exit('debian/changelog: no trailer line')
changelog.write_text(text, encoding='utf-8', newline='\n')
print('debian/changelog: 2.5.1')

roadmap = REPO / 'ROADMAP.md'
text = roadmap.read_text(encoding='utf-8')
start = text.index('**2.5.1 — `/scriptexecute` только для сети стенда** (в работе,')
end = text.index('**2.5.2 — встроенный Wi-Fi провижинера холодным запасным**')
text = text[:start] + text[end:]
done = (f'**2.5.1** вышла {DAY}, тег `v2.5.1`. `/scriptexecute` только для сети стенда.\n\n'
        '- **Доступ.** Адрес модулей отвечает только сети стенда `172.20.0.0/16` и самому серверу (`127.0.0.1`, `::1`), '
        'остальным — 403 с причиной. Решает сырой адрес соединения, заголовки на него не влияют.\n'
        '- **Без сессий.** Маршрут модулей живёт вне группы `web`: запрос модуля больше не пишет строку в `sessions` '
        '(на wlanpi-da2 таких было 1444 из 1445).\n'
        '- **Без CM3.** CM3/CM3+ по USB больше не поддерживаются; CM4 по USB через rpiboot остаётся.\n\n'
        f'Проверено: {PHP_TESTS} тестов на PHP 8.4 ({SKIPPED} из них пропускаются без симулятора SNMP) и {JS_TESTS} на JS; '
        'на стенде WSL — дымовой тест и 403 на запрос с Windows; на wlanpi-da2 — 403 из офиса и 200 модулям стенда.\n\n')
text = replace_once('ROADMAP.md', text, '## Этап 2: что осталось', done + '## Этап 2: что осталось')
roadmap.write_text(text, encoding='utf-8', newline='\n')
print('ROADMAP.md: 2.5.1 done')
```

Expected: `debian/changelog: 2.5.1`, `ROADMAP.md: 2.5.1 done`.

```bash
git add debian/changelog ROADMAP.md
git commit -q -F - <<'EOF'
/scriptexecute only for the stand network; 2.5.1

The modules' endpoint answers only 172.20.0.0/16 and the server itself,
outside the web middleware group, so a module request writes no session
row. CM3 and CM3+ over USB are no longer supported.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 2: Локальное слияние и тег — без пуша**

В слияние войдут все коммиты `stage2` после `v2.5.0`, в том числе спека затирания сенсоров `9ddf3b7` (лежит в `stage2` с 15 октября; пуш в шаге 6 опубликует её на GitHub). Спросить пользователя, выпускать ли её вместе. Если нет — до слияния убрать её из ещё не опубликованной истории `stage2` (`origin/stage2` стоит на `v2.5.0`) и проверить, что остальное на месте:

```bash
test "$(git rev-parse origin/stage2)" = "$(git rev-parse 'v2.5.0^{commit}')"
git rebase -q --onto 9ddf3b7^ 9ddf3b7 stage2
git log --oneline v2.5.0..stage2 | grep -c "re-provision a sensor"   # 0
```

Затем:

```bash
test "$(git rev-parse --abbrev-ref HEAD)" = stage2
test "$(git rev-parse main)" = "$(git rev-parse origin/main)"
git checkout -q main
git merge -q --no-ff stage2 -m "Merge stage2: release 2.5.1"
git tag -a v2.5.1 -m "cmprovision4 2.5.1"
test "$(git rev-parse 'v2.5.1^{commit}')" = "$(git rev-parse HEAD)"
git checkout -q stage2 && git merge -q --ff-only main
git log --oneline -3 main
```

Expected: верхняя строка — `Merge stage2: release 2.5.1`; `stage2` совпадает с `main`.

- [ ] **Step 3: Сборка на wlanpi-da2 из тега**

```bash
P="ssh -o HostKeyAlias=192.168.25.30 -o BatchMode=yes claude@192.168.68.237"
$P 'rm -rf ~/cmprovision/build/cmprovision && mkdir -p ~/cmprovision/build/cmprovision'
git archive v2.5.1 | $P 'tar -x -C ~/cmprovision/build/cmprovision'
$P 'cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d > ../build-2.5.1.log 2>&1; echo "build rc=$?"; ls -la ../cmprovision4_2.5.1_all.deb'
```

(таймаут Bash — 600000: сборка идёт около 150 с.)
Expected: `build rc=0` и файл `cmprovision4_2.5.1_all.deb`. Адрес провижинера — 192.168.68.237, если пользователь не назвал другой.

- [ ] **Step 4: Установка, пока ни один модуль не прошивается**

Записать `$SP/prov-install251.sh`:

```sh
#!/bin/sh
# Install 2.5.1 on the provisioner, only while no module is being flashed; then check what 2.5.1 changed
set -e
[ "$(hostname)" = wlanpi-da2 ] || { echo "wrong host: $(hostname)"; exit 1; }
cd /home/claude/cmprovision/build
DEB=cmprovision4_2.5.1_all.deb
APP=/var/lib/cmprovision
DB=$APP/database/database.sqlite
# as the web user: the database runs in WAL, and root must not open its files
q() { sudo -n -u www-data sqlite3 -readonly -cmd '.timeout 5000' $DB "$1"; }
n=$(q "select count(*) from cms where phase in ('preinstall','write','verify','postinstall') and provisioning_started_at > datetime('now','-2 hours');")
[ "$n" = 0 ] || { echo "a module is being flashed ($n): not installing"; exit 1; }
# the way back: 2.5.1 takes no snapshot (preinst does that only below 2.5.0), so the 2.5.0 package must be at hand
[ -f cmprovision4_2.5.0_all.deb ] || { echo "no cmprovision4_2.5.0_all.deb here to go back to: not installing"; exit 1; }
s0=$(q "select count(*) from sessions where ip_address like '172.20.%';")
sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y ./$DEB > install-2.5.1.log 2>&1 || { tail -40 install-2.5.1.log; exit 1; }
dpkg-query -W -f='${Version} ${Status}\n' cmprovision4
for s in nginx php8.4-fpm cmprovision-queue cmprovision-queue-images cmprovision-dnsmasq cmprovision-rpiboot; do printf '%s: %s\n' "$s" "$(systemctl is-active $s || true)"; done
echo "routes: $(ls $APP/routes | tr '\n' ' ')"
[ -e $APP/scriptexecute/cmdline.txt.ipv6ll ] && echo "cmdline.txt.ipv6ll: STILL THERE" || echo "cmdline.txt.ipv6ll: gone"
echo "loopback:          $(curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1/scriptexecute?serial=x&identify=poll')"
echo "stand address:     $(curl -s -o /dev/null -w '%{http_code}' 'http://172.20.0.1/scriptexecute?serial=x&identify=poll')"
echo "own Wi-Fi address: $(curl -s -o /dev/null -w '%{http_code}' "http://$(ip -4 -o addr show wlan1 | awk '{print $4}' | cut -d/ -f1)/scriptexecute?serial=x")"
curl -s -o /dev/null -w 'login %{http_code}\n' http://127.0.0.1/login
sleep 60
echo "module sessions since the install: $(( $(q "select count(*) from sessions where ip_address like '172.20.%';") - s0 ))"
echo "stand requests to /scriptexecute in the last 300 lines of the nginx log, by status:"
sudo tail -n 300 /var/log/nginx/access.log | awk '$1 ~ /^172\.20\./ && $7 ~ /^\/scriptexecute/ {print "  " $9}' | sort | uniq -c
echo "failed jobs: $(q 'select count(*) from failed_jobs;')"
```

Run: `$P 'sh -s' < "$SP/prov-install251.sh"`
Expected:
- `2.5.1 install ok installed`; все шесть служб `active`;
- `routes: api.php channels.php console.php module.php web.php`, `cmdline.txt.ipv6ll: gone`;
- `loopback: 200`, `stand address: 200`, `own Wi-Fi address: 403`, `login 200`;
- `module sessions since the install: 0`;
- у запросов стенда к `/scriptexecute` — только статус 200 (если ни один модуль не опрашивает сервер, строк нет: тогда это не проверено, записать в итог);
- `failed jobs:` — столько же, сколько было до установки.

- [ ] **Step 5: Запрос из офиса**

С этого компьютера (он в офисной сети):

```bash
curl -s -i "http://192.168.68.237/scriptexecute?serial=x" | sed -n '1p;$p'
```

Expected: `HTTP/1.1 403 Forbidden` и `cmprovision answers /scriptexecute only for the stand network 172.20.0.0/16 and for this server itself; this request came from 192.168.68.<адрес этого компьютера>.`

- [ ] **Step 6: Пуш — только если шаги 3–5 прошли**

```bash
git push -q origin main stage2 v2.5.1
git status -sb | head -1
```

Expected: `## stage2...origin/stage2`, без расхождений. Если что-то из шагов 3–5 не прошло — не пушить и сообщить пользователю; откат на провижинере — `sudo apt install --allow-downgrades ~/cmprovision/build/cmprovision4_2.5.0_all.deb` (скрипт шага 4 проверил, что пакет на месте), только по его слову.

- [ ] **Step 7: Память**

В `memory/cmprovision-fork-plan.md`: 2.5.1 выпущена и установлена на wlanpi-da2 (дата, что проверено); следующая — 2.5.2 (встроенный Wi-Fi холодным запасным); строку в `MEMORY.md` обновить.
