import {createRequire} from 'node:module';
import {test} from 'node:test';
import assert from 'node:assert/strict';
const require = createRequire(import.meta.url);
const {create} = require('../../public/js/station-power.js');
const boot = '11111111-1111-4111-8111-111111111111';
const next = '22222222-2222-4222-8222-222222222222';
function fixture(action='reboot') {
    let now=0, result={status:200,data:{boot_id:boot,pending:null}}, calls=0;
    const ui=create('/settings/power/status', {now:()=>now, origin:()=> 'https://station.test',
        every:()=>1, stop:()=>{}, read:async()=>{calls++; if(result instanceof Error) throw result; return result;}});
    ui.start({action,boot_id:boot});
    return {ui, set:value=>result=value, time:value=>now=value, calls:()=>calls};
}
test('same-boot HTTP 200 never claims a completed restart', async()=>{
    const f=fixture(); await f.ui.poll(); assert.equal(f.ui.state,'scheduled');
    f.set(new Error('offline')); await f.ui.poll(); assert.equal(f.ui.state,'reconnecting');
    f.set({status:200,data:{boot_id:boot,pending:null}}); await f.ui.poll(); assert.equal(f.ui.state,'reconnecting');
    f.set({status:200,data:{boot_id:next,pending:null}}); await f.ui.poll(); assert.equal(f.ui.state,'online');
});
test('shutdown reports loss of connection without pretending power is physically removed', async()=>{
    const f=fixture('poweroff'); f.set(new Error('offline')); await f.ui.poll(); assert.equal(f.ui.state,'offline');
    assert.match(f.ui.message(),/connection/i); assert.doesNotMatch(f.ui.message(),/safe to unplug/i);
});
test('restart without evidence of a new boot times out', async()=>{
    const f=fixture(); f.time(121000); await f.ui.poll(); assert.equal(f.ui.state,'timeout');
});
test('authentication expiry reports sign-in rather than a successful restart', async()=>{
    const f=fixture(); f.set({status:401,data:null}); await f.ui.poll(); assert.equal(f.ui.state,'signin');
});
test('malformed status cannot claim an online station', async()=>{
    const f=fixture(); f.set({status:200,data:{boot_id:5}}); await f.ui.poll(); assert.notEqual(f.ui.state,'online');
});
test('an HTTP error or malformed response does not prove shutdown or connection loss', async()=>{
    const f=fixture('poweroff'); f.set({status:503,data:null}); await f.ui.poll(); assert.equal(f.ui.state,'uncertain');
    f.set({status:200,data:{boot_id:5}}); await f.ui.poll(); assert.equal(f.ui.state,'uncertain');
});
test('unknown actions and cross-origin status endpoints are rejected',()=>{
    const f=fixture(); assert.equal(f.ui.start({action:'shell',boot_id:boot}),false);
    assert.throws(()=>create('https://other.test/status',{origin:()=> 'https://station.test'}));
});
test('a malformed HTTP body or fetch timeout does not prove shutdown', async(t)=>{
    const ui=create('/settings/power/status', {origin:()=> 'https://station.test', every:()=>1, stop:()=>{}});
    t.mock.method(globalThis,'fetch',async()=>({status:200,ok:true,json:async()=>{throw new SyntaxError('html');}}));
    ui.start({action:'poweroff',boot_id:boot}); await ui.poll(); assert.equal(ui.state,'uncertain');
    globalThis.fetch.mock.mockImplementation(async()=>{throw Object.assign(new Error('timeout'),{name:'AbortError'});});
    await ui.poll(); assert.equal(ui.state,'uncertain');
});
