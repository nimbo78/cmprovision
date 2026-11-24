const { test } = require('node:test');
const assert = require('node:assert/strict');
const { SerialSession, transferControls, readConfiguration, consoleRequest, progressAfterDisconnect } = require('../../public/js/serial-console.js');

test('disconnect identifies original confirmed and uncertain file lines without echoing the configuration', () => {
    assert.equal(progressAfterDisconnect(null), '');
    const progress = { last_done_line: 3, uncertain_line: 7, text: 'private command' };
    assert.equal(progressAfterDisconnect(progress), 'Transfer stopped. Last confirmed file line: 3. Verify file line 7 on the device before resuming.');
    assert.match(progressAfterDisconnect(progress, true), /Last update received by this browser/);
});

function deferred() {
    let resolve;
    const promise = new Promise(done => { resolve = done; });
    return { promise, resolve };
}

function station(reply = () => ({})) {
    const calls = [];
    const output = [];
    const request = async (op, body) => {
        calls.push({ op, body });
        if (op === 'session/open') return { session: 'lease' };
        if (op === 'session/poll') return { state: 'connected', data: '', next_cursor: body.cursor };
        return reply(op, body);
    };
    const session = new SerialSession(request, data => output.push(...data));
    return { session, calls, output };
}

const manual = { port: 'by-path/usb-2', generation: 'gen', mode: 'connect', baud: 9600, frame: '8n1' };

test('a silent manual session accepts raw controls and preserves write order and chunk boundaries', async () => {
    const { session, calls } = station();
    await session.open(manual);
    await session.poll();
    await Promise.all([session.write('a'.repeat(4095) + 'é'), session.write('\r\x03\x1b[A')]);
    const writes = calls.filter(call => call.op === 'session/write').map(call => call.body);
    assert.deepEqual(calls[0].body, manual);
    assert.deepEqual(writes.map(write => write.seq), [1, 2, 3]);
    const chunks = writes.map(write => Buffer.from(write.data, 'base64'));
    assert.ok(chunks.every(chunk => chunk.length <= 4096));
    assert.equal(Buffer.concat(chunks).toString(), 'a'.repeat(4095) + 'é\r\x03\x1b[A');
});

test('poll advances byte cursors and discards an old reply after port switch', async () => {
    const late = deferred();
    const received = [];
    const cursors = [];
    let token = 'first';
    const session = new SerialSession(async (op, body) => {
        if (op === 'session/open') return { session: token };
        if (op === 'session/close') return {};
        cursors.push(body.cursor);
        if (cursors.length === 2) return late.promise;
        return { state: 'connected', data: 'AP8=', next_cursor: 2 };
    }, bytes => received.push(...bytes));
    await session.open(manual);
    await session.poll();
    const polling = session.poll();
    await new Promise(setImmediate);
    await session.close();
    token = 'second';
    await session.open({ ...manual, generation: 'replacement' });
    late.resolve({ state: 'connected', data: 'c2VjcmV0', next_cursor: 8 });
    await polling;
    await session.poll();
    assert.deepEqual(cursors, [0, 2, 0]);
    assert.deepEqual(received, [0, 255, 0, 255]);
});

test('a late open after leaving the page releases the returned lease', async () => {
    const opening = deferred();
    const closed = [];
    const session = new SerialSession((op, body) => {
        if (op === 'session/open') return opening.promise;
        closed.push(body.session);
        return Promise.resolve({});
    });
    const pending = session.open(manual);
    await session.close();
    opening.resolve({ session: 'abandoned' });
    await pending;
    assert.deepEqual(closed, ['abandoned']);
    assert.equal(session.active, false);
});

test('an ambiguous write is never replayed and cancels pending input', async () => {
    const { session, calls } = station(op => {
        if (op === 'session/write') throw new TypeError('network error');
        return {};
    });
    await session.open(manual);
    await session.poll();
    const results = await Promise.allSettled([session.write('one\r'), session.write('two\r')]);
    assert.equal(results[0].status, 'rejected');
    assert.equal(session.active, false);
    assert.deepEqual(calls.map(c => c.op), ['session/open', 'session/poll', 'session/write', 'session/close']);
});

test('preview and operator actions share mutation sequence while preview pages are read-only', async () => {
    const { session, calls } = station(op => op === 'session/file_page' ? { pages: 2, rows: [{ num: 28, line: 'show clock' }] } : {});
    await session.open(manual);
    await session.poll();
    await session.mutate('file_preview', { filename: 'run.txt', text: '\nshow clock' });
    assert.deepEqual((await session.read('file_page', { page: 1 })).rows, [{ num: 28, line: 'show clock' }]);
    await session.mutate('file_action', { action: 'start', mode: 'step', allow_default: false });
    await session.mutate('file_action', { action: 'filename', value: '' });
    await session.mutate('file_action', { action: 'correct', value: 'display clock' });
    assert.deepEqual(calls.slice(2).map(c => c.body.seq), [1, undefined, 2, 3, 4]);
    assert.equal(calls.at(-2).body.value, '');
});

test('operator sees only answers matching the device and can stop an uncertain transfer', () => {
    assert.deepEqual(transferControls({stage:'question', answer_kind:'filename'}), ['filename', 'stop']);
    assert.deepEqual(transferControls({stage:'question', answer_kind:'yesno'}), ['yes', 'no', 'stop']);
    assert.deepEqual(transferControls({stage:'question', answer_kind:'pager'}), ['space', 'stop']);
    assert.deepEqual(transferControls({stage:'error'}), ['correct', 'stop']);
    assert.deepEqual(transferControls({stage:'uncertain'}), ['stop']);
    assert.deepEqual(transferControls({stage:'between', mode:'step'}), ['next', 'stop']);
    assert.deepEqual(transferControls({stage:'awaiting', mode:'bulk', hold:false}), ['pause', 'stop']);
    assert.deepEqual(transferControls({stage:'between', mode:'bulk', hold:true}), ['next', 'stop']);
});

test('file selection validates UTF-8 and the byte limit before preview', async () => {
    assert.equal(await readConfiguration(new Blob(['\n show clock\r\n'])), '\n show clock\r\n');
    await assert.rejects(readConfiguration(new Blob([Uint8Array.of(0xff)])), /UTF-8/);
    await assert.rejects(readConfiguration(new Blob(['a'.repeat(1048577)])), /1 MiB/);
});

test('only passive inventory uses GET and a close on navigation carries the session and CSRF in one keepalive request', async () => {
    const calls = [];
    const request = consoleRequest('/station/console', 'csrf', async (url, options) => {
        calls.push({ url, ...options });
        return Response.json({ ok: true });
    });
    await request('ports');
    await request('session/close', {session:'tab-token'}, true);
    assert.equal(calls[0].method, 'GET');
    assert.equal(calls[0].body, undefined);
    assert.equal(calls[1].method, 'POST');
    assert.equal(calls[1].url, '/station/console/session/close');
    assert.equal(calls[1].headers['X-CSRF-TOKEN'], 'csrf');
    assert.equal(calls[1].keepalive, true);
    assert.deepEqual(JSON.parse(calls[1].body), {session:'tab-token'});
});

test('expired authentication produces a recoverable status even if the server returns HTML', async () => {
    const request = consoleRequest('/console', '', async () => new Response('login HTML', { status: 419 }));
    await assert.rejects(request('session/poll', { session:'one', cursor:0 }), { status:419 });
});

test('a pre-broker validation refusal preserves the next sequence for a new operator action', async () => {
    const seq = [];
    const request = consoleRequest('/console', 'csrf', async (url, options) => {
        if (url.endsWith('/open')) return Response.json({session:'lease'});
        seq.push(JSON.parse(options.body).seq);
        if (seq.length === 1) return Response.json({ok:false, error:'invalid', seq_consumed:false}, {status:422});
        if (seq.length === 2) return Response.json({ok:false, error:'invalid'}, {status:422});
        return Response.json({ok:true});
    });
    const session = new SerialSession(request);
    await session.open(manual);
    await assert.rejects(session.mutate('file_preview', {filename:'\n', text:'one'}));
    await assert.rejects(session.mutate('file_preview', {filename:'empty.cfg', text:'\n'}));
    await session.mutate('file_preview', {filename:'commands.cfg', text:'show clock'});
    assert.deepEqual(seq, [1, 1, 2]);
});
