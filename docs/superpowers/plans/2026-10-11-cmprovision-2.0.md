# cmprovision 2.0 (Laravel 13): план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Перевести cmprovision с Laravel 8, Livewire 2, Jetstream 2, Tailwind 2 и Mix на Laravel 13, Livewire 3.8, Jetstream 5.5, Tailwind 3.4 (самостоятельный бинарник) и PHPUnit 12, сохранив поведение, данные и обновление поверх 1.6.11.

**Architecture:** Прыжок на месте. Зависимости меняются сразу на целевые версии, структура приложения (классы `Kernel`, провайдеры, наши конфиги) остаётся. Livewire-компоненты остаются в `App\Http\Livewire`. Шаблоны Jetstream публикуются заново из его заготовок 5.5. CSS собирает бинарник Tailwind без Node, своего JS-бандла нет: Alpine приходит с Livewire.

**Tech Stack:** PHP 8.4 (Debian 13), Laravel 13, Livewire 3.8 (с Alpine 3), Jetstream 5.5 + Fortify + Sanctum 4, PHPUnit 12.5, Tailwind CSS 3.4.19 standalone CLI, SQLite, Debian-пакет `cmprovision4`.

**Spec:** [docs/superpowers/specs/2026-10-11-cmprovision-2.0-design.md](../specs/2026-10-11-cmprovision-2.0-design.md)

## Global Constraints

- PHP `^8.3` в `composer.json`, на стенде и провижинере PHP 8.4. Зависимости `php-*` в `debian/control` — `>= 2:8.3` (с эпохой).
- Только Debian 13 / Raspberry Pi OS Trixie. Ветка 1.6.x заморожена на теге `v1.6.11`.
- Поведение не меняется: протокол с модулями (`/scriptexecute`), формат базы, пути пакета `/var/lib/cmprovision`, внешний вид.
- Livewire-классы остаются в `App\Http\Livewire`, макет страниц — `layouts.app`.
- В 2.0 остаются вход и смена пароля, 2FA с подтверждением кодом, API-токены (права create, read, update, delete; по умолчанию read), выход с других устройств. Выключены удаление аккаунта, сброс пароля по почте, регистрация, подтверждение почты.
- Ассеты собираются заранее и коммитятся: `public/css/app.css`. Своего JS-бандла и Node в сборке нет.
- В репозитории в OneDrive никогда не создаются `vendor/` и `node_modules/`: Composer работает только в WSL-стенде `/root/dev`, `composer.lock` копируется обратно.
- Комментарии в коде — на английском и в стиле окружающего кода. Новые тесты следуют стилю существующих (докблок класса с объяснением, имена `test_что_происходит`).
- Коммиты — в ветку `stage2`, push только при выпуске. Каждый коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Цикл sensor0 запускать только после явного «да» пользователя в текущем запросе.

## Review Focus

Пять входов, которые спецификация подразумевает, но тесты PHPUnit не проверяют. Их проверки встроены в задачи.

1. **Сессия, открытая на 1.6.11, переживает обновление.** Вошедший оператор после `apt install` 2.0 остаётся вошедшим (формат сессий `php`). Проверка — задача 9, шаг «сессия».
2. **POST от модуля на `/scriptexecute` без CSRF-токена по-прежнему принимается.** В тестах CSRF-проверка отключена, поэтому смотрим через nginx: ответ 401 (адрес не из сети модулей), а не 419. Проверка — задача 9.
3. **Зашифрованное ключом APP_KEY на 1.6.11 расшифровывается на 2.0:** секрет 2FA (его шифрует Fortify) и cookie сессии. Настройки каналов уведомлений хранятся в базе открытым текстом и просто должны остаться на месте. Проверка — задача 9.
4. **API-токен, выданный Sanctum 2, работает после миграции `expires_at`.** Проверка — задача 9.
5. **Оператор с тёмной темой в ОС видит прежний светлый интерфейс,** хотя в шаблонах Jetstream 5 есть классы `dark:`. Проверка — задача 7: в собранном CSS нет `prefers-color-scheme`.

---

## Окружение

Все команды выполняются из Git Bash на Windows. Помощники лежат в блокноте сессии (scratchpad).

```bash
SP="C:/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad"
. "$SP/wsl-bench/dev.sh"
```

`dev.sh` создаётся в задаче 1 и даёт:
- `$REPO` — путь к репозиторию;
- `w <команда>` — запуск в WSL-стенде с отфильтрованным шумом про systemd;
- `w_raw <команда>` — то же для двоичных потоков (tar), без фильтра;
- `sync_dev` — рабочее дерево (отслеживаемые и новые файлы) → `/root/dev`; `vendor/`, `.env` и `storage/` там сохраняются;
- `tests '<regex>'` — PHPUnit по фильтру, полный вывод в `/tmp/phpunit-filter.log` на стенде;
- `tests_all` — полный набор с журналом сообщений PHP (`/root/bench/02-tests.sh`);
- `from_dev <путь>` — файл из `/root/dev` обратно в репозиторий.

Стенд: WSL-дистрибутив `cmprov-trixie` (Debian 13, PHP 8.4, Composer 2.8, debhelper). Провижинер: `ssh claude@192.168.25.30`. sensor0: `ssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -o LogLevel=ERROR -J claude@192.168.25.30 wlanpi@172.20.108.222`.

## Карта файлов

| Файл | Что с ним |
|---|---|
| `composer.json`, `composer.lock` | Новые версии (задача 2) |
| `app/Http/Kernel.php`, `app/Http/Middleware/TrustProxies.php` | Встроенные посредники вместо пакетов (задача 2) |
| `config/sanctum.php`, `config/jetstream.php`, `config/session.php`, `config/fortify.php` | Ключи Sanctum 4 и Jetstream 5, формат сессий, возможности (задачи 2 и 6) |
| `config/livewire.php` | Новый: пространство имён и макет (задача 4) |
| `phpunit.xml` | Схема PHPUnit 12, SQLite в памяти (задача 2) |
| `database/migrations/2026_10_11_*` | Две колонки для Sanctum 4 и 2FA (задача 3) |
| `app/Http/Controllers/ScriptExecuteController.php`, `app/Http/Livewire/Labels.php` | Вывод печати этикетки в лог (задача 1) |
| `app/Http/Livewire/{Firmwares,Projects}.php` | Без объектов `Firmware` в свойствах, закрытые методы (задача 5) |
| `app/Http/Livewire/{Images,NotificationSettings}.php` | Мелкие правки под Livewire 3 (задача 4) |
| `resources/views/livewire/*.blade.php` | `wire:model`, Alpine 3 (задача 4) |
| `resources/views/layouts/*.blade.php`, `navigation-menu.blade.php` | Без Mix, `x-jet-` → `x-` (задачи 4 и 6) |
| `resources/views/{components,api,profile,auth}/` | Заготовки Jetstream 5.5 (задача 6) |
| `app/Actions/Fortify/*`, `app/Actions/Jetstream/DeleteUser.php` | Заготовки Jetstream 5.5 (задача 6) |
| `routes/web.php` | Посредник `auth_session` Jetstream (задача 6) |
| `tailwind.config.js`, `resources/css/app.css`, `public/css/app.css`, `package.json` | Tailwind 3.4, сборка без Node (задача 7) |
| Удаляются | `webpack.mix.js`, `resources/js/*`, `public/js/app.js*`, `public/mix-manifest.json`, `package-lock.json`, `resources/views/vendor/jetstream/`, четыре ненужных шаблона `auth/*` и четыре теста Jetstream |
| `debian/control`, `debian/preinst` (новый), `debian/source/lintian-overrides`, `debian/changelog` | Пакет 2.0 (задача 8) |
| `docker/*`, `README.md`, `CLAUDE.md`, `ROADMAP.md` | Документация и стенд Docker (задачи 8 и 11) |
| `tests/Feature/{LabelPrintingTest,TestDatabaseTest,UpgradeMigrationsTest,ProjectsFirmwareTest}.php` | Новые тесты |

---

### Task 1: Вывод команды печати этикетки — в лог модуля (на нынешнем стеке)

**Files:**
- Create: `$SP/wsl-bench/dev.sh`
- Create: `tests/Feature/LabelPrintingTest.php`
- Modify: `app/Http/Controllers/ScriptExecuteController.php` (метод `printLabel()`, ветка `command`)
- Modify: `app/Http/Livewire/Labels.php` (метод `printTestLabel()`)

**Interfaces:**
- Produces: строка лога `Label command output: <вывод>` (уровень info) у модуля. Сообщение пробной печати `Printed test label. Command output: <вывод>`.

- [ ] **Step 1: Создать помощник стенда**

```bash
SP="C:/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad"
cat > "$SP/wsl-bench/dev.sh" <<'EOF'
# Helpers for the 2.0 work from Git Bash: the WSL bench cmprov-trixie runs PHP 8.4.
REPO="/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
w() { MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- "$@" 2>&1 | grep -v "systemd user session"; }
w_raw() { MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- "$@" 2>/dev/null; }
# the working tree (tracked and new files, no local junk) -> /root/dev; vendor/, .env and storage/ stay
sync_dev() { (cd "$REPO" && git ls-files -co --exclude-standard | grep -vE '^(\.serena|\.vscode|CLAUDE_files)/|^CLAUDE\.html$' | tar cf - -T -) | w_raw sh /root/bench/15-dev-in.sh; }
# phpunit for one filter regex (alternatives with |); full output in /tmp/phpunit-filter.log on the bench
tests() { w sh /root/bench/21-tests-filter.sh "'$1'"; }
tests_all() { w sh /root/bench/02-tests.sh | sed -n '1,14p'; }
# a file of /root/dev back into the repository:  from_dev composer.lock
from_dev() { w_raw cat "/root/dev/$1" > "$REPO/$1"; }
EOF
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'WifiLinkTest'
```

Ожидается: `dev refreshed: … files`, затем `phpunit exit 0` и `OK (7 tests, …)`.

- [ ] **Step 2: Написать падающий тест**

`tests/Feature/LabelPrintingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Labels;
use App\Models\Cmlog;
use App\Models\Image;
use App\Models\Label;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A label "printer" may be any command. What it prints goes to the log even when it exits 0:
 * project 78's CSV logger printed "Permission denied" for two years and nobody saw it.
 */
class LabelPrintingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_executable('/bin/sh')) {
            $this->markTestSkipped('the print command runs in a POSIX shell');
        }
    }

    protected function projectPrintingWith($command)
    {
        $label = Label::create(['name' => 'csv', 'printer_type' => 'command', 'print_command' => $command,
                                'template' => '$serial,$mac', 'file_extension' => 'txt']);
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id,
                                    'label_id' => $label->id, 'label_moment' => 'preinstall', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
    }

    protected function start($serial)
    {
        $this->get("/scriptexecute?serial=$serial&model=CM4&storagesize=62500000&mac=e4:5f:01:00:00:01")->assertOk();
    }

    public function test_what_the_print_command_says_goes_to_the_log()
    {
        $this->projectPrintingWith('echo "cannot write /home/wlanpi/cmprovision.csv"');

        $this->start('1000000000000e01');

        $this->assertSame(1, Cmlog::where('cm', '1000000000000e01')
            ->where('msg', 'Label command output: cannot write /home/wlanpi/cmprovision.csv')->count());
    }

    public function test_a_silent_print_command_adds_nothing()
    {
        $this->projectPrintingWith('true');

        $this->start('1000000000000e02');

        $this->assertSame(0, Cmlog::where('msg', 'like', 'Label command output%')->count());
    }

    public function test_the_test_label_shows_what_the_command_said()
    {
        Livewire::test(Labels::class)
            ->set('printer_type', 'command')
            ->set('print_command', 'echo hello from the printer')
            ->set('template', '$serial')
            ->set('file_extension', 'txt')
            ->call('printTestLabel')
            ->assertSee('Printed test label. Command output: hello from the printer');
    }
}
```

- [ ] **Step 3: Убедиться, что тест падает**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'LabelPrintingTest'
```

Ожидается: `Tests: 3`, две ошибки — у `test_what_the_print_command_says_goes_to_the_log` (найдено 0 строк, а нужна 1) и у `test_the_test_label_shows_what_the_command_said` (нет «Command output»). `test_a_silent_print_command_adds_nothing` проходит.

- [ ] **Step 4: Реализовать**

В `ScriptExecuteController::printLabel()`, ветка `command`, после проверки `$retcode` и до закрывающей скобки ветки:

```php
                if ($retcode)
                    throw new \Exception("Executing '$cmd' returned exit code $retcode. Program output:\n".implode("\n", $output));
                // a command can report trouble and still exit 0 (a CSV it cannot write): keep what it said
                $said = trim(implode("\n", $output));
                if ($said !== '')
                    $this->logInfo('Label command output: '.$said);
```

В `Labels::printTestLabel()` перед `try` объявить `$said = '';`. В ветке `command` после проверки `$retcode` добавить `$said = trim(implode("\n", $output));`. Строку с флеш-сообщением заменить так:

```php
            session()->flash('message', 'Printed test label'.($said !== '' ? '. Command output: '.$said : ''));
```

- [ ] **Step 5: Тест проходит, весь набор зелёный**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'LabelPrintingTest' && tests_all
```

Ожидается: `OK (3 tests…)`, затем `phpunit exit 0` и `Tests: 251`.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add tests/Feature/LabelPrintingTest.php app/Http/Controllers/ScriptExecuteController.php app/Http/Livewire/Labels.php
git commit -q -m "Label print command output goes to the module log, also when it exits 0" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Прыжок зависимостей: Laravel 13, Livewire 3.8, Jetstream 5.5, Sanctum 4, PHPUnit 12

**Files:**
- Modify: `composer.json`, `composer.lock` (из стенда)
- Modify: `app/Http/Kernel.php`, `app/Http/Middleware/TrustProxies.php`
- Modify: `config/sanctum.php`, `config/jetstream.php`, `config/session.php`
- Modify: `phpunit.xml`
- Modify: `tests/Feature/EepromTransparencyTest.php:199`, `tests/Feature/ModuleCardTest.php:327`, `tests/Feature/NotificationsTest.php` (последний тест)
- Create: `tests/Feature/TestDatabaseTest.php`
- Create на стенде: `/root/bench/24-composer-update.sh`

**Interfaces:**
- Produces: приложение загружается на Laravel 13. Посредник `auth.session` зарегистрирован как `Illuminate\Session\Middleware\AuthenticateSession`. Ключи `jetstream.auth_session` и `jetstream.guard` заданы. `session.serialization` = `php`.

- [ ] **Step 1: Тесты-страховки (на нынешнем стеке они проходят)**

`tests/Feature/TestDatabaseTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Tests refresh the database: they must never reach the one .env points at. */
class TestDatabaseTest extends TestCase
{
    public function test_tests_run_on_an_in_memory_database()
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
```

В `NotificationsTest::test_started_events_are_sent_when_selected` последней строкой добавить:

```php
        Http::assertSentCount(1);   // one listener: Laravel 11+ discovers events too, it must not run twice
```

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'TestDatabaseTest|NotificationsTest'
```

Ожидается: `OK`. Скрипт `21-tests-filter.sh` сам задаёт `DB_CONNECTION=sqlite DB_DATABASE=:memory:`.

- [ ] **Step 2: `composer.json`**

Заменить блоки `require`, `require-dev` и `config`:

```json
    "require": {
        "php": "^8.3",
        "guzzlehttp/guzzle": "^7.8",
        "laravel/framework": "^13.0",
        "laravel/jetstream": "^5.5",
        "laravel/sanctum": "^4.3",
        "laravel/tinker": "^3.0",
        "livewire/livewire": "^3.8"
    },
    "require-dev": {
        "fakerphp/faker": "^1.23",
        "mockery/mockery": "^1.6",
        "nunomaduro/collision": "^8.6",
        "phpunit/phpunit": "^12.5"
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "platform": {
            "php": "8.3.0"
        }
    },
```

`platform.php = 8.3.0` заставляет Composer выбирать версии, работающие на PHP 8.3, — например, Symfony 7.4, а не 8.x, которой нужен 8.4. Так lock подходит и для 8.3, и для 8.4.

- [ ] **Step 3: Посредники**

`app/Http/Middleware/TrustProxies.php`: `use Fideloper\Proxy\TrustProxies as Middleware;` → `use Illuminate\Http\Middleware\TrustProxies as Middleware;`.

`app/Http/Kernel.php`:
- в `$middleware` строку `\Fruitcake\Cors\HandleCors::class,` заменить на `\Illuminate\Http\Middleware\HandleCors::class,`;
- из группы `web` удалить `\Laravel\Jetstream\Http\Middleware\AuthenticateSession::class,` — Jetstream 5 ставит его на маршруты сам, через `config('jetstream.auth_session')` (задача 6);
- в `$routeMiddleware` после `'auth.basic'` добавить `'auth.session' => \Illuminate\Session\Middleware\AuthenticateSession::class,`.

- [ ] **Step 4: Конфиги**

`config/sanctum.php`, блок `middleware`:

```php
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => App\Http\Middleware\EncryptCookies::class,
        'validate_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
    ],
```

`config/jetstream.php`, после строки `'middleware' => ['web'],`:

```php

    'auth_session' => Laravel\Jetstream\Http\Middleware\AuthenticateSession::class,

    'guard' => 'sanctum',
```

`config/session.php`, после блока `'driver'`:

```php

    /*
    |--------------------------------------------------------------------------
    | Session Serialization
    |--------------------------------------------------------------------------
    |
    | Laravel 13's skeleton stores sessions as JSON. Sessions written before
    | cmprovision 2.0 are PHP-serialized: keep that format, or the upgrade
    | logs everybody out.
    |
    */

    'serialization' => 'php',
```

- [ ] **Step 5: PHPUnit 12**

`phpunit.xml` целиком:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <server name="APP_ENV" value="testing"/>
        <server name="BCRYPT_ROUNDS" value="4"/>
        <server name="CACHE_DRIVER" value="array"/>
        <!-- tests refresh the database: never the one .env points at -->
        <server name="DB_CONNECTION" value="sqlite"/>
        <server name="DB_DATABASE" value=":memory:"/>
        <server name="MAIL_MAILER" value="array"/>
        <server name="QUEUE_CONNECTION" value="sync"/>
        <server name="SESSION_DRIVER" value="array"/>
    </php>
</phpunit>
```

`tests/Feature/EepromTransparencyTest.php`: `/** @dataProvider flashOutcomes */` → `#[DataProvider('flashOutcomes')]`, к `use` добавить `use PHPUnit\Framework\Attributes\DataProvider;`.

`tests/Feature/ModuleCardTest.php`: `/** @dataProvider failures */` → `#[DataProvider('failures')]` и тот же `use`.

- [ ] **Step 6: `composer update` на стенде, lock — в репозиторий**

```bash
. "$SP/wsl-bench/dev.sh"
cat > "$SP/wsl-bench/24-composer-update.sh" <<'EOF'
#!/bin/sh
# Bench only: resolve composer.json of /root/dev anew (with dev packages) and show the key versions.
cd /root/dev || exit 1
export COMPOSER_ALLOW_SUPERUSER=1 PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
rm -rf bootstrap/cache/*.php
composer update --no-interaction --no-progress --with-all-dependencies > /tmp/composer-update.log 2>&1
echo "composer exit $?"; grep -iE "problem|conflict|requires php|could not" /tmp/composer-update.log | head -12
php artisan --version 2>&1 | tail -1
composer show laravel/framework livewire/livewire laravel/jetstream laravel/fortify laravel/sanctum phpunit/phpunit 2>/dev/null | grep -E "^(name|versions)"
EOF
w_raw sh -c 'cat > /root/bench/24-composer-update.sh && chmod +x /root/bench/24-composer-update.sh' < "$SP/wsl-bench/24-composer-update.sh"
sync_dev && w sh /root/bench/24-composer-update.sh && from_dev composer.lock && git -C "$REPO" diff --stat composer.lock
```

Ожидается: `composer exit 0`, `Laravel Framework 13.x`, Livewire `v3.8.x`, Jetstream `v5.5.x`, Fortify `v1.4x`, Sanctum `v4.3.x`, PHPUnit `12.5.x`. В lock должны пропасть `fideloper/proxy`, `fruitcake/laravel-cors`, `facade/ignition`, `swiftmailer`, а `league/commonmark` стать 2.x.

Если Composer сообщает о конфликте — прочитать `/tmp/composer-update.log`, ослабить ограничение конфликтующего пакета и повторить. Тесты не трогать.

- [ ] **Step 7: Приложение загружается**

```bash
. "$SP/wsl-bench/dev.sh" && w sh -c 'cd /root/dev && php artisan route:list --except-vendor 2>&1 | tail -5; php artisan config:show session 2>&1 | grep -i serialization'
```

Ожидается: таблица маршрутов (`/scriptexecute`, `/cms/{serial}`, `/api/...`) без исключений и `serialization ... php`.

- [ ] **Step 8: Серверная часть зелёная**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'Unit|ScriptExecuteRobustnessTest|EepromTransparencyTest|ImageWriteDiagnosticsTest|ModuleLedsTest|NotificationsTest|ProvisioningProgressTest|ScriptsApiTest|FirmwareStoreTest|FirmwareUpdaterTest|ProjectActivationTest|TestDatabaseTest|LabelPrintingTest::test_(what|a_silent)'
```

Ожидается: `phpunit exit 0`, без ошибок. Если какой-то из этих тестов падает, причина в коде приложения. Исправить её по руководствам обновления Laravel 9–13 (ссылки в спецификации), ожидания тестов не менять, а исправление описать в сообщении коммита. Страницы и Livewire-компоненты до задач 4–6 падают — это ожидаемо, их здесь не запускаем.

- [ ] **Step 9: Commit**

```bash
cd "$REPO" && git add composer.json composer.lock app/Http/Kernel.php app/Http/Middleware/TrustProxies.php config/sanctum.php config/jetstream.php config/session.php phpunit.xml tests/Feature/TestDatabaseTest.php tests/Feature/EepromTransparencyTest.php tests/Feature/ModuleCardTest.php tests/Feature/NotificationsTest.php
git commit -q -m "Laravel 13, Livewire 3.8, Jetstream 5.5, Sanctum 4, PHPUnit 12: dependencies and framework middleware" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Колонки для Sanctum 4 и подтверждения 2FA

**Files:**
- Create: `tests/Feature/UpgradeMigrationsTest.php`
- Create: `database/migrations/2026_10_11_100000_add_expires_at_to_personal_access_tokens_table.php`
- Create: `database/migrations/2026_10_11_100100_add_two_factor_confirmed_at_to_users_table.php`

**Interfaces:**
- Produces: колонки `personal_access_tokens.expires_at` и `users.two_factor_confirmed_at` (обе nullable timestamp).

- [ ] **Step 1: Падающий тест**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Upgrading from 1.6 adds what Sanctum 4 and Fortify's confirmed two-factor setup read. */
class UpgradeMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_can_expire()
    {
        $this->assertTrue(Schema::hasColumn('personal_access_tokens', 'expires_at'));
    }

    public function test_two_factor_setup_can_be_confirmed()
    {
        $this->assertTrue(Schema::hasColumn('users', 'two_factor_confirmed_at'));
    }
}
```

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'UpgradeMigrationsTest'
```

Ожидается: два провала (`Failed asserting that false is true`).

- [ ] **Step 2: Миграции**

`2026_10_11_100000_add_expires_at_to_personal_access_tokens_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Sanctum 4 reads an expiry per token; tokens issued before 2.0 keep none (they never expire) */
class AddExpiresAtToPersonalAccessTokensTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
}
```

`2026_10_11_100100_add_two_factor_confirmed_at_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Fortify's 'confirm' option: two-factor login is on only after the user entered a valid code once */
class AddTwoFactorConfirmedAtToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_confirmed_at');
        });
    }
}
```

- [ ] **Step 3: Тест проходит**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'UpgradeMigrationsTest'
```

Ожидается: `OK (2 tests…)`.

- [ ] **Step 4: Commit**

```bash
cd "$REPO" && git add tests/Feature/UpgradeMigrationsTest.php database/migrations/2026_10_11_100000_add_expires_at_to_personal_access_tokens_table.php database/migrations/2026_10_11_100100_add_two_factor_confirmed_at_to_users_table.php
git commit -q -m "Migrations for Sanctum 4 token expiry and Fortify's confirmed two-factor setup" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Livewire 3 и Alpine 3 в наших компонентах и шаблонах

**Files:**
- Create: `config/livewire.php`
- Modify: `resources/views/livewire/{cms,editlabel,editproject,editscript,addstaticip,notification-settings,switch-settings}.blade.php`
- Modify: `resources/views/livewire/uplink-indicator.blade.php:11`, `resources/views/livewire/addimage.blade.php:13`
- Modify: `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php`
- Modify: `app/Http/Livewire/Images.php:11`, `app/Http/Livewire/NotificationSettings.php:63,102`
- Modify: `tests/Feature/ModuleCardTest.php:259`, `tests/Feature/ProvisioningLogTest.php:54,62`

**Interfaces:**
- Consumes: Livewire 3.8 из задачи 2.
- Produces: компоненты находятся по-старому (`livewire:uplink-indicator`, `Livewire::test('cm-card')`). Свой JS-бандл страницы больше не подключают. `NotificationSettings::channelRules(?NotificationChannel $existing): array`.

`php artisan livewire:upgrade` здесь не нужен: инвентаризация дала полный список правок, а команда перенесла бы классы в `App\Livewire`, что мы не делаем.

- [ ] **Step 1: Зафиксировать падения**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'UplinkIndicatorTest|ProvisioningStatusTest|ProvisioningLogTest|ModuleCardTest|ModuleIdentifyTest|NotificationSettingsTest|SwitchSettingsTest|StaticIpSettingsTest|ImagesPageRobustnessTest|LocalTimeDisplayTest|LabelPrintingTest'
```

Ожидается: много ошибок вида `Unable to find component` или про `payload`. Перечень сохранить для сравнения.

- [ ] **Step 2: `config/livewire.php`**

```php
<?php

/* Only what cmprovision changes; Livewire merges its defaults for everything else */
return [

    /* The components stayed where Livewire 2 kept them; Livewire 3 would look in App\Livewire */
    'class_namespace' => 'App\\Http\\Livewire',

    /* Full-page components render inside the Jetstream layout, as they did under Livewire 2 */
    'layout' => 'layouts.app',

];
```

- [ ] **Step 3: `wire:model`**

В Livewire 3 простой `wire:model` откладывает отправку, `.live` отправляет сразу, `.defer` больше нет. Порядок команд важен: сначала простые → `.live`, потом `.defer` → простые.

```bash
cd "$REPO/resources/views/livewire"
sed -i 's/wire:model="/wire:model.live="/g' cms.blade.php editlabel.blade.php editproject.blade.php notification-settings.blade.php switch-settings.blade.php
sed -i 's/wire:model\.defer="/wire:model="/g' editlabel.blade.php editproject.blade.php editscript.blade.php addstaticip.blade.php notification-settings.blade.php switch-settings.blade.php
grep -rn 'wire:model\.defer' . ; grep -rc 'wire:model\.live' . | grep -v ':0'
```

Ожидается: `.defer` не найден. `.live` есть в cms (1), editlabel (1), editproject (2), notification-settings (1), switch-settings (3).

- [ ] **Step 4: Alpine 3 в наших шаблонах**

- `uplink-indicator.blade.php`: `@click.away="open = false"` → `@click.outside="open = false"`.
- `addimage.blade.php:13`: удалить ` x-init="init()"` в конце строки — Alpine 3 сам вызывает метод `init()` объекта из `x-data`.

- [ ] **Step 5: Макеты без Mix и без своего JS**

`layouts/app.blade.php`: строку `<link rel="stylesheet" href="{{ mix('css/app.css') }}">` заменить на

```blade
        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
```

Строку `<script src="{{ mix('js/app.js') }}" defer></script>` и комментарий `<!-- Scripts -->` над ней удалить: Alpine приходит с `@livewireScripts`.

`layouts/guest.blade.php`: ту же замену ссылки на CSS, удалить скрипт `mix('js/app.js')`. Перед `</head>` добавить `@livewireStyles`, перед `</body>` — `@livewireScripts`: странице кода 2FA нужен Alpine.

- [ ] **Step 6: Компоненты**

- `Images.php:11`: удалить строку `use \Livewire\WithFileUploads;` — загрузка идёт через `AddImageController`.
- `NotificationSettings.php`: `protected function rules($existing)` → `protected function channelRules($existing)`. Вызов в `save()`: `$this->validate($this->rules($existing), [` → `$this->validate($this->channelRules($existing), [`. Livewire 3 сам вызывает метод `rules()` без аргументов, и обязательный аргумент привёл бы к ошибке.

- [ ] **Step 7: Тесты читают HTML через API Livewire 3**

- `ModuleCardTest.php:259`: `Livewire::test('cm-card', ['serial' => self::SERIAL])->payload['effects']['html'];` → `Livewire::test('cm-card', ['serial' => self::SERIAL])->html();`
- `ProvisioningLogTest.php:54`: `Livewire::test(ProvisioningLog::class)->payload['effects']['html']` → `Livewire::test(ProvisioningLog::class)->html()`
- `ProvisioningLogTest.php:62`: то же.

- [ ] **Step 8: Компоненты зелёные**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'UplinkIndicatorTest|ProvisioningStatusTest|ProvisioningLogTest|ModuleCardTest|ModuleIdentifyTest|NotificationSettingsTest|SwitchSettingsTest|StaticIpSettingsTest|ImagesPageRobustnessTest|LocalTimeDisplayTest|LabelPrintingTest'
w grep -E "^[0-9]+\) |Unable to locate a class or view for component" /tmp/phpunit-filter.log | head -20
```

Ожидается: оставшиеся падения — только тесты целых страниц (`->get('/dashboard')`, `/cms/…`) с ошибкой `Unable to locate a class or view for component [jet-…]`. Их чинит задача 6. Любое другое падение исправить здесь.

- [ ] **Step 9: Commit**

```bash
cd "$REPO" && git add config/livewire.php resources/views/livewire resources/views/layouts app/Http/Livewire/Images.php app/Http/Livewire/NotificationSettings.php tests/Feature/ModuleCardTest.php tests/Feature/ProvisioningLogTest.php
git commit -q -m "Livewire 3 and Alpine 3 in our components: wire:model, click.outside, no own JS bundle" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Страницы Firmware и Projects на Livewire 3, имя файла прошивки только из хранилища

**Files:**
- Create: `tests/Feature/ProjectsFirmwareTest.php`
- Modify: `app/Http/Livewire/Firmwares.php`, `app/Http/Livewire/Projects.php`

**Interfaces:**
- Consumes: `ProjectActivationTest::eepromImage(string $config): string` — готовый образ EEPROM с разделом `bootconf.txt`. `Firmware::all()` возвращает объекты со свойством `path` вида `<канал>/pieeprom-….bin`.
- Produces: `Projects::rules(): array` (вместо свойства `$rules`), `protected Projects::getEepromSettingsFromFirmwareFile(string $path): string` (`''` для всего, что не из хранилища), `protected Projects::firmwarePaths(): array`. Метод `getEepromSettings()` удалён. Шаблоны получают `$firmware` и `$firmwareChannels` как данные представления, а не как свойства компонента.

- [ ] **Step 1: Падающие тесты**

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Firmwares;
use App\Http\Livewire\Projects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The firmware lists are plain data for Livewire 3, and a project only takes a file of the firmware
 * store: the name comes from the browser, so it must not reach other files of the server.
 */
class ProjectsFirmwareTest extends TestCase
{
    use RefreshDatabase;

    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/fw-'.uniqid();
        mkdir($this->dir.'/default', 0755, true);
        file_put_contents($this->dir.'/default/pieeprom-2026-09-23.bin', ProjectActivationTest::eepromImage("[all]\nBOOT_UART=0\n"));
        file_put_contents($this->dir.'/outside.bin', ProjectActivationTest::eepromImage("[all]\nSECRET=1\n"));
        config(['cmprovision.firmware_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_firmware_page_lists_the_store()
    {
        Livewire::test(Firmwares::class)->assertSee('pieeprom-2026-09-23.bin')->assertSee('default');
    }

    public function test_choosing_a_firmware_fills_in_its_settings()
    {
        Livewire::test(Projects::class)
            ->call('create')
            ->set('firmware', 'default/pieeprom-2026-09-23.bin')
            ->assertSet('eeprom_settings', "[all]\nBOOT_UART=0\n");
    }

    public function test_a_file_outside_the_store_gives_no_settings_and_cannot_be_saved()
    {
        Livewire::test(Projects::class)
            ->call('create')
            ->set('name', 'x')
            ->set('firmware', '../outside.bin')
            ->assertSet('eeprom_settings', '')
            ->call('store')
            ->assertHasErrors(['firmware']);
    }

    public function test_the_settings_reader_is_not_an_action()
    {
        $this->expectException(MethodNotFoundException::class);

        Livewire::test(Projects::class)->call('getEepromSettingsFromFirmwareFile', '../outside.bin');
    }
}
```

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'ProjectsFirmwareTest|FirmwarePagesTest'
```

Ожидается: падения. Livewire 3 не сериализует объекты `Firmware` (сообщение про неподдерживаемый тип свойства). Методы ещё публичные, поэтому `MethodNotFoundException` не бросается. Файл вне хранилища читается.

- [ ] **Step 2: `Firmwares`**

Убрать `$firmware` из публичных свойств (`public $lastUpdate;`). `render()`:

```php
    public function render()
    {
        $setting = Setting::find('firmware_last_update');
        $this->lastUpdate = $setting ? Carbon::parse($setting->value, 'UTC')->local()->toDateTimeString() : null;

        // view data, not a property: Livewire 3 does not serialize Firmware objects
        return view('livewire.firmware', ['firmware' => Firmware::all()]);
    }
```

- [ ] **Step 3: `Projects`**

- Убрать `$firmwareChannels` из `public $images, $labels, $scripts, $firmwareChannels;`.
- Свойство `protected $rules = [...]` заменить методом. Добавить `use Illuminate\Validation\Rule;`.

```php
    protected function rules()
    {
        return [
            'name' => 'required|max:255',
            'device' => 'required|in:cm4',
            'storage' => 'required|max:255',
            'image_id' => 'nullable|numeric',
            'label_id' => 'nullable|numeric',
            // a file of the firmware store only: the name comes from the browser
            'firmware' => ['nullable', 'max:255', Rule::in($this->firmwarePaths())],
            'eeprom_settings' => 'nullable|max:2024',
            'label_moment' => 'required|in:never,preinstall,postinstall',
            'selectedScripts' => 'array',
            'verify' => 'required|boolean',
        ];
    }
```

- В `render()` цикл заполнения `$this->firmwareChannels` заменить локальной переменной и передать её в представление:

```php
        $firmwareChannels = [];
        foreach (Firmware::channels() as $channel)
        {
            $firmwareChannels[$channel] = Firmware::allOfChannel($channel);
        }
```

```php
        // view data, not a property: Livewire 3 does not serialize Firmware objects
        return view('livewire.projects', ['firmwareChannels' => $firmwareChannels]);
```

- Два последних метода заменить:

```php
    /** the settings embedded in an image of the firmware store; '' for anything else */
    protected function getEepromSettingsFromFirmwareFile($path)
    {
        if (!in_array($path, $this->firmwarePaths(), true))
            return '';
        $data = @file_get_contents(Firmware::basedir().'/'.$path);
        return $data === false ? '' : (string) EepromImage::getConfig($data);
    }

    /** "<channel>/pieeprom-....bin" of every image in the store */
    protected function firmwarePaths()
    {
        return array_map(function (Firmware $f) { return $f->path; }, Firmware::all());
    }
```

`projects.blade.php` подключает `editproject` через `@include`, поэтому `$firmwareChannels` доходит до формы без изменений.

- [ ] **Step 4: Тесты проходят**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests 'ProjectsFirmwareTest|FirmwarePagesTest|ProjectActivationTest'
```

Ожидается: `OK`.

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add tests/Feature/ProjectsFirmwareTest.php app/Http/Livewire/Firmwares.php app/Http/Livewire/Projects.php
git commit -q -m "Firmware and Projects pages on Livewire 3; a project takes only files of the firmware store" -m "The EEPROM settings reader is no longer a public action, and the firmware name from the browser" -m "must be one of the store's images (it could point outside the store before)." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Jetstream 5: шаблоны, действия, возможности и тесты

**Files:**
- Replace from `/root/dev/vendor/laravel/jetstream/stubs`: `resources/views/components/` (новый), `resources/views/{api,profile}/`, `resources/views/auth/{login,two-factor-challenge,confirm-password}.blade.php`, `app/Actions/Fortify/*`, `app/Actions/Jetstream/DeleteUser.php`, `tests/Feature/{AuthenticationTest,PasswordConfirmationTest,ApiTokenPermissionsTest,BrowserSessionsTest,CreateApiTokenTest,DeleteApiTokenTest,ProfileInformationTest,TwoFactorAuthenticationSettingsTest,UpdatePasswordTest}.php`
- Delete: `resources/views/vendor/jetstream/`, `resources/views/auth/{register,forgot-password,reset-password,verify-email}.blade.php`, `tests/Feature/{DeleteAccountTest,EmailVerificationTest,PasswordResetTest,RegistrationTest}.php`
- Modify: `resources/views/navigation-menu.blade.php`, `resources/views/layouts/app.blade.php`, остальные наши шаблоны с `x-jet-`
- Modify: `config/fortify.php`, `config/jetstream.php`, `routes/web.php`

**Interfaces:**
- Consumes: ключи `jetstream.auth_session` и `jetstream.guard` из задачи 2. Livewire 3 из задачи 4.
- Produces: анонимные компоненты `<x-…>` Jetstream 5 в `resources/views/components`. Маршруты приложения под `auth:sanctum` + `config('jetstream.auth_session')` + `verified`.

- [ ] **Step 1: Скопировать заготовки Jetstream 5.5**

```bash
. "$SP/wsl-bench/dev.sh"; cd "$REPO"
J=/root/dev/vendor/laravel/jetstream/stubs
git rm -rq resources/views/vendor/jetstream resources/views/auth/register.blade.php resources/views/auth/forgot-password.blade.php resources/views/auth/reset-password.blade.php resources/views/auth/verify-email.blade.php
git rm -q tests/Feature/DeleteAccountTest.php tests/Feature/EmailVerificationTest.php tests/Feature/PasswordResetTest.php tests/Feature/RegistrationTest.php
w_raw tar -C $J/livewire/resources/views -cf - components api profile auth/login.blade.php auth/two-factor-challenge.blade.php auth/confirm-password.blade.php | tar -C resources/views -xf -
w_raw tar -C $J/app/Actions -cf - Fortify Jetstream/DeleteUser.php | tar -C app/Actions -xf -
w_raw tar -C $J/tests -cf - AuthenticationTest.php PasswordConfirmationTest.php | tar -C tests/Feature -xf -
w_raw tar -C $J/tests/livewire -cf - ApiTokenPermissionsTest.php BrowserSessionsTest.php CreateApiTokenTest.php DeleteApiTokenTest.php ProfileInformationTest.php TwoFactorAuthenticationSettingsTest.php UpdatePasswordTest.php | tar -C tests/Feature -xf -
ls resources/views/components | wc -l; git status --short | head -40
```

Ожидается: 28 компонентов. В `api/`, `profile/`, `auth/`, `app/Actions/` и семи тестах — изменения или новые файлы.

- [ ] **Step 2: Наши шаблоны на компоненты без префикса**

```bash
cd "$REPO" && grep -rl "x-jet-" resources/views | xargs -r sed -i 's/x-jet-/x-/g'; grep -rn "x-jet-" resources/views || echo "no x-jet- left"
```

Ожидается: `no x-jet- left`. Среди изменённых — `navigation-menu`, `layouts/app` (`<x-banner />`), `dashboard`, `policy`, `terms`. В `navigation-menu` остаются ссылки приложения и `<livewire:uplink-indicator />`. Компонент `switchable-team` нужен: шаблон Blade разбирает его даже внутри выключенного блока команд. Наша правка кнопок из коммита 9b815f8 (`focus:shadow-outline-gray` → `focus:ring`) больше не нужна: заготовки Jetstream 5 уже используют `focus:ring`.

- [ ] **Step 3: Возможности**

`config/fortify.php`, блок `features`:

```php
    'features' => [
        // Features::registration(),
        // Features::resetPasswords(),
        // Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
```

`config/jetstream.php`, блок `features`:

```php
    'features' => [
        // Features::termsAndPrivacyPolicy(),
        // Features::profilePhotos(),
        Features::api(),
        // Features::teams(['invitations' => true]),
        // account deletion is off: it could remove the last administrator
    ],
```

- [ ] **Step 4: Маршруты приложения проверяют сессию, как маршруты Jetstream**

```bash
cd "$REPO" && sed -i "s/\['auth:sanctum', 'verified'\]/['auth:sanctum', config('jetstream.auth_session'), 'verified']/" routes/web.php && grep -c "jetstream.auth_session" routes/web.php
```

Ожидается: `10`.

- [ ] **Step 5: Весь набор зелёный**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests_all
```

Ожидается: `phpunit exit 0`. В «logged PHP messages» нет предупреждений из `app/` и `vendor/laravel/framework`. Если падает тест из заготовок Jetstream — сверить его с нашими настройками возможностей: тесты Jetstream сами пропускают себя для выключенных возможностей. Ожидания заготовок не менять.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add -A resources/views app/Actions tests/Feature config/fortify.php config/jetstream.php routes/web.php
git commit -q -m "Jetstream 5.5 views, Fortify actions and tests; 2FA with confirmation, no account deletion or password reset" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Tailwind 3.4 бинарником, без Mix и Node

**Files:**
- Create на стенде: `/root/bench/25-css.sh`
- Modify: `tailwind.config.js`, `resources/css/app.css`, `public/css/app.css`, `package.json`, `resources/views/livewire/images.blade.php:69`
- Delete: `webpack.mix.js`, `resources/js/app.js`, `resources/js/bootstrap.js`, `public/js/app.js`, `public/js/app.js.LICENSE.txt`, `public/mix-manifest.json`, `package-lock.json`

**Interfaces:**
- Produces: `public/css/app.css`, собранный Tailwind CSS 3.4.19 по нашим шаблонам, шаблонам Jetstream в `vendor` и скомпилированным представлениям.

- [ ] **Step 1: Скрипт сборки со сверкой SHA256**

```bash
. "$SP/wsl-bench/dev.sh"
cat > "$SP/wsl-bench/25-css.sh" <<'EOF'
#!/bin/sh
# Bench only: build the CSS of /root/dev with the standalone Tailwind CLI (no Node/npm; the binary
# includes the official forms and typography plugins). Output: /tmp/app.css with its size and md5.
set -e
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
V=3.4.19
BIN=/root/bench/bin/tailwindcss-$V
if [ ! -x "$BIN" ]; then
    mkdir -p /root/bench/bin
    curl -fsSL -o "$BIN.tmp" "https://github.com/tailwindlabs/tailwindcss/releases/download/v$V/tailwindcss-linux-x64"
    # GitHub computes the digest of every release asset itself
    WANT=$(curl -fsSL "https://api.github.com/repos/tailwindlabs/tailwindcss/releases/tags/v$V" | php -r '
        foreach (json_decode(stream_get_contents(STDIN), true)["assets"] as $a)
            if ($a["name"] === "tailwindcss-linux-x64") echo substr($a["digest"] ?? "", 7);')
    GOT=$(sha256sum "$BIN.tmp" | cut -d" " -f1)
    [ -n "$WANT" ] && [ "$WANT" = "$GOT" ] || { echo "checksum mismatch: want '$WANT' got '$GOT'"; rm -f "$BIN.tmp"; exit 1; }
    chmod +x "$BIN.tmp" && mv "$BIN.tmp" "$BIN"
    echo "tailwindcss $V: sha256 $GOT"
fi
cd /root/dev
php artisan view:cache > /dev/null      # compiled views are part of Tailwind's content
"$BIN" -c tailwind.config.js -i resources/css/app.css -o /tmp/app.css --minify 2>&1 | tail -3
echo "app.css: $(wc -c < /tmp/app.css) bytes, md5 $(md5sum /tmp/app.css | cut -d' ' -f1)"
EOF
w_raw sh -c 'cat > /root/bench/25-css.sh && chmod +x /root/bench/25-css.sh' < "$SP/wsl-bench/25-css.sh"
```

- [ ] **Step 2: `tailwind.config.js` для 3.4**

```js
const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');

/* Built with the standalone Tailwind CLI 3.4 (the forms and typography plugins are inside), see CLAUDE.md */
module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Jetstream 5's views carry dark: classes; the app stays light whatever the operator's OS theme is
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
            },
            // Tailwind 3 renamed v2's green, yellow and purple to emerald, amber and violet: keep the v2 look
            colors: {
                green: colors.emerald,
                yellow: colors.amber,
                purple: colors.violet,
            },
            width: {
                '1/8': '12.5%',
            },
        },
    },

    plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')],
};
```

- [ ] **Step 3: `resources/css/app.css`**

Три строки `@import 'tailwindcss/…';` заменить на:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

/* what Alpine shows only after it has started */
[x-cloak] {
    display: none !important;
}
```

Блоки `@font-face` Nunito остаются: пути `../fonts/…` из `public/css` ведут в закоммиченный `public/fonts`.

В `resources/views/livewire/images.blade.php` удалить строку 69 `<style>[x-cloak] { display: none !important; }</style>`.

- [ ] **Step 4: Убрать Mix и JS-бандл**

```bash
cd "$REPO" && git rm -q webpack.mix.js resources/js/app.js resources/js/bootstrap.js public/js/app.js public/js/app.js.LICENSE.txt public/mix-manifest.json package-lock.json
cat > package.json <<'EOF'
{
    "private": true,
    "scripts": {
        "test:js": "node --test tests/js/upload-progress.test.mjs"
    }
}
EOF
```

`"type": "module"` не добавлять: тест загружает `upload-progress.js` через `createRequire`.

- [ ] **Step 5: Собрать и проверить CSS**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && w sh /root/bench/25-css.sh && w_raw cat /tmp/app.css > "$REPO/public/css/app.css"
cd "$REPO" && git show HEAD:public/css/app.css > "$SP/app.v2.css"
cls() { grep -oE '\.[-a-zA-Z0-9_\\:/.]+' "$1" | tr -d '\\' | sort -u; }
cls "$SP/app.v2.css" > "$SP/cls.v2"; cls public/css/app.css > "$SP/cls.v3"
echo "== in v2, not in v3:"; comm -23 "$SP/cls.v2" "$SP/cls.v3" | tr '\n' ' '; echo
grep -c "prefers-color-scheme" public/css/app.css || echo "no prefers-color-scheme"
for c in text-yellow-600 w-80 top-full tabular-nums bg-teal-100 text-orange-700 w-1\\/8 form-checkbox; do grep -q "\.$c" public/css/app.css && printf "%s+ " "$c" || printf "%s- " "$c"; done; echo
```

Ожидается:
- `tailwindcss 3.4.19: sha256 …` (при первом запуске) и размер CSS.
- Список «in v2, not in v3» — только классы из удалённых шаблонов (register, forgot-password, старые компоненты Jetstream 2) и служебные имена Tailwind 2. Каждый оставшийся проверить: `grep -rn "<класс>" resources/views` должен ничего не находить. Если находит — значит, Tailwind 3 класс переименовал (например, `overflow-ellipsis` → `text-ellipsis`, `flex-grow-0` → `grow-0`, `decoration-clone` → `box-decoration-clone`). Переименовать его в шаблоне и повторить шаг 5.
- `no prefers-color-scheme` (пункт 5 Review Focus).
- Все перечисленные классы с `+`.

- [ ] **Step 6: Тесты PHP и JS**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests_all
cd "$REPO" && node --test tests/js/upload-progress.test.mjs 2>&1 | tail -3
```

Ожидается: `phpunit exit 0` и `# pass 6`, `# fail 0`.

- [ ] **Step 7: Commit**

```bash
cd "$REPO" && git add -A tailwind.config.js resources/css/app.css public/css/app.css package.json resources/views/livewire/images.blade.php
git commit -q -m "Tailwind 3.4 built by the standalone CLI; Laravel Mix, the JS bundle and Node packages are gone" -m "darkMode is 'class' so Jetstream 5's dark: classes never apply; v2 green, yellow and purple keep their colours." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Пакет 2.0, документация, стенд Docker

**Files:**
- Modify: `debian/control`, `debian/source/lintian-overrides`, `debian/changelog`, `debian/env.example`
- Create: `debian/preinst`
- Modify: `docker/docker-compose.yml`, `docker/Dockerfile`, `docker/bench.sh`, `docker/README.md`
- Modify: `README.md`, `CLAUDE.md`
- Modify на стенде: `/root/bench/03-deb.sh` (путь сайта nginx)

**Interfaces:**
- Produces: `cmprovision4_2.0.0~dev1_all.deb`. Он ставится только при PHP ≥ 8.3, а при обновлении с 1.x до распаковки делает снимок `cmprovision-backup`.

- [ ] **Step 1: `debian/control`**

В строке `Depends:` каждое `(>=7.3)` у `php-*` заменить на `(>= 2:8.3)`:

```bash
cd "$REPO" && sed -i 's/(>=7\.3)/(>= 2:8.3)/g' debian/control && grep -o "php-[a-z0-9]* ([^)]*)" debian/control
```

Ожидается: восемь пакетов `php-…`, у всех `(>= 2:8.3)`.

- [ ] **Step 2: `debian/preinst`**

```sh
#!/bin/sh
# Before an upgrade from 1.x to 2.0 (Laravel 13, new database columns) keep a snapshot of the data:
# cmprovision-restore <snapshot> puts 1.x back. Uploaded images are not in it: the upgrade leaves them alone.
set -e

if [ "$1" = "upgrade" ] && [ -n "$2" ] && dpkg --compare-versions "$2" lt "2.0.0~"; then
    if [ -x /usr/sbin/cmprovision-backup ]; then
        /usr/sbin/cmprovision-backup || echo "cmprovision: the snapshot before the upgrade failed, continuing" >&2
    fi
fi

#DEBHELPER#

exit 0
```

- [ ] **Step 3: lintian, шаблон `.env`, changelog**

- `debian/source/lintian-overrides`: удалить строку про `public/js/app.js`.
- `debian/env.example`: удалить строки `MIX_PUSHER_APP_KEY=…` и `MIX_PUSHER_APP_CLUSTER=…`, если они есть.
- `debian/changelog`: новая верхняя запись. Дату взять командой `date -R`.

```
cmprovision4 (2.0.0~dev1) unstable; urgency=medium

  * Laravel 13 on PHP 8.3 or newer: Debian 13 / Raspberry Pi OS Trixie only.
    Livewire 3, Jetstream 5.5 (Fortify, Sanctum 4), PHPUnit 12. The 1.6
    line stays on Debian 11 and 12 and gets no new features.
  * Two-factor login now asks for a code once before it is switched on.
    Account deletion and password reset by email are off; a forgotten
    password is replaced on the server with auth:create-user.
  * The styles are built with the standalone Tailwind CLI 3.4; the
    package needs no Node and ships no JavaScript bundle (Alpine comes
    with Livewire). The look is unchanged.
  * Upgrading from 1.x first takes a cmprovision-backup snapshot of the
    data; cmprovision-restore <snapshot> goes back.
  * A label print command's output goes to the module log even when the
    command exits 0. A project takes only images of the firmware store.

 -- nimbo78 <igor.sin.78@gmail.com>  <date -R>
```

- [ ] **Step 4: Стенд Docker только на trixie**

- `docker/docker-compose.yml`: удалить сервис `bullseye` целиком (с томом `composer-bullseye` в списке томов, если он там объявлен).
- `docker/Dockerfile`: `ARG DEBIAN=bullseye` → `ARG DEBIAN=trixie`, `ARG PHPV=7.4` → `ARG PHPV=8.4`.
- `docker/bench.sh`: `svc=bullseye` → `svc=trixie`. В строке разбора аргумента `case "${1:-}" in bullseye|trixie)` оставить только `trixie`. В справке убрать `[bullseye|trixie]` и `(default: bullseye)`.
- `docker/README.md`: убрать упоминания bullseye и PHP 7.4.

- [ ] **Step 5: README и CLAUDE.md**

`README.md`:
- раздел «On **Bullseye** (dhcpcd) …» с блоком кода удалить;
- `Raspberry Pi OS **Bookworm or later**` → `Raspberry Pi OS **Trixie**`;
- строки 131–135 заменить:

```markdown
* the styles are built with the standalone Tailwind CLI 3.4 (no Node needed): `tailwindcss -c tailwind.config.js -i resources/css/app.css -o public/css/app.css --minify` after `php artisan view:cache`, then commit `public/css/app.css`.
* tests: `php artisan test` (phpunit.xml runs them on an in-memory SQLite database) and `npm run test:js` (Node 20 or newer).
* requirements: Debian 13 / Raspberry Pi OS Trixie or newer, PHP 8.3 or newer. Version 1.6.11 is the last one for Debian 11 and 12.
* `docker/` holds a test bench (Debian 13 with PHP 8.4) laid out like the installed package, see `docker/README.md`.
```

`CLAUDE.md`:
- строка 7 (стек): `Laravel 8, Jetstream (Livewire 2 + Fortify + Sanctum), Tailwind 2 через Laravel Mix` → `Laravel 13, Jetstream 5.5 (Livewire 3 + Fortify + Sanctum 4), Tailwind 3.4 (самостоятельный бинарник, без Node)`;
- абзац строки 11: требования «PHP 7.4» и «один lock для 7.4 и 8.4» заменить:

```markdown
С 2.0 приложение требует PHP 8.3 и новее и ставится только на Debian 13 / Raspberry Pi OS Trixie (в `debian/control` зависимости `php-*` с эпохой: `>= 2:8.3`). `composer.json` закрепляет `config.platform.php = 8.3.0`, чтобы lock подходил и для 8.3, и для 8.4. Ветка 1.6.x (Laravel 8, PHP 7.4) заморожена на теге `v1.6.11`; рабочий провижинер пользователя — Raspberry Pi OS Trixie (PHP 8.4) на WLAN Pi M4+.
```

- строки 15–17 («Стенд», «Зависимости», «Ассеты»):

```markdown
- Стенд: `docker/bench.sh` поднимает контейнер trixie (PHP 8.4) с nginx и php-fpm в раскладке пакета; подробности в [docker/README.md](docker/README.md). На Windows ничего не запускается — стенд живёт на Docker-хосте или в WSL-дистрибутиве.
- Зависимости: `composer install`. Node нужен только для `npm run test:js`.
- Стили: `php artisan view:cache`, затем `tailwindcss -c tailwind.config.js -i resources/css/app.css -o public/css/app.css --minify` бинарником Tailwind CLI 3.4.19 (плагины forms и typography внутри); результат коммитится. Собирайте не в OneDrive (там нельзя `vendor/`): в WSL-стенде это `/root/bench/25-css.sh`.
```

- строка 18 (Тесты): `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test` → `php artisan test` (база в памяти задана в `phpunit.xml`). Убрать ссылку «Зачем переменные — см. «Тесты»». В разделе «Тесты» абзац про `RefreshDatabase` заменить: `phpunit.xml` задаёт SQLite в памяти, поэтому тесты не трогают базу из `.env`.
- строки 89–91 и 100 (раздел «Фронтенд»):

```markdown
- Livewire 3: классы компонентов остаются в `App\Http\Livewire` (`class_namespace` в `config/livewire.php`), макет полностраничных компонентов — `layouts.app`. Простой `wire:model` отправляет значение с действием, `wire:model.live` — сразу. Методы компонента, которые не должны вызываться из браузера, — `protected`: всё публичное доступно как действие. Объекты, которые Livewire 3 не сериализует (например, `App\Models\Firmware`), передаются в шаблон данными `render()`, а не публичными свойствами.
- Шаблоны Jetstream 5 опубликованы в `resources/views/{components,api,profile,auth}` (компоненты `<x-…>` без префикса). В них есть классы `dark:`, поэтому в `tailwind.config.js` стоит `darkMode: 'class'`: тёмная тема ОС интерфейс не перекрашивает.
- Собранный `public/css/app.css` закоммичен и попадает в `.deb` как есть. Своего JS-бандла нет: Alpine 3 приходит со скриптами Livewire, `public/js/upload-progress.js` — обычный скрипт без сборки, покрыт `tests/js`.
```

  Пункт про тестовый помощник Livewire 2 (`wire:initial-data`) удалить: в Livewire 3 его нет.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add -A debian docker README.md CLAUDE.md
git commit -q -m "Package 2.0: PHP 8.3+ only, snapshot before an upgrade from 1.x, docs and the Docker bench for Trixie" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Собрать пакет на стенде и поставить**

В `/root/bench/03-deb.sh` путь `/etc/nginx/sites-enabled/cmprovision` заменить на `/etc/nginx/sites-enabled/cmprovision.conf` (с 1.6.10 ссылка называется так).

```bash
. "$SP/wsl-bench/dev.sh"
w sed -i 's#sites-enabled/cmprovision$#sites-enabled/cmprovision.conf#' /root/bench/03-deb.sh
git -C "$REPO" archive HEAD | w_raw sh -c 'rm -rf /root/src && mkdir -p /root/src && tar -x -C /root/src'
w sh /root/bench/03-deb.sh
```

Ожидается:
- `=== build 2.0.0~dev1`, размер `.deb`, `phpunit in deb: 0`;
- после установки `cmprovision4 2.0.0~dev1 install ok installed`;
- `nginx`, `php8.4-fpm`, `cmprovision-queue`: `active`;
- `web: 200`.

Если сборка или установка падает — исправление отдельным коммитом, затем шаг 7 заново.

---

### Task 9: Репетиция обновления 1.6.11 → 2.0 на стенде и отката

**Files:**
- Create на стенде: `/root/bench/26-upgrade-2.0.sh`

**Interfaces:**
- Consumes: `/root/build/cmprovision4_2.0.0~dev1_all.deb` (задача 8). Пакет 1.6.11, собранный с тега. Снимок данных провижинера.

- [ ] **Step 1: Пакет 1.6.11 с тега и свежий снимок провижинера**

```bash
. "$SP/wsl-bench/dev.sh"; cd "$REPO"
git archive v1.6.11 | w_raw sh -c 'rm -rf /root/src-1611 && mkdir -p /root/src-1611 && tar -x -C /root/src-1611'
w sh -c 'export COMPOSER_ALLOW_SUPERUSER=1; rm -rf /root/b1611 && mkdir -p /root/b1611/cmprovision4-1.6.11 && cp -a /root/src-1611/. /root/b1611/cmprovision4-1.6.11/ && cd /root/b1611/cmprovision4-1.6.11 && dpkg-buildpackage -b -us -uc > /root/b1611/build.log 2>&1; echo "build exit $?"; ls /root/b1611/*.deb'
ssh -o BatchMode=yes claude@192.168.25.30 'sudo rm -rf /home/claude/rehearsal && sudo cmprovision-backup /home/claude/rehearsal >/dev/null && sudo tar -C /home/claude/rehearsal -cf - .' | w_raw sh -c 'rm -rf /root/snap && mkdir -p /root/snap && tar -x -C /root/snap && ls /root/snap'
```

Ожидается: `build exit 0`, `/root/b1611/cmprovision4_1.6.11_all.deb` и каталог снимка в `/root/snap` (без образов).

- [ ] **Step 2: Скрипт репетиции**

```bash
cat > "$SP/wsl-bench/26-upgrade-2.0.sh" <<'EOF'
#!/bin/sh
# Bench only: rehearse the upgrade of a real installation from 1.6.11 to 2.0, and the way back.
#   26-upgrade-2.0.sh <1.6.11 .deb> <2.0 .deb> <snapshot dir with the production data>
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin DEBIAN_FRONTEND=noninteractive LC_ALL=C.UTF-8
OLD=$1; NEW=$2; SNAP=$3; APP=/var/lib/cmprovision; JAR=/tmp/rehearsal.cookies; B=http://127.0.0.1
pass=0; fail=0
check() { if [ "$2" = "$3" ]; then echo "PASS $1"; pass=$((pass+1)); else echo "FAIL $1: got '$2', want '$3'"; fail=$((fail+1)); fi; }
tk() { (cd $APP && sudo -u www-data HOME=/tmp php artisan tinker --execute="$1" 2>/dev/null | tail -1); }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

echo "== 1.6.11 with the production data"
# cmprovision-backup puts a reinstallable .deb into a snapshot only with dpkg-repack
apt-get install -y -q dpkg-repack > /dev/null 2>&1
apt-get purge -y -q cmprovision4 > /dev/null 2>&1; rm -rf $APP
apt-get install -y -q /root/rpiboot_bench_all.deb "$OLD" > /tmp/r-old.log 2>&1 || { tail -20 /tmp/r-old.log; exit 1; }
cmprovision-restore --data-only "$SNAP" > /tmp/r-restore.log 2>&1
check "1.6.11 installed" "$(dpkg-query -W -f='${Version}' cmprovision4)" "1.6.11"

PW=$(head -c 18 /dev/urandom | base64 | tr -dc A-Za-z0-9)
tk "App\Models\User::where('email','like','rehearsal%')->delete(); \$u=App\Models\User::forceCreate(['name'=>'rehearsal','email'=>'rehearsal@localhost','password'=>bcrypt('$PW')]); \$v=App\Models\User::forceCreate(['name'=>'rehearsal 2fa','email'=>'rehearsal-2fa@localhost','password'=>bcrypt('$PW'),'two_factor_secret'=>encrypt('JBSWY3DPEHPK3PXP'),'two_factor_recovery_codes'=>encrypt(json_encode(['rehearsal-code']))]); echo 'ok';" > /dev/null
TOKEN=$(tk "echo App\Models\User::where('email','rehearsal@localhost')->first()->createToken('rehearsal',['read'])->plainTextToken;")
CHANNELS=$(tk "echo App\Models\NotificationChannel::count();")
CMS=$(tk "echo App\Models\Cm::count();")
rm -f $JAR
CSRF=$(curl -s -c $JAR -b $JAR $B/login | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
check "login on 1.6.11" "$(code -c $JAR -b $JAR -X POST -d "_token=$CSRF&email=rehearsal@localhost&password=$PW" $B/login)" "302"
check "dashboard on 1.6.11" "$(code -b $JAR $B/dashboard)" "200"
BEFORE=$(ls /var/backups/cmprovision 2>/dev/null | wc -l)

echo "== upgrade to $(dpkg-deb -f "$NEW" Version)"
apt-get install -y -q "$NEW" > /tmp/r-new.log 2>&1 || { tail -30 /tmp/r-new.log; exit 1; }
check "2.0 installed" "$(dpkg-query -W -f='${Version}' cmprovision4)" "$(dpkg-deb -f "$NEW" Version)"
check "snapshot taken before the upgrade" "$(ls /var/backups/cmprovision | wc -l)" "$((BEFORE + 1))"
SNAPSHOT=/var/backups/cmprovision/$(ls -t /var/backups/cmprovision | head -1)
check "the session survived (Review Focus 1)" "$(code -b $JAR $B/dashboard)" "200"
rm -f $JAR.2
CSRF2=$(curl -s -c $JAR.2 -b $JAR.2 $B/login | sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' | head -1)
check "login on 2.0 with the 1.6.11 password" "$(code -c $JAR.2 -b $JAR.2 -X POST -d "_token=$CSRF2&email=rehearsal@localhost&password=$PW" $B/login)" "302"
for p in cms images projects scripts labels firmware settings user/profile user/api-tokens; do
    check "page /$p" "$(code -b $JAR $B/$p)" "200"
done
check "css" "$(code $B/css/app.css)" "200"
check "livewire script" "$(code $B/livewire/livewire.min.js)" "200"
check "module POST without CSRF token (Review Focus 2)" "$(code -X POST $B/scriptexecute)" "401"
check "2FA secret decrypts (Review Focus 3)" "$(tk "echo decrypt(App\Models\User::where('email','rehearsal-2fa@localhost')->first()->two_factor_secret);")" "JBSWY3DPEHPK3PXP"
check "channels still there" "$(tk "echo App\Models\NotificationChannel::count();")" "$CHANNELS"
check "channel settings intact (plain JSON in the database)" "$(tk "echo App\Models\NotificationChannel::all()->every(fn(\$c) => is_array(\$c->settings) && count(\$c->settings) > 0) ? 'yes' : 'no';")" "yes"
check "Sanctum 2 token works (Review Focus 4)" "$(code -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' $B/api/projects)" "200"
check "modules kept" "$(tk "echo App\Models\Cm::count();")" "$CMS"
printf 'rehearsal\n' | gzip > $APP/public/uploads/rehearsal.img.gz; chown www-data:www-data $APP/public/uploads/rehearsal.img.gz
tk "\$i=new App\Models\Image; \$i->filename='rehearsal.img.gz'; \$i->filename_extension='gz'; \$i->filename_on_server='rehearsal.img.gz'; \$i->save(); App\Jobs\ComputeSHA256::dispatch(\$i); echo 'ok';" > /dev/null
sleep 10
check "queue computed the SHA256" "$(tk "echo App\Models\Image::where('filename','rehearsal.img.gz')->value('uncompressed_sha256');")" "$(printf 'rehearsal\n' | sha256sum | cut -d' ' -f1)"

echo "== back to 1.6.11 from $SNAPSHOT"
cmprovision-restore "$SNAPSHOT" > /tmp/r-back.log 2>&1
check "1.6.11 back" "$(dpkg-query -W -f='${Version}' cmprovision4)" "1.6.11"
check "dashboard after the rollback" "$(code -b $JAR $B/dashboard)" "200"
check "modules after the rollback" "$(tk "echo App\Models\Cm::count();")" "$CMS"
echo "== $pass passed, $fail failed"
EOF
. "$SP/wsl-bench/dev.sh"; w_raw sh -c 'cat > /root/bench/26-upgrade-2.0.sh && chmod +x /root/bench/26-upgrade-2.0.sh' < "$SP/wsl-bench/26-upgrade-2.0.sh"
```

- [ ] **Step 3: Прогнать**

```bash
. "$SP/wsl-bench/dev.sh"; w sh -c 'sh /root/bench/26-upgrade-2.0.sh /root/b1611/cmprovision4_1.6.11_all.deb "$(ls /root/build/cmprovision4_2.0.0~dev*_all.deb | tail -1)" "$(ls -d /root/snap/*/ | head -1)"'
```

Ожидается: все строки `PASS`, итог `0 failed`. Каждый `FAIL` — дефект 2.0. Его чинят в коде с тестом на PHPUnit, где это возможно, новой сборкой `~devN` (задача 8, шаг 6) и повторной репетицией.

Если классификатор безопасности заблокирует вход на стенде (пароль одноразовый, создаётся скриптом), строки входа и сессии пропускаются. Это отмечается в отчёте, и сессию тогда проверяет пользователь в задаче 10.

- [ ] **Step 4: Записать результат**

Итоговые строки репетиции (число PASS и FAIL) записать в сообщение коммита с исправлениями, если они были. Если исправлений не было — в описание задачи 10.

---

### Task 10: Провижинер: установка, цикл sensor0, проход по интерфейсу

**Files:** — (эксплуатация; найденные дефекты становятся коммитами с тестами)

- [ ] **Step 1: Собрать и поставить на провижинер**

```bash
cd "$REPO" && git archive HEAD | ssh -o BatchMode=yes -o ServerAliveInterval=30 claude@192.168.25.30 'rm -rf ~/cmprovision/build && mkdir -p ~/cmprovision/build/cmprovision && tar -x -C ~/cmprovision/build/cmprovision && cd ~/cmprovision/build/cmprovision && dpkg-buildpackage -b -us -uc -d > ../build.log 2>&1; echo "build exit $?"; cd .. && V=$(ls cmprovision4_2.0.0*_all.deb | tail -1) && export DEBIAN_FRONTEND=noninteractive && sudo -E apt-get install -y -q ./$V > ~/install-2.0.log 2>&1; echo "apt exit $?, installed: $(dpkg-query -W -f="\${Version}" cmprovision4)"; ls -t /var/backups/cmprovision | head -1; systemctl is-active nginx php8.4-fpm cmprovision-dnsmasq cmprovision-queue cmprovision-rpiboot | tr "\n" " "; echo; curl -s -o /dev/null -w "web %{http_code}\n" http://127.0.0.1/login'
```

Ожидается: `build exit 0`, `apt exit 0`, версия `2.0.0~devN`, свежий снимок с версией `1.6.11` в имени, пять `active`, `web 200`.

- [ ] **Step 2: Цикл sensor0 — только после «да» пользователя**

Спросить пользователя, можно ли прошить sensor0. После согласия:
1. Запустить наблюдение (Monitor): `ssh claude@192.168.25.30 '~/cmprovision/watch-module.sh 10000000e611110c 1700'`, фильтр строк `step: (preinstall|write|verify|done|failed)|END|TIMEOUT`.
2. Стереть sensor0 из WLAN Pi OS: `sudo sh -c 'dd if=/dev/zero of=/dev/mmcblk0 bs=1M count=1 conv=fsync; sync; nohup sh -c "sleep 2; reboot -f" >/dev/null 2>&1 &'`.
3. После `END: done`:
   - у канала `78-telegram` поле `last_error` пустое, а `last_sent_at` новый;
   - перезагрузить sensor0 в eMMC: `~/cmprovision/sensor0-reprovision.sh boot`, затем `findmnt /` → `/dev/mmcblk0p2`.

Ожидается: `write`, `verify`, `done` за ~5 минут, EEPROM `identical`, Telegram без ошибок.

- [ ] **Step 3: Проход по интерфейсу с пользователем**

Попросить пользователя пройти и сообщить замечания:
- вход и все страницы:
  - дашборд, CMs и карточка модуля;
  - загрузка небольшого образа с подсчётом SHA256;
  - правка и активация проекта;
  - скрипты, пробная печать этикетки (сообщение показывает вывод команды), проверка прошивок;
  - Other settings с журналами, коммутатором и тестом уведомлений;
- профиль: смена имени и пароля;
- 2FA: включение по QR с подтверждением кода, выход и вход с кодом, выключение;
- API: создание токена с правами, `curl -H "Authorization: Bearer <токен>" -H "Accept: application/json" http://192.168.25.30/api/projects`, удаление токена;
- меню и значок подключения на телефоне; внешний вид при тёмной теме ОС.

Каждое замечание — отдельный коммит с тестом, где это возможно, новая сборка `~devN` (задача 8, шаг 6, затем шаг 1 этой задачи).

---

### Task 11: Выпуск 2.0.0

**Files:**
- Modify: `debian/changelog`, `ROADMAP.md`, `CLAUDE.md`

- [ ] **Step 1: Документы выпуска**

- `debian/changelog`: `(2.0.0~devN)` → `(2.0.0)`, дата — `date -R`. Пункты дополнить исправлениями из задач 9–10, если они были.
- `ROADMAP.md`:
  - в «Сделано» после раздела 1.6.11 вставить текст ниже. Числа подставить из вывода `tests_all`, репетиции (задача 9) и цикла sensor0 (задача 10):

```markdown
**2.0.0** вышла <дата из changelog>, тег `v2.0.0`. Laravel 13: приложение снова на поддерживаемом фреймворке.

- **Стек.** Laravel 13 на PHP 8.3 и новее, Livewire 3, Jetstream 5.5 с Fortify и Sanctum 4, PHPUnit 12. Ставится только на Debian 13 / Raspberry Pi OS Trixie; для Debian 11 и 12 остаётся 1.6.11.
- **Вход и учётные записи.** Двухфакторный вход включается после того, как код один раз введён. Удаление аккаунта и сброс пароля по почте выключены, забытый пароль заменяется на сервере командой `auth:create-user`.
- **Стили без Node.** CSS собирает бинарник Tailwind 3.4, своего JS-бандла нет: Alpine приходит с Livewire. Внешний вид прежний, тёмная тема ОС его не перекрашивает.
- **Страховка обновления.** Пакет перед обновлением с 1.x сам делает снимок данных, `cmprovision-restore <снимок>` возвращает 1.6.11.
- **Мелочи.** Вывод команды печати этикетки попадает в лог модуля, даже если команда завершилась успешно. Проект принимает только образы из хранилища прошивок.

Проверено:
- **Тесты.** <число> тестов на PHP 8.4, в журнале нет предупреждений из кода приложения и фреймворка.
- **Репетиция на стенде.** Обновление 1.6.11 → 2.0 на копии рабочих данных и откат: <число> проверок из <число>, в том числе сессия, вход со старым паролем, секрет 2FA, API-токен Sanctum 2 и POST модуля без CSRF-токена.
- **Провижинер.** Цикл sensor0 <время>, EEPROM <итог>, Telegram без ошибок; вы прошли по интерфейсу, включая 2FA и API-токены.
```

  - из «Этап 2: что осталось» убрать раздел «### 4. PHP и Laravel, версия 2.0», раздел «### 5. Из upstream, дальше» перенумеровать в «### 4».
- `CLAUDE.md`: в абзаце про рабочий провижинер указать версию `2.0`.

- [ ] **Step 2: Полный набор и сборка выпуска**

```bash
. "$SP/wsl-bench/dev.sh" && sync_dev && tests_all
```

Ожидается: `phpunit exit 0`, в журнале нет предупреждений из `app/` и `vendor/laravel/framework`.

```bash
cd "$REPO" && git add debian/changelog ROADMAP.md CLAUDE.md && git commit -q -m "Changelog 2.0.0, ROADMAP: Laravel 13 done" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Повторить задачу 10, шаг 1, для `2.0.0` (снимок перед обновлением: `~devN` → `2.0.0`).

- [ ] **Step 3: Слияние, тег, push**

```bash
cd "$REPO" && printf 'Merge stage2: release 2.0.0\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>\n' > "$SP/merge-msg.txt"
git checkout -q main && git pull -q --ff-only origin main && git merge -q --no-ff stage2 -F "$SP/merge-msg.txt" && git tag -a v2.0.0 -m "cmprovision4 2.0.0" && git describe --tags --exact-match HEAD && head -1 debian/changelog
git push -q origin main v2.0.0 && git checkout -q stage2 && git merge -q --ff-only main && git push -q origin stage2 && git log --oneline -2 --decorate
```

Ожидается: `v2.0.0`, `cmprovision4 (2.0.0) unstable; urgency=medium`, и main, и stage2 на коммите слияния.

- [ ] **Step 4: Память проекта**

Обновить `cmprovision-fork-plan.md` (выпуск 2.0.0, коммит, нумерация ROADMAP), `cmprovision-dev-environment.md` (стенд: скрипты `24`, `25`, `26`; PHP 7.4 больше не нужен) и `cmprovision-target-os-risks.md` (2.0 только на Trixie).
