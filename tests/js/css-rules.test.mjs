// Run with: node --test tests/js/css-rules.test.mjs
// The built stylesheet as the package ships it (public/css/app.css): rules the themes depend on that the forms
// plugin or a component's own rule would otherwise undo.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../../public/css/app.css', import.meta.url), 'utf8');

// every value of a property for a selector, in stylesheet order: at equal specificity the last one wins
function values(selector, property) {
    const found = [];
    for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
        if (!m[1].split(',').map((s) => s.trim()).includes(selector)) continue;
        for (const declaration of m[2].split(';')) {
            const colon = declaration.indexOf(':');
            if (colon > 0 && declaration.slice(0, colon).trim() === property) found.push(declaration.slice(colon + 1).trim());
        }
    }
    return found;
}
const last = (selector, property) => values(selector, property).at(-1);

test('a focused checkbox or radio is ringed in the theme, not framed in white', () => {
    // the plugin rings .form-checkbox in its components layer, after the base rules: input.form-checkbox outranks it
    for (const selector of ['[type=checkbox]:focus', '[type=radio]:focus', 'input.form-checkbox:focus', 'input.form-radio:focus']) {
        assert.equal(last(selector, '--tw-ring-offset-color'), 'var(--panel)', selector);
        assert.equal(last(selector, '--tw-ring-color'), 'var(--focus)', selector);
    }
});

test('a focused field outside cmp-field takes the theme focus colour, not the plugin blue', () => {
    for (const selector of ['[type=text]:focus', 'textarea:focus', 'select:focus']) {
        assert.equal(last(selector, '--tw-ring-color'), 'var(--focus)', selector);
        assert.equal(last(selector, 'border-color'), 'var(--focus)', selector);
    }
});

test('cmp-field keeps the arrow of a select (no background shorthand) and room for it', () => {
    assert.deepEqual(values('.cmp-field', 'background'), []);
    assert.equal(last('.cmp-field', 'background-color'), 'var(--field)');
    assert.equal(last('select.cmp-field', 'padding-right'), '2rem');
});

test('the failed tile replaces the warm gradient of a tile', () => {
    assert.equal(last('.cmp-tile-fail', 'background'), 'var(--neg-bg)');
    assert.ok(css.indexOf('.cmp-tile-fail{') > css.indexOf('.cmp-tile{'), 'the failed tile comes after the tile');
});

test('a table card scrolls sideways below xl, its header sticking to the card there and under the top bar from xl', () => {
    // the card is then the header's scroll container: top: var(--bar-h) would push the header 48 px into the rows
    assert.equal(last('.cmp-card-scroll', 'overflow-x'), 'auto');
    assert.ok(css.includes('.cmp-card-scroll :where(.cmp-table th){top:0}'), 'the header sticks to the scrolling card');
    const xl = css.match(/@media \(min-width:1280px\)\{((?:[^{}]*\{[^{}]*\})*)\}/g) || [];
    assert.ok(xl.some((block) => block.includes('.cmp-card-scroll{overflow:clip}') && block.includes('.cmp-card-scroll :where(.cmp-table th){top:var(--bar-h)}')),
        'from xl the card clips and the header sticks under the top bar');
});

test('a single utility on a table cell wins over the table rules (align-top, a border colour)', () => {
    // the table's cell rules weigh one class (:where), like a utility, and come before the utilities
    for (const rule of ['.cmp-table :where(td){', '.cmp-table :where(th){', '.cmp-table :where(tr>:first-child){', '.cmp-table :where(tr>:last-child){'])
        assert.ok(css.includes(rule), rule);
    assert.ok(!/\.cmp-table (td|th)\{/.test(css), 'no two-part cell selector is left');
});

test('the page links scroll when the top bar is too narrow for them', () => {
    assert.equal(last('.cmp-nav', 'overflow-x'), 'auto');
    assert.equal(last('.cmp-account', 'white-space'), 'nowrap', 'the account name stays on one line in a squeezed bar');
});
