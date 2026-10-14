# Стенд в Docker

Контейнер `trixie` повторяет раскладку пакета `cmprovision4`: приложение в `/var/lib/cmprovision`, nginx с сайтом из `debian/cmprovision`, php-fpm, воркер очереди. ОС — Debian 13 с PHP 8.4, как Raspberry Pi OS Trixie на провижинере.

Порт `127.0.0.1:8082` открыт только на localhost хоста: снаружи доступ через `ssh -L 8082:127.0.0.1:8082 <хост>`. Пользователь веб-интерфейса создаётся автоматически: `bench@example.com` / `bench1234`.

```sh
docker/bench.sh up      # собрать образ, скопировать код, запустить (первый запуск ставит composer-зависимости)
docker/bench.sh sync    # после правки кода; воркер очереди подхватит код только после restart
docker/bench.sh test    # php artisan test на SQLite в памяти; --filter=Имя для одного теста
docker/bench.sh deb     # собрать .deb в контейнере, результат в docker/build/
```

Рабочая копия приложения лежит в `docker/trixie/app` (в git не попадает). Её состояние — `.env`, база, образы, прошивки, `vendor` — при `sync` не трогается; чтобы начать с чистого листа, удалите каталог и выполните `up` заново.

Что здесь не проверить: сетевую загрузку модуля, прошивку EEPROM, dnsmasq и rpiboot — для этого нужен Raspberry Pi.

## Поиск порта коммутатора на SNMP-агенте

`tests/Feature/SnmpAgentTest.php` проверяет поиск порта по MAC через настоящий php-snmp на симуляторе [snmpsim](https://pypi.org/project/snmpsim/), который отдаёт `tests/fixtures/snmp/*.snmprec`. Community v2c выбирает файл (`qbridge`, `ciscoios`, `ciscoios@20`, `huawei`, `yunshan`, `routeros`, цепочка из двух коммутаторов — `chaina` и `chainb`) так же, как Cisco IOS отдаёт VLAN по `community@vlan`; контекст SNMPv3 выбирает файл так же (`vlan-20`). Без переменной `SNMPSIM_ENDPOINT` тест пропускается.

```sh
python3 -m venv /opt/snmpsim && /opt/snmpsim/bin/pip install snmpsim pysmi
/opt/snmpsim/bin/snmpsim-command-responder --data-dir=tests/fixtures/snmp --agent-udpv4-endpoint=127.0.0.1:1161 \
    --v3-user=cmprova --v3-auth-key=authpass123 --v3-auth-proto=SHA --process-user=nobody --process-group=nogroup &
SNMPSIM_ENDPOINT=127.0.0.1:1161 php artisan test --filter=SnmpAgentTest
```

SNMPv3 с шифрованием AES симулятор (pysnmp 7) отдаёт так, что его не расшифровывает и net-snmp, поэтому тест v3 идёт без шифрования. Файлы данных собирает `tests/fixtures/snmp/generate.py` (записи `oid|тип|значение`, отсортированные по OID); правьте его, а не `.snmprec`. В `c9200` записи ENTITY-MIB, sysName и базовый MAC добавлены вручную, поэтому генерируйте файлы по именам: `python3 tests/fixtures/snmp/generate.py tests/fixtures/snmp chaina chainb`.

Для страницы Ports с двумя коммутаторами (Playwright) нужны два адреса: у каждого коммутатора в списке свой. Второй экземпляр симулятора слушает 127.0.0.1:1162 с тем же каталогом данных; в Settings добавьте `127.0.0.1:1161` (community `s5731`) и `127.0.0.1:1162` (community `c9200`).
