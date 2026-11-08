/* Native downloads stay outside JavaScript memory. The cookie only signals response headers. */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory(root);
    else root.DownloadStatus = factory(root);
}(typeof window !== 'undefined' ? window : globalThis, function (root) {
    'use strict';

    function create(identifier, runtime) {
        if (!/^[a-f0-9]{32}$/.test(identifier)) throw new Error('Invalid download identifier');
        var name = 'cmprovision_download_' + identifier;
        var env = Object.assign({
            cookies: function () { return root.document.cookie; },
            clearCookie: function () {
                root.document.cookie = name + '=; Max-Age=0; Path=/; SameSite=Strict' +
                    (root.location.protocol === 'https:' ? '; Secure' : '');
            },
            now: function () { return Date.now(); },
            origin: function () { return root.location.origin; },
            go: function (url) { root.location.assign(url); },
            every: function (callback) { return root.setInterval(callback, 500); },
            stop: function (id) { root.clearInterval(id); }
        }, runtime || {});
        return {
            state: 'idle', timer: null, began: null, failure: '',
            start: function (event) {
                if (this.state === 'preparing') {
                    if (event) event.preventDefault();
                    return false;
                }
                this.destroy();
                env.clearCookie();
                this.failure = '';
                this.state = 'preparing';
                this.began = env.now();
                var self = this;
                this.timer = env.every(function () { self.poll(); });
                return true;
            },
            poll: function () {
                if (env.cookies().split(';').some(function (value) { return value.trim().split('=')[0] === name; })) {
                    this.state = 'started';
                    this.destroy();
                } else if (env.now() - this.began > 900000) {
                    this.state = 'delayed';
                    this.destroy();
                }
            },
            frameLoaded: function (frame) {
                if (this.state === 'preparing') {
                    this.poll();
                    if (this.state === 'started') return;
                }
                try {
                    var url = new URL(frame.contentWindow.location.href);
                    if (url.href === 'about:blank') return;
                    if (url.origin !== env.origin()) throw new Error('Unexpected origin');
                    if (url.pathname === '/login' || url.pathname === '/user/confirm-password') {
                        this.destroy();
                        env.go(url.href);
                        return;
                    }
                    if (this.state !== 'preparing') return;
                    var message = frame.contentDocument.querySelector('#backup [role="alert"]');
                    this.failure = message ? message.textContent.trim().slice(0, 500) : '';
                } catch (error) {
                    if (this.state !== 'preparing') return;
                }
                this.failure = this.failure || 'The backup could not be prepared. Return to Backup and try again.';
                this.state = 'failed';
                this.destroy();
            },
            destroy: function () {
                if (this.timer !== null) env.stop(this.timer);
                this.timer = null;
            }
        };
    }
    return { create: create };
}));
