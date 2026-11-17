import { createRequire } from 'node:module';
import test from 'node:test';
import assert from 'node:assert/strict';
const api = createRequire(import.meta.url)('../../public/js/station-updates.js');

function livewireRequests() {
    const hooks = [];
    const livewire = { hook: (name, callback) => { assert.equal(name, 'request'); hooks.push(callback); } };
    const window = { Livewire: livewire, document: { addEventListener() {} } };
    api.start(window);
    return {
        hooks,
        response(status, marker) {
            let respond, fail;
            hooks[0]({ respond: callback => { respond = callback; }, fail: callback => { fail = callback; } });
            respond({ response: { status, headers: new Headers(marker ? { 'X-Cmprovision-Maintenance': marker } : {}) } });
            let suppressed = false;
            fail({ status, preventDefault: () => { suppressed = true; } });
            return suppressed;
        },
    };
}

test('planned update maintenance does not leave the Livewire failure overlay', () => {
    assert.equal(livewireRequests().response(503, 'station-update'), true);
});

test('backup maintenance also suppresses the modal failure overlay', () => {
    assert.equal(livewireRequests().response(503, 'station-backup'), true);
});

test('real errors and session expiry keep Livewire default handling', () => {
    const requests = livewireRequests();
    for (const [status, marker] of [[503, null], [500, 'station-update'], [419, null], [403, null], [503, 'other-maintenance']]) {
        assert.equal(requests.response(status, marker), false, `${status}: ${marker}`);
    }
    assert.equal(requests.response(503, 'station-update'), true);
    assert.equal(requests.response(503, null), false, 'maintenance detection belongs to one request only');
});

test('maintenance handling registers before Livewire init and only once', () => {
    const hooks = [];
    let initialized;
    const window = { document: { addEventListener: (name, callback) => {
        assert.equal(name, 'livewire:init'); initialized = callback;
    } } };
    api.start(window);
    window.Livewire = { hook: (name, callback) => { hooks.push(callback); } };
    initialized();
    api.start(window);
    assert.equal(hooks.length, 1);
});

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

test('reconnection keeps the last confirmed target and phase visible', async (t) => {
    const state = api.create('/status', { operation: { id: 'apply-1', component: 'application', version: '2.30.4',
        action: 'apply', state: 'applying', message: 'Installing application packages.' } });
    state.destroyed = true;
    t.mock.method(globalThis, 'fetch', async () => { throw new Error('offline'); });
    await state.poll();
    assert.match(state.operationLabel(state.operation), /Application.*2\.30\.4/);
    assert.match(state.message(), /Installing application packages/);
    assert.match(state.connectionMessage(), /do not apply again/);
    assert.equal(state.blocked(), true);
    assert.equal(state.canCancel(), false);
});

test('terminal operations remain blocked until pending recovery clears', async (t) => {
    const operation = { id: 'apply-1', state: 'failed', action: 'apply', component: 'application' };
    const state = api.create('/status', { operation, pending: { id: 'apply-1' } });
    state.destroyed = true;
    let refreshes = 0;
    state.$wire = { $refresh: () => { refreshes++; } };
    assert.equal(state.blocked(), true);
    assert.equal(state.busy(), false);
    t.mock.method(globalThis, 'fetch', async () => ({ ok: true, json: async () => ({ schema: 1, operation, pending: null, history: [] }) }));
    await state.poll();
    assert.equal(state.blocked(), false);
    assert.equal(refreshes, 1, 'finishing recovery refreshes candidates and installed versions');
});

test('component history includes shared checks and omits the current operation and other components', () => {
    const state = api.create('/status', { operation: { id: 'current', component: 'utility-os', state: 'downloading' }, history: [
        { id: 'current', component: 'utility-os', state: 'downloading' },
        { id: 'utility', component: 'utility-os', action: 'activate', state: 'succeeded' },
        { id: 'both', components: ['application', 'utility-os'], action: 'check', state: 'succeeded' },
        { id: 'app', component: 'application', action: 'apply', state: 'failed' },
        { id: 'legacy', action: 'check', state: 'succeeded' },
    ] });
    assert.deepEqual(state.historyFor('application').map(x => x.id), ['both', 'app', 'legacy']);
    assert.deepEqual(state.historyFor('utility-os').map(x => x.id), ['utility', 'both', 'legacy']);
    assert.equal(state.operationFor('application'), false);
    assert.equal(state.operationFor('utility-os'), true);
    assert.deepEqual(state.historyFor('utility-os', true).map(x => x.id), ['current', 'utility', 'both', 'legacy'], 'a completed current operation can be shown once as a past event');
});

test('phase feedback distinguishes application snapshots from Utility OS activation and recovery', () => {
    const state = api.create('/status', {});
    assert.match(state.phaseLabel({ action: 'apply', component: 'application', state: 'preparing' }), /snapshot/i);
    assert.doesNotMatch(state.phaseLabel({ action: 'activate', component: 'utility-os', state: 'preparing' }), /snapshot/i);
    assert.match(state.phaseLabel({ action: 'apply', component: 'utility-os', state: 'applying' }), /activat/i);
    assert.match(state.phaseLabel({ action: 'apply', component: 'application', state: 'reconnecting' }), /health/i);
    assert.match(state.phaseLabel({ state: 'recovery-required' }), /recovery required/i);
    assert.doesNotMatch(state.phaseLabel({ state: 'recovery-required' }), /completed|success/i);
});

test('timestamps follow the station timezone and tolerate incomplete operation data', () => {
    const state = api.create('/status', {}, 'Asia/Jerusalem');
    assert.match(state.formatTime(Date.UTC(2026, 10, 17, 11, 0) / 1000), /17 Nov 2026.*13:00/);
    for (const timestamp of [undefined, null, 'broken', 0, Number.POSITIVE_INFINITY]) {
        assert.equal(state.formatTime(timestamp), 'Time not available');
    }
    assert.equal(state.historyFor('application').length, 0);
    assert.equal(state.operationFor('application'), false);
});

test('application and Utility OS stage strips reflect the real operation instead of fake percentages', () => {
    const state = api.create('/status', { operation: { action: 'apply', component: 'application', state: 'reconnecting' } });
    assert.deepEqual(state.stages().map(x => x.state), ['done', 'done', 'current']);
    state.operation = { action: 'activate', component: 'utility-os', state: 'preparing' };
    assert.deepEqual(state.stages().map(x => x.state), ['current']);
    assert.equal(state.stages().some(x => /snapshot/i.test(x.label)), false);
    state.operation = { action: 'apply', component: 'application', state: 'failed', diagnostic: { phase: 'preparing' } };
    assert.deepEqual(state.stages().map(x => x.state), ['failed', 'next', 'next']);
});

test('real automatic and manual restore transitions never claim packages are still being installed', () => {
    const state = api.create('/status', { operation: { action: 'apply', component: 'application', state: 'applying',
        message: 'The update failed; automatically restoring the previous snapshot.', diagnostic: { phase: 'reconnecting', type: 'UpdateError' } } });
    assert.match(state.phaseLabel(state.operation), /restor/i);
    assert.doesNotMatch(state.phaseLabel(state.operation), /install/i);
    assert.deepEqual(state.stages().map(x => x.state), ['current']);
    state.operation.state = 'rolled-back';
    assert.deepEqual(state.stages().map(x => x.state), ['done']);
    state.operation = { action: 'rollback', component: 'application', state: 'applying' };
    assert.match(state.phaseLabel(state.operation), /restor/i);
});
