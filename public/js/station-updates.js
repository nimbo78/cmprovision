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
    function belongsTo(operation, component) {
        if (!operation) return false;
        if (operation.component) return operation.component === component;
        if (operation.components?.length) return operation.components.includes(component);
        return true; // Older station-wide checks did not record components.
    }
    function create(url, initial, timezone) {
        return {
            operation: initial.operation || null, reconnecting: false, signin: false, timer: null, destroyed: false,
            history: Array.isArray(initial.history) ? initial.history : [], pending: initial.pending || null,
            now: Math.floor(Date.now() / 1000),
            sequence: initial.operation?.updated_at || 0,
            busy: function () { return !!this.operation && !terminal.includes(this.operation.state); },
            blocked: function () { return this.busy() || !!this.pending || this.reconnecting || this.signin; },
            canCancel: function () { return !this.reconnecting && !this.signin && !this.pending && !!this.operation && ['queued', 'checking', 'downloading', 'verifying'].includes(this.operation.state); },
            percent: function () { return this.operation?.total > 0 ? Math.min(100, Math.floor(this.operation.downloaded * 100 / this.operation.total)) : 0; },
            bytes: function () { return Math.round((this.operation?.downloaded || 0) / 1048576) + ' / ' + Math.round((this.operation?.total || 0) / 1048576) + ' MiB'; },
            connectionMessage: function () {
                if (this.signin) return 'Sign in again to view update status. The operation continues independently; do not apply again.';
                return this.reconnecting ? 'Reconnecting to the station… The operation continues independently; do not apply again.' : '';
            },
            message: function () { return [this.operation?.message || this.phaseLabel(this.operation), this.connectionMessage()].filter(Boolean).join(' '); },
            operationFor: function (component) { return belongsTo(this.operation, component); },
            showOperation: function () { return this.blocked() || ['failed', 'rolled-back', 'canceled'].includes(this.operation?.state); },
            historyFor: function (component, includeCurrent) {
                var seen = new Set();
                return this.history.filter((entry) => {
                    if (!entry || !belongsTo(entry, component) || (!includeCurrent && entry.id === this.operation?.id) || seen.has(entry.id)) return false;
                    seen.add(entry.id);
                    return true;
                });
            },
            operationLabel: function (operation) {
                if (!operation) return 'Station update';
                var action = { check: 'Check', download: 'Download', apply: 'Install', activate: 'Switch', rollback: 'Restore' }[operation.action] || 'Update';
                var components = operation.component ? [operation.component] : (operation.components || []);
                var names = components.map(part => ({ application: 'Application', 'utility-os': 'Utility OS' }[part])).filter(Boolean);
                return action + ' · ' + (names.join(' + ') || 'Station') + (operation.version ? ' ' + operation.version : '');
            },
            phaseLabel: function (operation) {
                if (!operation) return '';
                if (operation.state === 'preparing') return operation.action === 'rollback' ? 'Preparing restore' :
                    operation.component === 'application' ? 'Preparing recovery snapshot' : 'Preparing boot files';
                if (operation.state === 'applying') {
                    if (operation.action === 'rollback' || operation.diagnostic) return 'Restoring previous version';
                    return operation.component === 'utility-os' ? 'Activating boot files' : 'Installing packages';
                }
                return { queued: 'Queued', checking: 'Checking stable releases', downloading: 'Downloading files',
                    verifying: 'Verifying signature and files', ready: 'Downloaded and verified', reconnecting: 'Checking station health',
                    succeeded: 'Completed', 'rolled-back': 'Previous version restored', failed: 'Failed', canceled: 'Canceled',
                    'recovery-required': 'Recovery required', interrupted: 'Interrupted' }[operation.state] || 'Update status';
            },
            stages: function () {
                var op = this.operation;
                if (!op) return [];
                var labels, phases;
                var recovering = !!op.diagnostic && ['applying', 'rolled-back', 'recovery-required'].includes(op.state);
                if (op.action === 'check') { labels = ['Check']; phases = [['queued', 'checking']]; }
                else if (op.action === 'download') { labels = ['Download', 'Verify']; phases = [['queued', 'downloading'], ['verifying']]; }
                else if (op.action === 'rollback' || recovering) { labels = ['Restore and check']; phases = [['queued', 'preparing', 'applying', 'reconnecting']]; }
                else if (op.component === 'application') { labels = ['Snapshot', 'Install', 'Health check']; phases = [['queued', 'preparing'], ['applying'], ['reconnecting']]; }
                else { labels = ['Activate']; phases = [['queued', 'preparing', 'applying']]; }
                var failed = ['failed', 'recovery-required', 'interrupted'].includes(op.state);
                var phase = failed ? (op.diagnostic?.phase || op.state) : op.state;
                var index = phases.findIndex(group => group.includes(phase));
                if (index < 0) index = 0;
                return labels.map((label, i) => ({ label: label, state: ['succeeded', 'ready', 'rolled-back'].includes(op.state) ? 'done' :
                    failed ? (i === index ? 'failed' : 'next') : i < index ? 'done' : i === index ? 'current' : 'next' }));
            },
            elapsed: function () {
                var start = Number(this.operation?.created_at);
                if (!Number.isFinite(start) || start <= 0) return '';
                var seconds = Math.max(0, this.now - Math.floor(start));
                return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            },
            formatTime: function (timestamp) {
                var value = Number(timestamp);
                if (!Number.isFinite(value) || value <= 0) return 'Time not available';
                try { return new Intl.DateTimeFormat('en-GB', { timeZone: timezone || 'UTC', year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value * 1000)); }
                catch (_) { return 'Time not available'; }
            },
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
                    var changed = this.operation?.id !== state.operation?.id || this.operation?.state !== state.operation?.state || (!!this.pending && !state.pending);
                    this.operation = state.operation || null;
                    this.history = Array.isArray(state.history) ? state.history : [];
                    this.pending = state.pending || null;
                    this.reconnecting = false;
                    this.signin = false;
                    if (changed && !this.busy() && !state.pending) {
                        try { sessionStorage.removeItem('cmprovision-update:' + url); } catch (_) {}
                        if (this.$wire) this.$wire.$refresh();
                    }
                } catch (_) { this.reconnecting = true; }
                this.now = Math.floor(Date.now() / 1000);
                if (!this.destroyed) this.timer = setTimeout(() => this.poll(), this.blocked() ? 2000 : 10000);
            }
        };
    }
    return { create: create, start: start };
}));
