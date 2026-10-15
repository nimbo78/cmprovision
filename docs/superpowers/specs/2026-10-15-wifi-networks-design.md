# Wi-Fi провижинера из веб-интерфейса (2.5.3)

Дата: 2026-10-15. Версия 2.5.3 после 2.5.2 — третья из трёх частей «сети провижинера» (ROADMAP, этап 2, пункт 2). Как собрана сеть провижинера — [docs/provisioner-network.md](../../provisioner-network.md); холодный запас встроенного Wi-Fi — [спека 2.5.2](2026-10-15-uplink-cold-spare-design.md).

## Зачем

Просьба пользователя (14 октября 2026): «управление ssid для подключения (wpa2-psk, wpa3-sae, mixed сети) прямо из web ui и выставление приоритета подключения». Сейчас сеть провижинера меняется только по SSH через `nmcli`: сменить пароль офисного Wi-Fi, добавить сеть другого офиса или раздачу с телефона, решить, какая сеть главнее, оператор без SSH не может.

Успех: в Settings оператор видит сети провижинера, добавляет и правит их (SSID, защита, пароль, скрытая сеть), меняет порядок, проверяет сеть «прямо сейчас», не роняя рабочее подключение, и может заранее ввести пароль, который роутер получит позже. Пароль Wi-Fi никогда не возвращается в браузер.

## Решения пользователя

- Одна сеть — один профиль NetworkManager без привязки к карте; карту выбирает NetworkManager.
- Встроенный Wi-Fi всегда запасной (как в 2.5.2); порядка карт в интерфейсе нет.
- Пароль можно сменить и заранее (провижинер возьмёт его при следующем подключении), и сразу (с проверкой).
- «Проверить сейчас» — на встроенном Wi-Fi, пока он простаивает в холодном запасе; карта при этом работает, отката нет.
- В разделе: поиск видимых сетей, скрытые сети, подтверждение паролем учётки перед изменениями.

## Не входит

- Порядок карт и выключатель холодного запаса (`SPARE`) в интерфейсе.
- Открытые сети, WPA2/WPA3-Enterprise (802.1X), OWE; статический адрес и DNS для Wi-Fi.
- Проверка сети самой картой и откат по таймеру: проверка идёт только на встроенном.
- Профили не из keyfile (netplan, который создаёт Imager на свежей Raspberry Pi OS): показываются только для чтения, README говорит, как перейти.
- WLAN Pi OS: там NetworkManager не управляет Wi-Fi, раздел пишет, что функция недоступна.

## NetworkManager

### Профиль сети

Сеть — keyfile-профиль в `/etc/NetworkManager/system-connections/`:

- `type=wifi`, `mode=infrastructure`, `connection.id` — SSID (повтор имени допустим, помощник работает по UUID);
- без привязки к устройству: пустые `802-11-wireless.mac-address` и `connection.interface-name`;
- `connection.multi-connect=multiple` — профиль может быть активен на обеих картах сразу: при загрузке нет гонки «кто первым заберёт сеть», а «Проверить сейчас» поднимает сеть на встроенном, пока она работает на карте;
- `ipv4.route-metric=-1`, `ipv6.route-metric=-1` — метрику даёт устройство (ниже);
- `connection.autoconnect-priority` — по порядку в списке: первая сеть 100, вторая 99 и так далее;
- `802-11-wireless.hidden=yes` для скрытой сети;
- `802-11-wireless-security.psk-flags=0` — пароль хранит система.

Защита:

| В интерфейсе | `key-mgmt` | `pmf` |
|---|---|---|
| WPA2-Personal | `wpa-psk` | 1 (disable) |
| WPA2/WPA3 (mixed) | `wpa-psk` | 2 (optional): NetworkManager добавляет SAE, если его умеют карта и точка |
| WPA3-Personal | `sae` | 3 (required) |

Как NetworkManager 1.52 передаёт смешанный режим в wpa_supplicant и умеет ли SAE встроенный `brcmfmac` CM4 — проверяется на wlanpi-da2 (раздел «Проверка»).

Правила ввода (их проверяют и приложение, и помощник):

- SSID — 1–32 байта UTF-8 без управляющих символов (0x00–0x1F, 0x7F);
- пароль — 8–63 печатных символа ASCII (0x20–0x7E); для WPA2 и mixed ещё 64 шестнадцатеричные цифры (готовый ключ); для WPA3 ключ из 64 цифр не годится;
- у новой сети пароль обязателен; у существующей пустое поле — «не менять». Смена защиты на WPA3 с сохранённым 64-значным ключом — отказ «enter a password: WPA3 does not take a 64-digit key».

Приоритет действует, когда провижинер подключается: с работающей сети на более приоритетную NetworkManager не уходит. Интерфейс так и пишет.

### Порядок карт

Пакет кладёт `/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf` — значения по умолчанию для подключений на устройствах:

```ini
# cmprovision: the built-in Wi-Fi (brcmfmac) is the cold spare, other Wi-Fi carries the traffic
[connection-cmprovision-builtin-wifi]
match-device=driver:brcmfmac
ipv4.route-metric=600
ipv6.route-metric=600

[connection-cmprovision-wifi]
match-device=type:wifi
ipv4.route-metric=100
ipv6.route-metric=100
```

Метрика профиля −1, поэтому один профиль на карте получает 100, на встроенном — 600. Холодный запас 2.5.2 находит встроенный по шине `sdio`, а NetworkManager не умеет сравнивать шину: здесь встроенный узнаётся по драйверу `brcmfmac` (у CM4 это одно и то же; USB-адаптер на `brcmfmac` получил бы 600 — допущение). Каталог дистрибутива, не conffile: администратор перекрывает файл своим в `/etc/NetworkManager/conf.d/`. `postinst` выполняет `nmcli general reload conf`; работающие подключения получают метрику при следующем подключении.

### Что берётся под управление

Управляемый профиль — Wi-Fi-профиль в режиме infrastructure с `key-mgmt` `wpa-psk` или `sae`, чей файл лежит в `/etc/NetworkManager/system-connections/`. Сеть — все управляемые профили с одним SSID; строка сети показывает настройки профиля, который останется при переходе (ниже). Остальные Wi-Fi-профили (netplan, Enterprise, открытые) показываются серыми строками «managed outside cmprovision» и не меняются, даже если SSID совпадает с управляемой сетью; Ethernet и прочее раздел не показывает.

### Переход на один профиль

Сеть из нескольких управляемых профилей (на wlanpi-da2 — пара `uplink-m2` на карте и `uplink-onboard` на встроенном, обе на сеть «78») при первом сохранении становится одним профилем без обрыва:

1. Остаётся профиль, активный на устройстве с маршрутом по умолчанию; если такого нет — любой активный; если активных нет — первый по UUID.
2. Он правится на месте: снимается привязка (`mac-address`, `interface-name`), метрики −1, `multi-connect=multiple`, `id` — SSID, плюс изменения из формы. Активное подключение живёт со старыми настройками до следующего подключения (NetworkManager не переприменяет изменённый профиль сам).
3. Остальные профили группы удаляются. Если какой-то из них активен (так бывает при `SPARE=no`, когда подключены оба радио), его радио переподключается на оставшийся профиль: обрывается только запасное подключение, не то, что несёт маршрут.

Пустой пароль в форме при переходе означает «оставить пароль оставшегося профиля».

## Помощник `wifi-networks`

`/usr/lib/cmprovision/wifi-networks` — POSIX sh, владелец root, 0755. Запускается только через sudo от `www-data`; правило в `/etc/sudoers.d/010_cmprovision` (рядом с перезапуском dnsmasq) — пять строк без аргументов, без `*`:

```
www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks save
www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks delete
www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks order
www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks check
www-data ALL=(root) NOPASSWD: /usr/lib/cmprovision/wifi-networks scan
```

### Ввод и вывод

Всё, кроме команды, приходит на stdin строками `ключ=значение` (значение — всё после первого `=`). Неизвестный ключ, повтор ключа (кроме `uuid` у `order`), значение вне правил — отказ без изменений.

| Команда | Ввод | Вывод при успехе |
|---|---|---|
| `save` | `uuid` (нет — новая сеть), `ssid`, `security` (`wpa2`, `mixed`, `wpa3`), `hidden` (`yes`, `no`), `password` (пусто — не менять), `by` | `uuid=<uuid>` |
| `delete` | `uuid`, `by` | — |
| `order` | `uuid` по одной строке в нужном порядке (все управляемые сети), `by` | — |
| `check` | `uuid`, `by` | `result=…`, `address=…`, `detail=…` |
| `scan` | — | строки `nmcli -t -f SSID,SIGNAL,SECURITY,FREQ device wifi list` |

`by` — имя пользователя для журнала (как в `cmlogs`): управляющие символы удаляются, длина — до 64 байт, пустое — `unknown`; это не секрет, оно идёт аргументом `logger`. `uuid` — формат UUID и управляемый профиль, иначе отказ; `delete`, `order` и `check` действуют на всю сеть этого профиля (все управляемые профили с его SSID).

Коды выхода: 0 — сделано; 1 — отказ или сбой, причина строкой `error=<текст для оператора>`; 2 — неверный вызов.

### Пароль

- Не попадает в аргументы процессов: несекретные свойства задаёт `nmcli connection add|modify`, а строку `psk=` помощник сам вписывает в keyfile (root, 0600) — значение передаётся awk через окружение (`ENVIRON`), не через `-v`, — затем `nmcli connection load <файл>`. Экранирование keyfile: обратная косая черта — `\\`, пробел в начале и в конце — `\s`.
- Сверка: помощник читает пароль обратно `nmcli -s -g 802-11-wireless-security.psk` в переменную (не в вывод) и сравнивает; при расхождении возвращает прежний keyfile и отказывает.
- Не попадает в журналы и вывод помощника.
- Не возвращается в браузер: приложение читает профили от `www-data` через `nmcli` без `-s` (как `NetworkStatus`), поле пароля при правке пустое, свойство Livewire очищается перед каждым ответом (ниже).

### Замки

- Изменения идут по одному: `flock` на `/run/cmprovision/wifi-networks.lock`, ожидание до 10 с, иначе отказ «another Wi-Fi change is running».
- `check` дополнительно держит замок холодного запаса (`/run/cmprovision/uplink-spare.lock`, ожидание до 10 с, иначе `result=busy`), чтобы `uplink-spare` не переключал встроенный посреди проверки. Проверки `uplink-spare`, которые в это время ждут замок дольше 10 с, выходят с кодом 0, как в 2.5.2; следующее событие или таймер решают заново.

### Команды

- **`save`** — новая сеть: `nmcli connection add` с настройками выше, в конец порядка (наименьший приоритет), затем пароль; SSID уже управляемой сети — отказ «'…' is already in the list: edit it». Существующая: переход на один профиль (если нужно), правка на месте, пароль — если задан. Изменённая сеть, которая сейчас в работе, остаётся подключённой со старыми настройками до следующего подключения.
- **`delete`** — удаляет все профили сети; сеть, активная на устройстве с маршрутом по умолчанию, — отказ «… is in use: connect another network first».
- **`order`** — список должен совпасть с набором управляемых сетей (по одному UUID на сеть); приоритеты 100, 99, … получают все профили каждой сети.
- **`scan`** — `nmcli device wifi rescan` на всех Wi-Fi-устройствах (пересканирование NetworkManager разрешает только root), затем список без пересканирования. Сети без SSID в список не идут.
- **`check`**:
  1. Встроенный Wi-Fi есть и простаивает: файл состояния холодного запаса — `state=standby`, `spare` — встроенный, `uplink` не пустой. Иначе `result=busy` с причиной: «the built-in Wi-Fi carries the traffic» (`active`), «the cold spare is switching» (`waiting`), «the cold spare is off (SPARE=no)» (`off`), «no built-in Wi-Fi».
  2. Профиль сети, который может подняться на встроенном: без привязки или привязанный к встроенному (у пары «78» до перехода это `uplink-onboard`). Нет такого — отказ «'…' is bound to the card: save it once to manage it here».
  3. Замок холодного запаса.
  4. `nmcli -w 30 connection up <uuid> ifname <встроенный>`; при успехе — адрес `nmcli -g IP4.ADDRESS device show <встроенный>`.
  5. Итог: `connected` (подключился и получил IPv4-адрес); `wrong-password` (NetworkManager: «Secrets were required, but not provided» или обрыв рукопожатия); `not-found` («The Wi-Fi network could not be found»); `no-address` (подключился, адреса нет); `timeout`; `failed` (иное, текст NetworkManager в `detail`).
  6. `nmcli -w 20 device disconnect <встроенный>`, снять замок, `uplink-spare check wifi-check` — тот записывает `standby`.

  Всё укладывается в 45 с.

### Журнал

Каждое действие — строка `logger -t cmprovision-wifi`, без паролей:

- `anna saved '78' (WPA2/WPA3, 1 of 2)`, `anna added 'phone' (WPA3, hidden, 2 of 2)`;
- `anna took over '78' (2 profiles into 1)`;
- `anna deleted 'guest'`, `anna ordered '78', 'phone'`;
- `anna checked 'phone' on wlan0: connected, 192.168.43.12` / `…: wrong password`;
- отказы: `anna: delete '78' refused: in use`.

## Веб-интерфейс

### Раздел «Wi-Fi networks» в Settings

Сразу после DHCP, компонент `WifiSettings`; чтение — `App\Services\WifiNetworks` (`nmcli -t` от `www-data`, разбор с экранированием `\:`), запись — он же через `sudo -n /usr/lib/cmprovision/wifi-networks <команда>` со вводом на stdin. Исполнитель команд подменяется в тестах.

- Таблица: порядок (стрелки ↑↓), SSID, защита, «hidden», состояние (`in use · M.2 card (wlan1)`, `in use · built-in (wlan0)`, `saved`), итог последней проверки этой сессии. Кнопки в строке: Check now, Edit, Delete; Delete у сети в работе неактивна с подсказкой.
- Серые строки — профили «managed outside cmprovision» с причиной (netplan, Enterprise, open).
- Форма под таблицей (Add network / Edit): SSID и кнопка **Find networks** — список видимых сетей с сигналом, защитой и диапазоном (2.4/5/6 GHz), щелчок подставляет SSID и защиту; защита — WPA2-Personal, WPA2/WPA3 (mixed), WPA3-Personal; пароль (`type=password`, `autocomplete=new-password`; при правке пустой, подпись «leave empty to keep»); флажок Hidden network; кнопки Save, Save and check, Cancel.
- После правки сети в работе: «Saved: the network in use takes it at its next connection. Check now tests it on the built-in Wi-Fi.»
- Check now недоступна (с причиной из `busy`), когда встроенный занят или его нет.
- **Changes log** — окно с `journalctl -t cmprovision-wifi -n 200` (группа `systemd-journal` у `www-data` уже есть).
- Где NetworkManager не управляет ни одним Wi-Fi-устройством или нет помощника — раздел пишет «Wi-Fi is not managed by NetworkManager here» без таблицы и формы.

### Пароль учётки

Save, Save and check, Delete, смена порядка и Check now идут через `<x-confirms-password>` Jetstream с `ConfirmsPasswords` — как двухфакторная аутентификация в профиле; подтверждение держится `auth.password_timeout` (3 часа) в сессии. Find networks ничего не меняет и пароля не просит.

### Пароль сети в Livewire

Свойство пароля сети очищается в `dehydrate()` — перед каждым ответом, в том числе при ошибке проверки: снимок компонента и HTML никогда не содержат пароль, после ошибки его вводят заново. В шаблоне поле пароля не получает значения с сервера.

### Значок подключения

- В подсказке — ссылка на раздел Wi-Fi networks.
- При аварии карты предупреждение берётся из состояния холодного запаса (`state=active`): «The M.2 Wi-Fi card is not connected since 09:41, the built-in Wi-Fi carries the traffic.» Нынешнее «preferred connection … is not connected» искало неподключённый профиль с лучшей метрикой; с одним профилем на обе карты такого нет, и без этой правки авария станет невидимой.

## Пакет

- `debian/rules`: `install -D -m 755 tools/wifi-networks debian/cmprovision4/usr/lib/cmprovision/wifi-networks` и `install -D -m 644 tools/90-cmprovision-uplink.conf debian/cmprovision4/usr/lib/NetworkManager/conf.d/90-cmprovision-uplink.conf`.
- `debian/010_cmprovision` — пять строк выше. Файл в `/etc`, debhelper делает его conffile: неизменённый обновляется молча, правленный вручную остановит обновление вопросом dpkg. Синтаксис проверяет `visudo -cf` на стенде.
- `postinst`: `nmcli general reload conf || true`.
- Откат на 2.5.2: профили остаются как есть (без привязки, `multi-connect`) и работают; файл `conf.d` уходит с пакетом — метрики снова 600 на обоих радио до следующего подключения; холодный запас 2.5.2 работает и так.
- `tools/backup.sh` уже кладёт в снимок `/etc/NetworkManager/system-connections`.

## Проверка

### Тесты

- `tests/Feature/WifiNetworksTest.php`: настоящий `tools/wifi-networks` под `sh` с заглушками `nmcli` (записывает вызовы, держит профили в подставном каталоге keyfile), `logger`, `uplink-spare`; подставные пути — переменными окружения, как у `UplinkSpareTest`. Без POSIX shell и `flock` тест пропускается.
  - новая сеть каждой защиты: `nmcli connection add` с `multi-connect`, метрикой −1, приоритетом в конце порядка; пароль — в keyfile; `nmcli connection load`;
  - пароль не встречается ни в одном записанном вызове и ни в журнале;
  - каждое правило ввода — отказ без вызовов `nmcli`, меняющих профили;
  - пустой пароль у существующей сети — keyfile не трогается; у новой — отказ; WPA3 с сохранённым 64-значным ключом — отказ;
  - переход пары «78»: правится активный профиль, удаляется простаивающий;
  - `delete` сети в работе — отказ; другой — удаление;
  - `order`: приоритеты 100, 99; список не того набора — отказ;
  - `check`: при `standby` — `connection up … ifname wlan0`, адрес, `device disconnect wlan0`, вызов `uplink-spare check`; при `active`, `waiting`, `off`, без встроенного — `busy` без вызовов; пара «78» до перехода — через профиль встроенного; сеть, привязанная только к карте, — отказ; разбор ответов NetworkManager в `wrong-password`, `not-found`, `no-address`, `timeout`;
  - новая сеть с SSID уже управляемой — отказ; `delete` и `order` действуют на все профили сети;
  - чужой UUID (Ethernet, Enterprise, netplan) — отказ.
- `tests/Feature/WifiSettingsTest.php` (Livewire, подставной исполнитель): список и серые строки; без подтверждения пароля учётки — ни одного вызова помощника; пароль сети не встречается ни в HTML, ни в снимке компонента после сохранения и после ошибки; тексты отказов помощника; раздел «недоступно»; итог проверки в строке.
- `UplinkSummaryTest`: предупреждение об аварии из `state=active` без `preferred_down`.
- `PackagingTest`: пять строк sudoers ровно, без `*`; установка помощника и `conf.d`; `nmcli general reload conf` в `postinst`.

### На стенде WSL

Установка, откат на 2.5.2 и повторная установка; `visudo -cf /etc/sudoers.d/010_cmprovision`; помощник без NetworkManager отказывает по-человечески.

### На wlanpi-da2

После установки, по слову пользователя:

1. Переход «78»: сохранить сеть без пароля — карта не отключилась, `uplink-onboard` удалён, профиль один, `multi-connect=3`.
2. После переподключения (или перезагрузки) — метрики 100 на карте и 600 на встроенном; холодный запас уводит встроенный, как в 2.5.2.
3. Check now «78» — `connected` с адресом `.236`, карта всё время на связи, встроенный вернулся в `standby`; с неверным паролем (новая сеть с тем же SSID) — `wrong-password`.
4. Find networks — список видимых сетей.
5. С точкой WPA3 (раздача с телефона в режимах WPA3 и WPA2/WPA3 — попросить пользователя): добавить, Check now; что NetworkManager передал в wpa_supplicant для mixed — по журналу.

## Документы и версия

- README — раздел о Wi-Fi-сетях: где в Settings, «сохранить заранее» и Check now, пароль не возвращается, приоритет при подключении, переход с профиля Imager (netplan): добавить сеть в Settings, проверить, удалить старый профиль `sudo nmcli connection delete <имя>`.
- CLAUDE.md — пункт о помощнике, правиле sudo, пароле и `conf.d`.
- docs/provisioner-network.md — часть C по факту.
- ROADMAP, `debian/changelog` — 2.5.3; ход разработки — `2.5.3~devN`.

## Риски

- Смешанный режим (`wpa-psk` + PMF optional) может не включать SAE в этой сборке NetworkManager; проверка на wlanpi-da2, при расхождении — `key-mgmt` и `pmf` для mixed уточняются в плане.
- SAE на встроенном `brcmfmac` CM4 зависит от прошивки; без него Check now сети WPA3 даст `failed`, хотя карта подключится.
- `multi-connect=multiple` для Wi-Fi проверен только офлайн (`nmcli --offline` принимает); вживую — на wlanpi-da2.
- Check now проверяет сеть встроенным радио: сеть только в 6 ГГц или вне его досягаемости даст `not-found`, хотя карта подключится. Сохранить такую сеть можно.
- Синхронный запрос Check now — до 45 с; у nginx `fastcgi_read_timeout` по умолчанию 60 с.
- Правленный вручную `/etc/sudoers.d/010_cmprovision` остановит обновление вопросом dpkg.
