/* Reconnect only after a changed boot identity; an early HTTP 200 is not a completed restart. */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory(root);
    else root.StationPowerStatus = factory(root);
}(typeof window !== 'undefined' ? window : globalThis, function (root) {
    'use strict';
    function create(path, runtime) {
        var env = Object.assign({
            origin: function () { return root.location.origin; }, now: function () { return Date.now(); },
            every: function (fn) { return root.setInterval(fn, 2000); }, stop: function (id) { root.clearInterval(id); },
            read: async function (url) {
                var controller = new root.AbortController();
                var timer = root.setTimeout(function () { controller.abort(); }, 3500);
                try {
                    var response = await root.fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
                    var data = null;
                    if (response.ok) {
                        try { data = await response.json(); } catch (invalidBody) { /* An HTTP response is not a lost connection. */ }
                    }
                    return {status: response.status, data: data};
                } finally { root.clearTimeout(timer); }
            }
        }, runtime || {});
        var url = new URL(path, env.origin());
        if (url.origin !== env.origin()) throw new Error('Power status must have the same origin');
        return {
            state: 'idle', action: null, originalBoot: '', began: 0, timer: null, busy: false, generation: 0,
            start: function (data) {
                if (!data || !['reboot', 'poweroff'].includes(data.action) || typeof data.boot_id !== 'string' || !data.boot_id) return false;
                this.destroy();
                this.action = data.action; this.originalBoot = data.boot_id; this.began = env.now();
                this.state = data.confirmed === false ? 'uncertain' : 'scheduled';
                var self = this;
                this.timer = env.every(function () { self.poll(); });
                return true;
            },
            poll: async function () {
                if (this.busy || !['scheduled', 'uncertain', 'reconnecting'].includes(this.state)) return;
                if (env.now() - this.began > 120000) { this.state = 'timeout'; this.destroy(); return; }
                this.busy = true;
                var generation = this.generation;
                try {
                    var response = await env.read(url.href);
                    if (generation !== this.generation) return;
                    if ([401, 403].includes(response.status)) { this.state = 'signin'; this.destroy(); return; }
                    var boot = response.data && response.data.boot_id;
                    if (response.status !== 200 || typeof boot !== 'string' || !boot || boot.length > 64) { this.state = 'uncertain'; return; }
                    if (boot !== this.originalBoot) { this.state = 'online'; this.destroy(); }
                } catch (error) {
                    if (generation !== this.generation) return;
                    if (error && error.name === 'AbortError') { this.state = 'uncertain'; return; }
                    this.state = this.action === 'poweroff' ? 'offline' : 'reconnecting';
                    if (this.state === 'offline') this.destroy();
                } finally { if (generation === this.generation) this.busy = false; }
            },
            message: function () {
                if (this.state === 'scheduled') return this.action === 'reboot' ? 'Restart scheduled. Waiting for the station to restart…' : 'Shutdown scheduled. Waiting for the connection to close…';
                if (this.state === 'uncertain') return 'Scheduling was not confirmed. Monitoring the station; check its status before retrying.';
                if (this.state === 'reconnecting') return 'Connection interrupted. Waiting for the station to return…';
                if (this.state === 'online') return 'The station is online after a new boot. Open it to continue.';
                if (this.state === 'offline') return 'The connection to the station is unavailable. Allow shutdown to finish; to start again, cycle PoE or external power.';
                if (this.state === 'signin') return 'The station answers. Sign in again to check its status.';
                if (this.state === 'timeout') return 'The power action was not confirmed within two minutes. Check the station before trying again.';
                return '';
            },
            destroy: function () {
                if (this.timer !== null) env.stop(this.timer);
                this.timer = null; this.busy = false; this.generation++;
            }
        };
    }
    return {create: create};
}));
