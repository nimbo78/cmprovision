import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const ui = require('../../public/js/station-backups.js');

test('new and legacy backup forms always submit retention one', () => {
    const panel = ui.create('/status', { schedule: { enabled: false, keep: 365, time: '04:00' } });
    panel.edit();
    assert.equal(panel.profile.keep, 1);
    panel.edit({ id: 'a'.repeat(32), keep: 7 });
    assert.equal(panel.profile.keep, 1);
    panel.editSchedule();
    assert.equal(panel.schedule.keep, 1);
    assert.equal(panel.schedule.time, '04:00');
    assert.equal(panel.schedule.enabled, false);
});

test('only byte-counted phases display numeric progress', () => {
    const panel = ui.create('/status', { operation: { state: 'verifying', bytes: 0, total: 0 } });
    assert.equal(panel.percent(), null);
    panel.operation = { state: 'archiving', bytes: 25, total: 100 };
    assert.equal(panel.percent(), 25);
    panel.operation = { state: 'ready', bytes: 100, total: 100 };
    assert.equal(panel.busy(), false);
});

test('opening a saved profile leaves credentials blank and keeps original fields', () => {
    const panel = ui.create('/status', {});
    panel.edit({ id: 'a'.repeat(32), type: 'sftp', name: 'Nightly', has_password: true, host: 'backup.example' });
    assert.equal(panel.profile.host, 'backup.example');
    assert.equal(panel.profile.password, '');
    assert.deepEqual(panel.profile.clear, []);
    assert.equal(panel.profile.has_password, undefined);
    panel.back();
    assert.equal(panel.screen, 'overview');
});

test('a deliberate new copy rotates the nonce only after a known terminal job', async () => {
    const panel = ui.create('/status', { operation: { state: 'ready' } });
    const calls = [];
    panel.$wire = { back: async () => calls.push('new-intent'), create: async () => calls.push('create') };
    await panel.startBackup();
    assert.deepEqual(calls, ['new-intent', 'create']);
    panel.operation = { state: 'queued' };
    await panel.startBackup();
    assert.equal(calls.length, 2);
});
