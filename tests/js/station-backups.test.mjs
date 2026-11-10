import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const ui = require('../../public/js/station-backups.js');

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
