import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const DownloadStatus = require('../../public/js/download-status.js');

function fixture() {
    let cookies = '', now = 0, cleared = 0, interval, destination;
    const model = DownloadStatus.create('a'.repeat(32), {
        cookies: () => cookies, clearCookie: () => { cookies = ''; }, now: () => now,
        origin: () => 'http://station.test', go: url => { destination = url; },
        every: callback => { interval = callback; return 123; },
        stop: id => { assert.equal(id, 123); cleared++; },
    });
    return { model, setCookie: value => { cookies = value; }, tick: (value, time = now) => { cookies = value; now = time; interval(); }, cleared: () => cleared, destination: () => destination };
}

test('waiting starts immediately and repeated submissions are blocked', () => {
    const { model } = fixture();
    assert.equal(model.start(), true);
    assert.equal(model.state, 'preparing');
    let prevented = false;
    assert.equal(model.start({ preventDefault: () => { prevented = true; } }), false);
    assert.equal(prevented, true);
});

test('only this request cookie means the browser received download headers', () => {
    const { model, tick, cleared } = fixture();
    model.start();
    tick('other=1; cmprovision_download_' + 'b'.repeat(32) + '=encrypted');
    assert.equal(model.state, 'preparing');
    tick('other=1; cmprovision_download_' + 'a'.repeat(32) + '=encrypted');
    assert.equal(model.state, 'started'); // This does not mean the file has finished downloading.
    assert.equal(cleared(), 1);
});

test('long waits never turn into a false success and do not retry automatically', () => {
    const { model, tick, cleared } = fixture();
    model.start();
    tick('', 900001);
    assert.equal(model.state, 'delayed');
    assert.equal(cleared(), 1);
});

test('leaving the page stops its polling without cancelling or repeating the server request', () => {
    const { model, cleared } = fixture();
    model.start(); model.destroy();
    assert.equal(cleared(), 1);
    model.destroy();
    assert.equal(cleared(), 1);
});

test('the initial blank download frame is not an error', () => {
    const { model } = fixture(); model.start();
    model.frameLoaded({ contentWindow: { location: { href: 'about:blank' } } });
    assert.equal(model.state, 'preparing');
});

test('expired authentication returns the visible page to same-origin password confirmation', () => {
    const { model, destination, cleared } = fixture(); model.start();
    model.frameLoaded({ contentWindow: { location: { href: 'http://station.test/user/confirm-password' } } });
    assert.equal(destination(), 'http://station.test/user/confirm-password');
    assert.equal(cleared(), 1);
});

test('a foreign frame cannot redirect the visible page', () => {
    const { model, destination } = fixture(); model.start();
    model.frameLoaded({ contentWindow: { location: { href: 'https://foreign.invalid/login' } } });
    assert.equal(destination(), undefined);
    assert.equal(model.state, 'failed');
});

test('a failed native download exposes its bounded server message and permits an explicit retry', () => {
    const { model } = fixture(); model.start();
    model.frameLoaded({ contentWindow: { location: { href: 'http://station.test/settings?tab=status#backup' } },
        contentDocument: { querySelector: () => ({ textContent: 'Not enough free space.' }) } });
    assert.equal(model.state, 'failed');
    assert.equal(model.failure, 'Not enough free space.');
    assert.equal(model.start(), true);
    assert.equal(model.failure, '');
});

test('download headers arriving before a frame load are success even before the next timer tick', () => {
    const { model, setCookie } = fixture(); model.start();
    setCookie('cmprovision_download_' + 'a'.repeat(32) + '=encrypted');
    model.frameLoaded({ contentWindow: { location: { href: 'http://station.test/settings/backup' } },
        contentDocument: { querySelector: () => null } });
    assert.equal(model.state, 'started');
});
