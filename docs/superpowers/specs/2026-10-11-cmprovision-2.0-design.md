# cmprovision 2.0: переход на Laravel 13

Дизайн, согласованный по частям 11 октября 2026 года. Это пункт 4 ROADMAP. Исходная точка — выпуск 1.6.11 (тег `v1.6.11`, коммит e0d54d7).

## Цель

Перевести cmprovision на поддерживаемую основу: Laravel 13 на PHP 8.4, только Debian 13 и Raspberry Pi OS Trixie. Это убирает фреймворк без исправлений безопасности (Laravel 8 не получает их с января 2023 года) и предупреждения об устаревшем в логах. Заодно готовится почва для компактного веба (пункт 3) и задач из upstream (пункт 5).

## Что решили вы

- Остаёмся на PHP и Laravel, проект не переписываем.
- От PHP 7.4 отказываемся. 2.0 работает только на PHP 8.3 и новее, ветка 1.6.x заморожена на `v1.6.11`.
- Порядок работ по ROADMAP: 4 (2.0), затем 5 (upstream), затем 3 (компактный веб).
- В 2.0 остаются вход и смена пароля, двухфакторная аутентификация и API с токенами. Сброс пароля по почте не нужен.
- Путь переезда — прыжок на месте (вариант 1 из трёх): обновляем зависимости в текущем репозитории, не переходя через промежуточные версии Laravel и не начиная с чистого каркаса.

## Допущения

- Поведение не меняется:
  - протокол с модулями: скрипт прошивки, отчёты, прогресс, светодиоды, опознание;
  - EEPROM, уведомления, SNMP, значок подключения;
  - формат базы и пути пакета (`/var/lib/cmprovision`).
- Внешний вид остаётся прежним. Переделка интерфейса — это пункт 3.
- Ассеты собираются заранее и коммитятся, Node на провижинере не нужен.
- По базе провижинера на 11 октября: два пользователя, 2FA ни у кого не включена, единственным API-токеном (право `read`) не пользовались, Jetstream Teams никогда не включались.

## Исходное состояние

- **Версии:** Laravel 8.83.27, Livewire 2.12.3, Jetstream 2.9.0, Fortify 1.16.0, Sanctum 2.15.1, PHPUnit 9.6.11, Alpine 2.8.2, Tailwind 2.2.19, Laravel Mix 6.
- **Код:** 78 PHP-файлов в `app/` (5,2 тыс. строк), 74 шаблона (3 тыс. строк), 13 Livewire-компонентов, 23 миграции.
- **Тесты:** 44 файла, 248 тестов на PHP 8.4.
- **Jetstream:** 166 тегов `<x-jet-*>` в 24 файлах — опубликованные шаблоны Jetstream и меню навигации. Наши собственные страницы их не используют.
- **Livewire 2:** 49 `wire:model.defer`, 15 простых `wire:model`, один `@entangle(...).defer`.
  - В публичных свойствах лежат объекты `App\Models\Firmware`, а это не модель Eloquent: `Firmwares::$firmware` и `Projects::$firmwareChannels`. Livewire 3 такие не сериализует.
  - В трёх тестах читаются внутренности Livewire 2 (`payload['effects']['html']`).
- **Alpine 2:**
  - собственная копия Alpine в `resources/js/app.js`;
  - два `@click.away`, один `x-show.transition`;
  - `imageUploader()` с `x-init="init()"` и методом `init()` — под Alpine 3 он выполнился бы дважды.
- **Laravel 8:**
  - пакеты `fideloper/proxy`, `fruitcake/laravel-cors`, `facade/ignition`;
  - посредник `AuthenticateSession` из Jetstream;
  - закрепление `league/commonmark ^1.6` (Laravel 13 требует 2.x).
- **Найденная мелочь:** методы `Projects::getEepromSettingsFromFirmwareFile()` и `getEepromSettings()` объявлены без модификатора видимости, поэтому вызываются из браузера. Наружу метод отдаёт только раздел `bootconf.txt` образа EEPROM, а для любого другого файла — `false`, так что утечки нет. Вызвать его может только вошедший пользователь.

## Целевой стек

| Что | Было | Будет |
|---|---|---|
| PHP | ^7.3 \| ^8.0 | ^8.3 (на провижинере 8.4) |
| Laravel | 8.83 | 13.x (на 6 октября — 13.35) |
| Livewire | 2.12 | 3.8 |
| Alpine | 2.8, своя копия | 3, встроен в Livewire |
| Jetstream | 2.9 | 5.5 (Livewire-стек) |
| Fortify / Sanctum | 1.16 / 2.15 | 1.41 / 4.3 |
| PHPUnit | 9.6 | 12.5 |
| Tailwind | 2.2 через Laravel Mix | 3.4.19, самостоятельный бинарник Tailwind |
| JS-сборка | Mix + webpack (app.js: Alpine, axios, lodash) | нет |

Почему так:
- **Livewire 3, а не 4.** Jetstream 5.5 официально поддерживает только Livewire 3. Перейти с 2 сразу на 4 нельзя: инструмент `livewire:upgrade` есть только в ветке 3.x, а руководство по 4 исходит из 3. Ветки 3 и 4 выпускаются параллельно.
- **Jetstream 5.5.** Поддерживает Laravel 13 (с версии 5.5.0 от 10 марта 2026), но сопровождается только ради совместимости. Если к Laravel 14 он отстанет, его три компонента (профиль, 2FA, токены) переносятся в приложение поверх Fortify и Sanctum.
- **Tailwind 3.4.** Внешний вид прежний, и нет нижней границы по браузерам, как у Tailwind 4 (Safari 16.4, Chrome 111, Firefox 128). Шаблоны Jetstream 5 рассчитаны на 3.4. Самостоятельный бинарник включает плагины форм и типографики, поэтому Node и npm для сборки не нужны.
- **Без Vite.** Alpine теперь приходит вместе с Livewire, а другого JS для сборки нет: `upload-progress.js` — обычный скрипт без сборки.

## Изменения в коде

### Зависимости

- `composer.json`:
  - require: `php ^8.3`, `laravel/framework ^13.0`, `livewire/livewire ^3.8`, `laravel/jetstream ^5.5`, `laravel/sanctum ^4.3`, `laravel/tinker ^3.0`, `guzzlehttp/guzzle ^7.8`;
  - require-dev: `phpunit/phpunit ^12.5`, `nunomaduro/collision ^8.0`, `mockery/mockery ^1.6`, `fakerphp/faker ^1.23`;
  - удалить: `fideloper/proxy`, `fruitcake/laravel-cors`, `facade/ignition`, `laravel/sail`, закрепление `league/commonmark`.
- `package.json` остаётся только ради `npm run test:js` (`node --test`, Node 20+): без зависимостей и без `"type": "module"`, иначе сломается загрузка `upload-progress.js` в тесте.

### Структура

- `app/Http/Kernel.php`, `app/Console/Kernel.php` и провайдеры остаются: Laravel 13 их поддерживает.
- Меняются только посредники, которых больше нет: `TrustProxies` и `HandleCors` — на встроенные во фреймворк, `AuthenticateSession` из Jetstream — на `Illuminate\Session\Middleware\AuthenticateSession`.
- Исключение CSRF для `scriptexecute` остаётся (в Laravel 13 `VerifyCsrfToken` — устаревший подкласс `PreventRequestForgery` со свойством `$except`). `originOnly` не включаем: на HTTP браузер не шлёт `Sec-Fetch-Site`.
- `EventServiceProvider` проверяем на двойную регистрацию: в Laravel 11+ свой механизм автообнаружения, а у нас ещё `shouldDiscoverEvents()`. Слушатель уведомлений должен срабатывать один раз, это закрепляет тест.
- Конфиги остаются нашими. Новые ключи добавляем явно:
  - `session.serialization = php`, чтобы обновление никого не разлогинило;
  - ключи `guard` и `auth_session` для Jetstream 5;
  - имена посредников в `sanctum.php` для Sanctum 4.
- Публичные методы, которые не должны вызываться из браузера, делаем закрытыми: это касается двух методов `Projects`.

### Livewire 3

- Классы остаются в `App\Http\Livewire` (`class_namespace` в `config/livewire.php`), макет страниц — `layouts.app` (`layout` в том же конфиге). Маршруты и тесты не переписываются.
- `wire:model.defer` становится `wire:model` (49 мест), простой `wire:model` — `wire:model.live` (15 мест), `@entangle(...).defer` — `@entangle(...)`.
- Объекты `Firmware` уходят из публичных свойств: страницы Firmware и Projects держат простые массивы (канал, файл, версия, дата) или вычисляют список при отрисовке.
- `NotificationSettings::rules($existing)` с обязательным аргументом переименовываем, чтобы Livewire не вызвал его сам без аргумента.
- `WithFileUploads` в `Images` не используется (загрузка идёт через `AddImageController`) и удаляется.
- Тесты, которые читали `payload['effects']['html']`, переходят на `->html()` из API тестов Livewire 3.
- `livewire:upgrade` не используется: инвентаризация дала полный список правок, а команда перенесла бы классы в `App\Livewire`.

### Alpine 3

- Своя копия Alpine удаляется, работает копия из Livewire. Гостевые страницы (вход, код 2FA) тоже получают скрипты Livewire, иначе там не будет Alpine.
- `@click.away` становится `@click.outside` (`dropdown`, `uplink-indicator`), `x-show.transition...` — `x-transition` (`action-message`).
- `imageUploader`: убрать `x-init="init()"`, потому что Alpine 3 сам вызывает `init()`.
- CSS для `[x-cloak]` переезжает в `app.css`.

### Jetstream 5

- Шаблоны Jetstream публикуются заново: `<x-jet-*>` становится `<x-*>`, компоненты лежат в `resources/views/components`. В них переносятся наши правки:
  - меню навигации со ссылками приложения и значком подключения;
  - стили кнопок из коммита 9b815f8.
- Возможности:
  - профиль со сменой имени и пароля;
  - 2FA с подтверждением кодом (`confirm`);
  - API-токены с правами create, read, update, delete (по умолчанию read);
  - выход с других устройств.
- Удаление аккаунта выключаем, чтобы не удалить последнего администратора.
- В Fortify выключаем сброс пароля по почте, регистрация и подтверждение почты остаются выключенными. Забытый пароль восстанавливается на сервере: `auth:create-user` заводит нового пользователя.

### Стили

- `tailwind.config.js` под 3.4:
  - `purge` становится `content` (наши шаблоны, шаблоны Jetstream в `vendor` и пагинация);
  - `variants` убирается;
  - цвета teal и orange теперь есть в палитре по умолчанию.
- Классы, переименованные между 2 и 3, правятся по руководству Tailwind.
- `resources/css/app.css` собирается бинарником `tailwindcss` 3.4.19 в `public/css/app.css` и коммитится. Шрифты Nunito уже лежат в `public/fonts`.
- Удаляются `webpack.mix.js`, `resources/js/*`, `public/js/app.js` и `public/mix-manifest.json`.
- Макеты подключают стили через `asset('css/app.css')` с версией по времени изменения файла, как сейчас `upload-progress.js`.

### Тесты

- PHPUnit 12: две аннотации `@dataProvider` становятся атрибутами `#[DataProvider]`, `phpunit.xml` приводится к схеме 12.
- В `phpunit.xml` включаются `DB_CONNECTION=sqlite` и `DB_DATABASE=:memory:`, чтобы тесты больше не могли стереть базу из `.env`.
- Шаблонные тесты Jetstream обновляются до версии 5. Тесты выключенных функций (регистрация, подтверждение почты, сброс пароля, удаление аккаунта) убираются.
- Новые тесты:
  - страницы Firmware и Projects на Livewire 3: отрисовка, смена прошивки проекта, проверка обновлений;
  - вывод команды печати этикетки попадает в лог модуля;
  - закрытые методы `Projects` нельзя вызвать как действие Livewire;
  - после миграций есть колонки `two_factor_confirmed_at` и `personal_access_tokens.expires_at`;
  - слушатель уведомлений срабатывает на событие один раз.

### Мелочи, которые входят в 2.0

- `printLabel()` и `printTestLabel()` пишут вывод команды печати в лог, даже если команда вернула 0. Так сломанный CSV проекта 78 был бы виден сразу.

## Пакет и обновление

- **`debian/control`.** Все зависимости `php-*` требуют `>= 2:8.3`. У метапакетов Debian есть эпоха `2:`, без неё условие выполнялось бы всегда. С ней apt откажется ставить 2.0 на Debian 11/12 и Raspberry Pi OS Bookworm (PHP 8.2), и 1.6.11 там останется.
- **Сборка** — `composer install --no-dev` на Trixie (провижинер или WSL-стенд), без Node.
- **`debian/install`** больше не содержит `public/js/app.js` и `mix-manifest.json`. Исключения lintian правятся под пути Livewire 3.
- **Обновление поверх 1.6.11** — обычным `apt install`:
  - `.env` не меняется: ключи вроде `CACHE_DRIVER` читают наши конфиги;
  - миграции добавляют `users.two_factor_confirmed_at` и `personal_access_tokens.expires_at`;
  - `APP_KEY` прежний, поэтому секреты 2FA (их шифрует Fortify) и cookie сессий расшифровываются. Настройки каналов уведомлений хранятся в базе открытым текстом и просто остаются на месте;
  - `postinst` перезапускает php-fpm, `dh_installsystemd` перезапускает очередь, кэши конфигов и шаблонов сбрасываются.
- **Страховка.** `preinst` при обновлении с версии ниже 2.0 запускает `cmprovision-backup` (без образов) до распаковки нового кода. Откат — `cmprovision-restore <снимок>`: ставит 1.6.11 из снимка и возвращает базу в старой схеме.
- **Заморозка 1.6.x.** Точка заморозки — тег `v1.6.11`. Критическое исправление, если понадобится, делается в ветке от тега.
- **Документация и стенд:**
  - README требует Debian 13 / Raspberry Pi OS Trixie и новее;
  - в CLAUDE.md правило про PHP 7.4 заменяется требованием PHP 8.3+ и описанием сборки CSS бинарником Tailwind;
  - `docker/bench.sh` остаётся только с контейнером trixie.
- **Версии:** сборки стенда — `2.0.0~devN`, выпуск — `2.0.0` с тегом `v2.0.0`.

## Проверка

**WSL-стенд (Debian 13, PHP 8.4):**
- **Тесты.** Полный набор с записью всех сообщений PHP. Ни одного предупреждения об устаревшем из `app/` и `laravel/framework`; от PHPUnit они допустимы.
- **CSS.** Сборка бинарником Tailwind и сверка списка классов: все классы, которые используются в шаблонах, есть в собранном CSS.
- **Обновление:**
  1. установить 1.6.11 и положить копию рабочих данных из снимка 20261010-210032;
  2. обновиться до 2.0.0~devN;
  3. проверить:
     - данные на месте, пароли подходят;
     - секрет 2FA расшифровывается, настройки каналов на месте, API-токен работает;
     - страницы открываются, задача очереди (SHA256) выполняется;
     - снимок перед обновлением появился;
  4. откатиться через `cmprovision-restore` и проверить, что вернулась 1.6.11 с прежними данными.

**Провижинер:**
1. Установить `2.0.0~devN`.
2. Прогнать цикл sensor0: сетевая загрузка, EEPROM, запись и сверка, светодиоды, Telegram о старте и завершении, карточка модуля, время около 5 минут.
3. Проход по интерфейсу вместе с вами:
   - вход и все страницы:
     - дашборд, CMs и карточка модуля;
     - загрузка небольшого образа с подсчётом SHA256;
     - правка и активация проекта;
     - скрипты, пробная печать этикетки, проверка прошивок;
     - Other settings с журналами, коммутатором и тестом уведомлений;
   - профиль: смена имени и пароля;
   - 2FA: включение по QR с подтверждением кода, вход с кодом, выключение;
   - API: создание токена с правами, запрос `curl` к `/api/projects`, удаление токена;
   - меню и значок подключения на телефоне.

**Выпуск.** Changelog `2.0.0`, ROADMAP (пункт 4 сделан), README и CLAUDE.md. Слияние stage2 → main, тег `v2.0.0`, push. Работа идёт в ветке stage2.

## Критерии готовности

- Все тесты зелёные на PHP 8.4, в логах нет предупреждений об устаревшем из кода приложения и фреймворка.
- Цикл sensor0 на 2.0 идёт так же, как на 1.6.11.
- Обновление с 1.6.11 сохраняет данные, пароли, 2FA и токены. Откат на 1.6.11 работает.
- Вы прошли по интерфейсу без замечаний.

## Не входит в 2.0

- Tailwind 4 и Livewire 4 — вместе с компактным вебом (пункт 3).
- Замена Jetstream собственными компонентами — только если он отстанет от Laravel.
- Задачи из upstream (пункт 5): новая утилитарная ОС, CM5, адрес сервера (#22), имя хоста на этикетке (#45), повторная прошивка (#48), докачка образа.
- Путь CM3 по USB на Trixie: оборудования нет.

## Риски и как их снимаем

| Риск | Что делаем |
|---|---|
| Объекты `Firmware` в свойствах Livewire 3 | Переводим на массивы, страницы закрываем тестами |
| Двойная регистрация слушателя уведомлений в Laravel 11+ | Тест «одно событие — одно уведомление» |
| Значения по умолчанию Laravel 13 для сессий и кэша | Явные ключи в наших конфигах |
| Jetstream сопровождается только ради совместимости | Его код остаётся в `vendor`, наши шаблоны опубликованы. Запасной путь — три компонента поверх Fortify и Sanctum |
| Livewire 3 по умолчанию ищет классы в `App\Livewire` | Пространство имён закрепляем в `config/livewire.php`, `livewire:upgrade` не запускаем |
| PHPUnit 12 не читает аннотации | Две аннотации `@dataProvider` переводим в атрибуты вручную; Rector не нужен |
| Бинарник Tailwind | Фиксированная версия 3.4.19 со сверкой SHA256 по данным GitHub, сборка в WSL |
| Тёмная тема ОС у оператора | `darkMode: 'class'`: классы `dark:` из шаблонов Jetstream 5 не срабатывают |

## Источники

- [Laravel 13 Release Notes](https://laravel.com/docs/13.x/releases), [Laravel 13 Upgrade Guide](https://laravel.com/docs/13.x/upgrade), [Laravel 11 Upgrade Guide](https://laravel.com/docs/11.x/upgrade)
- [Livewire 3 Upgrade Guide](https://livewire.laravel.com/docs/3.x/upgrading), [Livewire 4 Upgrade Guide](https://livewire.laravel.com/docs/4.x/upgrading), [Livewire releases](https://github.com/livewire/livewire/releases)
- [Jetstream v5.5.0](https://github.com/laravel/jetstream/releases/tag/v5.5.0), [Jetstream UPGRADE.md](https://github.com/laravel/jetstream/blob/5.x/UPGRADE.md)
- [Tailwind upgrade guide](https://tailwindcss.com/docs/upgrade-guide), [Tailwind compatibility](https://tailwindcss.com/docs/compatibility)
- [PHPUnit 12](https://phpunit.de/announcements/phpunit-12.html), [Alpine upgrade guide](https://alpinejs.dev/upgrade-guide)
- [rector-laravel](https://github.com/driftingly/rector-laravel)
