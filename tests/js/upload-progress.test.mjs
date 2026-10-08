// Run with: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const UploadProgress = require('../../public/js/upload-progress.js');

const MB = 1024 * 1024;

test('reports percent of the bytes sent', () => {
    const t = UploadProgress.createTracker();
    const s = t.update(25 * MB, 100 * MB, 1000);
    assert.equal(s.percent, 25);
    assert.equal(s.loaded, 25 * MB);
    assert.equal(s.total, 100 * MB);
});

test('needs two samples before it can tell speed or remaining time', () => {
    const t = UploadProgress.createTracker();
    const s = t.update(1 * MB, 100 * MB, 1000);
    assert.equal(s.speed, null);
    assert.equal(s.eta, null);
});

test('computes speed and remaining time from the samples in the window', () => {
    const t = UploadProgress.createTracker({ windowMs: 5000 });
    t.update(0, 10 * MB, 0);
    t.update(1 * MB, 10 * MB, 1000);
    const s = t.update(2 * MB, 10 * MB, 2000);
    assert.equal(s.speed, 1 * MB);          // bytes per second
    assert.equal(s.eta, 8);                 // seconds for the remaining 8 MB
});

test('old samples fall out of the window, so a slowdown shows up quickly', () => {
    const t = UploadProgress.createTracker({ windowMs: 3000 });
    t.update(0, 100 * MB, 0);
    t.update(10 * MB, 100 * MB, 1000);     // 10 MB/s at first
    t.update(10.5 * MB, 100 * MB, 3000);
    t.update(11 * MB, 100 * MB, 5000);
    const s = t.update(11.5 * MB, 100 * MB, 7000);   // only samples from t>=4000 count
    assert.ok(s.speed <= 0.3 * MB, `speed should reflect the slow phase, got ${s.speed / MB} MB/s`);
});

test('remaining time is unknown while nothing moves', () => {
    const t = UploadProgress.createTracker({ windowMs: 5000 });
    t.update(5 * MB, 10 * MB, 0);
    const s = t.update(5 * MB, 10 * MB, 2000);
    assert.equal(s.speed, 0);
    assert.equal(s.eta, null);
});

test('formats byte counts and durations for people', () => {
    assert.equal(UploadProgress.formatBytes(512), '512 B');
    assert.equal(UploadProgress.formatBytes(1536 * 1024), '1.5 MB');
    assert.equal(UploadProgress.formatBytes(3.07 * 1024 * MB), '3.07 GB');
    assert.equal(UploadProgress.formatDuration(42), '42 s');
    assert.equal(UploadProgress.formatDuration(105), '1 min 45 s');
    assert.equal(UploadProgress.formatDuration(3725), '1 h 2 min');
});
