// Run with: node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const SettingsAnchors = require('../../public/js/settings-anchors.js');

test('an old link to a section opens the tab that holds it', () => {
    assert.equal(SettingsAnchors.redirect('/settings', '', '#wifi'), '/settings?tab=network#wifi');
    assert.equal(SettingsAnchors.redirect('/settings', '', '#reprovision-key'), '/settings?tab=provisioning#reprovision-key');
    assert.equal(SettingsAnchors.redirect('/settings', '', '#reprovision-accounts'), '/settings?tab=provisioning#reprovision-accounts');
    assert.equal(SettingsAnchors.redirect('/settings', '', '#utility-os'), '/settings?tab=provisioning#utility-os');
    assert.equal(SettingsAnchors.redirect('/settings', '', '#usb-prep'), '/settings?tab=provisioning#usb-prep');
    assert.equal(SettingsAnchors.redirect('/settings', '', '#hdmi-display'), '/settings?tab=provisioning#hdmi-display');
});

test('a section on the open tab stays where it is', () => {
    assert.equal(SettingsAnchors.redirect('/settings', '?tab=network', '#wifi'), null);
    assert.equal(SettingsAnchors.redirect('/settings', '?foo=1&tab=provisioning', '#utility-os'), null);
    assert.equal(SettingsAnchors.redirect('/settings', '', '#services'), null, 'no tab in the address is the Status tab');
});

test('a section of another tab than the open one sends to its own', () => {
    assert.equal(SettingsAnchors.redirect('/settings', '?tab=status', '#switches'), '/settings?tab=network#switches');
    assert.equal(SettingsAnchors.redirect('/settings', '?tab=network', '#hdmi-display'), '/settings?tab=provisioning#hdmi-display');
});

test('an anchor that is none of ours is left alone', () => {
    assert.equal(SettingsAnchors.redirect('/settings', '', ''), null);
    assert.equal(SettingsAnchors.redirect('/settings', '', '#something-else'), null);
    assert.equal(SettingsAnchors.redirect('/settings', '', '#constructor'), null, 'prototype names are not sections');
});

test('a broken tab value does not throw', () => {
    assert.doesNotThrow(() => SettingsAnchors.redirect('/settings', '?tab=%', '#wifi'));
    assert.equal(SettingsAnchors.redirect('/settings', '?tab=%E0%A4%A', '#wifi'), '/settings?tab=network#wifi', 'an unreadable tab is not the open one');
});

test('every section id of the tab views is in the table', async () => {
    const { readFileSync, readdirSync } = await import('node:fs');
    const dir = new URL('../../resources/views/livewire/', import.meta.url);
    const ids = new Set();
    const files = ['wifi-settings', 'switch-settings', 'notification-settings', 'utility-os-settings', 'usb-prep-settings', 'wallboard-settings']
        .map((f) => `${f}.blade.php`)
        .concat(['settings/status.blade.php', 'settings/network.blade.php', 'settings/provisioning.blade.php']);
    for (const f of files) {
        const text = readFileSync(new URL(f, dir), 'utf8');
        for (const m of text.matchAll(/<x-settings-card[^>]*?\sid="([a-z-]+)"|<div[^>]*\sid="(reprovision[a-z-]*)"/g)) ids.add(m[1] || m[2]);
    }
    assert.ok(ids.size >= 7, [...ids].join(','));
    for (const id of ids) assert.ok(id in SettingsAnchors.TAB_OF, `#${id} is missing from TAB_OF`);
    void readdirSync;
});
