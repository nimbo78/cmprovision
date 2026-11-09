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

test('Alpine data reinitialization resumes polling after a Livewire render', async (t) => {
    const states = ['checking', 'succeeded', 'succeeded'];
    const requests = [];
    const timers = [];
    const cleared = [];
    let refreshes = 0;
    t.mock.method(globalThis, 'fetch', async (url) => {
        requests.push(url);
        return { ok: true, json: async () => ({ schema: 1, pending: null,
            operation: { id: 'check-1', state: states.shift() } }) };
    });
    t.mock.method(globalThis, 'setTimeout', (callback, delay) => {
        const timer = { callback, delay };
        timers.push(timer);
        return timer;
    });
    t.mock.method(globalThis, 'clearTimeout', (timer) => cleared.push(timer));
    const mount = async (operation) => {
        const component = api.create('/status', { operation });
        component.$wire = { $refresh: () => { refreshes++; } };
        // Alpine calls init for a replaced x-data attribute; an unchanged x-init
        // attribute is not run again when Livewire morphs only the initial state.
        await component.init?.();
        return component;
    };
    const first = await mount({ id: 'check-1', state: 'queued' });
    assert.equal(first.operation.state, 'checking');
    first.destroy();
    assert.ok(cleared.includes(timers[0]));
    const replacement = await mount({ id: 'check-1', state: 'queued' });
    assert.equal(replacement.operation.state, 'succeeded');
    assert.equal(replacement.busy(), false);
    assert.equal(refreshes, 1);
    replacement.destroy();
    const refreshed = await mount({ id: 'check-1', state: 'succeeded' });
    assert.equal(refreshes, 1);
    assert.equal(requests.length, 3);
    assert.equal(timers.at(-1).delay, 10000);
    refreshed.destroy();
});
