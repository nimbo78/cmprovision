(() => {
    'use strict';

    const fileLimit = 1024 * 1024;
    const encoder = new TextEncoder();

    async function readConfiguration(file) {
        if (file.size > fileLimit) throw new Error('Choose a configuration of 1 MiB or less.');
        const bytes = await file.arrayBuffer();
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
        } catch {
            throw new Error('Choose a UTF-8 text file.');
        }
    }

    function transferControls(job) {
        switch (job.stage) {
        case 'review': return ['start', 'clear'];
        case 'stopped':
        case 'done': return ['clear'];
        case 'question': {
            const answers = { yesno: ['yes', 'no'], enter: ['enter'], pager: ['space'], filename: ['filename'] };
            return [...(answers[job.answer_kind] || []), 'stop'];
        }
        case 'error': return ['correct', 'stop'];
        case 'between':
            if (job.hold || job.mode === 'step') return ['next', 'stop'];
            return ['pause', 'stop'];
        case 'awaiting':
            return job.mode !== 'step' && !job.hold ? ['pause', 'stop'] : ['stop'];
        default: return ['stop'];
        }
    }

    function progressAfterDisconnect(progress, reportedByBrowser = false) {
        if (!progress) return '';
        const parts = [reportedByBrowser ? 'Last update received by this browser.' : 'Transfer stopped.'];
        if (progress.last_done_line) parts.push('Last confirmed file line: ' + progress.last_done_line + '.');
        if (progress.uncertain_line) parts.push('Verify file line ' + progress.uncertain_line + ' on the device before resuming.');
        return parts.join(' ');
    }

    // The lease, cursor, pending writes and RX callbacks belong to exactly one opening.
    // Closing detaches its queue immediately, even if the network reply arrives later.
    class SerialSession {
        constructor(request, receive = () => {}) {
            this.request = request;
            this.receive = receive;
            this.lease = null;
        }

        get active() { return Boolean(this.lease); }

        async open(settings) {
            if (this.active) return;
            const lease = { token: null, seq: 0, cursor: 0, queue: Promise.resolve(), pendingBytes: 0 };
            this.lease = lease;
            try {
                const reply = await this.request('session/open', settings);
                if (!reply.session) throw new Error('The station did not open a session.');
                if (this.lease !== lease) {
                    await this.release(reply.session);
                    return;
                }
                lease.token = reply.session;
            } catch (error) {
                if (this.lease !== lease) return;
                this.lease = null;
                throw error;
            }
        }

        async release(token) {
            if (token) {
                // The broker independently expires the lease if the browser has lost its connection.
                try { await this.request('session/close', { session: token }, true); } catch {}
            }
        }

        close() {
            const token = this.lease?.token;
            this.lease = null;
            return this.release(token);
        }

        enqueue(op, payload, mutation) {
            const lease = this.lease;
            if (!lease?.token) return Promise.resolve();
            const result = lease.queue.then(async () => {
                if (lease !== this.lease) return;
                const body = { ...payload, session: lease.token };
                if (mutation) body.seq = ++lease.seq;
                if (op === 'poll') body.cursor = lease.cursor;
                try {
                    const reply = await this.request('session/' + op, body);
                    if (lease !== this.lease) return;
                    if (op === 'poll') {
                        const bytes = Uint8Array.from(atob(reply.data || ''), char => char.charCodeAt(0));
                        if (reply.next_cursor !== lease.cursor + bytes.length) throw new Error('The station lost terminal output.');
                        lease.cursor = reply.next_cursor;
                        if (bytes.length) this.receive(bytes);
                    }
                    return reply;
                } catch (error) {
                    if (lease !== this.lease) return;
                    if (mutation && error.code === 'invalid' && error.seqConsumed === false) lease.seq--;
                    if (error.code !== 'invalid') await this.close();
                    throw error;
                }
            });
            lease.queue = result.catch(() => {});
            return result;
        }

        read(op, payload = {}) { return this.enqueue(op, payload, false); }
        mutate(op, payload) { return this.enqueue(op, payload, true); }
        poll() { return this.read('poll'); }

        async write(text) {
            const lease = this.lease;
            if (!lease?.token) return;
            const bytes = encoder.encode(text);
            if (lease.pendingBytes + bytes.length > 65536) {
                await this.close();
                throw new Error('Input exceeded the terminal buffer. Reconnect and review the device state.');
            }
            lease.pendingBytes += bytes.length;
            try {
                const chunks = [];
                for (let offset = 0; offset < bytes.length; offset += 4096) {
                    const data = btoa(String.fromCharCode(...bytes.subarray(offset, offset + 4096)));
                    chunks.push(this.mutate('write', { data }));
                }
                await Promise.all(chunks);
            } finally {
                lease.pendingBytes -= bytes.length;
            }
        }
    }

    function consoleRequest(base, csrf, send = fetch) {
        return async (op, payload, keepalive = false) => {
            const writing = payload !== undefined;
            const headers = { Accept: 'application/json' };
            if (writing) {
                headers['Content-Type'] = 'application/json';
                headers['X-CSRF-TOKEN'] = csrf;
            }
            const response = await send(base + '/' + op, {
                method: writing ? 'POST' : 'GET', headers, credentials: 'same-origin',
                cache: 'no-store', redirect: 'error', keepalive,
                signal: AbortSignal.timeout(8000), body: writing ? JSON.stringify(payload) : undefined,
            });
            let reply;
            try { reply = await response.json(); } catch {}
            if (!reply || !response.ok || reply.ok === false) {
                const error = new Error(reply?.message || 'The station could not complete the request.');
                error.code = reply?.error;
                error.seqConsumed = reply?.seq_consumed;
                error.status = response.status;
                throw error;
            }
            return reply;
        };
    }

    if (typeof module !== 'undefined') module.exports = { SerialSession, transferControls, readConfiguration, consoleRequest, progressAfterDisconnect };
    if (typeof document === 'undefined') return;
    const root = document.getElementById('serial-console');
    if (!root) return;

    const el = name => document.getElementById('sc-' + name);
    const show = (name, visible) => { el(name).hidden = !visible; };
    const put = (name, value) => {
        const text = String(value ?? '');
        if (el(name).textContent !== text) el(name).textContent = text;
    };
    const request = consoleRequest(root.dataset.base, document.querySelector('meta[name=csrf-token]').content);
    const session = new SerialSession(request, bytes => terminal().write(bytes));
    const stateLabels = { connected: 'Connected', detecting: 'Detecting', listening: 'Listening', waiting: 'Waiting for data', busy: 'In use', available: 'Available' };
    const settings = new Map();
    let ports = [], selected = null, snapshot = null, pane = 'terminal', portListKey;
    let screen, pollTimer, inventoryTimer, inventoryRun = 0, revision = 0, suspended = false, authExpired = false;
    let openedAs, picker = false, working = false, fileEpoch = 0, jobKey, controlsKey, page = 0, pageCount = 0;
    let nativePicker = null, returnedFile = null;
    let inputEvent = null;
    // xterm also emits onData for terminal queries. Forward only a user's
    // active input dispatch; a completed DOM event has eventPhase NONE.
    for (const type of ['keydown', 'keypress', 'input', 'compositionend', 'paste']) {
        el('screen').addEventListener(type, event => {
            inputEvent = event;
            setTimeout(() => { if (inputEvent === event) inputEvent = null; }, 0);
        }, true);
    }

    const port = () => ports.find(item => item.id === selected);
    const job = () => snapshot?.transfer;
    const connected = () => session.active && snapshot?.state === 'connected';
    const manualAllowed = () => connected() && (!job() || ['review', 'done', 'stopped'].includes(job().stage));
    const stamp = value => {
        if (!value) return '';
        const date = new Date(typeof value === 'number' ? value * 1000 : value);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    };
    const usb = p => [p.usb ? 'USB ' + p.usb : p.id, p.driver].filter(Boolean).join(' · ');
    const portName = p => (p.id === selected && snapshot?.hostname) || p.label ||
        (p.id === selected && session.active ? '' : p.hostname) || usb(p);
    const lineSettings = () => settings.get(selected) || { baud: 'auto', frame: '8n1' };
    const state = p => p.id === selected && session.active ? (snapshot?.state || 'detecting') : p.busy ? 'busy' : 'available';
    const button = (caption, action, primary = false) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = primary ? 'cmp-btn' : 'cmp-btn cmp-btn-secondary';
        b.textContent = caption;
        b.dataset.action = action;
        return b;
    };

    function theme() {
        const style = getComputedStyle(root);
        return { background: style.getPropertyValue('--log').trim(), foreground: style.getPropertyValue('--log-text').trim(),
            cursor: style.getPropertyValue('--log-text').trim() };
    }

    function fit() {
        if (!screen || !el('screen').clientWidth || !el('screen').clientHeight) return;
        const cell = el('screen').querySelector('.xterm-screen');
        if (!cell?.clientWidth || !cell.clientHeight) return;
        const style = getComputedStyle(el('screen'));
        const width = el('screen').clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
        const height = el('screen').clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
        const viewport = el('screen').querySelector('.xterm-viewport');
        const scrollbar = viewport ? viewport.offsetWidth - viewport.clientWidth : 0;
        const columns = Math.max(2, Math.floor((width - scrollbar) / (cell.clientWidth / screen.cols)));
        const rows = Math.max(1, Math.floor(height / (cell.clientHeight / screen.rows)));
        if (columns !== screen.cols || rows !== screen.rows) screen.resize(columns, rows);
    }

    function terminal() {
        if (screen) return screen;
        screen = new window.Terminal({ cursorBlink: true, scrollback: 1500, fontSize: 13, logLevel: 'off',
            theme: theme(), fontFamily: 'ui-monospace, Consolas, monospace', linkHandler: { activate() {} } });
        screen.open(el('screen'));
        screen.onData(text => {
            if (inputEvent?.isTrusted && inputEvent.eventPhase !== Event.NONE && manualAllowed() && !working && pane === 'terminal') {
                handle(() => session.write(text));
            }
        });
        return screen;
    }

    new ResizeObserver(fit).observe(el('screen'));
    new MutationObserver(() => { if (screen) screen.options.theme = theme(); })
        .observe(document.documentElement, { attributes: true, attributeFilter: ['data-mode', 'data-style', 'class'] });

    function clearFile() {
        fileEpoch++;
        picker = working = false;
        jobKey = controlsKey = undefined;
        page = pageCount = 0;
        el('file').value = el('response').value = '';
        el('mode').value = 'bulk';
        el('default').checked = false;
        put('filename', ''); put('notice', ''); put('progress-text', '');
        el('rows').replaceChildren();
        el('file-actions').replaceChildren();
    }

    function reset() {
        revision++;
        clearTimeout(pollTimer);
        const closing = session.close();
        inputEvent = null;
        snapshot = openedAs = null;
        screen?.dispose();
        screen = null;
        el('screen').replaceChildren();
        clearFile();
        render();
        return closing;
    }

    function report(error) {
        if (suspended) return;
        const activeJob = job();
        const disconnected = error instanceof TypeError || error.name === 'TimeoutError' || !session.active;
        const last = activeJob && !['review', 'stopped', 'done'].includes(activeJob.stage) && disconnected
            ? progressAfterDisconnect({last_done_line: activeJob.last_done_line,
                uncertain_line: ['awaiting', 'question', 'uncertain'].includes(activeJob.stage) ? activeJob.line : null}, true) : '';
        const denied = [401, 403, 419, 423].includes(error.status);
        if (denied) {
            authExpired = true;
            clearTimeout(inventoryTimer);
            reset();
            show('auth', true);
        } else if (!session.active && snapshot) reset();
        const message = denied ? 'Console access requires a new sign-in or password confirmation.' :
            disconnected && (error instanceof TypeError || error.name === 'TimeoutError') ? 'Station connection lost. Check the device before reconnecting.' : error.message;
        put('message', [message, last].filter(Boolean).join(' '));
        render();
    }

    async function handle(fn) {
        const view = revision;
        try { await fn(); } catch (error) { if (view === revision) report(error); }
    }

    function listPorts() {
        const key = JSON.stringify(ports.map(p => [p.id, p.generation, p.label, p.hostname, p.seen_at, usb(p), state(p), p.id === selected, portName(p)]));
        if (portListKey === key) return;
        portListKey = key;
        const focusId = document.activeElement?.dataset.port;
        el('list').replaceChildren();
        el('port').replaceChildren();
        for (const p of ports) {
            const item = document.createElement('li');
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'sc-port-button'; b.dataset.port = p.id;
            b.setAttribute('aria-pressed', String(p.id === selected));
            const name = document.createElement('strong');
            name.textContent = portName(p);
            const meta = document.createElement('small');
            meta.textContent = usb(p);
            const status = document.createElement('span');
            status.className = 'sc-status'; status.dataset.state = state(p); status.textContent = stateLabels[state(p)] || 'Unavailable';
            const seen = document.createElement('small');
            seen.textContent = p.hostname ? [p.hostname, stamp(p.seen_at)].filter(Boolean).join(' · last seen ') : 'Hostname unknown';
            b.append(name, meta, status, seen);
            item.append(b); el('list').append(item);
            if (focusId === p.id) b.focus();
            el('port').add(new Option(portName(p) + ' · ' + status.textContent, p.id, false, p.id === selected));
        }
    }

    function render() {
        const p = port();
        show('empty', !p); show('workspace', Boolean(p));
        put('count', ports.length);
        listPorts();
        if (!p) return;
        const status = state(p), available = status === 'available' && !authExpired && Boolean(window.Terminal);
        const waiting = status === 'waiting';
        put('target', portName(p));
        const past = p.hostname && !snapshot?.hostname ? ' · ' + p.hostname + ' last seen ' + stamp(p.seen_at) : '';
        put('meta', usb(p) + past);
        put('badge', stateLabels[status] || status);
        el('badge').dataset.state = status;
        show('disconnect', session.active); show('terminal-pane', pane === 'terminal'); show('details', pane === 'details');
        el('terminal-tab').setAttribute('aria-pressed', String(pane === 'terminal'));
        el('details-tab').setAttribute('aria-pressed', String(pane === 'details'));
        show('screen', connected()); show('placeholder', !connected());
        const placeholder = status === 'busy' ? ['Port in use', 'The adapter will be available when the other session closes.'] :
            waiting ? ['Waiting for serial data', 'For a silent device, choose its baud rate and format to open a manual terminal.'] :
            status === 'listening' ? ['Listening to the console', 'The terminal will open in a moment.'] :
            status === 'detecting' ? [openedAs === 'identify' ? 'Listening for a hostname' : 'Detecting line settings', 'Listening for incoming serial data.'] :
            ['Open a serial session', 'Connect to open the terminal, or listen for a hostname first.'];
        put('placeholder-title', placeholder[0]); put('placeholder-help', placeholder[1]);
        show('connect', available); show('identify', available); show('manual', waiting);
        show('paste-file', connected() && !picker && !job());
        el('paste-file').disabled = !snapshot?.ready;
        el('paste-file').title = snapshot?.ready ? '' : 'Open an authenticated Cisco or Huawei prompt first';
        show('redetect', (waiting || connected()) && !picker && !job());
        const observed = snapshot?.hostname || p.hostname;
        const observedAt = stamp(snapshot?.hostname ? snapshot.seen_at : p.seen_at);
        put('connection', usb(p)); put('path', p.id);
        put('hostname', observed ? observed + (snapshot?.hostname ? ' · prompt received' : ' · last seen') + ' ' + observedAt : 'Unknown');
        put('platform', snapshot?.platform || 'Unknown');
        show('version', manualAllowed() && !working && ['show version', 'display version'].includes(snapshot?.version_command));
        const settings = lineSettings();
        const baud = snapshot?.baud || settings.baud;
        put('settings-summary', baud === 'auto' ? 'Baud & format · Auto' : baud + ' baud · ' + String(snapshot?.frame || settings.frame).toUpperCase());
        show('transfer', connected() && (picker || Boolean(job())));
        show('picker', !job()); show('job', Boolean(job()));
        el('file-close').disabled = working;
        el('file').disabled = working;
        if (!job()) put('file-close', 'Close');
        if (job()) renderJob(job());
        if (connected() && pane === 'terminal') {
            terminal().options.disableStdin = !manualAllowed() || working;
            fit();
        }
    }

    function renderJob(transfer) {
        const { stage, line, total, done, mode, hold, text, answer_kind: kind } = transfer;
        const labels = { review: 'Review before sending', awaiting: 'Waiting for device', question: 'Device needs an answer',
            error: 'Command rejected', uncertain: 'Check device response', done: 'End of file', stopped: 'Stopped',
            between: hold || mode === 'step' ? 'Paused before next line' : 'Sending' };
        put('filename', transfer.file);
        put('progress-text', [labels[stage], done + ' / ' + total + ' returned to prompt',
            line ? 'File line ' + line : '', transfer.auto_answers ? 'Auto-answered ' + transfer.auto_answers : '',
            transfer.pages ? 'Pages advanced ' + transfer.pages : ''].filter(Boolean).join(' · '));
        el('progress').max = total || 1; el('progress').value = done || 0;
        const needsVersion = stage === 'review' && el('mode').value !== 'step' && snapshot.smart_ready === false && snapshot.ready;
        put('notice', transfer.notice || (needsVersion ? 'Run display version on this device to send automatically, or select Step.' :
            stage === 'review' && !snapshot.ready ? 'Open an authenticated Cisco IOS or Huawei command prompt before sending.' : ''));
        show('review', stage === 'review');
        const allowDefault = stage === 'review' && transfer.default_line && el('mode').value === 'smart';
        show('default-choice', Boolean(allowDefault));
        if (allowDefault) put('default-label', 'Line ' + transfer.default_line + ' · Send Enter once for “Destination filename [startup-config]?”');
        const controls = transferControls(transfer);
        const key = JSON.stringify([stage, line, text, kind]);
        const edit = controls.includes('correct'), filename = controls.includes('filename');
        show('response-field', edit || filename);
        if (key !== jobKey) {
            jobKey = key;
            el('response').value = edit ? text || '' : '';
        }
        if (edit || filename) put('response-label', edit ? 'Edit line ' + line : 'File name (blank accepts device default)');
        const closing = controls.includes('clear') ? 'clear' : 'stop';
        put('file-close', closing === 'clear' ? 'Close' : 'Stop');
        el('file-close').disabled = working;
        const titles = { yes: 'Send yes', no: 'Send no', enter: 'Send Enter', space: 'Send Space',
            filename: 'Send file name', correct: 'Send corrected line', next: mode === 'step' ? 'Send next line' : 'Resume',
            pause: 'Pause after this command', start: 'Start sending' };
        const buttons = controls.filter(action => action !== closing);
        const buttonKey = JSON.stringify([buttons, mode]);
        if (controlsKey !== buttonKey) {
            controlsKey = buttonKey;
            el('file-actions').replaceChildren(...buttons.map(action => button(titles[action], action, ['start', 'next'].includes(action))));
        }
        for (const b of el('file-actions').children) b.disabled = working || (b.dataset.action === 'start' && (!snapshot.ready || needsVersion));
        el('response').disabled = working;
        el('prev').disabled = working || page <= 0;
        el('next-page').disabled = working || page + 1 >= pageCount;
    }

    function select(id) {
        nativePicker = returnedFile = null;
        reset();
        selected = id;
        pane = 'terminal';
        el('label').value = port()?.label || '';
        el('wake').checked = Boolean(port()?.wake_on_connect);
        const config = lineSettings();
        el('baud').value = String(config.baud); el('frame').value = config.frame;
        el('frame').disabled = config.baud === 'auto';
        el('save').disabled = false;
        put('message', '');
        render();
    }

    async function inventory() {
        clearTimeout(inventoryTimer);
        const run = ++inventoryRun;
        try {
            const result = await request('ports');
            if (run !== inventoryRun || suspended || authExpired) return;
            const old = port();
            ports = result.ports;
            const current = port();
            if (!current || !old || old.generation !== current.generation) {
                if (current && old?.generation !== current.generation) settings.delete(current.id);
                select(current?.id || ports[0]?.id || null);
                if (old) put('message', 'USB adapter changed. The previous session was released.');
            } else {
                if (el('wake').checked === Boolean(old.wake_on_connect)) el('wake').checked = Boolean(current.wake_on_connect);
                if (el('label').value === (old.label || '')) el('label').value = current.label || '';
                render();
            }
            put('empty-title', 'No USB serial adapters');
            put('empty-help', 'Connect an FTDI or PL2303 adapter to the station.');
            if (!window.Terminal) put('message', 'Terminal assets are unavailable. Reload the console after the station package is repaired.');
        } catch (error) {
            if (run !== inventoryRun || suspended) return;
            put('empty-title', 'USB inventory unavailable');
            put('empty-help', 'The station could not list its adapters.');
            report(error);
        } finally {
            if (run === inventoryRun && !suspended && !authExpired) inventoryTimer = setTimeout(inventory, 3000);
        }
    }

    async function poll(view) {
        const next = await session.poll();
        if (view !== revision || !next) return;
        if (['closed', 'identified', 'busy', 'error'].includes(next.state)) {
            if (next.hostname && port()) {
                port().hostname = next.hostname; port().seen_at = next.seen_at;
            }
            reset();
            const message = next.message || (next.state === 'identified' ? 'Identification finished. Port released.' : 'Session closed.');
            put('message', [message, progressAfterDisconnect(next.final_progress)].filter(Boolean).join(' '));
            inventory();
            return;
        }
        const first = !connected() && next.state === 'connected';
        if (job() && !next.transfer) clearFile();
        snapshot = next;
        render();
        if (first && pane === 'terminal') terminal().focus();
        if (connected() && next.ready && returnedFile) {
            const file = returnedFile;
            returnedFile = null;
            picker = true;
            await handle(() => preview(() => readConfiguration(file), file.name));
        }
    }

    async function tick(view) {
        try { await poll(view); } catch (error) { if (view === revision) report(error); }
        if (view === revision && session.active && !suspended) pollTimer = setTimeout(() => tick(view), 350);
    }

    async function open(mode, forceAuto = false, explicitConnect = false) {
        const p = port(), view = revision;
        if (!p || p.busy || session.active || authExpired || suspended) return;
        const manual = !forceAuto && mode === 'connect' && lineSettings().baud !== 'auto';
        openedAs = mode;
        put('message', returnedFile ? 'Open an authenticated device prompt to review your selected file.' : '');
        const options = { port: p.id, generation: p.generation, mode,
            baud: manual ? Number(lineSettings().baud) : 'auto', frame: manual ? lineSettings().frame : 'auto' };
        if (explicitConnect) options.wake = Boolean(p.wake_on_connect);
        const opening = session.open(options);
        render();
        await opening;
        if (view === revision && session.active) await tick(view);
    }

    async function reconnect(forceAuto) {
        const generation = port()?.generation;
        const closing = reset(), view = revision;
        await closing;
        if (view !== revision) return;
        await inventory();
        if (view !== revision || port()?.generation !== generation) return;
        if (port().busy) {
            put('message', 'The port is still in use. Connect again once it is available.');
            return;
        }
        await handle(() => open('connect', forceAuto));
    }

    async function loadPage(number, view) {
        const epoch = fileEpoch;
        const result = await session.read('file_page', { page: number });
        if (!result || view !== revision || epoch !== fileEpoch || job()?.stage !== 'review') return;
        page = number; pageCount = result.pages;
        const rows = result.rows.map(row => {
            const li = document.createElement('li'), index = document.createElement('span'), code = document.createElement('code');
            index.textContent = row.num; code.textContent = row.line;
            li.append(index, code); return li;
        });
        el('rows').replaceChildren(...rows);
        show('pagination', pageCount > 1);
        put('page-number', (page + 1) + ' / ' + pageCount);
        render();
    }

    async function fileOperation(fn) {
        if (working || !connected()) return;
        const view = revision, epoch = fileEpoch;
        working = true;
        put('message', ''); render();
        try { await fn(view); } finally {
            if (view === revision && epoch === fileEpoch) { working = false; render(); }
        }
    }

    async function preview(getText, filename) {
        await fileOperation(async view => {
            const epoch = fileEpoch;
            const text = await getText();
            if (epoch !== fileEpoch || view !== revision) return;
            if (encoder.encode(text).length > fileLimit) throw new Error('Choose a configuration of 1 MiB or less.');
            await session.mutate('file_preview', { text, filename });
            await poll(view);
            if (view === revision && job()?.stage === 'review') await loadPage(0, view);
        });
    }

    async function action(name) {
        if (!job() || !transferControls(job()).includes(name)) return;
        const body = { action: name };
        if (name === 'start') {
            body.mode = el('mode').value;
            body.allow_default = body.mode === 'smart' && Boolean(job().default_line) && el('default').checked;
        }
        if (name === 'filename' || name === 'correct') body.value = el('response').value;
        await fileOperation(async view => {
            await session.mutate('file_action', body);
            await poll(view);
        });
    }

    function changePane(next) {
        if (pane === next) return;
        const stage = job()?.stage;
        const finished = stage === 'done' || stage === 'stopped';
        if (!finished && (working || (stage && stage !== 'review'))) {
            reset();
            put('message', 'File closed and port released. Reconnect to continue.');
        }
        pane = next;
        render();
    }

    el('list').addEventListener('click', event => {
        const b = event.target.closest('[data-port]');
        if (b && selected !== b.dataset.port) select(b.dataset.port);
    });
    el('port').addEventListener('change', () => select(el('port').value));
    el('terminal-tab').onclick = () => changePane('terminal');
    el('details-tab').onclick = () => changePane('details');
    el('line-settings').onclick = el('manual').onclick = () => { changePane('details'); el('baud').focus(); };
    el('connect').onclick = () => handle(() => open('connect', false, true));
    el('identify').onclick = () => handle(() => open('identify'));
    el('disconnect').onclick = () => {
        returnedFile = nativePicker = null;
        reset().then(inventory); put('message', 'Session disconnected.');
    };
    el('redetect').onclick = () => reconnect(true);
    el('version').onclick = () => {
        const command = snapshot?.version_command;
        if (!manualAllowed() || !['show version', 'display version'].includes(command)) return;
        pane = 'terminal'; render(); terminal().focus();
        handle(() => session.write(command + '\r'));
    };
    el('baud').onchange = () => { el('frame').disabled = el('baud').value === 'auto'; };
    el('form').onsubmit = event => {
        event.preventDefault();
        const p = port(), view = revision;
        if (!p || el('save').disabled || authExpired) return;
        const label = el('label').value.trim(), config = { baud: el('baud').value, frame: el('frame').value };
        const changes = {};
        if (label !== (p.label || '')) changes.label = label;
        if (el('wake').checked !== Boolean(p.wake_on_connect)) changes.wake_on_connect = el('wake').checked;
        const resumeManual = session.active && snapshot?.state === 'waiting' && config.baud !== 'auto';
        el('save').disabled = true;
        handle(async () => {
            try {
                if (Object.keys(changes).length) await request('ports/label', { port: p.id, generation: p.generation, ...changes });
                if (view !== revision) return;
                Object.assign(port(), changes);
                settings.set(p.id, config);
                pane = 'terminal';
                if (resumeManual) await reconnect(false);
                else { put('message', 'Line settings saved.'); render(); }
            } finally { if (selected === p.id) el('save').disabled = false; }
        });
    };
    el('paste-file').onclick = () => {
        if (!connected() || !snapshot.ready || job()) return;
        returnedFile = null;
        picker = true; render(); el('file').focus();
    };
    // Some mobile file pickers background the browser. Release the UART as usual.
    // A file returned by that picker can be reviewed after an explicit reconnection.
    el('file').onclick = () => { nativePicker = port()?.generation; };
    el('file').oncancel = () => { nativePicker = null; };
    el('file').onchange = () => {
        const file = el('file').files[0];
        const sameCable = nativePicker && nativePicker === port()?.generation;
        nativePicker = null;
        el('file').value = '';
        if (!file) return;
        if (connected() && picker) handle(() => preview(() => readConfiguration(file), file.name));
        else if (sameCable && file.size <= fileLimit) {
            returnedFile = file;
            put('message', 'File selected. Reconnect to this port and open an authenticated device prompt to review it.');
        } else if (sameCable) put('message', 'Choose a configuration of 1 MiB or less.');
    };
    // Capture paste before xterm can normalize or send it as manual input.
    el('screen').addEventListener('paste', event => {
        const text = event.clipboardData?.getData('text/plain');
        if (!text || !/[\r\n]/.test(text)) return;
        event.preventDefault(); event.stopImmediatePropagation();
        if (!connected() || working || job()) return;
        if (!snapshot.ready) {
            put('message', 'Open an authenticated Cisco or Huawei prompt before pasting multiple lines.');
            return;
        }
        picker = true; render();
        handle(() => preview(() => text, 'Clipboard'));
    }, true);
    el('mode').onchange = render;
    el('file-actions').onclick = event => {
        const b = event.target.closest('[data-action]');
        if (b && !b.disabled) handle(() => action(b.dataset.action));
    };
    el('file-close').onclick = () => {
        if (job()) handle(() => action(transferControls(job()).includes('clear') ? 'clear' : 'stop'));
        else { clearFile(); render(); }
    };
    el('prev').onclick = () => handle(() => fileOperation(view => loadPage(page - 1, view)));
    el('next-page').onclick = () => handle(() => fileOperation(view => loadPage(page + 1, view)));
    function suspend() {
        if (suspended) return;
        suspended = true; inventoryRun++; clearTimeout(inventoryTimer);
        returnedFile = null;
        const wasActive = session.active;
        reset();
        if (wasActive) put('message', 'Port released when you left the console.');
    }
    function resume() {
        if (!suspended || document.hidden) return;
        suspended = false;
        if (!authExpired) inventory();
    }
    window.addEventListener('pagehide', () => { nativePicker = returnedFile = null; suspend(); });
    window.addEventListener('pageshow', resume);
    document.addEventListener('visibilitychange', () => document.hidden ? suspend() : resume());
    if (document.hidden) suspend(); else inventory();
})();
