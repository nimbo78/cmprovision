# Нагрузочная репетиция: 48 модулей разом

Проверка 2.5.0 на стенде WSL (`cmprov-trixie`): установленный пакет (nginx, пул php-fpm `cmprovision`, обе службы очереди), два 24-портовых Huawei в snmpsim, 48 модулей, которые стартуют одновременно, и заглушка Telegram. Спека — `docs/superpowers/specs/2026-10-14-load-48-design.md`, раздел «Проверка».

Файлы:
- `stand.php` — коммутаторы `127.0.0.1:1161` (`stand48a`) и `127.0.0.1:1162` (`stand48b`), проект `load-48` с образом 8 МБ, новая партия, канал Telegram `load-48 sink`; `cleanup.php` всё это убирает;
- `wave.php` — 48 стартов одновременно, минуту прогресса раз в 5 с, затем 45 «готово» и 3 сбоя записи;
- `telegram-sink.php` — Bot API на `127.0.0.1:8099`: пишет каждый вызов в `/tmp/sink.log`, на каждый десятый отвечает 429 с `retry_after` 3;
- фикстуры `tests/fixtures/snmp/stand48a.snmprec`, `stand48b.snmprec` (`generate.py`): модули `e4:5f:01:64:00:01…30`, по 24 на коммутатор, XGE0/0/1 стенда `stand48a` ведёт к `stand48b`.

## Критерии

- каждый старт получил скрипт быстрее 2 с (95-й перцентиль), ни одного ответа не 200;
- все 48 плат проставлены за 15 с от волны;
- в `storage/logs/laravel.log` нет «database is locked»;
- заглушка получила не больше 20 вызовов в минуту, каждое событие попало в сообщение, последняя правка сводки — итоговые числа;
- на волну одна партия;
- `database.sqlite`, `-wal` и `-shm` принадлежат www-data, в том числе после `cmprovision-backup`.

## Шаги

Все команды на стенде — от root, скриптом через stdin (`wsl.exe -d cmprov-trixie -u root -- sh -s < script.sh`).

1. Пакет: `git archive HEAD | wsl.exe -d cmprov-trixie -u root -- sh -c 'rm -rf /root/src && mkdir -p /root/src && tar -x -C /root/src'`, затем `/root/bench/03-deb.sh`. После установки: пул `/etc/php/8.4/fpm/pool.d/cmprovision.conf` и сокет `/run/php/cmprovision-fpm.sock` есть, `fastcgi_pass` сайта указывает на него, `systemctl is-active php8.4-fpm nginx cmprovision-queue cmprovision-queue-images` — все `active`, `/login` отвечает 200, `runuser -u www-data -- sqlite3 /var/lib/cmprovision/database/database.sqlite 'PRAGMA journal_mode;'` — `wal`.
2. snmpsim: `snmpagent-ok.sh` со стороны Windows (синхронизирует `/root/dev` и поднимает агента на :1161 со всеми фикстурами), второй агент на :1162 с теми же данными:
   ```sh
   mkdir -p /tmp/snmpsim-cache2 && chmod a+rwX /tmp/snmpsim-cache2
   nohup /opt/snmpsim/bin/snmpsim-command-responder --data-dir=/tmp/snmpsim-data --cache-dir=/tmp/snmpsim-cache2 \
       --agent-udpv4-endpoint=127.0.0.1:1162 --process-user=nobody --process-group=nogroup > /tmp/snmpsim2.log 2>&1 &
   snmpget -v2c -c stand48b 127.0.0.1:1162 1.3.6.1.2.1.1.5.0
   ```
3. Стенд и заглушка:
   ```sh
   install -m 644 /root/src/tests/load/*.php /tmp/
   cd /var/lib/cmprovision && runuser -u www-data -- env HOME=/tmp php artisan tinker --execute="require '/tmp/stand.php';"
   echo 'TELEGRAM_API=http://127.0.0.1:8099' >> /var/lib/cmprovision/.env
   runuser -u www-data -- /var/lib/cmprovision/artisan config:clear
   systemctl restart cmprovision-queue cmprovision-queue-images
   rm -f /tmp/sink.log /tmp/sink.count; cd /tmp && nohup php -S 127.0.0.1:8099 telegram-sink.php > /tmp/sink-server.log 2>&1 &
   ```
4. Волна: `runuser -u www-data -- php /tmp/wave.php http://127.0.0.1 /var/lib/cmprovision/database/database.sqlite`, потом две минуты на последние пачки.
5. Проверки:
   ```sh
   DB=/var/lib/cmprovision/database/database.sqlite
   q() { runuser -u www-data -- sqlite3 -cmd '.timeout 5000' "$DB" "$1"; }
   grep -c "database is locked" /var/lib/cmprovision/storage/logs/laravel.log
   awk '{print int($1/60)}' /tmp/sink.log | uniq -c | sort -rn | head -1
   for n in $(seq 1 48); do s=$(printf '10000000aa%06d' $n); c=$(grep -o "$s" /tmp/sink.log | wc -l); [ "$c" -ge 2 ] || echo "missing $s ($c)"; done
   grep editMessageText /tmp/sink.log | tail -1
   q 'select count(*) from notification_batches'
   q "select count(*) from notification_outbox where sent_at is null"
   q "select count(*) from cms where serial like '10000000aa%' and board_pending_since is not null"
   cmprovision-backup /tmp/load-backup && ls -l /var/lib/cmprovision/database/
   ```
6. Уборка: `tinker` с `/tmp/cleanup.php`, `sed -i '/^TELEGRAM_API=/d' /var/lib/cmprovision/.env`, `config:clear` от www-data, перезапуск обеих очередей, `pkill -f 'php -S 127.0.0.1:8099'`, `pkill -f 'agent-udpv4-endpoint=127.0.0.1:1162'`, `rm -rf /tmp/load-backup /tmp/*.php /tmp/sink.*`.

## Последний прогон

14.10.2026, стенд WSL (16 ядер x86, Debian 13, PHP 8.4), пакет 2.5.0~dev1 поверх 2.4.1: снимок `preinst` сделан, вопроса dpkg о conffile не было. Заглушка отвечает 429 на каждый `SINK_429_EVERY`-й вызов (по умолчанию 10-й).

| | волна 1 | волна 2 | волна 3 |
|---|---|---|---|
| 429 у заглушки | каждый 10-й | каждый 3-й | каждый 3-й |
| старт p50 / p95 / максимум, с | 0,45 / 0,64 / 0,85 | 0,93 / 1,42 / 1,92 | 0,64 / 0,85 / 1,08 |
| ответов не 200 | 0 | 0 | 0 |
| плат через 16 с | 48 из 48 | 48 из 48 | 48 из 48 |
| вызовов Telegram (из них 429) | 4 (0) | 5 (1) | 5 (1) |
| «database is locked» | 0 | 2 | 0 |

- Платы: одна задача `ResolveBoards` за 287 мс нашла все 48 плат через 3 с после первого старта (24 на `stand48a`, 24 на `stand48b`); 47 постановок из 48 поглотил замок уникальности.
- Уведомления: пачка стартов через 10 с после первого события, пачка итогов через 10 с после них; каждый модуль ровно в одном сообщении на событие, последняя правка сводки — «✅ 45 done · ❌ 3 failed · ⏳ 0 in progress», одна партия на все три волны, очередь уведомлений пуста.
- Разбор волны 1 (429 так и не случился) показал, что 429 посреди пачки повторил бы уже отправленный ответ. Исправлено до волны 2: сводка правится до ответов, каждое сообщение помечает свои события. Волна 2 прошла 429 без повторов, но два воркера, одновременно резервируя задачи, получили «database is locked» (одна задача упала, плату нашла вторая). Исправлено: транзакции SQLite `IMMEDIATE`. Волна 3 прошла с обоими исправлениями.
- После финального ревью этикетка preinstall печатается отдельной задачей `PrintLabel`. Волна 4 с командой печати `sleep 0.5; cat $file`: старт p95 1,04 с, все 48 плат записаны одним прогоном через секунду после первого старта, 48 этикеток напечатаны за 15 с с двумя воркерами, ошибок базы нет.
- php-fpm в `pm.max_children` не упирался; `database.sqlite`, `-wal` и `-shm` принадлежат www-data до и после `cmprovision-backup`, копия в снимке — root.
- CM4 провижинера заметно медленнее стенда: старт там займёт больше времени.
