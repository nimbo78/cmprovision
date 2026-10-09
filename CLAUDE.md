# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Обзор

Веб-приложение для массовой прошивки Raspberry Pi Compute Module 3/3+/4 (и Pi 4 с включённой сетевой загрузкой). Стек: Laravel 8, Jetstream (Livewire 2 + Fortify + Sanctum), Tailwind 2 через Laravel Mix, SQLite. Форк `raspberrypi/cmprovision`.

Целевая среда — Raspberry Pi OS на Pi 4: пакет `cmprovision4` ставится в `/var/lib/cmprovision` и работает поверх nginx + php-fpm, dnsmasq, rpiboot и systemd. Код вызывает Linux-утилиты (`systemctl`, `journalctl`, `sudo`, `bash`, `gzip`/`xz`/`bunzip2`, `sha256sum`), поэтому страница Settings и подсчёт хешей образов работают только на Linux, а сквозной провижининг проверяется только на Pi с подключёнными модулями.

Рабочий провижинер пользователя — Debian 11 (bullseye) с PHP 7.4, поэтому код должен оставаться совместимым с PHP 7.4 (без `match`, именованных аргументов, `?->`, enum, `readonly`, `str_contains`); `composer.json` объявляет `^7.3|^8.0`. Один и тот же `composer.lock` должен ставиться и на PHP 7.4, и на 8.4 (Raspberry Pi OS Trixie): обновляйте его под PHP 7.4 (на сервере), а затем проверяйте `composer install` на 8.4 — пакеты с верхней границей PHP ломают сборку `.deb` на Trixie. Поэтому `league/commonmark` закреплён на `^1.6`: ветка 2.x тянет `nette/utils` 3 и `nette/schema` 1.2 с ограничением PHP < 8.3 (Markdown приложение не использует).

## Команды

- Стенд: `docker/bench.sh` поднимает контейнеры bullseye (PHP 7.4) и trixie (PHP 8.4) с nginx и php-fpm в раскладке пакета; подробности в [docker/README.md](docker/README.md). На Windows ничего не запускается — стенд живёт на Docker-хосте.
- Зависимости: `composer install`, `npm install`.
- Ассеты: `npm run prod` — сборка с purge, её результат коммитится (см. «Фронтенд»); `npm run dev` — без purge, только для разработки.
- Тесты: `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test`; для одного класса или метода добавьте `--filter=AuthenticationTest`. Зачем переменные — см. «Тесты». JS без сборки: `npm run test:js` (Node ≥ 20, `tests/js/`).
- Линтера и форматтера в проекте нет.
- Artisan-команды проекта: `auth:create-user` — штатный способ завести пользователя (регистрация выключена в `config/fortify.php`); `ethernetswitch:configure` — интерактивная настройка SNMP-коммутатора.
- `.deb` собирается debhelper'ом из `debian/` (например, `dpkg-buildpackage -b -us -uc`); версия пакета — верхняя запись `debian/changelog`. `dh_auto_build` запускает корневой `Makefile`, цель `vendor` (`composer install --optimize-autoloader --no-dev`), а `debian/install` кладёт `vendor/` в пакет как есть. Если `vendor/` уже существует, цель пропускается и в пакет уходит локальный `vendor/` (с dev-зависимостями, если они ставились) — перед сборкой удаляйте его. debhelper выставляет всем файлам пакета mtime из даты записи changelog, поэтому при повторной установке той же версии opcache не замечает изменённый код — `postinst` перезапускает php-fpm при обновлении; при ручном копировании файлов на сервер перезапускайте его сами. `tools/backup.sh` и `tools/restore.sh` ставятся пакетом как `cmprovision-backup`/`cmprovision-restore` в `/usr/sbin` (root-owned, вне www-data-каталога).

Локальный запуск повторяет `debian/postinst`. Корневого `.env.example` нет, шаблон — `debian/env.example`: скопируйте его в `.env`, замените прод-путь в `DB_DATABASE`, выполните `php artisan key:generate`, создайте пустой файл БД, затем `php artisan migrate --seed`, `php artisan auth:create-user`, `php artisan serve`. Задачи очереди (`QUEUE_CONNECTION=database`) обрабатывает `php artisan queue:work`.

## Тесты

- Feature-тесты используют `RefreshDatabase` (то есть `migrate:fresh`), а переопределение на in-memory SQLite в `phpunit.xml` закомментировано. Без переменных из команды выше тесты сотрут БД, указанную в `.env`.
- Тесты — заготовки Jetstream (вход, профиль, API-токены) и примеры; логика провижининга не покрыта. `tests/Feature/ExampleTest` ждёт 200 от `/`, а `/` редиректит на логин, поэтому этот тест падает.

## Провижининг: сквозной поток

Модуль загружает `scriptexecute` — готовые бинарники из `scriptexecute/` (ядро, initramfs `scriptexecute.img`, загрузочные файлы, оверлеи; исходников в репозитории нет):

- **CM4 / Pi 4 — по Ethernet.** `etc/dnsmasq.conf` (сервис `cmprovision-dnsmasq`) раздаёт DHCP в 172.20.0.0/16, узнаёт Pi по сигнатуре в GUID из DHCP option 97 (в обоих порядках байтов) и отдаёт `scriptexecute/` по TFTP.
- **CM3/3+ — по USB.** `rpiboot -l -d scriptexecute` (сервис `cmprovision-rpiboot`). Модуль поднимает USB-Ethernet gadget и обращается к серверу по IPv6 link-local (секция `[pi3]` в `scriptexecute/config.txt` → `cmdline.txt.ipv6ll`). Поэтому `postinst` переключает dhcpcd на `slaac hwaddr`, а контроллер превращает `[addr]` в `[addr%usb0]`.

Дальше модуль общается с `/scriptexecute` → `ScriptExecuteController`. Маршрут намеренно открыт без аутентификации и исключён из CSRF (`VerifyCsrfToken::$except`): клиент — `curl` на модуле в изолированной сети.

1. `GET ?serial=…&mac=…&model=…` — upsert `Cm` по серийному номеру; ответ — shell-скрипт из `resources/views/scriptexecute.blade.php` (`text/plain`). Модуль исполняет любой ответ, поэтому ошибки тоже отдаются как shell (`echo '…'`).
2. Скрипт выполняет пре-скрипты проекта (первым — сгенерированная прошивка EEPROM через `flashrom`, если у проекта выбрана прошивка), затем `curl` образа | распаковка | `dd`, затем пост-скрипты (первым — сгенерированная сверка SHA256, если включён `verify`). Логи и версия EEPROM возвращаются multipart-POST'ами (`log` + `phase` + `retcode`, `eeprom_version`) через функцию `report()` скрипта: она повторяет запрос до 10 минут (`--retry-connrefused`, таймауты), потому что потерянный отчёт оставляет модуль «немым» — стартовый скрипт утилитарной ОС (`S99scriptexec`, `set -e`) после ненулевого выхода скрипта просто останавливается, IP остаётся, ничего не сообщается. В `/tmp/dd.log` попадают код выхода `curl`, stderr распаковщика и вывод `dd`; при сбое фазы `dd` контроллер добавляет в запись лога расшифровку (`ScriptExecuteController::WRITE_FAILURE_HINTS`). Пока модуль пишет длинный участок нулей, он не читает из сети, поэтому для `/uploads/` в nginx стоит `send_timeout 6h` (`debian/cmprovision`, для старых установок дописывает `postinst`).
3. `?alldone=1` — отметка о завершении и событие `CmProvisioningComplete`.

Параллельно скрипт сообщает живой статус: `progress_mark` перед каждым скриптом и записью, а `progress_start`/`progress_stop` на время записи образа и сверки раз в 5 секунд шлют число секторов из `/sys/block/<dev>/stat` (поле 7 — записано, 3 — прочитано). Это `GET ?progress=<фаза>&detail=&sectors=` без повторов и с таймаутом 4 секунды: статус необязателен и не должен тормозить прошивку. Сервер хранит фазу (`preinstall`, `write`, `verify`, `postinstall`, `done`, `failed`), её начало и байты в колонках `cms` (`Cm::setPhase()`), в лог такие запросы не пишутся. Дашборд показывает модули компонентом `ProvisioningStatus`: процент, скорость, остаток и «нет связи», если модуль молчит дольше `Cm::STALE_AFTER_*`.

События провижининга пишутся в таблицу `cmlogs` (`ScriptExecuteController::logInfo()`); дашборд показывает последние 100 записей.

## Связи между файлами

- Параметры запроса модуля задаются плейсхолдерами в `scriptexecute/cmdline.txt` и `cmdline.txt.ipv6ll` (их подставляет initramfs) и читаются в `ScriptExecuteController::startProvisoning()`. Меняйте обе стороны вместе.
- Подсеть 172.20.0.0/16 с сервером 172.20.0.1 зашита в `etc/dnsmasq.conf`, `scriptexecute/cmdline.txt`, `Host::firstAvailableIP()` и README.
- `scriptexecute.blade.php` рендерит shell, а `{{ }}` экранирует HTML (`&` → `&amp;`): сырой shell-код вставляйте через `{!! !!}`. Пользовательские скрипты записываются в файлы через quoted heredoc с маркером `CMPROVISIONINGEOF`. Директива Blade в конце строки с shell-кодом (`... @endif`) компилируется в `?>`, после которого PHP съедает перевод строки, и следующая строка склеивается с этой; хвост строки выводите через `{!! !!}` (после echo Blade перевод строки сохраняет). Так однажды `RETCODE=$?` приклеился к запуску скрипта.
- Строки `dhcp-host=` в `etc/dnsmasq.conf` генерирует `Settings::regenDnsmasqConfAndRestart()` из таблицы `hosts`, остальные строки сохраняются. Файл объявлен conffile в `debian/conffiles`.
- Через `sudo` веб-приложению разрешён только `systemctl restart cmprovision-dnsmasq` (`debian/010_cmprovision`); журналы читаются благодаря группе `systemd-journal`, которую выдаёт `postinst`. Новый привилегированный вызов требует правки sudoers-файла.
- Побочные эффекты активации проекта живут в `Projects::setActive()` (Livewire), а не в модели: запись `public/uploads/pieeprom.bin` с вшитым `bootconf.txt` проекта и его SHA256 в настройку `active_eeprom_sha256`. `PATCH /api/projects/{id}` этот путь обходит.
- Печать этикеток (FTP или команда; плейсхолдеры `$mac`, `$serial`, `$provisionboard`, `$file`) реализована дважды: `ScriptExecuteController::printLabel()` и `Labels::printTestLabel()`. Когда печатать, задаёт `label_moment` проекта.

## Данные и хранилище

- `settings` — таблица ключ-значение (PK `key`): `active_project` (активен не более одного проекта), `active_eeprom_sha256`, `ethernetswitch_ip`, `ethernetswitch_snmp_community`, `firmware_last_update`.
- `Firmware` и `EthernetSwitch` — не Eloquent-модели. Прошивки — файлы `<firmware_dir>/<канал>/pieeprom-*.bin` (`config/cmprovision.php`, по умолчанию `storage/app/firmware`); каналы `default` и `latest` наполняет `FirmwareUpdater` (страница Firmware и `artisan firmware:update`) из GitHub contents API `firmware-2711/*` и из пакета `rpi-eeprom`, скачивая только недостающие файлы и никогда не удаляя; старые каналы `stable`/`beta`/`critical` показываются, пока в них есть файлы. Время последней удачной проверки — настройка `firmware_last_update`. `EthernetSwitch` по SNMP BRIDGE-MIB находит порт коммутатора по MAC; результат записывается в `provisioning_board`, а без коммутатора туда идут инвертированные биты джамперов (GPIO 5/13/21, `scriptexecute/config.txt`).
- Образы `.gz`/`.xz`/`.bz2` принимает обычный контроллер `AddImageController`, потому что Livewire не справляется с большими файлами (лимит 8G задан в `debian/cmprovision` и `postinst`). Файлы лежат в `public/uploads/` под случайными именами, модули качают их напрямую через nginx. SHA256 сжатого и распакованного образа считает задача очереди `ComputeSHA256` (сервис `cmprovision-queue`); пока нет `uncompressed_sha256`, проект с `verify` провижинить отказывается.
- `CmProvisioningComplete` — точка расширения: авто-обнаружение событий включено (`EventServiceProvider::shouldDiscoverEvents()`), слушателю достаточно лежать в `app/Listeners`.
- При обновлении пакета `postinst` выполняет только `migrate`, сидеры запускаются лишь при первой установке. Данные для существующих установок добавляйте миграцией.
- `postinst` не должен падать: запуск служб (`#DEBHELPER#`) стоит в его конце, и после ошибки сервер остаётся без dnsmasq и очереди до следующего успешного configure. Команды artisan там — только с `--force` (в `.env` стоит `APP_ENV=production`).
- `postinst` на каждом configure идемпотентно правит окружение существующих установок: `send_timeout` для `/uploads/` в сайте nginx, `upload_tmp_dir = storage/app/upload-tmp` в php.ini (на Debian 13 `/tmp` в RAM), `APP_ENV=production`/`APP_DEBUG=false` в `.env` вместо значений из старого шаблона. Новые правки такого рода добавляйте туда же, а не только в шаблоны: сайт nginx и `.env` при обновлении не перезаписываются.
- Время хранится в UTC, показывается в `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE` или системный пояс сервера) через макрос `Carbon::local()`; CSV-экспорт и API отдают UTC. Системный пояс читает `App\Support\SystemTimezone` из `/etc/timezone` или ссылки `/etc/localtime`: спрашивать PHP нельзя — PHP 8.4 в Debian 13 возвращает UTC при любом поясе системы, и `date.timezone` по умолчанию там тоже `UTC`.

## Фронтенд

- Страницы — full-page Livewire-компоненты (`app/Http/Livewire/*` + `resources/views/livewire/*`), подключённые прямо в `routes/web.php`.
- Собранные `public/css`, `public/js` и `public/mix-manifest.json` закоммичены и попадают в `.deb` как есть: при упаковке ассеты не собираются. Добавив Tailwind-класс, которого ещё нет в шаблонах, выполните `npm run prod` и закоммитьте результат. Purge сканирует и `vendor/laravel/jetstream`, поэтому `composer install` выполняется до сборки ассетов.
- README предписывает после правки `.blade` выполнять `php artisan view:cache`; скомпилированные шаблоны (`storage/framework/views`) тоже входят в purge-пути Tailwind.
- Загрузка образа идёт XMLHttpRequest'ом из Alpine-компонента `imageUploader()` (определён в `images.blade.php`, форма в `addimage.blade.php`) на тот же `POST /addImage`; расчёт скорости и остатка — `public/js/upload-progress.js`, обычный скрипт без сборки, покрыт `tests/js`. Пока у какого-то образа не посчитан SHA256, страница Images опрашивает сервер (`wire:poll`), кроме момента, когда открыт диалог.
