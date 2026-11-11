(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else {
        root.StationUpdatesStatus = factory();
        root.StationUpdatesStatus.start(root);
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    var terminal = ['ready', 'succeeded', 'rolled-back', 'failed', 'canceled'];
    var hooked = new WeakSet();
    function start(win) {
        function install() {
            var livewire = win.Livewire;
            if (!livewire || hooked.has(livewire)) return;
            hooked.add(livewire);
            livewire.hook('request', function ({ respond, fail }) {
                var maintenance = false;
                respond(function ({ response }) {
                    var marker = response.headers.get('X-Cmprovision-Maintenance');
                    maintenance = response.status === 503 && ['station-update', 'station-backup'].includes(marker);
                    if (maintenance && marker === 'station-backup') win.document.dispatchEvent?.(new Event('cmprovision-backup-maintenance'));
                });
                fail(function ({ status, preventDefault }) {
                    // Keep authentication and unexpected server errors visible. Never
                    // retry the interrupted request: it may have contained a mutation.
                    if (status === 503 && maintenance) preventDefault();
                });
            });
        }
        if (win.Livewire) install();
        else win.document.addEventListener('livewire:init', install, { once: true });
    }
    function create(url, initial) {
        return {
            operation: initial.operation || null, reconnecting: false, signin: false, timer: null, destroyed: false,
            sequence: initial.operation?.updated_at || 0,
            busy: function () { return !!this.operation && !terminal.includes(this.operation.state); },
            canCancel: function () { return !!this.operation && ['queued', 'checking', 'downloading', 'verifying'].includes(this.operation.state); },
            percent: function () { return this.operation?.total > 0 ? Math.min(100, Math.floor(this.operation.downloaded * 100 / this.operation.total)) : 0; },
            bytes: function () { return Math.round((this.operation?.downloaded || 0) / 1048576) + ' / ' + Math.round((this.operation?.total || 0) / 1048576) + ' MiB'; },
            message: function () { return this.signin ? 'Sign in again to view update status. The operation continues independently; do not apply again.' : (this.reconnecting ? 'Reconnecting to the station… The operation continues independently; do not apply again.' : (this.operation?.message || this.operation?.state || '')); },
            track: function (id) {
                if (id) { try { sessionStorage.setItem('cmprovision-update:' + url, id); } catch (_) {} }
                this.poll();
            },
            init: function () { return this.poll(); },
            destroy: function () { this.destroyed = true; clearTimeout(this.timer); },
            poll: async function () {
                clearTimeout(this.timer);
                try {
                    var response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin', signal: AbortSignal.timeout(10000) });
                    this.signin = response.status === 401;
                    if (!response.ok) throw new Error('reconnecting');
                    var state = await response.json();
                    if (state.schema !== 1) throw new Error('invalid status');
                    var changed = this.operation?.id !== state.operation?.id || this.operation?.state !== state.operation?.state;
                    this.operation = state.operation || null;
                    this.reconnecting = false;
                    this.signin = false;
                    if (changed && !this.busy() && !state.pending) {
                        try { sessionStorage.removeItem('cmprovision-update:' + url); } catch (_) {}
                        if (this.$wire) this.$wire.$refresh();
                    }
                } catch (_) { this.reconnecting = true; }
                if (!this.destroyed) this.timer = setTimeout(() => this.poll(), this.busy() || this.reconnecting ? 2000 : 10000);
            }
        };
    }
    return { create: create, start: start };
}));
