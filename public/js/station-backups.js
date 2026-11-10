(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.StationBackups = factory();
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';
    const secrets = ['password', 'key_pem', 'key_password', 'access_key', 'secret_key', 'session_token', 'ca_pem'];
    const terminal = ['ready', 'succeeded', 'failed', 'canceled'];
    function create(url, initial) {
        return {
            status: initial, operation: initial.operation || null, screen: 'overview', master: '', profile: {}, schedule: {},
            target: 'download', images: false, packages: false, reconnecting: false, signin: false, timer: null, destroyed: false, submitting: false,
            busy() { return !!this.operation && !terminal.includes(this.operation.state); },
            percent() { return this.operation?.total > 0 && ['archiving', 'uploading', 'verifying'].includes(this.operation.state)
                ? Math.min(100, Math.floor(this.operation.bytes * 100 / this.operation.total)) : null; },
            init() { this.poll(); },
            destroy() { this.destroyed = true; clearTimeout(this.timer); this.master = ''; this.profile = {}; },
            accept(value) { if (value?.schema === 1) { this.status = value; this.operation = value.operation || null; } },
            back() { this.master = ''; this.profile = {}; this.screen = 'overview'; if (this.$wire) this.$wire.back(); },
            async startBackup() {
                if (this.submitting || this.busy()) return;
                this.submitting = true;
                try {
                    if (this.operation && terminal.includes(this.operation.state)) await this.$wire.back();
                    await this.$wire.create(this.target, { images: this.images, packages: this.packages });
                } finally { this.submitting = false; }
            },
            edit(saved = null) {
                this.profile = { type: 'sftp', name: '', host: '', port: 22, user: '', path: '/backups', keep: 7,
                    auth: 'password', host_key: '', mode: 'explicit', plain_ack: false, bucket: '', region: 'us-east-1',
                    endpoint: '', path_style: true, clear: [] };
                if (saved) for (const [key, value] of Object.entries(saved)) if (!key.startsWith('has_') && !secrets.includes(key)) this.profile[key] = value;
                for (const key of secrets) this.profile[key] = '';
                this.screen = 'profile';
            },
            editSchedule() {
                this.schedule = Object.assign({ enabled: false, frequency: 'daily', time: '03:00', weekday: 0, profile: null, keep: 7, images: false, packages: false }, this.status.schedule || {});
                delete this.schedule.not_before;
                this.screen = 'schedule';
            },
            async poll() {
                clearTimeout(this.timer);
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000) });
                    this.signin = response.status === 401;
                    if (!response.ok) throw new Error('reconnect');
                    const value = await response.json();
                    if (value.schema !== 1) throw new Error('invalid');
                    this.accept(value); this.reconnecting = false; this.signin = false;
                } catch (_) { this.reconnecting = true; }
                if (!this.destroyed) this.timer = setTimeout(() => this.poll(), this.busy() || this.reconnecting ? 2000 : 10000);
            }
        };
    }
    return { create };
}));
