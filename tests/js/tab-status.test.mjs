// Run with: node --test tests/js/tab-status.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const T = require('../../public/js/tab-status.js');

function fakeDocument(title) {
    const links = { 'cmp-icon-svg': { href: '/favicon.svg?v=1791761600' }, 'cmp-icon-png': { href: '/favicon.ico?v=1791761600' } };
    return { title, getElementById: (id) => links[id] || null, links };
}
const element = (attrs) => ({ getAttribute: (name) => (name in attrs ? attrs[name] : null) });

test('the title starts with what the batch says and keeps the page, or is the page alone', () => {
    assert.equal(T.titleFor('1 failed · 3 running', 'CMs · cmprovision'), '1 failed · 3 running · CMs · cmprovision');
    assert.equal(T.titleFor('3 running', 'cmprovision'), '3 running · cmprovision');
    assert.equal(T.titleFor('', 'CMs · cmprovision'), 'CMs · cmprovision');
});

test('each state has its icons, keeping the version of the page', () => {
    assert.deepEqual(T.iconsFor('fail', '1791761600'), { svg: '/favicon-fail.svg?v=1791761600', png: '/favicon-fail.png?v=1791761600' });
    assert.deepEqual(T.iconsFor('stale', '1791761600'), { svg: '/favicon-stale.svg?v=1791761600', png: '/favicon-stale.png?v=1791761600' });
    assert.deepEqual(T.iconsFor('idle', '1791761600'), { svg: '/favicon.svg?v=1791761600', png: '/favicon.ico?v=1791761600' });
    assert.deepEqual(T.iconsFor('nonsense', ''), { svg: '/favicon.svg', png: '/favicon.ico' });
});

test('apply sets the title and both icon links, and returns to the page title when the batch is over', () => {
    T.reset();
    const doc = fakeDocument('Dashboard · cmprovision');
    T.apply(doc, element({ 'data-tab-title': '1 failed · 3 running', 'data-tab-icon': 'fail' }));
    assert.equal(doc.title, '1 failed · 3 running · Dashboard · cmprovision');
    assert.equal(doc.links['cmp-icon-svg'].href, '/favicon-fail.svg?v=1791761600');
    assert.equal(doc.links['cmp-icon-png'].href, '/favicon-fail.png?v=1791761600');

    T.apply(doc, element({ 'data-tab-title': '', 'data-tab-icon': 'idle' }));
    assert.equal(doc.title, 'Dashboard · cmprovision');
    assert.equal(doc.links['cmp-icon-svg'].href, '/favicon.svg?v=1791761600');
});
