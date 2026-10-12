/*
 * The browser tab tells the batch's state: the title starts with "1 failed · 3 running" or "✓ 16 done" and the
 * icon gets a blue, red or green dot. BatchIndicator (top bar, polled every 10 s even in a background tab) carries
 * data-tab-title and data-tab-icon; this script copies them to the title and the icon links whenever they change.
 * Plain script (no build step): the browser gets window.TabStatus, node tests require() it.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.TabStatus = api;
        root.document.addEventListener('DOMContentLoaded', function () { api.watch(root.document); });
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var ICONS = { idle: '/favicon', run: '/favicon-run', fail: '/favicon-fail', done: '/favicon-done' };
    var base = null;   // the page's own title ("Dashboard · cmprovision"), read once

    function titleFor(prefix, pageTitle) {
        return prefix ? prefix + ' · cmprovision' : pageTitle;
    }

    function iconsFor(state, version) {
        var v = version ? '?v=' + version : '';
        var name = ICONS[state] || ICONS.idle;
        return { svg: name + '.svg' + v, png: (name === ICONS.idle ? '/favicon.ico' : name + '.png') + v };
    }

    function versionOf(doc) {
        var link = doc.getElementById('cmp-icon-svg');
        var m = link && /[?&]v=([^&]+)/.exec(link.href || '');
        return m ? m[1] : '';
    }

    function apply(doc, el) {
        if (base === null) base = doc.title;
        doc.title = titleFor(el.getAttribute('data-tab-title') || '', base);
        var icons = iconsFor(el.getAttribute('data-tab-icon') || 'idle', versionOf(doc));
        var svg = doc.getElementById('cmp-icon-svg'), png = doc.getElementById('cmp-icon-png');
        if (svg) svg.href = icons.svg;
        if (png) png.href = icons.png;
    }

    function watch(doc) {
        var el = doc.querySelector('[data-tab-icon]');
        if (!el) return;
        apply(doc, el);
        if (typeof MutationObserver !== 'undefined')
            new MutationObserver(function () { apply(doc, el); }).observe(el, { attributes: true, attributeFilter: ['data-tab-title', 'data-tab-icon'] });
    }

    return { titleFor: titleFor, iconsFor: iconsFor, apply: apply, watch: watch, reset: function () { base = null; } };
}));
