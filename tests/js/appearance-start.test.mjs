// Run with: node --test tests/js/appearance-start.test.mjs
// Appearance.start() in a page: a fake window with the server's place, storage, timers and events, and a clock the
// test moves. A laptop that slept or a page restored from the back/forward cache must not keep a stale theme.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const A = require('../../public/js/appearance.js');
const RealDate = Date;

function page(iso, stored) {
    let now = new RealDate(iso).getTime();
    const attrs = { 'data-sun': '55.75583,37.61777', 'data-sun-city': 'Moscow', 'data-sun-zone': 'Europe/Moscow' };
    const store = stored ? { 'cmprovision-appearance': JSON.stringify(stored) } : {};
    const on = {}, onDocument = {}, intervals = [];
    const win = {
        document: {
            hidden: false,
            documentElement: { getAttribute: (name) => attrs[name] ?? null, setAttribute: (name, value) => { attrs[name] = String(value); } },
            addEventListener: (type, fn) => { (onDocument[type] ||= []).push(fn); },
        },
        localStorage: { getItem: (key) => store[key] ?? null, setItem: (key, value) => { store[key] = String(value); } },
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        setTimeout: () => 1,            // the sunrise timer never fires: the machine slept through it
        clearTimeout() {},
        setInterval: (fn) => { intervals.push(fn); return intervals.length; },
        addEventListener: (type, fn) => { (on[type] ||= []).push(fn); },
    };
    return {
        win, attrs, store,
        start() {
            // appearance.js reads the time with new Date(): the page's clock is the test's
            globalThis.Date = class extends RealDate { constructor(...a) { if (a.length) super(...a); else super(now); } static now() { return now; } };
            try { A.start(win); } finally { globalThis.Date = RealDate; }
        },
        later(iso2, fn) {
            now = new RealDate(iso2).getTime();
            globalThis.Date = class extends RealDate { constructor(...a) { if (a.length) super(...a); else super(now); } static now() { return now; } };
            try { fn(); } finally { globalThis.Date = RealDate; }
        },
        fire: (type, event = {}) => (on[type] || []).forEach((fn) => fn(event)),
        fireDocument: (type) => (onDocument[type] || []).forEach((fn) => fn({})),
        tick: () => intervals.forEach((fn) => fn()),
    };
}

test('Auto is dark at 23:00 in Moscow', () => {
    const p = page('2026-10-12T20:00:00Z');
    p.start();
    assert.equal(p.attrs['data-mode'], 'dark');
});

test('after a night asleep, Auto turns light when the tab is shown again', () => {
    const p = page('2026-10-12T20:00:00Z');
    p.start();
    p.later('2026-10-13T07:00:00Z', () => p.fireDocument('visibilitychange'));   // 10:00, the timer never fired
    assert.equal(p.attrs['data-mode'], 'light');
});

test('without any event, the minute check catches up after sleep', () => {
    const p = page('2026-10-12T20:00:00Z');
    p.start();
    p.later('2026-10-13T07:00:00Z', () => p.tick());
    assert.equal(p.attrs['data-mode'], 'light');
});

test('without browser storage the page gets the defaults and a choice holds until the next load', () => {
    const p = page('2026-10-13T07:00:00Z');
    Object.defineProperty(p.win, 'localStorage', { get() { throw new Error('SecurityError: storage is disabled'); } });
    p.start();
    assert.deepEqual([p.attrs['data-style'], p.attrs['data-density'], p.attrs['data-mode']], ['warm', 'compact', 'light']);
    A.set({ style: 'neutral', mode: 'dark' });
    assert.deepEqual([p.attrs['data-style'], p.attrs['data-mode']], ['neutral', 'dark']);
});

test('a page back from the back/forward cache takes the choice made meanwhile in another tab', () => {
    const p = page('2026-10-13T07:00:00Z', { style: 'warm', density: 'compact', mode: 'auto' });
    p.start();
    assert.equal(p.attrs['data-style'], 'warm');
    p.store['cmprovision-appearance'] = JSON.stringify({ style: 'neutral', density: 'roomy', mode: 'dark' });
    p.later('2026-10-13T07:05:00Z', () => p.fire('pageshow', { persisted: true }));
    assert.deepEqual([p.attrs['data-style'], p.attrs['data-density'], p.attrs['data-mode']], ['neutral', 'roomy', 'dark']);
});
