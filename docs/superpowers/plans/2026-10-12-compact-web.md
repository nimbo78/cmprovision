# Компактный веб — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** каркас A на всех страницах (верхняя панель с итогом партии, плотные таблицы, без полосы заголовка), окно Appearance со стилем Warm/Neutral, плотностью Compact/Roomy и темой Light/Dark/Auto по восходу и закату, заголовок и значок вкладки по состоянию партии.

**Architecture:** цвета — CSS-переменные четырёх тем (стиль × режим) и переменные плотности в `app.css`; Tailwind знает цвета только как токены, общие элементы — классы `.cmp-*`. `public/js/appearance.js` в `<head>` ставит `data-style`, `data-density`, `data-mode` на `<html>` до отрисовки и ведёт окно Appearance (Alpine). `App\Support\BatchSummary` по открытой партии (`NotificationBatch::open()`) считает итог и состояние вкладки, Livewire `BatchIndicator` в панели опрашивает раз в 10 секунд с `keep-alive`, `public/js/tab-status.js` переносит его атрибуты в заголовок и значок. Шаблоны переводятся на токены по таблице правил, `TokenGuardTest` держит список ещё не переведённых файлов и не пускает «сырые» цвета в переведённые.

**Tech Stack:** PHP 8.3+ (стенд 8.4), Laravel 13, Livewire 3.8, Alpine 3 (приходит с Livewire), Tailwind 3.4 (бинарник), PHPUnit 12, Node ≥ 20 для `node --test`, skia-python для значков (`docs/brand/build_logo.py`).

**Spec:** `docs/superpowers/specs/2026-10-12-compact-web-design.md` (макеты: https://claude.ai/artifact/9Parxo7sbSdvogfrf4Rkn3, версия 2; источник тёплых токенов: `../t-premium-ui/plugin/kit/css/tokens.css`).

## Global Constraints

- PHP 8.3 и новее; Laravel 13 / Livewire 3: компоненты в `App\Http\Livewire`, макет `layouts.app`, методы, которые браузер вызывать не должен, — `protected`.
- Тексты интерфейса — по-английски, как всё приложение.
- Выбор оформления хранится только в `localStorage` браузера (ключ `cmprovision-appearance`), никаких новых таблиц и настроек на сервере. По умолчанию Warm, Compact, Auto.
- Шрифты t-premium в репозиторий не попадают: шрифт — Nunito.
- В шаблонах (`resources/views`, кроме `scriptexecute/`) нет цветов палитры Tailwind, вариантов `dark:` и шестнадцатеричных цветов в `style` — только токены (`TokenGuardTest`). Корпус и плитки страницы Ports (классы `ports-*` в `app.css`) остаются в своих цветах.
- Поведение страниц, данные и тексты журнала не меняются, кроме перечисленного в спеке (сводка и таблица партии на дашборде, поиск и колонки в CMs, подтверждение удаления и новой партии, «Settings» вместо «Other settings»).
- В репозитории в OneDrive не создаются `vendor/` и `node_modules/`. PHP-тесты гоняются на WSL-стенде `cmprov-trixie` помощниками из `dev.sh`; JS-тесты — локально `node --test <файл>` (каталог аргументом не работает). CSS собирает `/root/bench/25-css.sh`, результат `public/css/app.css` коммитится.
- Версия сборок — `2.2.0~devN` в `debian/changelog` (сейчас `2.2.0~dev1`).
- Каждый коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Push — только при выпуске, после «да» пользователя.

Переменные и помощники в командах (Git Bash):

```bash
REPO="/c/Users/igors/OneDrive/Документы/Claude/cmprovision"
SP="/c/Users/igors/AppData/Local/Temp/claude/c--Users-igors-OneDrive-----------Claude-cmprovision/61fffffd-cfb4-4c6d-b75a-efe1edfc6f5a/scratchpad"
. "$SP/wsl-bench/dev.sh"   # sync_dev, tests '<regex>', tests_all, tests_ok, tests_all_ok, w, w_raw, from_dev
```

Стенд останавливается без открытой сессии `wsl.exe`: на время работы держать фоновый `MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sleep 5400`. Многострочные команды для стенда — скриптом на stdin (`cat <<'EOF' | MSYS_NO_PATHCONV=1 wsl.exe -d cmprov-trixie -u root -- sh -s`). После `sync_dev` шаблон с прежним временем файла может остаться скомпилированным: если тест видит старую разметку, `php artisan view:clear` в `/root/dev`.

Сборка CSS (после любой правки `resources/css/app.css`, `tailwind.config.js` или классов в шаблонах):

```bash
cd "$REPO" && sync_dev >/dev/null && w sh /root/bench/25-css.sh && w_raw cat /tmp/app.css > public/css/app.css && git diff --stat public/css/app.css
```

## Review Focus

1. **Фоновая вкладка.** Вкладка скрыта, модуль упал: через ≤ 10 секунд (в глубоком фоне Chrome — до минуты) заголовок начинается с «1 failed», значок с красной точкой; партия закончилась — «✓ N done» и зелёная точка; партии нет — название страницы и обычный значок. Опрос `BatchIndicator` не должен засыпать в фоне (`keep-alive`). Тесты — задачи 3, 4 и 5.
2. **Auto там, где день переходит через полночь UTC, и где солнце не встаёт.** Владивосток: днём светлая тема, хотя восход по UTC позже заката того же UTC-дня; Мурманск в декабре: системная тема. Тесты — задача 2.
3. **Хранилище браузера недоступно или испорчено** (приватное окно, чужой JSON, неизвестные значения): страница открывается со значениями по умолчанию, без ошибок в консоли, выбор работает до перезагрузки. Тесты — задача 2.
4. **Тёмная тема на всём, что рисует браузер и Jetstream:** поля ввода плагина forms, флажки, выпадающие меню, модальные окна, полосы прокрутки, страница входа. Защита — сторож (задачи 1, 8–12) и снимки всех страниц в четырёх сочетаниях (задача 12).
5. **Края партии.** Нет активного проекта; проект сменили посреди партии; простой больше 3 часов; партию закрыли кнопкой; модули из времён до партий (без `notification_batch_id`); модуль идёт в другой партии. Дашборд показывает то, что на стенде, итог считает только открытую партию. Тесты — задачи 3 и 6.

Ещё одна ловушка, общая для задач 5 и 6: Livewire при каждом опросе приводит разметку к серверной, поэтому всё, что пользователь открыл или раскрыл (окно Appearance, журнал), живёт в состоянии Alpine (`x-data`), а не в атрибутах вроде `open` у `<details>`.

## Перевод шаблонов на токены

Общие правила для задач 5–11. Слева то, что встречается в шаблонах сейчас, справа — чем заменить.

| Было | Стало |
|---|---|
| Обёртки страницы `py-6`/`py-12` + `max-w-7xl mx-auto sm:px-6 lg:px-8` | `<div class="cmp-page">` |
| Карточки `bg-white overflow-hidden shadow-xl sm:rounded-lg …` | `cmp-card` (+ отступы `px-4 py-3` по месту) |
| Таблицы `table-auto/table-fixed …`, `th class="px-4 py-2"`, `td class="border px-4 py-2"` | `<table class="cmp-table">`, `<th>`, `<td>` без классов; моноширинные ячейки — `class="mono"` |
| `bg-gray-50/100` в заголовке таблицы | убрать (заголовок `.cmp-table th` уже на `--head`) |
| `bg-white` у панели, выпадающего окна | `bg-panel` / `bg-pop` |
| `bg-gray-100` у страницы | `bg-page` |
| `text-gray-900/800/700`, `text-black` | `text-fg` |
| `text-gray-600/500` | `text-fg-muted` |
| `text-gray-400/300` | `text-fg-faint` |
| `border-gray-100/200/300` (рамки) / разделители строк | `border-pline` / `border-line` |
| Ссылки `text-blue-600/700`, `text-indigo-600/700` | `text-link` |
| Кнопки `bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded` | `cmp-btn` |
| Кнопки `bg-gray-…`/белые с рамкой, «Cancel», «Export as CSV» | `cmp-btn cmp-btn-secondary` |
| Кнопки `bg-red-500 …` | `cmp-btn cmp-btn-danger` |
| Маленькие кнопки `text-xs … py-1 px-2` | добавить `cmp-btn-small` |
| Ошибка `bg-red-100/50`, `text-red-600/700/800` | `bg-neg-bg`, `text-neg` |
| Успех `bg-green-100`, `text-green-600/700/800` | `bg-pos-bg`, `text-pos` |
| Предупреждение `bg-yellow-50/100`, `text-yellow-600/700`, amber | `bg-warn-bg`, `text-warn` |
| Сведения `bg-blue-50/100`, `text-blue-800` | `bg-info-bg`, `text-info` |
| Сообщение `bg-teal-100 border-t-4 border-teal-500 … text-teal-900` | `cmp-flash` |
| Поля `border-gray-300 focus:border-indigo-300 focus:ring …` | `cmp-field` |
| `focus:ring-indigo-…`, `focus:border-indigo-…` | убрать (фокус задают `.cmp-*`) или `focus:ring-accent` |
| `style="color: #b45309"` и другие цвета в `style` | класс состояния (`text-warn`, `text-neg`, …) |
| `dark:…` | убрать |
| `<x-slot name="header">…</x-slot>` | удалить (заголовок, где он нужен, — строка `cmp-title` над содержимым) |

Каждая задача перевода: убрать свои файлы из `TokenGuardTest::NOT_YET` → тест падает с перечнем мест (RED) → перевести → тест и тесты страниц зелёные (GREEN) → собрать CSS → коммит. Строки и логику Livewire не трогать.

---

### Task 1: Токены, плотность, общие классы и сторож

**Files:**
- Modify: `resources/css/app.css` (токены, плотность, база, `.cmp-*`; `.cmp-lockup` берёт цвет из токена)
- Modify: `tailwind.config.js` (цвета-токены, как пресет t-premium)
- Modify: `package.json` (`test:js` — список файлов)
- Create: `tests/js/tokens.test.mjs`
- Create: `tests/Feature/TokenGuardTest.php`
- Delete: `resources/views/welcome.blade.php`, `resources/views/components/welcome.blade.php`, `resources/views/terms.blade.php`, `resources/views/policy.blade.php`, `resources/views/profile/delete-user-form.blade.php`
- Build: `public/css/app.css`

**Interfaces:**
- Produces: CSS-переменные тем (`--page`, `--panel`, `--panel-line`, `--line`, `--head`, `--card`, `--pop`, `--pop-shadow`, `--scrim`, `--text`, `--muted`, `--faint`, `--link`, `--accent`, `--accent-soft`, `--accent-text`, `--focus`, `--field`, `--field-line`, `--btn`, `--on-btn`, `--sec`, `--sec-line`, `--danger`, `--on-danger`, `--seg`, `--seg-text`, `--seg-on`, `--seg-on-text`, `--pos-bg|text|bar`, `--neg-bg|text|bar`, `--info-bg|text|bar`, `--warn-bg|text|bar`, `--track`, `--log`, `--log-text`, `--log-muted`, `--log-line`, `--log-err`, `--mark-a`, `--mark-b`, `--mark-ink`, `--mark-name`) и плотности (`--bar-h`, `--row-h`, `--fs`, `--fs-mono`, `--fs-th`, `--cell-x`, `--edge`, `--gap`, `--page-x`, `--page-y`, `--tile-num`, `--log-h`, `--mark`, `--nav-gap`); классы `cmp-page`, `cmp-title`, `cmp-card`, `cmp-table` (+ `mono`, `in-scroll`), `cmp-btn` (+ `cmp-btn-secondary`, `cmp-btn-danger`, `cmp-btn-small`), `cmp-chip` (+ `cmp-chip-run|ok|fail|stale|idle`), `cmp-field`, `cmp-tile`, `cmp-bar`, `cmp-flash`; цвета Tailwind `page panel pline line head track link pop fg(-muted|-faint) accent(-soft|-text) pos|neg|info|warn(-bg|-bar) seg(-text|-on|-ontext) log(-text|-muted|-line|-err)`, тень `shadow-pop`.
- Produces: `Tests\Feature\TokenGuardTest::NOT_YET` (array, относительные пути внутри `resources/views`) и `TokenGuardTest::problems(string $blade): array`.

- [ ] **Step 1: Падающий тест контраста**

`tests/js/tokens.test.mjs`:

```js
// Run with: node --test tests/js/tokens.test.mjs
// The theme tokens of resources/css/app.css: four themes with the same tokens, text readable (WCAG AA) on its surface.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../resources/css/app.css', import.meta.url), 'utf8');

function themes() {
    const found = {};
    for (const m of css.matchAll(/:root\[data-style="(\w+)"\]\[data-mode="(\w+)"\]\s*\{([^}]*)\}/g)) {
        const vars = {};
        for (const d of m[3].matchAll(/--([\w-]+):\s*([^;]+);/g)) vars[d[1]] = d[2].trim();
        found[`${m[1]} ${m[2]}`] = vars;
    }
    return found;
}

function rgba(value) {
    let m = value.match(/^#([0-9a-f]{6})$/i);
    if (m) return [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16)).concat(1);
    m = value.match(/^#([0-9a-f]{3})$/i);
    if (m) return [...m[1]].map((c) => parseInt(c + c, 16)).concat(1);
    m = value.match(/^rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\s*\)$/);
    if (m) return [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]];
    throw new Error(`not a colour: ${value}`);
}
const over = (fg, bg) => fg.slice(0, 3).map((c, i) => c * fg[3] + bg[i] * (1 - fg[3])).concat(1);
const luminance = (c) => c.slice(0, 3).map((v) => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
    .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
function contrast(fgValue, bgValue) {
    const bg = rgba(bgValue);
    const [a, b] = [luminance(over(rgba(fgValue), bg)), luminance(bg)].sort((x, y) => y - x);
    return (a + 0.05) / (b + 0.05);
}

// text on the surface it is read on; --faint is for what can be skipped (a clock time next to a duration)
const PAIRS = [
    ['text', 'page'], ['text', 'panel'], ['text', 'head'], ['text', 'pop'], ['text', 'field'],
    ['muted', 'page'], ['muted', 'panel'], ['muted', 'head'], ['link', 'panel'], ['link', 'page'],
    ['pos-text', 'pos-bg'], ['neg-text', 'neg-bg'], ['info-text', 'info-bg'], ['warn-text', 'warn-bg'],
    ['on-btn', 'btn'], ['on-danger', 'danger'], ['seg-text', 'seg'], ['seg-on-text', 'seg-on'], ['accent-text', 'accent-soft'],
    ['log-text', 'log'], ['log-muted', 'log'], ['log-err', 'log'], ['mark-name', 'page'],
];

test('there are four themes: warm and neutral, light and dark', () => {
    assert.deepEqual(Object.keys(themes()).sort(), ['neutral dark', 'neutral light', 'warm dark', 'warm light']);
});

test('every theme defines the same tokens', () => {
    const all = themes();
    const names = Object.keys(all['warm light']).sort();
    assert.ok(names.length > 40, 'the warm light theme defines the tokens');
    for (const [theme, vars] of Object.entries(all)) assert.deepEqual(Object.keys(vars).sort(), names, theme);
});

for (const [fg, bg] of PAIRS) {
    test(`${fg} on ${bg} keeps AA contrast in every theme`, () => {
        for (const [theme, vars] of Object.entries(themes())) {
            const ratio = contrast(vars[fg], vars[bg]);
            assert.ok(ratio >= 4.5, `${theme}: ${fg} ${vars[fg]} on ${bg} ${vars[bg]} is ${ratio.toFixed(2)}:1`);
        }
    });
}
```

Run: `cd "$REPO" && node --test tests/js/tokens.test.mjs`
Expected: FAIL — `there are four themes` (в `app.css` ещё нет блоков тем).

- [ ] **Step 2: Токены, плотность и общие классы**

В `resources/css/app.css` после блока `[x-cloak]` и шрифтов Nunito (перед комментарием `/* The cmprovision lockup …`) вставить:

```css
/* Theme tokens: two styles (warm: t-premium-ui's warm palette; neutral: the 2.1 greys and indigo) x two modes,
   chosen on <html> by public/js/appearance.js. Templates use only these (TokenGuardTest); the bare :root is warm
   light, what a page shows before the script runs. Contrast: tests/js/tokens.test.mjs. */
:root,
:root[data-style="warm"][data-mode="light"] {
    --page: #ffffff; --panel: #ffffff; --panel-line: rgba(0, 16, 36, 0.12); --line: rgba(0, 16, 36, 0.07); --head: #fbf7f2;
    --card: linear-gradient(90deg, #fef7ef 50%, #fcecd9 100%); --pop: #ffffff; --pop-shadow: 0 12px 32px rgba(0, 0, 0, 0.14); --scrim: rgba(20, 14, 8, 0.45);
    --text: rgba(0, 0, 0, 0.8); --muted: rgba(0, 0, 0, 0.58); --faint: rgba(0, 0, 0, 0.38); --link: #a4470b;
    --accent: #e2681c; --accent-soft: #ffe4cc; --accent-text: #8a3c0c; --focus: #e2681c;
    --field: #ffffff; --field-line: rgba(0, 16, 36, 0.18); --btn: #bf5410; --on-btn: #ffffff; --sec: #ffffff; --sec-line: rgba(0, 16, 36, 0.16);
    --danger: #c8261b; --on-danger: #ffffff;
    --seg: #f3f0ec; --seg-text: rgba(0, 0, 0, 0.7); --seg-on: #bf5410; --seg-on-text: #ffffff;
    --pos-bg: #e3f5e8; --pos-text: #177c32; --pos-bar: #2f9e55;
    --neg-bg: #fde8e6; --neg-text: #b3261e; --neg-bar: #d52b1e;
    --info-bg: #e8effc; --info-text: #1f4fa8; --info-bar: #3b74e0;
    --warn-bg: #fff1d6; --warn-text: #8a5a00; --warn-bar: #e0a21a;
    --track: #ece5dd;
    --log: #1f1b17; --log-text: #e9e1d8; --log-muted: #a89d91; --log-line: #2c2620; --log-err: #ff7b70;
    --mark-a: #ffd37a; --mark-b: #e2681c; --mark-ink: #2a1a0c; --mark-name: #2a1a0c;
}
:root[data-style="warm"][data-mode="dark"] {
    --page: #000000; --panel: #121214; --panel-line: #222226; --line: #1b1b1e; --head: #18181c;
    --card: #18181c; --pop: #18181c; --pop-shadow: 0 12px 32px rgba(0, 0, 0, 0.5); --scrim: rgba(0, 0, 0, 0.6);
    --text: rgba(255, 255, 255, 0.9); --muted: rgba(255, 255, 255, 0.54); --faint: rgba(255, 255, 255, 0.36); --link: #ffb27a;
    --accent: #ff8a3d; --accent-soft: #2b1f15; --accent-text: #ffc99a; --focus: #ff8a3d;
    --field: #222226; --field-line: #2c2c30; --btn: #bf5410; --on-btn: #ffffff; --sec: #222226; --sec-line: #2e2e33;
    --danger: #d52b1e; --on-danger: #ffffff;
    --seg: #18181c; --seg-text: rgba(255, 255, 255, 0.7); --seg-on: #bf5410; --seg-on-text: #ffffff;
    --pos-bg: #12301c; --pos-text: #7ee2a0; --pos-bar: #3fbf6b;
    --neg-bg: #3a1512; --neg-text: #ff9d93; --neg-bar: #ff6b5e;
    --info-bg: #14213a; --info-text: #9fbcf5; --info-bar: #5b8df0;
    --warn-bg: #35270f; --warn-text: #ffcf7a; --warn-bar: #e0a21a;
    --track: #2a2a2e;
    --log: #0d0c0b; --log-text: #d8d2ca; --log-muted: #8a8178; --log-line: #1c1a18; --log-err: #ff7b70;
    --mark-a: #ffe3b8; --mark-b: #e9a661; --mark-ink: #2a1a0c; --mark-name: rgba(255, 255, 255, 0.9);
}
:root[data-style="neutral"][data-mode="light"] {
    --page: #f3f4f6; --panel: #ffffff; --panel-line: #e5e7eb; --line: #f0f1f3; --head: #f9fafb;
    --card: #ffffff; --pop: #ffffff; --pop-shadow: 0 12px 32px rgba(0, 0, 0, 0.14); --scrim: rgba(17, 24, 39, 0.45);
    --text: #111827; --muted: #5f6672; --faint: #9ca3af; --link: #1d4ed8;
    --accent: #6366f1; --accent-soft: #eef2ff; --accent-text: #4338ca; --focus: #6366f1;
    --field: #ffffff; --field-line: #d1d5db; --btn: #4f46e5; --on-btn: #ffffff; --sec: #ffffff; --sec-line: #d1d5db;
    --danger: #dc2626; --on-danger: #ffffff;
    --seg: #f3f4f6; --seg-text: #374151; --seg-on: #4f46e5; --seg-on-text: #ffffff;
    --pos-bg: #ecfdf5; --pos-text: #047857; --pos-bar: #22c55e;
    --neg-bg: #fef2f2; --neg-text: #b91c1c; --neg-bar: #ef4444;
    --info-bg: #eff6ff; --info-text: #1d4ed8; --info-bar: #3b82f6;
    --warn-bg: #fffbeb; --warn-text: #b45309; --warn-bar: #f59e0b;
    --track: #e5e7eb;
    --log: #ffffff; --log-text: #111827; --log-muted: #5f6672; --log-line: #f0f1f3; --log-err: #b91c1c;
    --mark-a: #ffd37a; --mark-b: #e2681c; --mark-ink: #2a1a0c; --mark-name: #2a1a0c;
}
:root[data-style="neutral"][data-mode="dark"] {
    --page: #0b1120; --panel: #111827; --panel-line: #1f2937; --line: #182132; --head: #0f172a;
    --card: #111827; --pop: #1f2937; --pop-shadow: 0 12px 32px rgba(0, 0, 0, 0.5); --scrim: rgba(0, 0, 0, 0.6);
    --text: #e5e7eb; --muted: #9ca3af; --faint: #6b7280; --link: #93c5fd;
    --accent: #818cf8; --accent-soft: #1e1b4b; --accent-text: #c7d2fe; --focus: #818cf8;
    --field: #1f2937; --field-line: #374151; --btn: #4f46e5; --on-btn: #ffffff; --sec: #1f2937; --sec-line: #374151;
    --danger: #dc2626; --on-danger: #ffffff;
    --seg: #1f2937; --seg-text: #d1d5db; --seg-on: #4f46e5; --seg-on-text: #ffffff;
    --pos-bg: #052e1c; --pos-text: #6ee7b7; --pos-bar: #34d399;
    --neg-bg: #3b0d0d; --neg-text: #fca5a5; --neg-bar: #f87171;
    --info-bg: #0c1f3d; --info-text: #93c5fd; --info-bar: #60a5fa;
    --warn-bg: #3a2606; --warn-text: #fcd34d; --warn-bar: #f59e0b;
    --track: #1f2937;
    --log: #0b1120; --log-text: #d1d5db; --log-muted: #9ca3af; --log-line: #182132; --log-err: #fca5a5;
    --mark-a: #ffe3b8; --mark-b: #e9a661; --mark-ink: #2a1a0c; --mark-name: #e5e7eb;
}
:root[data-mode="dark"] { color-scheme: dark; }

/* Density: the same layout, two scales (compact is the default) */
:root,
:root[data-density="compact"] {
    --bar-h: 48px; --row-h: 30px; --fs: 13px; --fs-mono: 12px; --fs-th: 11px; --cell-x: 8px; --edge: 16px;
    --gap: 12px; --page-x: 24px; --page-y: 16px; --tile-num: 24px; --log-h: 300px; --mark: 28px; --nav-gap: 20px;
}
:root[data-density="roomy"] {
    --bar-h: 64px; --row-h: 44px; --fs: 15px; --fs-mono: 14px; --fs-th: 12px; --cell-x: 12px; --edge: 24px;
    --gap: 20px; --page-x: 32px; --page-y: 24px; --tile-num: 30px; --log-h: 400px; --mark: 36px; --nav-gap: 32px;
}

html { background: var(--page); }
body { background: var(--page); color: var(--text); }

@layer components {
    .cmp-page { max-width: 1920px; margin: 0 auto; padding: var(--page-y) var(--page-x); display: flex; flex-direction: column; gap: var(--gap); font-size: var(--fs); }
    .cmp-title { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
    .cmp-title h1 { font-size: 18px; font-weight: 700; }
    .cmp-card { background: var(--panel); border: 1px solid var(--panel-line); border-radius: 8px; }
    .cmp-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: var(--fs); }
    .cmp-table th { position: sticky; top: var(--bar-h); z-index: 1; background: var(--head); color: var(--muted); font-size: var(--fs-th);
        font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; text-align: left; padding: 6px var(--cell-x);
        border-bottom: 1px solid var(--panel-line); white-space: nowrap; }
    .cmp-table.in-scroll th { top: 0; }
    .cmp-table td { height: var(--row-h); padding: 0 var(--cell-x); border-top: 1px solid var(--line); vertical-align: middle; }
    .cmp-table tbody tr:first-child td { border-top: 0; }
    .cmp-table tr > :first-child { padding-left: var(--edge); }
    .cmp-table tr > :last-child { padding-right: var(--edge); }
    .cmp-table .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: var(--fs-mono); white-space: nowrap; }
    .cmp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: 6px; padding: 4px 12px;
        font-size: var(--fs); font-weight: 700; line-height: 20px; white-space: nowrap; cursor: pointer;
        background: var(--btn); color: var(--on-btn); border: 1px solid transparent; }
    .cmp-btn:hover { filter: brightness(1.08); }
    .cmp-btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
    .cmp-btn:disabled { opacity: 0.5; cursor: default; filter: none; }
    .cmp-btn-secondary { background: var(--sec); color: var(--text); border-color: var(--sec-line); }
    .cmp-btn-danger { background: var(--danger); color: var(--on-danger); }
    .cmp-btn-small { padding: 0 8px; font-size: 12px; line-height: 22px; }
    .cmp-chip { display: inline-flex; align-items: center; gap: 4px; border-radius: 999px; padding: 0 8px; font-size: 12px; font-weight: 700; line-height: 20px; white-space: nowrap; }
    .cmp-chip .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .cmp-chip-run { background: var(--info-bg); color: var(--info-text); }
    .cmp-chip-ok { background: var(--pos-bg); color: var(--pos-text); }
    .cmp-chip-fail { background: var(--neg-bg); color: var(--neg-text); }
    .cmp-chip-stale { background: var(--warn-bg); color: var(--warn-text); }
    .cmp-chip-idle { background: var(--seg); color: var(--seg-text); }
    .cmp-field { background: var(--field); color: var(--text); border: 1px solid var(--field-line); border-radius: 6px; padding: 4px 8px; font-size: var(--fs); }
    .cmp-field:focus { outline: 2px solid var(--focus); outline-offset: 0; border-color: transparent; box-shadow: none; }
    .cmp-tile { background: var(--card); border: 1px solid var(--panel-line); border-radius: 8px; padding: 8px 16px; }
    .cmp-tile .num { font-size: var(--tile-num); font-weight: 800; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .cmp-tile .label { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); }
    .cmp-bar { width: 112px; height: 6px; border-radius: 999px; background: var(--track); overflow: hidden; flex-shrink: 0; }
    .cmp-bar > span { display: block; height: 100%; }
    .cmp-flash { background: var(--pos-bg); color: var(--pos-text); border-radius: 8px; padding: 8px 16px; font-weight: 600; }
}
```

В правиле `.cmp-lockup` заменить `color: #2a1a0c;` на `color: var(--mark-name);`.

- [ ] **Step 3: Tailwind знает цвета как токены**

`tailwind.config.js` целиком:

```js
const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');

/* Built with the standalone Tailwind CLI 3.4 (the forms and typography plugins are inside), see CLAUDE.md.
   The app's colours are the theme tokens of resources/css/app.css, as in t-premium-ui's preset: templates
   name roles (bg-panel, text-fg-muted, bg-neg-bg) and data-style/data-mode on <html> pick the values. */
const token = (name) => `var(--${name})`;

module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Jetstream 5's views carry dark: classes until they are converted to tokens (TokenGuardTest::NOT_YET)
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Tailwind 3 renamed v2's green, yellow and purple: the views not yet on tokens keep the v2 look
                green: colors.emerald,
                yellow: colors.amber,
                purple: colors.violet,
                page: token('page'), panel: token('panel'), pline: token('panel-line'), line: token('line'), head: token('head'),
                track: token('track'), link: token('link'), pop: token('pop'),
                fg: { DEFAULT: token('text'), muted: token('muted'), faint: token('faint') },
                accent: { DEFAULT: token('accent'), soft: token('accent-soft'), text: token('accent-text') },
                pos: { DEFAULT: token('pos-text'), bg: token('pos-bg'), bar: token('pos-bar') },
                neg: { DEFAULT: token('neg-text'), bg: token('neg-bg'), bar: token('neg-bar') },
                info: { DEFAULT: token('info-text'), bg: token('info-bg'), bar: token('info-bar') },
                warn: { DEFAULT: token('warn-text'), bg: token('warn-bg'), bar: token('warn-bar') },
                seg: { DEFAULT: token('seg'), text: token('seg-text'), on: token('seg-on'), ontext: token('seg-on-text') },
                log: { DEFAULT: token('log'), text: token('log-text'), muted: token('log-muted'), line: token('log-line'), err: token('log-err') },
            },
            boxShadow: {
                pop: token('pop-shadow'),
            },
            width: {
                '1/8': '12.5%',
            },
        },
    },

    plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')],
};
```

`package.json`, ключ `scripts`:

```json
"test:js": "node --test tests/js/upload-progress.test.mjs tests/js/tokens.test.mjs"
```

Run: `cd "$REPO" && node --test tests/js/tokens.test.mjs`
Expected: PASS — 25 тестов (2 + 23 пары).

- [ ] **Step 4: Сторож без списка — падает**

`tests/Feature/TokenGuardTest.php`:

```php
<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Views name colours by role (theme tokens, tailwind.config.js), never by palette: the Appearance style and mode
 * then reach every page. scriptexecute/ renders shell, not HTML.
 */
class TokenGuardTest extends TestCase
{
    /* Views still on raw colours: each conversion task takes its files off this list (plan Task 1) */
    const NOT_YET = [];

    const PALETTE = '(?:white|black|slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)';
    const UTILITIES = '(?:bg|text|border|border-[trblxy]|ring|ring-offset|divide|outline|placeholder|from|via|to|fill|stroke|decoration|accent|caret|shadow)';

    public static function views(): array
    {
        $root = resource_path('views').DIRECTORY_SEPARATOR;
        $views = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $view = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)));
            if (str_ends_with($view, '.blade.php') && !str_starts_with($view, 'scriptexecute/'))
                $views[$view] = [$view];
        }
        ksort($views);
        return $views;
    }

    /** Raw colours in a template: palette utilities (with any variant prefix), dark: variants, hex colours in style */
    public static function problems(string $blade): array
    {
        preg_match_all('/(?<![\w-])(?:[a-z-]+:)*'.self::UTILITIES.'-'.self::PALETTE.'(?:-\d{2,3})?(?:\/\d+)?(?![\w-])/', $blade, $classes);
        preg_match_all('/(?<![\w-])dark:[\w\/\[\]\.-]+/', $blade, $dark);
        preg_match_all('/style="[^"]*#[0-9a-fA-F]{3,8}/', $blade, $hex);
        return array_values(array_unique(array_merge($classes[0], $dark[0], $hex[0])));
    }

    #[DataProvider('views')]
    public function test_views_use_theme_tokens_only(string $view)
    {
        $problems = self::problems(file_get_contents(resource_path('views/'.$view)));
        if (in_array($view, self::NOT_YET, true)) {
            $this->assertNotEmpty($problems, "$view has no raw colours left: take it off TokenGuardTest::NOT_YET");
            return;
        }
        $this->assertSame([], $problems, "$view uses raw colours");
    }

    public function test_the_list_names_only_views_that_exist()
    {
        foreach (self::NOT_YET as $view)
            $this->assertFileExists(resource_path('views/'.$view));
    }

    public function test_the_guard_sees_what_it_should()
    {
        $this->assertSame(['bg-white', 'hover:text-gray-700', 'dark:bg-gray-800', 'style="color: #b45309'],
            self::problems('<div class="bg-white hover:text-gray-700 dark:bg-gray-800 text-fg bg-panel border-transparent text-sm" style="color: #b45309">'));
        $this->assertSame([], self::problems('<td class="text-fg-muted bg-neg-bg ring-pline shadow-pop">'));
    }
}
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'TokenGuardTest' | tail -4`
Expected: FAIL — 57 шаблонов с «сырыми» цветами (и 5 удаляемых в шаге 5).

- [ ] **Step 5: Удалить неиспользуемые шаблоны Jetstream и заполнить список**

Удалить (включена только функция `api()`; маршрут `/` уводит на вход; `x-welcome` нигде не вызывается; `delete-user-form` подключается только при включённом удалении аккаунта):

```bash
cd "$REPO" && git rm -q resources/views/welcome.blade.php resources/views/components/welcome.blade.php resources/views/terms.blade.php resources/views/policy.blade.php resources/views/profile/delete-user-form.blade.php
```

`components/switchable-team.blade.php` остаётся до задачи 5: на него ссылается `navigation-menu` (Blade разрешает компоненты при компиляции).

`NOT_YET` — ровно эти 57 файлов:

```php
    const NOT_YET = [
        'api/api-token-manager.blade.php', 'api/index.blade.php',
        'auth/confirm-password.blade.php', 'auth/login.blade.php', 'auth/two-factor-challenge.blade.php',
        'components/action-message.blade.php', 'components/action-section.blade.php', 'components/authentication-card.blade.php',
        'components/banner.blade.php', 'components/button.blade.php', 'components/checkbox.blade.php',
        'components/confirmation-modal.blade.php', 'components/danger-button.blade.php', 'components/dialog-modal.blade.php',
        'components/dropdown-link.blade.php', 'components/dropdown.blade.php', 'components/form-section.blade.php',
        'components/input-error.blade.php', 'components/input.blade.php', 'components/label.blade.php',
        'components/modal.blade.php', 'components/nav-link.blade.php', 'components/responsive-nav-link.blade.php',
        'components/secondary-button.blade.php', 'components/section-border.blade.php', 'components/section-title.blade.php',
        'components/switchable-team.blade.php', 'components/validation-errors.blade.php',
        'dashboard.blade.php', 'layouts/app.blade.php', 'layouts/guest.blade.php',
        'livewire/addimage.blade.php', 'livewire/addstaticip.blade.php', 'livewire/cm-card.blade.php', 'livewire/cms.blade.php',
        'livewire/editlabel.blade.php', 'livewire/editproject.blade.php', 'livewire/editscript.blade.php',
        'livewire/firmware.blade.php', 'livewire/images.blade.php', 'livewire/labels.blade.php',
        'livewire/notification-settings.blade.php', 'livewire/ports.blade.php', 'livewire/projects.blade.php',
        'livewire/provisioning-log.blade.php', 'livewire/provisioning-status.blade.php', 'livewire/scripts.blade.php',
        'livewire/settings.blade.php', 'livewire/switch-settings.blade.php', 'livewire/switch-snmp-hints.blade.php',
        'livewire/uplink-indicator.blade.php', 'livewire/viewlog.blade.php', 'navigation-menu.blade.php',
        'profile/logout-other-browser-sessions-form.blade.php', 'profile/show.blade.php',
        'profile/two-factor-authentication-form.blade.php', 'profile/update-profile-information-form.blade.php',
    ];
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'TokenGuardTest' | tail -3`
Expected: PASS (все шаблоны: переведённые чистые, из списка — ещё с цветами).

- [ ] **Step 6: CSS и весь набор**

Собрать CSS (команда из раздела Global Constraints).
Expected: в `public/css/app.css` есть `--page:#fff` (в любом регистре/минификации — `grep -c "data-style=warm\]\[data-mode=dark\]\|data-style=\"warm\"\]\[data-mode=\"dark\"\]" public/css/app.css` ≥ 1), `.cmp-table`, `.cmp-btn`; `.cmp-*`, которых нет в шаблонах, Tailwind выбрасывает (это нормально, классы появятся со своими шаблонами).

Run: `cd "$REPO" && tests_all_ok | tail -4; node --test tests/js/upload-progress.test.mjs tests/js/tokens.test.mjs 2>&1 | tail -3`
Expected: phpunit exit 0 (тестов больше на число шаблонов в сторожe); node: `# fail 0`.

- [ ] **Step 7: Commit**

```bash
cd "$REPO" && git add -A resources/css/app.css public/css/app.css tailwind.config.js package.json tests/js/tokens.test.mjs tests/Feature/TokenGuardTest.php resources/views && git commit -q -m "Theme tokens, density and the token guard" -m "Four themes (warm from t-premium-ui, neutral from the 2.1 greys) x light/dark as CSS variables, two densities, cmp-* components, colours as tokens in Tailwind; the AA contrast of text pairs is tested. TokenGuardTest lists the 57 views still on raw colours; unused Jetstream views (welcome, terms, policy, account deletion) are gone." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Appearance — стиль, плотность, тема до отрисовки

**Files:**
- Create: `app/Support/SunPlace.php`, `tests/Unit/SunPlaceTest.php`
- Create: `public/js/appearance.js`, `tests/js/appearance.test.mjs`
- Modify: `resources/views/layouts/app.blade.php`, `resources/views/layouts/guest.blade.php` (атрибуты `data-sun*` на `<html>`, скрипт в `<head>`)
- Create: `tests/Feature/AppearanceBootTest.php`
- Modify: `package.json` (`test:js` + `tests/js/appearance.test.mjs`)

**Interfaces:**
- Produces: `App\Support\SunPlace::forZone(?string $zone): ?array` → `['lat' => float(4 знака), 'lng' => float, 'city' => string, 'zone' => string]` или `null`.
- Produces: `window.Appearance` в браузере: `get(): {style, density, mode}`, `set(patch)`, `subscribe(fn)`, `today(): {sunrise: Date, sunset: Date}|null`, `city(): string|null`, `zone(): string|null`; глобальная функция `window.appearancePopover()` для `x-data` (данные Alpine: `open`, `choice`, `set(patch)`, `close()`, `caption()`).
- Produces (node): `require('public/js/appearance.js')` → `{ DEFAULTS, readChoice(text), sunTimes(date, place), autoMode(now, place, prefersDark), nextSwitch(now, place), resolve(choice, now, place, prefersDark), start(win) }`.

- [ ] **Step 1: Падающие тесты SunPlace**

`tests/Unit/SunPlaceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\SunPlace;
use PHPUnit\Framework\TestCase;

/** Where the server's display time zone points: the city whose sunrise and sunset drive the Auto theme */
class SunPlaceTest extends TestCase
{
    public function test_a_city_zone_gives_its_place()
    {
        $this->assertSame(['lat' => 55.7558, 'lng' => 37.6178, 'city' => 'Moscow', 'zone' => 'Europe/Moscow'], SunPlace::forZone('Europe/Moscow'));
    }

    public function test_underscores_in_the_city_become_spaces()
    {
        $this->assertSame('New York', SunPlace::forZone('America/New_York')['city']);
    }

    public function test_utc_unknown_and_empty_zones_have_no_place()
    {
        $this->assertNull(SunPlace::forZone('UTC'));
        $this->assertNull(SunPlace::forZone('Not/A_Zone'));
        $this->assertNull(SunPlace::forZone(''));
        $this->assertNull(SunPlace::forZone(null));
    }
}
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'SunPlaceTest' | tail -3`
Expected: FAIL — `Class "App\Support\SunPlace" not found`.

- [ ] **Step 2: SunPlace**

`app/Support/SunPlace.php`:

```php
<?php

namespace App\Support;

use DateTimeZone;

/**
 * The place of the server's display time zone (its reference city, as the tz database gives it): the Auto theme
 * is light from sunrise to sunset there (public/js/appearance.js). A zone without a place (UTC) gives null, and
 * Auto then follows the browser's system setting.
 */
class SunPlace
{
    public static function forZone(?string $zone): ?array
    {
        if ($zone === null || $zone === '')
            return null;
        try {
            $location = (new DateTimeZone($zone))->getLocation();
        } catch (\Exception $e) {
            return null;
        }
        if (!$location || ($location['country_code'] ?? '??') === '??' || ($location['latitude'] == 0 && $location['longitude'] == 0))
            return null;
        $parts = explode('/', $zone);
        return ['lat' => round($location['latitude'], 4), 'lng' => round($location['longitude'], 4),
                'city' => str_replace('_', ' ', end($parts)), 'zone' => $zone];
    }
}
```

Run: `tests 'SunPlaceTest' | tail -3`
Expected: PASS (3 tests).

- [ ] **Step 3: Падающие JS-тесты**

`tests/js/appearance.test.mjs`:

```js
// Run with: node --test tests/js/appearance.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const A = require('../../public/js/appearance.js');

const MOSCOW = { lat: 55.7558, lng: 37.6178 };
const VLADIVOSTOK = { lat: 43.1333, lng: 131.9 };
const MURMANSK = { lat: 68.9667, lng: 33.0833 };
const at = (iso) => new Date(iso);
const near = (date, iso, minutes = 2) => assert.ok(Math.abs(date - at(iso)) <= minutes * 60000, `${date.toISOString()} is not within ${minutes} min of ${iso}`);

test('sunrise and sunset in Moscow on 12 October 2026', () => {
    const sun = A.sunTimes(at('2026-10-12T09:00:00Z'), MOSCOW);
    near(sun.sunrise, '2026-10-12T03:53:00Z');
    near(sun.sunset, '2026-10-12T14:38:00Z');
});

test('auto is light between sunrise and sunset and dark otherwise', () => {
    assert.equal(A.autoMode(at('2026-10-12T09:00:00Z'), MOSCOW, true), 'light');
    assert.equal(A.autoMode(at('2026-10-12T15:00:00Z'), MOSCOW, false), 'dark');
    assert.equal(A.autoMode(at('2026-10-12T02:00:00Z'), MOSCOW, false), 'dark');
});

test('a day that spans midnight UTC is still light at noon (Vladivostok)', () => {
    assert.equal(A.autoMode(at('2026-10-12T02:00:00Z'), VLADIVOSTOK, true), 'light');   // 12:00 local
    assert.equal(A.autoMode(at('2026-10-12T13:00:00Z'), VLADIVOSTOK, false), 'dark');   // 23:00 local
});

test('without a sunrise or a place auto follows the system setting', () => {
    assert.equal(A.sunTimes(at('2026-12-20T09:00:00Z'), MURMANSK), null);               // polar night
    assert.equal(A.autoMode(at('2026-12-20T09:00:00Z'), MURMANSK, true), 'dark');
    assert.equal(A.autoMode(at('2026-12-20T09:00:00Z'), MURMANSK, false), 'light');
    assert.equal(A.autoMode(at('2026-10-12T09:00:00Z'), null, true), 'dark');
});

test('the next switch is the coming sunset or the next sunrise', () => {
    near(A.nextSwitch(at('2026-10-12T09:00:00Z'), MOSCOW), '2026-10-12T14:38:00Z');
    near(A.nextSwitch(at('2026-10-12T15:00:00Z'), MOSCOW), '2026-10-13T03:55:00Z', 3);
    assert.equal(A.nextSwitch(at('2026-10-12T09:00:00Z'), null), null);
});

test('a stored choice is read back, and anything else falls back to the defaults', () => {
    assert.deepEqual(A.readChoice('{"style":"neutral","density":"roomy","mode":"dark"}'), { style: 'neutral', density: 'roomy', mode: 'dark' });
    assert.deepEqual(A.readChoice('{"style":"blue","density":3}'), A.DEFAULTS);
    assert.deepEqual(A.readChoice('not json'), A.DEFAULTS);
    assert.deepEqual(A.readChoice(null), A.DEFAULTS);
    assert.deepEqual(A.readChoice('null'), A.DEFAULTS);
    assert.deepEqual(A.DEFAULTS, { style: 'warm', density: 'compact', mode: 'auto' });
});

test('resolve turns auto into light or dark and keeps the rest', () => {
    assert.deepEqual(A.resolve({ style: 'neutral', density: 'roomy', mode: 'auto' }, at('2026-10-12T15:00:00Z'), MOSCOW, false),
        { style: 'neutral', density: 'roomy', mode: 'dark' });
    assert.deepEqual(A.resolve({ style: 'warm', density: 'compact', mode: 'light' }, at('2026-10-12T15:00:00Z'), MOSCOW, true),
        { style: 'warm', density: 'compact', mode: 'light' });
});
```

`package.json`: `"test:js": "node --test tests/js/upload-progress.test.mjs tests/js/tokens.test.mjs tests/js/appearance.test.mjs"`.

Run: `cd "$REPO" && node --test tests/js/appearance.test.mjs 2>&1 | tail -4`
Expected: FAIL — `Cannot find module '../../public/js/appearance.js'`.

- [ ] **Step 4: appearance.js**

`public/js/appearance.js`:

```js
/*
 * Appearance: style (warm, neutral), density (compact, roomy) and theme (light, dark, auto) on <html> before the
 * page paints, Auto by sunrise and sunset where the server's time zone points (data-sun on <html>, as
 * t-premium-ui does for Moscow), and the Appearance popover in the top bar (Alpine: x-data="appearancePopover()").
 * Plain script (no build step): <head> runs it and gets window.Appearance; node tests require() its pure functions.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.Appearance = api;
        api.start(root);
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var KEY = 'cmprovision-appearance';
    var DEFAULTS = { style: 'warm', density: 'compact', mode: 'auto' };
    var CHOICES = { style: ['warm', 'neutral'], density: ['compact', 'roomy'], mode: ['light', 'dark', 'auto'] };
    var DAY = 86400000;

    function rad(d) { return d * Math.PI / 180; }
    function deg(r) { return r * 180 / Math.PI; }
    function mod(a, n) { return ((a % n) + n) % n; }

    /* "Almanac for Computers" (t-premium-ui's sun.ts, ~2 min): the event's hour UTC on the UTC day of date, null without one */
    function eventHours(date, lat, lng, rising) {
        var lngHour = lng / 15;
        var start = Date.UTC(date.getUTCFullYear(), 0, 0);
        var day = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
        var t = Math.round((day - start) / DAY) + ((rising ? 6 : 18) - lngHour) / 24;
        var M = 0.9856 * t - 3.289;
        var L = mod(M + 1.916 * Math.sin(rad(M)) + 0.02 * Math.sin(rad(2 * M)) + 282.634, 360);
        var RA = mod(deg(Math.atan(0.91764 * Math.tan(rad(L)))), 360);
        RA = (RA + (Math.floor(L / 90) * 90 - Math.floor(RA / 90) * 90)) / 15;
        var sinDec = 0.39782 * Math.sin(rad(L));
        var cosDec = Math.cos(Math.asin(sinDec));
        var cosH = (Math.cos(rad(90.833)) - sinDec * Math.sin(rad(lat))) / (cosDec * Math.cos(rad(lat)));
        if (cosH > 1 || cosH < -1) return null;
        var H = (rising ? 360 - deg(Math.acos(cosH)) : deg(Math.acos(cosH))) / 15;
        return mod(H + RA - 0.06571 * t - 6.622 - lngHour, 24);
    }

    /* Sunrise and sunset on the UTC day of date at place {lat, lng}; null during polar day or night */
    function sunTimes(date, place) {
        var rise = eventHours(date, place.lat, place.lng, true);
        var set = eventHours(date, place.lat, place.lng, false);
        if (rise === null || set === null) return null;
        var midnight = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
        return { sunrise: new Date(midnight + rise * 3600000), sunset: new Date(midnight + set * 3600000) };
    }

    function autoMode(now, place, prefersDark) {
        var sun = place ? sunTimes(now, place) : null;
        if (!sun) return prefersDark ? 'dark' : 'light';
        var day = sun.sunrise < sun.sunset
            ? now >= sun.sunrise && now < sun.sunset
            : now >= sun.sunrise || now < sun.sunset;   // the local day spans midnight UTC (far east and west)
        return day ? 'light' : 'dark';
    }

    function nextSwitch(now, place) {
        if (!place) return null;
        for (var i = 0; i < 3; i++) {
            var sun = sunTimes(new Date(now.getTime() + i * DAY), place);
            if (!sun) return null;
            var next = [sun.sunrise, sun.sunset].filter(function (t) { return t > now; }).sort(function (a, b) { return a - b; });
            if (next.length) return next[0];
        }
        return null;
    }

    function readChoice(text) {
        var saved;
        try { saved = JSON.parse(text || '{}'); } catch (e) { saved = null; }
        if (!saved || typeof saved !== 'object') saved = {};
        var choice = {};
        Object.keys(DEFAULTS).forEach(function (key) {
            choice[key] = CHOICES[key].indexOf(saved[key]) >= 0 ? saved[key] : DEFAULTS[key];
        });
        return choice;
    }

    function resolve(choice, now, place, prefersDark) {
        return { style: choice.style, density: choice.density, mode: choice.mode === 'auto' ? autoMode(now, place, prefersDark) : choice.mode };
    }

    var api = { DEFAULTS: DEFAULTS, readChoice: readChoice, sunTimes: sunTimes, autoMode: autoMode, nextSwitch: nextSwitch, resolve: resolve };

    /* In the browser: apply now (from <head>), then follow sunrise/sunset, the system theme and other tabs */
    api.start = function (win) {
        var html = win.document.documentElement;
        var media = win.matchMedia ? win.matchMedia('(prefers-color-scheme: dark)') : null;
        var listeners = [];
        var timer = null;

        function place() {
            var sun = (html.getAttribute('data-sun') || '').split(',');
            var lat = parseFloat(sun[0]), lng = parseFloat(sun[1]);
            return sun.length === 2 && isFinite(lat) && isFinite(lng) ? { lat: lat, lng: lng } : null;
        }
        function load() {
            try { return readChoice(win.localStorage.getItem(KEY)); } catch (e) { return readChoice(null); }
        }
        var choice = load();

        function apply() {
            var now = new Date(), where = place();
            var resolved = resolve(choice, now, where, !!(media && media.matches));
            html.setAttribute('data-style', resolved.style);
            html.setAttribute('data-density', resolved.density);
            html.setAttribute('data-mode', resolved.mode);
            if (timer) win.clearTimeout(timer);
            var next = choice.mode === 'auto' ? nextSwitch(now, where) : null;
            timer = next ? win.setTimeout(apply, Math.max(next - now, 0) + 1000) : null;
        }
        function notify() {
            listeners.forEach(function (fn) { fn(api.get()); });
        }

        apply();
        if (media && media.addEventListener) media.addEventListener('change', apply);
        win.addEventListener('storage', function (e) {
            if (e.key === KEY) { choice = load(); apply(); notify(); }
        });

        api.get = function () { return { style: choice.style, density: choice.density, mode: choice.mode }; };
        api.set = function (patch) {
            var next = api.get();
            Object.keys(patch || {}).forEach(function (key) { next[key] = patch[key]; });
            choice = readChoice(JSON.stringify(next));
            try { win.localStorage.setItem(KEY, JSON.stringify(choice)); } catch (e) { /* not remembered: this page only */ }
            apply();
            notify();
        };
        api.subscribe = function (fn) { listeners.push(fn); };
        api.today = function () { var where = place(); return where ? sunTimes(new Date(), where) : null; };
        api.city = function () { return html.getAttribute('data-sun-city'); };
        api.zone = function () { return html.getAttribute('data-sun-zone'); };

        win.appearancePopover = function () {
            return {
                open: false,
                choice: api.get(),
                init: function () {
                    var self = this;
                    api.subscribe(function (c) { self.choice = c; });
                },
                set: function (patch) {
                    api.set(patch);
                    this.choice = api.get();
                },
                close: function () {
                    if (!this.open) return;
                    this.open = false;
                    if (this.$refs && this.$refs.button) this.$refs.button.focus();
                },
                caption: function () {
                    var sun = api.today(), city = api.city(), zone = api.zone();
                    if (!sun || !city) return 'Auto: follows your system setting.';
                    var hm = function (d) {
                        try { return d.toLocaleTimeString('en-GB', { timeZone: zone || undefined, hour: '2-digit', minute: '2-digit' }); }
                        catch (e) { return d.toTimeString().slice(0, 5); }
                    };
                    return 'Auto: light from sunrise to sunset in ' + city + ', from the server\u2019s time zone. Today '
                        + hm(sun.sunrise) + '\u2013' + hm(sun.sunset) + '.';
                }
            };
        };
    };

    return api;
}));
```

Run: `cd "$REPO" && node --test tests/js/appearance.test.mjs 2>&1 | tail -4`
Expected: PASS (7 тестов, `# fail 0`).

- [ ] **Step 5: Падающий тест загрузки в макетах**

`tests/Feature/AppearanceBootTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every page applies the stored appearance in <head>, before it paints, and knows where Auto takes its sun from */
class AppearanceBootTest extends TestCase
{
    use RefreshDatabase;

    protected function assertAppliedInHead(string $html)
    {
        $script = strpos($html, '<script src="'.asset('js/appearance.js'));
        $this->assertNotFalse($script, 'appearance.js is on the page');
        $this->assertLessThan(strpos($html, '<body'), $script, 'appearance.js runs in <head>, before the page paints');
    }

    public function test_the_sign_in_page_applies_it()
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $html = $this->get('/login')->assertOk()->getContent();
        $this->assertAppliedInHead($html);
        $this->assertStringContainsString('data-sun="55.7558,37.6178" data-sun-city="Moscow" data-sun-zone="Europe/Moscow"', $html);
    }

    public function test_the_app_pages_apply_it()
    {
        config(['app.display_timezone' => 'Europe/Moscow']);
        $html = $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->getContent();
        $this->assertAppliedInHead($html);
        $this->assertStringContainsString('data-sun-city="Moscow"', $html);
    }

    public function test_a_server_on_utc_leaves_auto_to_the_system_setting()
    {
        config(['app.display_timezone' => 'UTC']);
        $this->get('/login')->assertOk()->assertDontSee('data-sun=', false);
    }
}
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'AppearanceBootTest' | tail -3`
Expected: FAIL — `appearance.js is on the page`.

- [ ] **Step 6: Атрибуты и скрипт в обоих макетах**

В `resources/views/layouts/app.blade.php` и `resources/views/layouts/guest.blade.php` заменить строку `<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">` на:

```blade
@php($sun = \App\Support\SunPlace::forZone(config('app.display_timezone')))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($sun) data-sun="{{ $sun['lat'] }},{{ $sun['lng'] }}" data-sun-city="{{ $sun['city'] }}" data-sun-zone="{{ $sun['zone'] }}" @endif>
```

и сразу после строки `<link rel="stylesheet" href="{{ asset('css/app.css') }}…">` в обоих добавить:

```blade
        <script src="{{ asset('js/appearance.js') }}?v={{ @filemtime(public_path('js/appearance.js')) }}"></script>
```

Run: `tests 'AppearanceBootTest|SunPlaceTest|FaviconTest|BrandingTest' | tail -3`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd "$REPO" && git add app/Support/SunPlace.php tests/Unit/SunPlaceTest.php public/js/appearance.js tests/js/appearance.test.mjs tests/Feature/AppearanceBootTest.php resources/views/layouts/app.blade.php resources/views/layouts/guest.blade.php package.json && git commit -q -m "Appearance: style, density and theme on <html> before the page paints" -m "appearance.js reads the choice from localStorage (defaults warm, compact, auto) and sets data-style, data-density and data-mode in <head>; Auto is light from sunrise to sunset where the server's time zone points (SunPlace, the zone's reference city), and follows the system setting without one. The sun algorithm is t-premium-ui's; days that span midnight UTC are handled." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Открытая партия и её итог

**Files:**
- Modify: `app/Models/NotificationBatch.php` (`open()`, `lastClosed()`)
- Create: `app/Support/BatchSummary.php`
- Create: `tests/Feature/BatchSummaryTest.php`

**Interfaces:**
- Consumes: `Project::getActiveId(): ?int`, `NotificationBatch::IDLE_HOURS`, `Cm::ACTIVE_PHASES`, `Cm::secondsLeft(): ?int`.
- Produces: `NotificationBatch::open(): ?NotificationBatch`, `NotificationBatch::lastClosed(): ?NotificationBatch`.
- Produces: `App\Support\BatchSummary` — `static current(): ?self` (открытая партия), `static forDashboard(): ?self` (открытая или последняя), публичные `batch`, `project` (`?Project`), `modules` (Collection of Cm), `open` (bool), `counts` (`['active' => int, 'done' => int, 'failed' => int]`), `averageSeconds` (`?int`), `leftSeconds` (`?int`), `state` (`'run'|'fail'|'done'|'idle'`); `title(): string` (префикс заголовка вкладки, пустая строка — нечего сказать).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/BatchSummaryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Support\BatchSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The batch the stand works on (the one the bots report) and what the top bar, the tab and the dashboard say of it */
class BatchSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 07:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function project($name = '78', $active = true)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        if ($active)
            Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return $project;
    }

    protected function batch($project, array $attributes = [])
    {
        return NotificationBatch::create(array_merge(['project_id' => $project->id, 'started_at' => now()->subMinutes(38),
            'last_event_at' => now()->subMinute()], $attributes));
    }

    protected function module($batch, $n, $phase, array $attributes = [])
    {
        return Cm::create(array_merge(['serial' => sprintf('10000000a1f3c2%02x', $n), 'mac' => sprintf('e4:5f:01:a1:c2:%02x', $n),
            'phase' => $phase, 'notification_batch_id' => $batch->id, 'project_id' => $batch->project_id,
            'provisioning_started_at' => now()->subMinutes(10), 'phase_started_at' => now()->subMinutes(5)], $attributes));
    }

    public function test_the_open_batch_counts_running_done_and_failed_modules()
    {
        $batch = $this->batch($this->project());
        $this->module($batch, 1, 'done', ['provisioning_started_at' => now()->subMinutes(20), 'provisioning_complete_at' => now()->subMinutes(15)]);
        $this->module($batch, 2, 'done', ['provisioning_started_at' => now()->subMinutes(12), 'provisioning_complete_at' => now()->subMinutes(6)]);
        $this->module($batch, 3, 'failed');
        $this->module($batch, 4, 'write', ['progress_bytes' => 300, 'progress_total' => 900,
            'phase_started_at' => now()->subSeconds(100), 'progress_updated_at' => now()]);

        $s = BatchSummary::current();
        $this->assertTrue($s->open);
        $this->assertSame('78', $s->project->name);
        $this->assertSame(['active' => 1, 'done' => 2, 'failed' => 1], $s->counts);
        $this->assertSame(330, $s->averageSeconds);   // 5:00 and 6:00
        $this->assertSame(200, $s->leftSeconds);      // 600 bytes at 3 bytes/s
        $this->assertSame('fail', $s->state);
        $this->assertSame('1 failed · 1 running', $s->title());
    }

    public function test_states_and_titles()
    {
        $batch = $this->batch($this->project());
        $done = $this->module($batch, 1, 'done', ['provisioning_complete_at' => now()]);
        $this->assertSame(['done', '✓ 1 done'], [BatchSummary::current()->state, BatchSummary::current()->title()]);

        $running = $this->module($batch, 2, 'verify');
        $this->assertSame(['run', '1 running'], [BatchSummary::current()->state, BatchSummary::current()->title()]);

        $running->update(['phase' => 'failed']);
        $this->assertSame(['fail', '1 failed · 1 done'], [BatchSummary::current()->state, BatchSummary::current()->title()]);
    }

    public function test_a_batch_without_modules_is_idle()
    {
        $this->batch($this->project());
        $s = BatchSummary::current();
        $this->assertSame(['idle', '', null, null], [$s->state, $s->title(), $s->averageSeconds, $s->leftSeconds]);
    }

    public function test_an_idle_batch_is_not_open_any_more()
    {
        $this->batch($this->project(), ['last_event_at' => now()->subHours(NotificationBatch::IDLE_HOURS)->subMinute()]);
        $this->assertNull(NotificationBatch::open());
        $this->assertNull(BatchSummary::current());
        $this->assertFalse(BatchSummary::forDashboard()->open, 'the dashboard still shows it, as the last batch');
    }

    public function test_a_batch_of_another_project_than_the_active_one_is_not_open()
    {
        $batch = $this->batch($this->project('stage1-test', false));
        $this->project('78');
        $this->assertNull(NotificationBatch::open());
        $this->assertSame($batch->id, NotificationBatch::lastClosed()->id);
    }

    public function test_without_an_active_project_nothing_is_open()
    {
        $this->batch($this->project('78', false));
        $this->assertNull(NotificationBatch::open());
    }

    public function test_a_batch_closed_by_hand_is_not_open_and_nothing_is_created()
    {
        $batch = $this->batch($this->project());
        NotificationBatch::startNew();
        $this->assertNull(NotificationBatch::open());
        $this->assertSame($batch->id, NotificationBatch::lastClosed()->id);
        $this->assertSame(1, NotificationBatch::count());
    }

    public function test_no_batch_at_all()
    {
        $this->assertNull(BatchSummary::current());
        $this->assertNull(BatchSummary::forDashboard());
        $this->assertNull(NotificationBatch::lastClosed());
    }
}
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'BatchSummaryTest' | tail -3`
Expected: FAIL — `Class "App\Support\BatchSummary" not found`.

- [ ] **Step 2: open() и lastClosed()**

В `app/Models/NotificationBatch.php` после `startNew()`:

```php
    /* The batch the stand works on now: not closed, not idle and of the active project. Null when the next
       module starts a new one; nothing is created or closed here (current() does that for a module). */
    public static function open()
    {
        $active = Project::getActiveId();
        $batch = self::whereNull('closed_at')->latest('id')->first();
        if (!$batch || !$active || $batch->project_id != $active || $batch->last_event_at->lt(now()->subHours(self::IDLE_HOURS)))
            return null;
        return $batch;
    }

    /* The latest batch that is over: closed by hand or by a project change, or idle */
    public static function lastClosed()
    {
        $open = self::open();
        return self::when($open, function ($q) use ($open) { $q->where('id', '!=', $open->id); })->latest('id')->first();
    }
```

- [ ] **Step 3: BatchSummary**

`app/Support/BatchSummary.php`:

```php
<?php

namespace App\Support;

use App\Models\Cm;
use App\Models\NotificationBatch;

/**
 * A batch as the top bar, the browser tab and the dashboard show it: its modules by state, the average time of a
 * finished module, how long the running ones still need, and the tab's state (a failure outweighs running modules).
 */
class BatchSummary
{
    public $batch, $project, $modules, $open, $counts, $averageSeconds, $leftSeconds, $state;

    /* The open batch, or null */
    public static function current()
    {
        $batch = NotificationBatch::open();
        return $batch ? new self($batch, true) : null;
    }

    /* The open batch, else the last one that is over */
    public static function forDashboard()
    {
        if ($summary = self::current())
            return $summary;
        $batch = NotificationBatch::lastClosed();
        return $batch ? new self($batch, false) : null;
    }

    public function __construct(NotificationBatch $batch, bool $open)
    {
        $this->batch = $batch;
        $this->open = $open;
        $this->project = $batch->project;
        $this->modules = $batch->cms()->get();
        $active = $this->modules->filter(function ($cm) { return in_array($cm->phase, Cm::ACTIVE_PHASES, true); });
        $done = $this->modules->where('phase', 'done');
        $this->counts = ['active' => $active->count(), 'done' => $done->count(), 'failed' => $this->modules->where('phase', 'failed')->count()];

        $durations = $done->filter(function ($cm) { return $cm->provisioning_started_at && $cm->provisioning_complete_at; })
            ->map(function ($cm) { return $cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp(); });
        $this->averageSeconds = $durations->isEmpty() ? null : (int) round($durations->avg());
        $left = $active->map(function ($cm) { return $cm->secondsLeft(); })->filter(function ($s) { return $s !== null; });
        $this->leftSeconds = $left->isEmpty() ? null : (int) $left->max();

        $this->state = $this->counts['failed'] ? 'fail' : ($this->counts['active'] ? 'run' : ($this->counts['done'] ? 'done' : 'idle'));
    }

    /* The start of the browser tab's title; empty when there is nothing to tell */
    public function title()
    {
        $c = $this->counts;
        if ($c['failed'] && $c['active'])
            return "{$c['failed']} failed · {$c['active']} running";
        if ($c['failed'])
            return "{$c['failed']} failed · {$c['done']} done";
        if ($c['active'])
            return "{$c['active']} running";
        return $c['done'] ? "✓ {$c['done']} done" : '';
    }
}
```

Run: `tests 'BatchSummaryTest|NotificationsTest|NotificationSettingsTest' | tail -3`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
cd "$REPO" && git add app/Models/NotificationBatch.php app/Support/BatchSummary.php tests/Feature/BatchSummaryTest.php && git commit -q -m "The open batch and its summary for the top bar, the tab and the dashboard" -m "NotificationBatch::open() is the batch the stand works on (not closed, not idle, of the active project) and lastClosed() the one before it, without creating or closing anything. BatchSummary counts its modules, the average time of a finished one and the time the running ones still need, and gives the tab's state and title." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Значки состояний и скрипт вкладки

**Files:**
- Modify: `docs/brand/build_logo.py` (значки с точками), `docs/brand/README.md` (строка про значки состояний)
- Create: `docs/brand/cmprovision-favicon-run.svg`, `-fail.svg`, `-done.svg` (генерирует скрипт)
- Create: `public/favicon-run.svg`, `public/favicon-fail.svg`, `public/favicon-done.svg`, `public/favicon-run.png`, `public/favicon-fail.png`, `public/favicon-done.png`
- Create: `public/js/tab-status.js`, `tests/js/tab-status.test.mjs`
- Modify: `tests/Feature/FaviconTest.php`, `package.json`

**Interfaces:**
- Produces: файлы `/favicon-{run,fail,done}.{svg,png}` (SVG 48×48 viewBox, PNG 32×32).
- Produces (node и браузер `window.TabStatus`): `titleFor(prefix, base): string`, `iconsFor(state, version): {svg, png}`, `apply(doc, el)`, `watch(doc)`. Браузер вызывает `TabStatus.watch(document)` после `DOMContentLoaded`; элемент — первый `[data-tab-icon]`; ссылки на значки — `#cmp-icon-svg` и `#cmp-icon-png` (задача 5).

- [ ] **Step 1: Падающие тесты значков**

В `tests/Feature/FaviconTest.php` добавить:

```php
    public function test_the_tab_has_an_icon_for_each_state_of_the_batch()
    {
        foreach (['run' => '#2563eb', 'fail' => '#dc2626', 'done' => '#16a34a'] as $state => $dot) {
            $svg = file_get_contents(public_path("favicon-$state.svg"));
            $this->assertStringContainsString('stop-color="#ffd37a"', $svg, "$state: the mark's tile");
            $this->assertStringContainsString($dot, $svg, "$state: the dot");
            $this->assertSame([32, 32], array_slice(getimagesize(public_path("favicon-$state.png")), 0, 2));
        }
    }
```

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'FaviconTest' | tail -3`
Expected: FAIL — `file_get_contents(…favicon-run.svg): Failed to open stream`.

- [ ] **Step 2: Значки в генераторе**

В `docs/brand/build_logo.py` после блока `write('cmprovision-favicon.svg', …)`:

```python
# the browser tab's state (public/js/tab-status.js): the favicon cut with a dot, white-ringed, at the top right
def dot(cx, cy, r):
    p = skia.Path()
    p.addCircle(cx, cy, r)
    return p


CHECK = 'M34.6 9.4L37.6 12.4L43.1 6.4'
for state, colour in (('run', '#2563eb'), ('fail', '#dc2626'), ('done', '#16a34a')):
    badge = P(dot(39, 9, 10.5), '#fff') + P(dot(39, 9, 8.5), colour)
    if state == 'done':
        badge += P(stroked(parse(CHECK), 2.6), '#fff')
    write(f'cmprovision-favicon-{state}.svg', GRAD + P(fav_tile, 'url(#cmp-tile)') + P(fav_glyph, INK) + badge)
```

Сгенерировать, скопировать в `public/` и отрисовать PNG 32 px (venv скиллов логотипа из `$SP/logo-venv`; рендер — `render_png.py` скилла):

```bash
cd "$REPO" && "$SP/logo-venv/Scripts/python.exe" -I docs/brand/build_logo.py docs/brand public/fonts/nunito-v16-latin-700.woff2 | grep -c wrote
S="C:/Users/igors/.claude/plugins/cache/logo-design-skill/logo-design/1.4.4/skills/logo-design/scripts"
for s in run fail done; do cp docs/brand/cmprovision-favicon-$s.svg public/favicon-$s.svg; python "$S/render_png.py" public/favicon-$s.svg -o public/favicon-$s.png --size 32 | tail -1; done
git status --short docs/brand public | head
```

Expected: `wrote` 15 раз (12 прежних + 3), три SVG и три PNG; прежние файлы `docs/brand` не изменились (`git status` показывает только новые).

В `docs/brand/README.md`, раздел «In the app», после строки про `public/favicon.svg`:

```markdown
- While a batch runs, `public/js/tab-status.js` swaps the tab icon for `favicon-run`, `favicon-fail` or `favicon-done`
  (the favicon cut with a blue, red or green dot; `build_logo.py` makes them, PNGs at 32 px).
```

Run: `tests 'FaviconTest' | tail -3`
Expected: PASS.

- [ ] **Step 3: Падающие тесты скрипта вкладки**

`tests/js/tab-status.test.mjs`:

```js
// Run with: node --test tests/js/tab-status.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const T = require('../../public/js/tab-status.js');

function fakeDocument(title) {
    const links = { 'cmp-icon-svg': { href: '/favicon.svg?v=1791761600' }, 'cmp-icon-png': { href: '/favicon.ico?v=1791761600' } };
    return { title, getElementById: (id) => links[id] || null, links };
}
const element = (attrs) => ({ getAttribute: (name) => (name in attrs ? attrs[name] : null) });

test('the title starts with what the batch says, or is the page alone', () => {
    assert.equal(T.titleFor('1 failed · 3 running', 'Dashboard · cmprovision'), '1 failed · 3 running · cmprovision');
    assert.equal(T.titleFor('', 'CMs · cmprovision'), 'CMs · cmprovision');
});

test('each state has its icons, keeping the version of the page', () => {
    assert.deepEqual(T.iconsFor('fail', '1791761600'), { svg: '/favicon-fail.svg?v=1791761600', png: '/favicon-fail.png?v=1791761600' });
    assert.deepEqual(T.iconsFor('idle', '1791761600'), { svg: '/favicon.svg?v=1791761600', png: '/favicon.ico?v=1791761600' });
    assert.deepEqual(T.iconsFor('nonsense', ''), { svg: '/favicon.svg', png: '/favicon.ico' });
});

test('apply sets the title and both icon links, and returns to the page title when the batch is over', () => {
    const doc = fakeDocument('Dashboard · cmprovision');
    T.apply(doc, element({ 'data-tab-title': '1 failed · 3 running', 'data-tab-icon': 'fail' }));
    assert.equal(doc.title, '1 failed · 3 running · cmprovision');
    assert.equal(doc.links['cmp-icon-svg'].href, '/favicon-fail.svg?v=1791761600');
    assert.equal(doc.links['cmp-icon-png'].href, '/favicon-fail.png?v=1791761600');

    T.apply(doc, element({ 'data-tab-title': '', 'data-tab-icon': 'idle' }));
    assert.equal(doc.title, 'Dashboard · cmprovision');
    assert.equal(doc.links['cmp-icon-svg'].href, '/favicon.svg?v=1791761600');
});
```

`package.json`: добавить `tests/js/tab-status.test.mjs` в `test:js`.

Run: `cd "$REPO" && node --test tests/js/tab-status.test.mjs 2>&1 | tail -3`
Expected: FAIL — `Cannot find module`.

- [ ] **Step 4: tab-status.js**

`public/js/tab-status.js`:

```js
/*
 * The browser tab tells the batch's state: the title starts with "1 failed · 3 running" or "✓ 16 done" and the
 * icon gets a blue, red or green dot. BatchIndicator (top bar, polled every 10 s even in a background tab) carries
 * data-tab-title and data-tab-icon; this script copies them to the title and the icon links whenever they change.
 * Plain script (no build step): the browser gets window.TabStatus, node tests require() it.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.TabStatus = api;
        root.document.addEventListener('DOMContentLoaded', function () { api.watch(root.document); });
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var ICONS = { idle: '/favicon', run: '/favicon-run', fail: '/favicon-fail', done: '/favicon-done' };
    var base = null;   // the page's own title ("Dashboard · cmprovision"), read once

    function titleFor(prefix, pageTitle) {
        return prefix ? prefix + ' · cmprovision' : pageTitle;
    }

    function iconsFor(state, version) {
        var v = version ? '?v=' + version : '';
        var name = ICONS[state] || ICONS.idle;
        return { svg: name + '.svg' + v, png: (name === ICONS.idle ? '/favicon.ico' : name + '.png') + v };
    }

    function versionOf(doc) {
        var link = doc.getElementById('cmp-icon-svg');
        var m = link && /[?&]v=([^&]+)/.exec(link.href || '');
        return m ? m[1] : '';
    }

    function apply(doc, el) {
        if (base === null) base = doc.title;
        doc.title = titleFor(el.getAttribute('data-tab-title') || '', base);
        var icons = iconsFor(el.getAttribute('data-tab-icon') || 'idle', versionOf(doc));
        var svg = doc.getElementById('cmp-icon-svg'), png = doc.getElementById('cmp-icon-png');
        if (svg) svg.href = icons.svg;
        if (png) png.href = icons.png;
    }

    function watch(doc) {
        var el = doc.querySelector('[data-tab-icon]');
        if (!el) return;
        apply(doc, el);
        if (typeof MutationObserver !== 'undefined')
            new MutationObserver(function () { apply(doc, el); }).observe(el, { attributes: true, attributeFilter: ['data-tab-title', 'data-tab-icon'] });
    }

    return { titleFor: titleFor, iconsFor: iconsFor, apply: apply, watch: watch, reset: function () { base = null; } };
}));
```

`reset()` забывает запомненный заголовок страницы: тесты, которые вызывают `apply` с разными документами, начинают с `T.reset()`.

Run: `cd "$REPO" && node --test tests/js/tab-status.test.mjs 2>&1 | tail -3`
Expected: PASS (3 теста).

- [ ] **Step 5: Commit**

```bash
cd "$REPO" && git add docs/brand public/favicon-run.svg public/favicon-fail.svg public/favicon-done.svg public/favicon-run.png public/favicon-fail.png public/favicon-done.png public/js/tab-status.js tests/js/tab-status.test.mjs tests/Feature/FaviconTest.php package.json && git commit -q -m "Tab icons for a running, failed and finished batch, and the script that sets them" -m "build_logo.py adds a blue, red or green dot to the favicon cut; tab-status.js copies the top bar's data-tab-title and data-tab-icon into the document title and the icon links whenever Livewire changes them." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Каркас — макет, верхняя панель, Appearance, итог партии

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (без полосы заголовка; `<title>`; ссылки на значки с id; `tab-status.js`)
- Modify: `app/View/Components/AppLayout.php` (свойство `title`)
- Modify: `resources/views/navigation-menu.blade.php` (переписать)
- Modify: `resources/views/components/nav-link.blade.php`, `responsive-nav-link.blade.php`, `dropdown.blade.php`, `dropdown-link.blade.php`, `application-mark.blade.php`, `application-logo.blade.php`, `authentication-card-logo.blade.php`
- Modify: `resources/views/livewire/uplink-indicator.blade.php` (токены)
- Delete: `resources/views/components/switchable-team.blade.php`
- Create: `app/Http/Livewire/BatchIndicator.php`, `resources/views/livewire/batch-indicator.blade.php`, `tests/Feature/BatchIndicatorTest.php`
- Modify: `#[Title]` в `Cms`, `CmCard`, `Ports`, `Images`, `Projects`, `Scripts`, `Labels`, `Firmwares`, `Settings`; `dashboard.blade.php` передаёт `title="Dashboard"`; из 12 шаблонов удалить `<x-slot name="header">…</x-slot>`
- Modify: `resources/css/app.css` (классы панели), `tests/Feature/BrandingTest.php`, `tests/Feature/FaviconTest.php`, `tests/Feature/TokenGuardTest.php` (убрать из `NOT_YET` файлы этой задачи)

**Interfaces:**
- Consumes: `BatchSummary::current()` (задача 3), `window.appearancePopover()` (задача 2), `window.TabStatus` (задача 4).
- Produces: Livewire `batch-indicator` (корень с `wire:poll.10s.keep-alive`, `data-tab-title`, `data-tab-icon`); ссылки `<link id="cmp-icon-svg">`, `<link id="cmp-icon-png">`; классы `cmp-topbar`, `cmp-topbar-row`, `cmp-mark`, `cmp-nav`, `cmp-icon-btn`, `cmp-pop`, `cmp-pop-title`, `cmp-pop-row`, `cmp-pop-note`, `cmp-seg`, `cmp-swatch`, `cmp-mode`, `cmp-batch`.

- [ ] **Step 1: Падающие тесты**

`tests/Feature/BatchIndicatorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\BatchIndicator;
use App\Models\Cm;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The open batch in the top bar of every page, polled even in a background tab, and what the tab title and icon get */
class BatchIndicatorTest extends TestCase
{
    use RefreshDatabase;

    protected function openBatch()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        $batch = NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subMinutes(38), 'last_event_at' => now()]);
        foreach (['done', 'done', 'failed', 'write'] as $n => $phase)
            Cm::create(['serial' => sprintf('10000000a1f3c2%02x', $n), 'mac' => sprintf('e4:5f:01:a1:c2:%02x', $n), 'phase' => $phase,
                'notification_batch_id' => $batch->id, 'provisioning_started_at' => now()->subMinutes(5)]);
        return $batch;
    }

    public function test_the_top_bar_shows_the_open_batch_and_tells_the_tab()
    {
        $this->openBatch();
        Livewire::test(BatchIndicator::class)
            ->assertSee('78')
            ->assertSeeHtml('1<span class="hidden md:inline">&nbsp;running</span>')
            ->assertSee('✓ 2')
            ->assertSeeHtml('✕ 1<span class="hidden md:inline">&nbsp;failed</span>')
            ->assertSeeHtml('data-tab-title="1 failed · 1 running"')
            ->assertSeeHtml('data-tab-icon="fail"')
            ->assertSee(route('dashboard'), false);
    }

    public function test_without_an_open_batch_it_is_silent()
    {
        Livewire::test(BatchIndicator::class)
            ->assertSeeHtml('data-tab-title=""')
            ->assertSeeHtml('data-tab-icon="idle"')
            ->assertDontSee('running');
    }

    public function test_every_page_has_it_polling_in_the_background_with_the_appearance_button()
    {
        $html = $this->actingAs(User::factory()->create())->get('/cms')->assertOk()->getContent();
        $this->assertStringContainsString('wire:poll.10s.keep-alive', $html);
        $this->assertStringContainsString('aria-label="Appearance"', $html);
        $this->assertStringContainsString('x-data="appearancePopover()"', $html);
        $this->assertStringContainsString('<title>CMs · cmprovision</title>', $html);
        $this->assertStringContainsString('id="cmp-icon-svg"', $html);
        $this->assertStringContainsString('<script src="'.asset('js/tab-status.js'), $html);
        $this->assertMatchesRegularExpression('#>\s*Settings\s*</a>#', $html);
        $this->assertStringNotContainsString('Other settings', $html);
    }

    public function test_the_sign_in_page_has_no_appearance_button()
    {
        $this->get('/login')->assertOk()->assertDontSee('aria-label="Appearance"', false);
    }
}
```

В `tests/Feature/BrandingTest.php` заменить константы и проверки цвета знака на токены:

```php
    // the tile's gradient and the line's ink, as theme tokens (lighter tile in a dark theme)
    const TILE_TOP = 'stop-color: var(--mark-a)';
    const TILE_BOTTOM = 'stop-color: var(--mark-b)';
    const INK = 'stroke: var(--mark-ink)';
```

(в `test_the_browser_tab_and_home_screen_icons_are_the_mark` проверка файла `favicon.svg` берёт литерал `'stop-color="#ffd37a"'` вместо `self::TILE_TOP`: значок вкладки не следует теме.)

В `tests/Feature/FaviconTest.php` проверку ссылки заменить на:

```php
        $this->get('/login')->assertOk()->assertSee(
            '<link rel="icon" href="/favicon.svg?v='.filemtime(public_path('favicon.svg')).'" type="image/svg+xml" id="cmp-icon-svg">', false);
```

Убрать из `TokenGuardTest::NOT_YET`: `layouts/app.blade.php`, `layouts/guest.blade.php`, `navigation-menu.blade.php`, `components/nav-link.blade.php`, `components/responsive-nav-link.blade.php`, `components/dropdown.blade.php`, `components/dropdown-link.blade.php`, `components/switchable-team.blade.php`, `livewire/uplink-indicator.blade.php`, `dashboard.blade.php`.

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'BatchIndicatorTest|BrandingTest|FaviconTest|TokenGuardTest' | tail -6`
Expected: FAIL — `BatchIndicator` не найден, знак ещё на шестнадцатеричных цветах, сторож перечисляет цвета в 9 файлах и `switchable-team` в списке без файла не останется только после удаления.

- [ ] **Step 2: BatchIndicator**

`app/Http/Livewire/BatchIndicator.php`:

```php
<?php

namespace App\Http\Livewire;

use App\Support\BatchSummary;
use Livewire\Component;

/* The open batch in the top bar of every page; its root carries the tab's title and icon (public/js/tab-status.js).
   Polled every 10 s with keep-alive: Livewire would otherwise poll a background tab 95% less often. */
class BatchIndicator extends Component
{
    public function render()
    {
        return view('livewire.batch-indicator', ['summary' => BatchSummary::current()]);
    }
}
```

`resources/views/livewire/batch-indicator.blade.php`:

```blade
<div wire:poll.10s.keep-alive class="flex items-center" data-tab-title="{{ $summary ? $summary->title() : '' }}" data-tab-icon="{{ $summary ? $summary->state : 'idle' }}">
    @if ($summary)
        <a href="{{ route('dashboard') }}" class="cmp-batch" title="Project {{ $summary->project?->name }}, batch since {{ $summary->batch->started_at->local()->format('H:i') }}">
            <span class="text-fg-muted">{{ $summary->project?->name }}</span>
            @if ($summary->counts['active'])
                <span class="cmp-chip cmp-chip-run"><span class="dot"></span>{{ $summary->counts['active'] }}<span class="hidden md:inline">&nbsp;running</span></span>
            @endif
            <span class="cmp-chip cmp-chip-ok">✓ {{ $summary->counts['done'] }}</span>
            @if ($summary->counts['failed'])
                <span class="cmp-chip cmp-chip-fail">✕ {{ $summary->counts['failed'] }}<span class="hidden md:inline">&nbsp;failed</span></span>
            @endif
        </a>
    @endif
</div>
```


- [ ] **Step 3: Макет и заголовок документа**

`app/View/Components/AppLayout.php`:

```php
<?php

namespace App\View\Components;

use Illuminate\View\Component;

class AppLayout extends Component
{
    /** @param string|null $title the page's name in the browser tab ("Dashboard" -> "Dashboard · cmprovision") */
    public function __construct(public ?string $title = null)
    {
    }

    public function render()
    {
        return view('layouts.app');
    }
}
```

`resources/views/layouts/app.blade.php` целиком:

```blade
<!DOCTYPE html>
@php($sun = \App\Support\SunPlace::forZone(config('app.display_timezone')))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($sun) data-sun="{{ $sun['lat'] }},{{ $sun['lng'] }}" data-sun-city="{{ $sun['city'] }}" data-sun-zone="{{ $sun['zone'] }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? 'Dashboard' }} · cmprovision</title>

        <!-- Styles; appearance.js sets style, density and theme before the page paints -->
        <link rel="icon" href="/favicon.svg?v={{ @filemtime(public_path('favicon.svg')) }}" type="image/svg+xml" id="cmp-icon-svg">
        <link rel="icon" href="/favicon.ico?v={{ @filemtime(public_path('favicon.ico')) }}" sizes="32x32" id="cmp-icon-png">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ @filemtime(public_path('apple-touch-icon.png')) }}">
        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
        <script src="{{ asset('js/appearance.js') }}?v={{ @filemtime(public_path('js/appearance.js')) }}"></script>
        <script src="{{ asset('js/tab-status.js') }}?v={{ @filemtime(public_path('js/tab-status.js')) }}" defer></script>

        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen">
            @livewire('navigation-menu')

            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('modals')

        @livewireScripts
    </body>
</html>
```

`FaviconTest` (ссылка с `id`) уже ждёт этот вид; `guest.blade.php` получает те же `id` у двух ссылок на значки (вкладка входа не меняет значок, но разметка одинаковая), `<title>cmprovision</title>` и `text-fg` вместо `text-gray-900` у обёртки.

Полностраничные компоненты: добавить `use Livewire\Attributes\Title;` и атрибут перед классом — `#[Title('CMs')]` (`Cms`), `#[Title('Module')]` (`CmCard`), `#[Title('Ports')]`, `#[Title('Images')]`, `#[Title('Projects')]`, `#[Title('Scripts')]`, `#[Title('Labels')]`, `#[Title('Firmware')]` (`Firmwares`), `#[Title('Settings')]`. `dashboard.blade.php`: `<x-app-layout title="Dashboard">`.

Удалить блоки `<x-slot name="header"> … </x-slot>` из 12 шаблонов, где они есть (`grep -rl 'x-slot name="header"' resources/views`). В `dashboard.blade.php` обёртку `<div class="py-6"><div class="max-w-7xl mx-auto sm:px-6 lg:px-8">` заменить на `<div class="cmp-page">`, плашку «To get started…» — на `<div class="cmp-card px-4 py-3 text-fg-muted">…</div>` (дальше дашборд — задача 6).

- [ ] **Step 4: Верхняя панель**

`resources/views/navigation-menu.blade.php` целиком:

```blade
{{-- The top bar (layout A): the mark and the pages; the open batch, the uplink, Appearance and the account on the right --}}
@php($pages = [['dashboard', 'Dashboard'], ['cms', 'CMs'], ['ports', 'Ports'], ['images', 'Images'], ['projects', 'Projects'], ['scripts', 'Scripts'], ['labels', 'Labels'], ['firmware', 'Firmware'], ['settings', 'Settings']])
<nav x-data="{ open: false }" class="cmp-topbar">
    <div class="cmp-topbar-row">
        <a href="{{ route('dashboard') }}" class="shrink-0" aria-label="Dashboard">
            <x-application-mark class="cmp-mark" />
        </a>

        <div class="cmp-nav hidden sm:flex">
            @foreach ($pages as [$route, $label])
                <x-nav-link href="{{ route($route) }}" :active="request()->routeIs($route)">{{ $label }}</x-nav-link>
            @endforeach
        </div>

        <div class="ml-auto flex items-center gap-3">
            <livewire:batch-indicator />
            <livewire:uplink-indicator />

            <div x-data="appearancePopover()" class="relative" @keydown.escape.window="close()" @click.outside="open = false">
                <button type="button" x-ref="button" class="cmp-icon-btn" :class="open && 'is-open'" @click="open = ! open"
                        aria-haspopup="dialog" :aria-expanded="open.toString()" aria-label="Appearance" title="Appearance">
                    <svg x-show="choice.mode === 'light'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    <svg x-show="choice.mode === 'dark'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.6 6.6 0 0 0 10.7 10.7z"/></svg>
                    <svg x-show="choice.mode === 'auto'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v6M8.5 5.5 12 2l3.5 3.5M4.2 10.2l1.4 1.4M2 18h2M20 18h2M18.4 11.6l1.4-1.4M22 22H2M16 18a4 4 0 0 0-8 0"/></svg>
                </button>
                <div x-show="open" x-cloak class="cmp-pop" role="dialog" aria-label="Appearance">
                    <div class="cmp-pop-title">Style</div>
                    <div class="cmp-pop-row" role="group" aria-label="Style">
                        <button type="button" class="cmp-swatch cmp-swatch-warm" :aria-pressed="(choice.style === 'warm').toString()" @click="set({ style: 'warm' })" aria-label="Warm" title="Warm"></button>
                        <button type="button" class="cmp-swatch cmp-swatch-neutral" :aria-pressed="(choice.style === 'neutral').toString()" @click="set({ style: 'neutral' })" aria-label="Neutral" title="Neutral"></button>
                    </div>
                    <div class="cmp-pop-title">Density</div>
                    <div class="cmp-seg" role="group" aria-label="Density">
                        <button type="button" :aria-pressed="(choice.density === 'compact').toString()" @click="set({ density: 'compact' })">Compact</button>
                        <button type="button" :aria-pressed="(choice.density === 'roomy').toString()" @click="set({ density: 'roomy' })">Roomy</button>
                    </div>
                    <div class="cmp-pop-title">Theme</div>
                    <div class="cmp-pop-row" role="group" aria-label="Theme">
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'light').toString()" @click="set({ mode: 'light' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>Light</button>
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'dark').toString()" @click="set({ mode: 'dark' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.6 6.6 0 0 0 10.7 10.7z"/></svg>Dark</button>
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'auto').toString()" @click="set({ mode: 'auto' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v6M8.5 5.5 12 2l3.5 3.5M4.2 10.2l1.4 1.4M2 18h2M20 18h2M18.4 11.6l1.4-1.4M22 22H2M16 18a4 4 0 0 0-8 0"/></svg>Auto</button>
                    </div>
                    <p class="cmp-pop-note" x-text="caption()"></p>
                </div>
            </div>

            <div class="hidden sm:block">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="cmp-account">
                            {{ Auth::user()->name }}
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="block px-4 py-2 text-xs text-fg-faint">{{ __('Manage Account') }}</div>
                        <x-dropdown-link href="{{ route('profile.show') }}">{{ __('Profile') }}</x-dropdown-link>
                        @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                            <x-dropdown-link href="{{ route('api-tokens.index') }}">{{ __('API Tokens') }}</x-dropdown-link>
                        @endif
                        <div class="border-t border-pline"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <button type="button" @click="open = ! open" class="cmp-icon-btn sm:hidden" aria-label="Menu">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    {{-- The phone menu: Dashboard and the account, as since 1.6 (the user's decision in 2.0) --}}
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden border-t border-pline">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link href="{{ route('dashboard') }}" :active="request()->routeIs('dashboard')">{{ __('Dashboard') }}</x-responsive-nav-link>
        </div>
        <div class="pt-4 pb-1 border-t border-pline">
            <div class="px-4">
                <div class="font-medium text-base text-fg">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-fg-muted">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link href="{{ route('profile.show') }}" :active="request()->routeIs('profile.show')">{{ __('Profile') }}</x-responsive-nav-link>
                @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                    <x-responsive-nav-link href="{{ route('api-tokens.index') }}" :active="request()->routeIs('api-tokens.index')">{{ __('API Tokens') }}</x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
```

`git rm -q resources/views/components/switchable-team.blade.php` и убрать его из `NOT_YET`.

Компоненты (только классы; разметка и слоты прежние):
- `nav-link`: активный — `inline-flex items-center h-full border-b-2 border-accent text-fg font-semibold`, обычный — `inline-flex items-center h-full border-b-2 border-transparent text-fg-muted font-semibold hover:text-fg hover:border-pline`; без `dark:`.
- `responsive-nav-link`: активный — `block w-full ps-3 pe-4 py-2 border-l-4 border-accent text-start text-base font-medium text-fg bg-accent-soft`, обычный — `block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-fg-muted hover:text-fg hover:bg-seg`.
- `dropdown`: панель — `rounded-md bg-pop shadow-pop ring-1 ring-pline py-1` (переменная `$contentClasses` по умолчанию).
- `dropdown-link`: `block w-full px-4 py-2 text-start text-sm leading-5 text-fg hover:bg-seg focus:outline-none focus:bg-seg`.
- `application-mark`, `application-logo` и `authentication-card-logo`: `stop-color="#ffd37a"` → `style="stop-color: var(--mark-a)"`, `stop-color="#e2681c"` → `style="stop-color: var(--mark-b)"`, `stroke="#2a1a0c"` → `style="stroke: var(--mark-ink)"`; у `application-mark` класс по умолчанию — из атрибутов (`class="cmp-mark"` в панели).
- `uplink-indicator`: `$ink` → `['normal' => 'text-fg', 'warn' => 'text-warn', 'bad' => 'text-fg-faint']`, `text-gray-300` → `text-fg-faint`, `text-red-600` → `text-neg`, подсказка `bg-white … ring-black ring-opacity-5 … text-gray-700` → `bg-pop ring-1 ring-pline shadow-pop … text-fg`, `text-gray-800` → `text-fg`, `text-gray-500` → `text-fg-muted`, `text-yellow-700` → `text-warn`, бейдж `bg-white` → `bg-panel`, `hover:bg-gray-50 focus:ring-indigo-300` → `hover:bg-seg focus:ring-accent`.

В `resources/css/app.css`, в `@layer components`:

```css
    .cmp-topbar { position: sticky; top: 0; z-index: 30; background: var(--panel); border-bottom: 1px solid var(--panel-line); }
    .cmp-topbar-row { height: var(--bar-h); display: flex; align-items: center; gap: 24px; padding: 0 var(--page-x); max-width: 1920px; margin: 0 auto; }
    .cmp-mark { display: block; width: var(--mark); height: var(--mark); }
    .cmp-nav { align-self: stretch; align-items: stretch; gap: var(--nav-gap); font-size: var(--fs); min-width: 0; }
    .cmp-icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px;
        color: var(--muted); border: 1px solid var(--panel-line); background: transparent; }
    .cmp-icon-btn:hover, .cmp-icon-btn.is-open { color: var(--text); background: var(--seg); }
    .cmp-icon-btn:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
    .cmp-account { display: inline-flex; align-items: center; gap: 4px; font-size: var(--fs); font-weight: 600; color: var(--muted); }
    .cmp-account:hover { color: var(--text); }
    .cmp-batch { display: flex; align-items: center; gap: 6px; font-size: var(--fs); font-weight: 600; }
    .cmp-pop { position: absolute; right: 0; top: calc(100% + 8px); z-index: 40; width: 300px; padding: 12px; border-radius: 8px;
        background: var(--pop); box-shadow: var(--pop-shadow); border: 1px solid var(--panel-line); }
    .cmp-pop-title { font-size: 11px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); margin: 12px 0 6px; }
    .cmp-pop-title:first-child { margin-top: 0; }
    .cmp-pop-row { display: flex; gap: 6px; }
    .cmp-pop-note { margin-top: 10px; font-size: 12px; line-height: 1.4; color: var(--muted); }
    .cmp-seg { display: flex; gap: 2px; padding: 2px; border-radius: 6px; background: var(--seg); }
    .cmp-seg button { flex: 1; padding: 4px 8px; border-radius: 4px; font-size: 13px; font-weight: 600; color: var(--seg-text); }
    .cmp-seg button[aria-pressed="true"] { background: var(--seg-on); color: var(--seg-on-text); }
    .cmp-swatch { width: 40px; height: 40px; border-radius: 10px; border: 2px solid transparent; }
    .cmp-swatch[aria-pressed="true"] { border-color: var(--focus); }
    .cmp-swatch-warm { background: conic-gradient(from 200deg, #ffe3b8, #ffd37a, #e2681c, #ffe3b8); }
    .cmp-swatch-neutral { background: conic-gradient(from 200deg, #e5e7eb, #9ca3af, #4f46e5, #e5e7eb); }
    .cmp-mode { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 8px 0; border-radius: 6px;
        font-size: 11px; font-weight: 700; background: var(--seg); color: var(--seg-text); }
    .cmp-mode[aria-pressed="true"] { background: var(--seg-on); color: var(--seg-on-text); }
    .cmp-swatch:focus-visible, .cmp-mode:focus-visible, .cmp-seg button:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
```

- [ ] **Step 5: GREEN, CSS, весь набор**

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'BatchIndicatorTest|BrandingTest|FaviconTest|TokenGuardTest|AppearanceBootTest|AuthenticationTest' | tail -4`
Expected: PASS.

Собрать CSS; затем `tests_all_ok | tail -4` — phpunit exit 0; `node --test tests/js/upload-progress.test.mjs tests/js/tokens.test.mjs tests/js/appearance.test.mjs tests/js/tab-status.test.mjs 2>&1 | tail -3` — `# fail 0`.

- [ ] **Step 6: Commit**

```bash
cd "$REPO" && git add -A app resources public/css/app.css tests && git commit -q -m "Layout A: the top bar with the open batch and the Appearance popover" -m "No header bar; the top bar (48 px, 64 px roomy) holds the mark, the pages (Other settings is now Settings), the batch (BatchIndicator, polled every 10 s with keep-alive, carrying the tab's title and icon), the uplink, the Appearance popover (style, density, theme) and the account. The document title is the page's name; logos and the nav components use theme tokens." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Дашборд — сводка партии, таблица модулей, свёрнутый журнал

**Files:**
- Modify: `app/Http/Livewire/ProvisioningStatus.php`, `resources/views/livewire/provisioning-status.blade.php`
- Modify: `resources/views/livewire/provisioning-log.blade.php`
- Modify: `tests/Feature/ProvisioningStatusTest.php`, `tests/Feature/ProvisioningLogTest.php`, `tests/Feature/TokenGuardTest.php`

**Interfaces:**
- Consumes: `BatchSummary::forDashboard()`, `NotificationBatch::startNew()`; формат прежних строк (`ProvisioningStatus::bytes()`, `speed()`, `duration()`).
- Produces: действие `newBatch()`; таблица с прежними текстами «Writing image», «N%», «X of Y», «MB/s», «left», «No report for N min», «took», «failed after», «since», кнопки Identify/Stop.

- [ ] **Step 1: Падающие тесты**

В `tests/Feature/ProvisioningStatusTest.php` добавить импорты `App\Models\Image`, `App\Models\NotificationBatch`, `App\Models\Project`, `App\Models\Setting` и тесты:

```php
    protected function openBatch()
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        $project = Project::create(['name' => '78', 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
        Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
        return NotificationBatch::create(['project_id' => $project->id, 'started_at' => now()->subHours(4), 'last_event_at' => now()->subMinute()]);
    }

    public function test_the_summary_counts_the_open_batch()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c01', ['phase' => 'done', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()->subHours(3),
                                           'provisioning_started_at' => now()->subHours(3)->subMinutes(5), 'provisioning_complete_at' => now()->subHours(3)]);
        $this->module('1000000000000c02', ['phase' => 'failed', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()]);
        $this->module('1000000000000c03', ['phase' => 'write', 'notification_batch_id' => $batch->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        Livewire::test(ProvisioningStatus::class)
            ->assertSeeHtml('<div class="num text-info">1</div>')
            ->assertSeeHtml('<div class="num text-pos">1</div>')
            ->assertSeeHtml('<div class="num text-neg">1</div>')
            ->assertSee('Project 78 · batch since')
            ->assertSee('3 modules · 5:00 per module')
            ->assertSee('1000000000000c01');   // a module of the batch stays, however long ago it finished
    }

    public function test_new_batch_closes_the_open_one()
    {
        $batch = $this->openBatch();
        Livewire::test(ProvisioningStatus::class)->assertSeeHtml('wire:confirm=')->call('newBatch');
        $this->assertNotNull($batch->fresh()->closed_at);
    }

    public function test_after_the_batch_the_last_one_is_shown_as_such()
    {
        $batch = $this->openBatch();
        $this->module('1000000000000c04', ['phase' => 'done', 'notification_batch_id' => $batch->id, 'phase_started_at' => now()->subHours(5),
                                           'provisioning_complete_at' => now()->subHours(5)]);
        NotificationBatch::startNew();

        Livewire::test(ProvisioningStatus::class)
            ->assertSee('Last batch, closed at')
            ->assertSee('1000000000000c04');
    }

    public function test_a_module_running_in_another_batch_is_listed_too()
    {
        $batch = $this->openBatch();
        // closed by New batch while this module still ran
        $other = NotificationBatch::create(['project_id' => $batch->project_id, 'started_at' => now()->subHour(), 'last_event_at' => now(), 'closed_at' => now()]);
        $this->module('1000000000000c05', ['phase' => 'verify', 'notification_batch_id' => $other->id, 'phase_started_at' => now(), 'progress_updated_at' => now()]);

        Livewire::test(ProvisioningStatus::class)->assertSee('1000000000000c05');
    }
```

В `tests/Feature/ProvisioningLogTest.php` добавить:

```php
    public function test_the_log_is_folded_to_its_latest_entry_and_opens_on_request()
    {
        $this->entry('Provisioning started. Starting preinstall scripts.', '2026-10-09 06:00:00');
        $this->entry('Provisioning completed. Verification successful.', '2026-10-09 06:05:00');

        Livewire::test(ProvisioningLog::class)
            ->assertSeeHtml('aria-controls="cmp-log-entries"')
            ->assertSeeHtml('x-show="open"')
            ->assertSeeInOrder(['Provisioning completed. Verification successful.', 'Provisioning started.']);
    }
```

(имена `entry()` и время — как в существующем тесте этого файла; если сигнатура другая — использовать её).

Убрать из `NOT_YET`: `livewire/provisioning-status.blade.php`, `livewire/provisioning-log.blade.php`.

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'ProvisioningStatusTest|ProvisioningLogTest|TokenGuardTest' | tail -6`
Expected: FAIL — нет сводки, `newBatch` не существует, журнал не свёрнут, сторож видит цвета в двух шаблонах.

- [ ] **Step 2: ProvisioningStatus**

В `app/Http/Livewire/ProvisioningStatus.php` заменить комментарий класса, константы и `render()`, добавить `newBatch()`:

```php
use App\Models\NotificationBatch;
use App\Support\BatchSummary;

/* Dashboard panel: the batch on the stand (its totals and modules), modules running outside it, and modules
   from before batches existed that finished recently */
class ProvisioningStatus extends Component
{
    const RECENT_HOURS = 2;      // modules outside any batch stay visible this long after they finish
    const ACTIVE_HOURS = 12;     // a module still "in progress" after this long was unplugged
    const MAX_ROWS = 50;

    // identify() and stopIdentify() stay as they are

    /* Close the batch: the next module starts a new one, here and in the bots' threads */
    public function newBatch()
    {
        NotificationBatch::startNew();
    }

    public function render()
    {
        $summary = BatchSummary::forDashboard();
        $ids = $summary ? $summary->modules->pluck('id')->all() : [];
        $recent = now()->subHours(self::RECENT_HOURS);
        $modules = Cm::whereNotNull('phase')
            ->where(function ($q) use ($ids, $recent) {
                $q->whereIn('id', $ids)
                  ->orWhere(function ($q) {
                      $q->whereIn('phase', Cm::ACTIVE_PHASES)->where('provisioning_started_at', '>=', now()->subHours(self::ACTIVE_HOURS));
                  })
                  ->orWhere(function ($q) use ($recent) {
                      $q->whereNull('notification_batch_id')->whereIn('phase', ['done', 'failed'])->where('phase_started_at', '>=', $recent);
                  });
            })
            ->orderByDesc('provisioning_started_at')
            ->limit(self::MAX_ROWS)
            ->get()
            // port (or jumper board) order: every row stays where its module is plugged in
            ->sort(function ($a, $b) {
                return strnatcasecmp((string) $a->provisioning_board, (string) $b->provisioning_board)
                    ?: strcmp($a->serial, $b->serial);
            })
            ->values();

        return view('livewire.provisioning-status', ['summary' => $summary, 'modules' => $modules,
            'activeCount' => $modules->filter->isActive()->count()]);
    }
```

- [ ] **Step 3: Шаблон сводки и таблицы**

`resources/views/livewire/provisioning-status.blade.php` целиком:

```blade
@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<div @if ($activeCount) wire:poll.2s @else wire:poll.10s @endif class="flex flex-col gap-[var(--gap)]">
    @if ($summary)
        <div class="grid gap-[var(--gap)] grid-cols-3 lg:grid-cols-[repeat(3,minmax(0,160px))_minmax(0,1fr)]">
            <div class="cmp-tile"><div class="num text-info">{{ $summary->counts['active'] }}</div><div class="label">running</div></div>
            <div class="cmp-tile"><div class="num text-pos">{{ $summary->counts['done'] }}</div><div class="label">done</div></div>
            <div class="cmp-tile {{ $summary->counts['failed'] ? 'bg-neg-bg' : '' }}"><div class="num text-neg">{{ $summary->counts['failed'] }}</div><div class="label">failed</div></div>
            <div class="cmp-tile col-span-3 lg:col-span-1 flex items-center gap-4">
                <div class="min-w-0">
                    <div class="font-bold">
                        @if ($summary->open)
                            Project {{ $summary->project?->name }} · batch since {{ $summary->batch->started_at->local()->format('H:i') }}
                        @else
                            Last batch, closed at {{ ($summary->batch->closed_at ?? $summary->batch->last_event_at)->local()->format('H:i') }} · project {{ $summary->project?->name }}
                        @endif
                    </div>
                    <div class="text-fg-muted">
                        {{ $summary->modules->count() }} {{ $summary->modules->count() === 1 ? 'module' : 'modules' }}
                        @if ($summary->averageSeconds) · {{ $fmt::duration($summary->averageSeconds) }} per module @endif
                        @if ($summary->open && $summary->leftSeconds !== null) · the running ones finish in {{ $summary->leftSeconds < 60 ? 'under a minute' : 'about '.ceil($summary->leftSeconds / 60).' min' }} @endif
                    </div>
                </div>
                @if ($summary->open)
                    <button type="button" wire:click="newBatch" wire:confirm="Close this batch? The next module starts a new one, here and in the bots." class="cmp-btn cmp-btn-secondary ml-auto">New batch</button>
                @endif
            </div>
        </div>
    @endif

    <div class="cmp-card overflow-clip">
        @if ($modules->isEmpty())
            <div class="px-4 py-3 text-fg-muted">No modules are being provisioned.</div>
        @else
            <table class="cmp-table">
                <thead>
                    <tr><th>Board</th><th>Serial</th><th>MAC</th><th>Step</th><th>Progress</th><th>Time</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($modules as $m)
                    @php($percent = $m->progressPercent())
                    @php($silent = $m->silentFor())
                    @php($speed = $m->bytesPerSecond())
                    @php($left = $m->secondsLeft())
                    <tr wire:key="cm-{{ $m->id }}" class="{{ $m->phase === 'failed' ? 'bg-neg-bg' : '' }}">
                        <td class="text-fg-muted whitespace-nowrap">{{ $m->provisioning_board }}</td>
                        <td class="mono"><a href="{{ route('cm', $m->serial) }}" class="text-link hover:underline">{{ $m->serial }}</a></td>
                        <td class="mono text-fg-muted">{{ $m->mac }}</td>
                        <td class="whitespace-nowrap">
                            @if ($m->phase === 'done')
                                <span class="text-pos font-semibold">Done</span>
                            @elseif ($m->phase === 'failed')
                                <span class="text-neg font-semibold">Failed</span>@if ($m->phase_detail)<span class="text-neg">: {{ $m->phase_detail }}</span>@endif
                            @else
                                {{ $m->phaseLabel() }}
                            @endif
                            @if ($silent)
                                <span class="cmp-chip cmp-chip-stale ml-1">No report for {{ intdiv($silent, 60) }} min</span>
                            @endif
                        </td>
                        <td>
                            @if ($percent !== null)
                                <div class="flex items-center gap-2">
                                    <div class="cmp-bar"><span class="{{ $m->phase === 'failed' ? 'bg-neg-bar' : ($m->phase === 'done' ? 'bg-pos-bar' : ($silent ? 'bg-warn-bar' : 'bg-info-bar')) }}" style="width: {{ $percent }}%"></span></div>
                                    <span class="tabular-nums whitespace-nowrap">{{ $percent }}%
                                        @if ($m->progress_total) <span class="text-fg-muted">· {{ $fmt::bytes($m->progress_bytes ?? 0) }} of {{ $fmt::bytes($m->progress_total) }}</span> @endif
                                        @if ($speed && $m->isStreaming() && !$silent) <span class="text-fg-muted">· {{ $fmt::speed($speed) }}</span> @endif
                                        @if ($left !== null && $m->isStreaming() && !$silent) <span class="text-fg-muted">· {{ $fmt::duration($left) }} left</span> @endif
                                    </span>
                                </div>
                            @elseif ($m->isStreaming() && $m->progress_bytes)
                                <span class="text-fg-muted">{{ $fmt::bytes($m->progress_bytes) }} @if ($speed) · {{ $fmt::speed($speed) }} @endif</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap tabular-nums">
                            @if ($m->provisioning_started_at)
                                @if ($m->phase === 'done' && $m->provisioning_complete_at)
                                    took {{ $fmt::duration($m->provisioning_complete_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $m->provisioning_complete_at->local()->format('H:i') }}</span>
                                @elseif ($m->phase === 'failed' && $m->phase_started_at)
                                    failed after {{ $fmt::duration($m->phase_started_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $m->phase_started_at->local()->format('H:i') }}</span>
                                @else
                                    {{ $fmt::duration(now()->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">since {{ $m->provisioning_started_at->local()->format('H:i') }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($m->canIdentify())
                                @if ($m->isIdentifying())
                                    <span class="text-xs font-semibold text-info mr-1">blinking</span>
                                    <button type="button" wire:click="stopIdentify({{ $m->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Stop</button>
                                @else
                                    <button type="button" wire:click="identify({{ $m->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Identify</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
```

- [ ] **Step 4: Журнал**

`resources/views/livewire/provisioning-log.blade.php` целиком:

```blade
{{-- Folded to its latest entry; open or folded is Alpine's (and the browser's) state, so polling keeps it --}}
<div wire:poll.5s class="rounded-lg overflow-hidden bg-log text-log-text"
     x-data="{ open: (() => { try { return localStorage.getItem('cmprovision-log-open') === '1' } catch (e) { return false } })() }"
     x-init="$watch('open', v => { try { localStorage.setItem('cmprovision-log-open', v ? '1' : '0') } catch (e) {} })">
    <button type="button" class="w-full flex items-center gap-3 px-[var(--edge)] h-10 text-left" @click="open = ! open"
            :aria-expanded="open.toString()" aria-controls="cmp-log-entries">
        <span class="font-bold">Log</span>
        <span class="text-log-muted whitespace-nowrap">Last {{ \App\Http\Livewire\ProvisioningLog::ROWS }} entries · {{ $zone }}</span>
        @if ($first = $log->first())
            <span class="text-log-muted truncate min-w-0">{{ $first->created_at->local()->format('H:i:s') }} · {{ $first->board }} · {{ \Illuminate\Support\Str::limit($first->msg, 120) }}</span>
        @endif
        <span class="ml-auto font-semibold whitespace-nowrap" x-text="open ? 'Hide' : 'Show'">Show</span>
    </button>
    <div id="cmp-log-entries" x-show="open" x-cloak class="overflow-y-auto border-t border-log-line" style="max-height: var(--log-h)">
        <table class="cmp-table in-scroll">
            <tbody>
                @forelse ($log as $l)
                    @php($at = $l->created_at->local())
                    <tr wire:key="log-{{ $l->id }}" class="{{ $l->loglevel == 'error' ? 'text-log-err' : '' }}">
                        <td class="tabular-nums text-log-muted whitespace-nowrap border-log-line">{{ $at->toDateString() === $today ? $at->format('H:i:s') : $at->format('d.m H:i:s') }}</td>
                        <td class="text-log-muted whitespace-nowrap border-log-line">{{ $l->board }}</td>
                        <td class="mono border-log-line">@if ($l->cm)<a href="{{ route('cm', $l->cm) }}" class="hover:underline">{{ $l->cm }}</a>@endif</td>
                        <td class="border-log-line">{!! nl2br(e($l->msg), false) !!}</td>
                    </tr>
                @empty
                    <tr><td class="border-log-line" colspan="4">No entries</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

Существующий `test_today_shows_the_time_and_older_entries_the_date_too` проверяет форматы «H:i:s» и «d.m H:i:s» — они в отдельной ячейке, без сообщения: если тест ищет «время + пробел + сообщение» одной строкой, переписать проверку на `assertSeeInOrder([время, сообщение])`.

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'ProvisioningStatusTest|ProvisioningLogTest|TokenGuardTest|LocalTimeDisplayTest|ModuleIdentifyTest' | tail -4`
Expected: PASS.

- [ ] **Step 5: CSS и commit**

Собрать CSS; `tests_all_ok | tail -3` — exit 0.

```bash
cd "$REPO" && git add app/Http/Livewire/ProvisioningStatus.php resources/views/livewire/provisioning-status.blade.php resources/views/livewire/provisioning-log.blade.php public/css/app.css tests && git commit -q -m "Dashboard: the batch's totals, its modules in one dense table, the log folded" -m "Tiles for running, done and failed modules of the open batch (or the last one, marked as such) with its average time and when the running ones finish, and a New batch button that asks first. The table lists the batch, modules running in another batch and recent ones from before batches; the log folds to its latest entry and remembers whether it is open." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: CMs — поиск, колонки, новые сверху

**Files:**
- Modify: `app/Http/Livewire/Cms.php`, `resources/views/livewire/cms.blade.php`
- Create: `tests/Feature/CmsPageTest.php`
- Modify: `tests/Feature/TokenGuardTest.php` (убрать `livewire/cms.blade.php`)

**Interfaces:**
- Produces: свойства `projectId`, `search`; `protected cms(): Collection`; действия `delete($id)`, `exportCSV()` (прежние).

- [ ] **Step 1: Падающие тесты**

`tests/Feature/CmsPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Livewire\Cms;
use App\Models\Cm;
use App\Models\Image;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The list of CMs: newest first, searchable by serial or MAC, with the project, the board and the last result */
class CmsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function project($name)
    {
        $image = new Image;
        $image->filename = 'wlanpi-os.img.gz';
        $image->filename_extension = 'gz';
        $image->filename_on_server = 'img.gz';
        $image->sha256 = str_repeat('a', 64);
        $image->uncompressed_size = 8 * 1024 * 1024;
        $image->save();
        return Project::create(['name' => $name, 'device' => 'cm4', 'storage' => '/dev/mmcblk0', 'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
    }

    protected function cms()
    {
        $p78 = $this->project('78');
        $test = $this->project('stage1-test');
        Cm::create(['serial' => '10000000aaaa0001', 'mac' => 'e4:5f:01:aa:00:01', 'model' => 'CM4', 'project_id' => $p78->id,
                    'provisioning_board' => 'GE0/0/1', 'phase' => 'done', 'provisioning_complete_at' => now()]);
        Cm::create(['serial' => '10000000aaaa0002', 'mac' => 'e4:5f:01:aa:00:02', 'model' => 'CM4', 'project_id' => $p78->id,
                    'provisioning_board' => 'GE0/0/2', 'phase' => 'failed']);
        Cm::create(['serial' => '10000000bbbb0003', 'mac' => 'E4:5F:01:BB:00:03', 'model' => 'CM4', 'project_id' => $test->id, 'phase' => 'write']);
        return [$p78, $test];
    }

    public function test_newest_first_with_project_board_and_result()
    {
        $this->cms();
        Livewire::test(Cms::class)
            ->set('projectId', 0)
            ->assertSeeInOrder(['10000000bbbb0003', '10000000aaaa0002', '10000000aaaa0001'])
            ->assertSee('stage1-test')
            ->assertSee('GE0/0/2')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-fail">failed</span>')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-run">running</span>')
            ->assertSeeHtml('<span class="cmp-chip cmp-chip-ok">done</span>');
    }

    public function test_search_finds_a_part_of_the_serial_or_the_mac_in_any_case()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->set('search', 'bb:00')
            ->assertSee('10000000bbbb0003')->assertDontSee('10000000aaaa0001');
        Livewire::test(Cms::class)->set('projectId', 0)->set('search', 'AAAA0002')
            ->assertSee('10000000aaaa0002')->assertDontSee('10000000bbbb0003');
    }

    public function test_the_project_filter_still_works()
    {
        [$p78] = $this->cms();
        Livewire::test(Cms::class)->set('projectId', $p78->id)
            ->assertSee('10000000aaaa0001')->assertDontSee('10000000bbbb0003');
    }

    public function test_delete_asks_first()
    {
        $this->cms();
        Livewire::test(Cms::class)->set('projectId', 0)->assertSeeHtml('wire:confirm="Delete CM 10000000aaaa0001?"');
    }
}
```

Убрать `livewire/cms.blade.php` из `NOT_YET`.

Run: `cd "$REPO" && sync_dev >/dev/null && tests 'CmsPageTest|TokenGuardTest' | tail -4`
Expected: FAIL — порядок старый, нет поиска и чипов, сторож видит цвета.

- [ ] **Step 2: Cms**

`app/Http/Livewire/Cms.php` целиком:

```php
<?php

namespace App\Http\Livewire;

use App\Models\Cm;
use App\Models\Project;
use Livewire\Attributes\Title;
use Livewire\Component;

/* The list of CMs: newest first, by project, searchable by a part of the serial or the MAC */
#[Title('CMs')]
class Cms extends Component
{
    public $projectId = -1;
    public $search = '';

    protected function cms()
    {
        $query = Cm::with('project')->orderByDesc('id');
        if ($this->projectId)
            $query->where('project_id', $this->projectId);
        $term = strtolower(trim((string) $this->search));
        if ($term !== '')
            $query->where(function ($q) use ($term) {
                $q->whereRaw('lower(serial) like ?', ['%'.$term.'%'])->orWhereRaw('lower(mac) like ?', ['%'.$term.'%']);
            });
        return $query->get();
    }

    public function render()
    {
        if ($this->projectId == -1)
            $this->projectId = Project::getActiveId();

        return view('livewire.cms', [
            'CMs' => $this->cms(),
            'total' => Cm::count(),
            'projects' => Project::withCount('cms')->orderBy('name')->get(),
        ]);
    }

    public function delete($id)
    {
        Cm::destroy($id);
        session()->flash('message', 'Cm deleted.');
    }

    public function exportCSV()
    {
        $cms = $this->cms();
        return response()->streamDownload(function () use ($cms) {
            $fd = fopen('php://output', 'w');
            if (count($cms)) {
                fputcsv($fd, array_keys($cms[0]->getAttributes()));
                foreach ($cms as $cm)
                    fputcsv($fd, $cm->getAttributes());
            }
            fclose($fd);
        }, 'export-cm-'.date('Ymd').'.csv');
    }
}
```

(Атрибут `#[Title('CMs')]` задача 5 уже поставила — здесь файл целиком.)

- [ ] **Step 3: Шаблон**

`resources/views/livewire/cms.blade.php` целиком:

```blade
<div class="cmp-page">
    <div class="cmp-title">
        <h1>CMs</h1>
        <span class="text-fg-muted">{{ number_format($total) }} {{ $total === 1 ? 'module' : 'modules' }}</span>
        <select wire:model.live="projectId" class="cmp-field pr-8" aria-label="Project">
            <option value="0">All projects</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}">{{ $project->name }} ({{ $project->cms_count }})</option>
            @endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" class="cmp-field w-60" placeholder="Serial or MAC" aria-label="Search by serial or MAC">
        <button type="button" wire:click="exportCSV()" class="cmp-btn cmp-btn-secondary ml-auto">Export CSV</button>
    </div>

    @if (session()->has('message'))
        <div class="cmp-flash" role="status">{{ session('message') }}</div>
    @endif

    <div class="cmp-card overflow-clip">
        <table class="cmp-table">
            <thead>
                <tr><th>Serial</th><th>MAC</th><th>Model</th><th>Project</th><th>Board</th><th>Result</th><th>Finished</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($CMs as $c)
                    <tr wire:key="cm-{{ $c->id }}">
                        <td class="mono"><a href="{{ route('cm', $c->serial) }}" class="text-link hover:underline">{{ $c->serial }}</a></td>
                        <td class="mono text-fg-muted">{{ $c->mac }}</td>
                        <td>{{ $c->model }}</td>
                        <td>{{ $c->project?->name }}</td>
                        <td class="text-fg-muted whitespace-nowrap">{{ $c->provisioning_board }}</td>
                        <td>
                            @if ($c->phase === 'done')<span class="cmp-chip cmp-chip-ok">done</span>
                            @elseif ($c->phase === 'failed')<span class="cmp-chip cmp-chip-fail">failed</span>
                            @elseif ($c->isActive())<span class="cmp-chip cmp-chip-run">running</span>
                            @else <span class="text-fg-faint">—</span>
                            @endif
                        </td>
                        <td class="tabular-nums text-fg-muted whitespace-nowrap">{{ $c->provisioning_complete_at ? $c->provisioning_complete_at->local()->format('Y-m-d H:i') : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('cm', $c->serial) }}" class="text-link font-semibold mr-3">View</a>
                            <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Delete CM {{ $c->serial }}?" class="text-fg-faint hover:text-neg" title="Delete" aria-label="Delete CM {{ $c->serial }}">
                                <svg class="inline w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M8 2h4a1 1 0 011 1v1h4v2H3V4h4V3a1 1 0 011-1zm-3 6h10l-.8 9.2A2 2 0 0112.2 19H7.8a2 2 0 01-2-1.8L5 8z"/></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-fg-muted">No entries</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
```

Run: `tests 'CmsPageTest|TokenGuardTest|LocalTimeDisplayTest' | tail -3`
Expected: PASS.

- [ ] **Step 4: CSS и commit**

Собрать CSS; `tests_all_ok | tail -3` — exit 0.

```bash
cd "$REPO" && git add app/Http/Livewire/Cms.php resources/views/livewire/cms.blade.php public/css/app.css tests && git commit -q -m "CMs: newest first, search by serial or MAC, project, board and result" -m "The list keeps its project filter and CSV export; delete is an icon that asks first." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Jetstream — компоненты, вход, профиль, API-токены

**Files (все — из `NOT_YET`):** `components/action-message`, `action-section`, `authentication-card`, `banner`, `button`, `checkbox`, `confirmation-modal`, `danger-button`, `dialog-modal`, `form-section`, `input-error`, `input`, `label`, `modal`, `secondary-button`, `section-border`, `section-title`, `validation-errors`; `auth/confirm-password`, `auth/login`, `auth/two-factor-challenge`; `profile/show`, `profile/logout-other-browser-sessions-form`, `profile/two-factor-authentication-form`, `profile/update-profile-information-form`; `api/index`, `api/api-token-manager`.

**Interfaces:**
- Consumes: классы `.cmp-*` и цвета-токены (задача 1).
- Produces: компоненты Jetstream с прежними слотами и атрибутами; разметка `x-button` → `cmp-btn`, `x-secondary-button` → `cmp-btn cmp-btn-secondary`, `x-danger-button` → `cmp-btn cmp-btn-danger`, `x-input` → `cmp-field`.

- [ ] **Step 1: RED.** Убрать перечисленные 27 файлов из `NOT_YET`. Run: `tests 'TokenGuardTest' | tail -3` — FAIL, 27 файлов с цветами.
- [ ] **Step 2: Перевод** по таблице правил. Особые места:
  - `button`, `secondary-button`, `danger-button`: весь список классов `$attributes->merge(['class' => …])` заменить на `cmp-btn` / `cmp-btn cmp-btn-secondary` / `cmp-btn cmp-btn-danger`, `type` прежний; `uppercase tracking-widest text-xs` не сохранять (кнопки приложения — обычный регистр).
  - `input`: `class` → `cmp-field w-full`; `checkbox`: `rounded border-pline text-accent focus:ring-accent bg-field`.
  - `modal`: подложка `bg-gray-500 opacity-75` → `style="background: var(--scrim)"` нельзя (цвет в `style` — только переменная, сторож пропускает: в нём нет `#`), окно `bg-white` → `bg-pop text-fg`; `dialog-modal` и `confirmation-modal`: подвал `bg-gray-100` → `bg-head`, текст `text-gray-600` → `text-fg-muted`, значок предупреждения `bg-red-100 text-red-600` → `bg-neg-bg text-neg`.
  - `banner`: `bg-indigo-500`/`bg-red-700` → `bg-info-bar`/`bg-neg-bar`, текст `text-white` → `text-[color:var(--on-btn)]`.
  - `authentication-card`: `bg-gray-100` → `bg-page`, карточка `bg-white shadow-md` → `bg-panel border border-pline`.
  - `form-section`/`action-section`: `bg-white shadow` → `bg-panel border border-pline`, `bg-gray-50` в подвале → `bg-head`.
  - профиль и API: ссылки и сообщения по таблице; `text-green-…` в «Saved.» → `text-pos`.
- [ ] **Step 3: GREEN.** Run: `tests 'TokenGuardTest|AuthenticationTest|TwoFactor|PasswordConfirmationTest|ProfileInformationTest|UpdatePasswordTest|BrowserSessionsTest|ApiToken|CreateApiTokenTest|DeleteApiTokenTest|BrandingTest|SignedOutSessionTest' | tail -3` — PASS. Собрать CSS; `tests_all_ok | tail -3` — exit 0.
- [ ] **Step 4: Commit** — `git add -A resources/views public/css/app.css tests && git commit -q -m "Jetstream on theme tokens: buttons, fields, modals, sign-in, profile, API tokens" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

---

### Task 9: Карточка модуля и страница Ports

**Files (из `NOT_YET`):** `livewire/cm-card.blade.php`, `livewire/ports.blade.php`, `livewire/viewlog.blade.php`.

- [ ] **Step 1: RED.** Убрать три файла из `NOT_YET`; `tests 'TokenGuardTest' | tail -3` — FAIL.
- [ ] **Step 2: Перевод** по таблице правил. Особые места:
  - `cm-card`: цвета в `style` (совет по сбою `#b91c1c`/`#b45309`, шаги) → `text-neg`/`text-warn`; шкала шагов и их длительности — `cmp-table`; блок EEPROM «до и после» — `cmp-card`; кнопки Identify/Stop — `cmp-btn cmp-btn-secondary cmp-btn-small`.
  - `ports`: обёртки и подробности порта — `cmp-page`, `cmp-card`, токены; совет в подробностях (`color: {{ … ? '#b91c1c' : '#b45309' }}`) → `class="{{ … ? 'text-neg' : 'text-warn' }}"`; корпус и плитки (`ports-*`) и чипы сводки (`ports-chip` с цветами состояний) не трогать; тексты, на которые смотрит `PortsPageTest` (`<span class="label">1 done</span>`, `<span class="short">UP</span>`, `x-show="filter.includes('run')" style="display: none"`), сохраняются.
  - `viewlog` (окно журнала модуля): моноширинный текст на `bg-log text-log-text`, рамка `border-log-line`.
- [ ] **Step 3: GREEN.** Run: `tests 'TokenGuardTest|ModuleCardTest|ModuleIdentifyTest|PortsPageTest|EepromTransparencyTest|ImageWriteDiagnosticsTest' | tail -3` — PASS. CSS; `tests_all_ok | tail -3` — exit 0.
- [ ] **Step 4: Commit** — `git add -A resources/views public/css/app.css tests && git commit -q -m "Module card and Ports on theme tokens" -m "The switch's chassis and port tiles keep their colours in every theme." -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

---

### Task 10: Images, Projects, Scripts, Labels, Firmware

**Files (из `NOT_YET`):** `livewire/images.blade.php`, `livewire/addimage.blade.php`, `livewire/projects.blade.php`, `livewire/editproject.blade.php`, `livewire/scripts.blade.php`, `livewire/editscript.blade.php`, `livewire/labels.blade.php`, `livewire/editlabel.blade.php`, `livewire/firmware.blade.php`.

- [ ] **Step 1: RED.** Убрать девять файлов из `NOT_YET`; `tests 'TokenGuardTest' | tail -3` — FAIL.
- [ ] **Step 2: Перевод** по таблице правил. Особые места:
  - каждая страница: строка `cmp-title` с заголовком (Images, Projects, Scripts, Labels, Firmware) и главными кнопками справа («Add image», «Add project»…), список — `cmp-card` + `cmp-table`;
  - `images`/`addimage`: полоса загрузки (`imageUploader()`) — `cmp-bar` с `bg-info-bar`, текст скорости `text-fg-muted`; логика Alpine и `wire:poll` прежние (`ImageUploadTest`, `ImagesPageRobustnessTest`);
  - активный проект (бейдж) — `cmp-chip cmp-chip-ok`; кнопки «Activate», «Edit», «Delete» — `cmp-btn-secondary`/`cmp-btn-danger` с `cmp-btn-small`;
  - окна правки (`editproject`, `editscript`, `editlabel`, `addimage`) — `x-dialog-modal` уже на токенах (задача 8), поля — `cmp-field`; текстовые области скриптов — `cmp-field font-mono`;
  - `firmware`: каналы и файлы — `cmp-table`, кнопка обновления — `cmp-btn`.
- [ ] **Step 3: GREEN.** Run: `tests 'TokenGuardTest|ImageUploadTest|ImagesPageRobustnessTest|ProjectActivationTest|ProjectsFirmwareTest|FirmwarePagesTest|FirmwareStoreTest|ScriptsApiTest|LabelPrintingTest' | tail -3` — PASS. CSS; `tests_all_ok | tail -3` — exit 0; `npm`-тесты JS: `node --test tests/js/upload-progress.test.mjs` — PASS.
- [ ] **Step 4: Commit** — `git add -A resources/views public/css/app.css tests && git commit -q -m "Images, projects, scripts, labels and firmware on theme tokens" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

---

### Task 11: Settings

**Files (из `NOT_YET`):** `livewire/settings.blade.php`, `livewire/switch-settings.blade.php`, `livewire/switch-snmp-hints.blade.php`, `livewire/notification-settings.blade.php`, `livewire/addstaticip.blade.php`.

- [ ] **Step 1: RED.** Убрать пять файлов из `NOT_YET`; `tests 'TokenGuardTest' | tail -3` — FAIL.
- [ ] **Step 2: Перевод** по таблице правил. Особые места: разделы страницы (сеть, коммутатор, уведомления, статические адреса, журналы служб) — `cmp-card` с заголовком раздела `font-bold`; журналы `journalctl` — `bg-log text-log-text font-mono`; состояния каналов уведомлений («last error») — `text-neg`; подсказки SNMP — `bg-info-bg text-info`; кнопки Test/Save — `cmp-btn`/`cmp-btn-secondary`.
- [ ] **Step 3: GREEN.** Run: `tests 'TokenGuardTest|SwitchSettingsTest|NotificationSettingsTest|StaticIpSettingsTest|NotificationsTest|UplinkIndicatorTest' | tail -3` — PASS. CSS; `tests_all_ok | tail -3` — exit 0.
- [ ] **Step 4: Commit** — `git add -A resources/views public/css/app.css tests && git commit -q -m "Settings on theme tokens" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"`.

---

### Task 12: Сторож строгий, документы, стенд

**Files:**
- Modify: `tests/Feature/TokenGuardTest.php` (убрать `NOT_YET` и ветку для него), `tailwind.config.js` (без `darkMode` и подмен палитры)
- Modify: `CLAUDE.md`, `ROADMAP.md`, `debian/changelog` (`2.2.0~dev2`), `docs/brand/README.md` (если меняли)
- Build: `public/css/app.css`

- [ ] **Step 1: Сторож без списка.** В `TokenGuardTest` удалить константу `NOT_YET`, ветку `in_array($view, self::NOT_YET…)` и тест `test_the_list_names_only_views_that_exist`. Run: `tests 'TokenGuardTest' | tail -3` — PASS (все шаблоны на токенах). Если падает — шаблон пропущен: перевести по таблице.
- [ ] **Step 2: Tailwind без палитры.** Из `tailwind.config.js` убрать `darkMode: 'class'` с комментарием и `green`/`yellow`/`purple` (вместе с `require('tailwindcss/colors')`). Собрать CSS. Run: `grep -c "dark\\\\:" public/css/app.css` — `0`.
- [ ] **Step 3: Документы.**
  - `CLAUDE.md`, раздел «Фронтенд»: заменить пункт о `darkMode: 'class'` на: оформление (`appearance.js`: `data-style`, `data-density`, `data-mode` до отрисовки, Auto по восходу и закату города часового пояса — `SunPlace`, без места — системная тема; выбор в `localStorage` браузера), токены (`app.css`, четыре темы, плотность, `.cmp-*`; цвета Tailwind — только токены; `TokenGuardTest` не пускает палитру, `dark:` и цвета в `style`; контраст — `tests/js/tokens.test.mjs`), итог партии (`NotificationBatch::open()`, `BatchSummary`, `BatchIndicator` с `wire:poll.10s.keep-alive`, `tab-status.js`, значки `favicon-{run,fail,done}`), ловушку морфинга (раскрытое и открытое — в Alpine). В разделе «Команды» — `npm run test:js` перечисляет файлы.
  - `ROADMAP.md`: пункт 3 «Компактный веб» — в «Сделано» запись 2.2.0 (после выпуска), из «что осталось» убрать.
  - `debian/changelog`: запись `2.2.0~dev2` (версия повышается на месте, одна запись) — пункты: компактный каркас и итог партии в панели; Appearance (стиль, плотность, тема, Auto по восходу и закату); вкладка (заголовок и значок); дашборд (сводка, таблица партии, New batch, журнал); CMs (поиск, колонки, новые сверху, подтверждение удаления); правило про Raspberry Pi на странице Ports (из `~dev1`).
- [ ] **Step 4: Весь набор и JS.** `tests_all_ok | tail -4` — exit 0; `node --test tests/js/upload-progress.test.mjs tests/js/tokens.test.mjs tests/js/appearance.test.mjs tests/js/tab-status.test.mjs 2>&1 | tail -3` — `# fail 0`.
- [ ] **Step 5: Commit** — документы, сторож, CSS: `git commit -m "Token guard without exceptions; docs and changelog for 2.2.0~dev2"`.
- [ ] **Step 6: Стенд.** Собрать и поставить `.deb` на WSL-стенде (`git archive HEAD | wsl … sh /root/bench/20-src-in.sh`, затем `w sh /root/bench/03-deb.sh`). На копии данных стенда каналы уведомлений выключены; запросов `scriptexecute` нет. Одноразовая учётка (пароль свежий, после — удалить учётку и её сессии). Playwright, окно 1512×862:
  - каждая страница (Dashboard, CMs, карточка модуля, Ports, Images, Projects, Scripts, Labels, Firmware, Settings, Profile, API Tokens, вход) в компактной плотности в четырёх сочетаниях (Warm/Neutral × Light/Dark): выбор ставится через `localStorage.setItem('cmprovision-appearance', …)` и перезагрузку;
  - Roomy — Dashboard и CMs;
  - окно Appearance открыто; модальные окна (правка проекта, загрузка образа) в тёмной теме;
  - вкладка: партия с модулями в базе стенда (`Cm::create` через tinker), затем смена фазы одного на `failed` — через ≤ 10 секунд заголовок «1 failed · …», значок `favicon-fail`;
  - время ответа `/cms` на копии данных (≥ 1 000 модулей): `curl -s -o /dev/null -w '%{time_total}'` с куки сессии; больше 1 секунды — записать в ROADMAP постраничную разбивку отдельной задачей.
  Найденное исправляется в этой задаче с тестом, где он возможен (вёрстка — снимком). Снимки — пользователю (SendUserFile).

---

### Task 13: Выпуск 2.2.0

Только после «да» пользователя.

- [ ] `debian/changelog`: `2.2.0~devN` → `2.2.0`, дата; `ROADMAP.md`: запись 2.2.0 в «Сделано».
- [ ] Весь набор на стенде — exit 0.
- [ ] `git checkout main && git merge --ff-only origin/main && git merge --no-ff stage2 -m "Merge stage2: release 2.2.0" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"` (сообщения — только `-m`: `git merge -F -` не читает stdin), `git tag -a v2.2.0 -m "cmprovision4 2.2.0"`, проверить `git rev-parse --short 'v2.2.0^{commit}'` = merge-коммит, `git checkout stage2 && git merge --ff-only main`, `git push origin main stage2 v2.2.0`.
- [ ] Сборка на wlanpi-da2 из тега, проверка, что не идёт прошивка, `apt-get install`, проверка служб и `curl` страниц.
