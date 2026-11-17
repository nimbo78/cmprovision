import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { guardState } = require('../../public/js/station-wizard.js');

test('navigation is blocked for an editor, dirty fields or an in-flight save', () => {
    const guard = guardState();
    assert.equal(guard.canLeave(), true);
    guard.editor('wifi', true);
    assert.equal(guard.canLeave(), false);
    guard.editor('wifi', false);
    guard.change('access');
    assert.equal(guard.canLeave(), false);
    guard.saved('access');
    guard.saving(true);
    assert.equal(guard.canLeave(), false);
    guard.saving(false);
    assert.equal(guard.canLeave(), true);
});

test('saving one component does not discard another component edits', () => {
    const guard = guardState();
    guard.change('wifi'); guard.change('network');
    guard.saved('wifi');
    assert.equal(guard.canLeave(), false);
    guard.saved('network');
    assert.equal(guard.canLeave(), true);
});

test('closing an editor after save or cancel clears only its own dirty state', () => {
    const guard = guardState();
    guard.editor('switch', true); guard.change('switch');
    guard.editor('accounts', true); guard.change('accounts');
    guard.editor('switch', false);
    assert.equal(guard.canLeave(), false);
    guard.editor('accounts', false);
    assert.equal(guard.canLeave(), true);
});

test('a closed form that becomes dirty stays guarded on a normal rerender', () => {
    const guard = guardState();
    guard.editor('web-account', false);
    guard.change('web-account');
    guard.editor('web-account', false);
    assert.equal(guard.canLeave(), false);
});
