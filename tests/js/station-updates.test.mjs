import { createRequire } from 'node:module';
import test from 'node:test';
import assert from 'node:assert/strict';
const api = createRequire(import.meta.url)('../../public/js/station-updates.js');

test('download percentage uses actual received bytes; verification is indeterminate', () => {
    const state = api.create('/status', { operation: { state: 'downloading', downloaded: 25, total: 100 } });
    assert.equal(state.percent(), 25);
    assert.equal(state.busy(), true);
    state.operation = { state: 'verifying', downloaded: 100, total: 100 };
    assert.equal(state.canCancel(), true);
    state.operation.state = 'applying';
    assert.equal(state.canCancel(), false);
    state.operation.state = 'recovery-required';
    assert.equal(state.busy(), true);
});

test('lost connection never resubmits an installation', async () => {
    const state = api.create('/status', { operation: { state: 'applying' } });
    state.destroyed = true;
    const original = globalThis.fetch;
    let calls = [];
    globalThis.fetch = async (...args) => { calls.push(args); throw new Error('offline'); };
    try { await state.poll(); } finally { globalThis.fetch = original; }
    assert.equal(state.reconnecting, true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0][1].method, undefined);
    assert.match(state.message(), /do not apply again/);
});

test('expired authentication shows sign-in guidance without another installation request', async () => {
    const state = api.create('/status', { operation: { state: 'applying' } });
    state.destroyed = true;
    const original = globalThis.fetch;
    globalThis.fetch = async () => ({ ok: false, status: 401 });
    try { await state.poll(); } finally { globalThis.fetch = original; }
    assert.equal(state.signin, true);
    assert.match(state.message(), /Sign in again/);
});
