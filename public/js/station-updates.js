(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.StationUpdatesStatus = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    var terminal = ['ready', 'succeeded', 'rolled-back', 'failed', 'canceled'];
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
            start: function () { this.poll(); },
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
    return { create: create };
}));
