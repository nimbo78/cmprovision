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
    ['pos-text', 'pos-bg'], ['neg-text', 'neg-bg'], ['info-text', 'info-bg'], ['warn-text', 'warn-bg'], ['muted', 'neg-bg'],
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
