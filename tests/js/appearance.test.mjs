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
