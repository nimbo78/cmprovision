/*
 * Settings (2.9): the sections moved to tabs, and the old links (#wifi, #reprovision-key, #utility-os, #hdmi-display) point at
 * sections that are no longer on the page they open. This takes such a link to the tab that holds the section.
 * Plain script (no build step): the browser runs it, node tests require() it.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        var api = factory();
        root.SettingsAnchors = api;
        if (root.location && /\/settings\/?$/.test(root.location.pathname)) {
            var follow = function () {
                var to = api.redirect(root.location.pathname, root.location.search, root.location.hash);
                if (to) root.location.replace(to);
            };
            follow();
            root.addEventListener('hashchange', follow);
        }
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /* the section (its id) => the tab that holds it; the same table as Settings::TABS and the views */
    var TAB_OF = {
        'services': 'status',
        'wifi': 'network',
        'switches': 'network',
        'dhcp': 'network',
        'utility-os': 'provisioning',
        'reprovision': 'provisioning',
        'reprovision-key': 'provisioning',
        'reprovision-accounts': 'provisioning',
        'hdmi-display': 'provisioning',
        'notifications': 'notifications'
    };

    function currentTab(search) {
        var m = /(?:^|[?&])tab=([^&]*)/.exec(search || '');
        return m ? decodeURIComponent(m[1]) : 'status';
    }

    /* the address to go to, or null when the section is on the open tab (or is none of ours) */
    function redirect(pathname, search, hash) {
        var id = (hash || '').replace(/^#/, '');
        if (!Object.prototype.hasOwnProperty.call(TAB_OF, id)) return null;
        if (TAB_OF[id] === currentTab(search)) return null;
        return pathname + '?tab=' + TAB_OF[id] + '#' + id;
    }

    return { redirect: redirect, TAB_OF: TAB_OF };
}));
