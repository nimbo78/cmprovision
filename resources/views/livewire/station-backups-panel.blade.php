<div class="border-t border-border pt-4 space-y-4" x-data="StationBackups.create(@js(route('settings.backups.status')), JSON.parse($el.dataset.initial))"
     data-initial="{{ json_encode($status, JSON_THROW_ON_ERROR) }}" x-on:station-backup-changed.window="accept($event.detail.status)">
    <div><h3 class="font-semibold">Full station backup</h3><p class="text-sm text-fg-muted">Encrypted application data, administrators, SSH, Wi-Fi, network and supported system settings. Images and sensor packages are optional.</p></div>
    @if ($error)<p class="cmp-flash text-neg" role="alert">{{ $error }}</p>@endif
    @if (!$available)<p class="text-fg-muted">Install the updated cmprovision4 package to enable full backups.</p>@else
    <p x-show="reconnecting" x-cloak class="cmp-flash" role="status">Reconnecting… The job continues on the station. Do not start another copy.</p>
    <a x-show="signin" x-cloak href="{{ route('login') }}" class="text-link">Sign in again</a>
    <div x-show="operation" x-cloak class="cmp-flash space-y-2" role="status" aria-live="polite">
        <p x-text="operation?.message"></p>
        <template x-if="percent() !== null"><div><div class="cmp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent()" aria-label="Backup bytes processed"><span class="cmp-progress-bar" :style="{ width: percent() + '%' }"></span></div><p class="text-xs mono" x-text="percent() + '% · ' + Math.round(operation.bytes / 1048576) + ' / ' + Math.round(operation.total / 1048576) + ' MiB'"></p></div></template>
        <p x-show="busy() && percent() === null" class="text-sm"><span class="animate-pulse" aria-hidden="true">●</span> Working…</p>
        <p x-show="operation?.warning" class="text-warn" x-text="operation?.warning"></p>
        <button type="button" x-show="busy() && operation?.state !== 'recovery-required'" x-on:click="$wire.cancel(operation.id)" class="cmp-btn cmp-btn-secondary">Cancel job</button>
        <template x-if="operation?.host_keys"><div class="space-y-2"><p>Compare the fingerprint with your SFTP server, then explicitly accept a key.</p><template x-for="key in operation.host_keys" :key="key.key"><div class="break-all text-xs mono"><span x-text="key.fingerprint"></span> <button type="button" class="text-link" x-on:click="profile.host_key = key.key">Use this key</button></div></template></div></template>
    </div>
    <div x-show="screen === 'overview'" class="space-y-4">
        <div class="flex flex-wrap gap-2"><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="screen = 'master'" :disabled="busy()">Master password</button><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="edit()" :disabled="busy()">Add destination</button><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="editSchedule()" :disabled="busy()">Schedule</button></div>
        <p class="text-sm" x-text="status.password_configured ? 'Master password saved. Keep it separately: it is never included in a backup.' : 'Set a master password before creating your first full backup.'"></p>
        <form class="space-y-3" x-on:submit.prevent="startBackup()">
            <label class="block text-sm">Destination<select x-model="target" class="cmp-field w-full mt-1"><option value="download">Download in this browser</option><template x-for="item in (status.profiles || [])" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select></label>
            <div class="flex flex-wrap gap-4 text-sm"><label><input type="checkbox" x-model="images"> Include images</label><label><input type="checkbox" x-model="packages"> Include sensor packages</label></div>
            <button class="cmp-btn" :disabled="busy() || submitting || !status.password_configured">Create encrypted backup</button>
        </form>
        <p class="text-sm text-fg-muted">Runs only on an idle station. Backup settings require your account password again. Downloads expire after one hour. Full restore is an administrator operation over SSH.</p>
        <template x-for="item in (status.profiles || [])" :key="item.id"><div class="border border-border rounded-lg p-3 flex flex-wrap items-center gap-2"><strong class="mr-auto" x-text="item.name + ' · ' + item.type.toUpperCase()"></strong><button type="button" class="cmp-btn cmp-btn-secondary" :disabled="busy()" x-on:click="edit(item)">Edit</button><button type="button" class="cmp-btn cmp-btn-secondary" :disabled="busy()" x-on:click="$wire.testProfile(item.id)">Test</button><button type="button" class="cmp-btn cmp-btn-secondary" :disabled="busy()" x-on:click="confirm('Remove this backup destination? Existing copies will remain.') && $wire.deleteProfile(item.id)">Remove</button></div></template>
        <p class="text-sm text-fg-muted" x-text="status.schedule?.enabled ? 'Schedule: ' + status.schedule.frequency + ' at ' + status.schedule.time + ' · ' + (status.timezone || 'system time') : 'Scheduled backups are off.'"></p>
        <div class="space-y-2"><h4 class="font-semibold">Recent jobs</h4><template x-for="item in (status.history || [])" :key="item.id"><div class="border border-border rounded-lg p-3 text-sm space-y-1"><p><span class="mono" x-text="new Date(item.created_at * 1000).toLocaleString()"></span> · <strong x-text="item.state"></strong></p><p x-text="item.message"></p><template x-if="item.state === 'ready' && item.expires_at * 1000 > Date.now()"><a class="text-link" :href="@js(url('/settings/backups')) + '/' + item.id + '/download'">Download encrypted copy</a></template></div></template></div>
    </div>
    <form x-show="screen === 'master'" x-cloak class="space-y-3" x-on:submit.prevent="await $wire.saveMasterPassword(master); master = ''">
        <label class="block text-sm">Master password<input type="password" autocomplete="new-password" minlength="12" required x-model="master" class="cmp-field w-full mt-1"></label>
        <p class="text-sm text-fg-muted">At least 12 characters. Changing this password affects new copies; older files still need their original password.</p>
        <div class="flex gap-2"><button class="cmp-btn">Save password</button><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="back()">Back</button></div>
    </form>
    <form x-show="screen === 'profile'" x-cloak class="space-y-3" x-on:submit.prevent="await $wire.saveProfile(profile); for (const k of ['password','key_pem','key_password','access_key','secret_key','session_token','ca_pem']) profile[k] = ''">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="text-sm">Name<input x-model="profile.name" class="cmp-field w-full mt-1" required></label>
            <label class="text-sm">Protocol<select x-model="profile.type" class="cmp-field w-full mt-1" x-on:change="profile.port = profile.type === 'sftp' ? 22 : 21"><option value="sftp">SFTP</option><option value="ftp">FTP / FTPS</option><option value="s3">S3</option></select></label>
        </div>
        <div x-show="profile.type !== 's3'" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <label class="text-sm">Host<input x-model="profile.host" class="cmp-field w-full mt-1"></label><label class="text-sm">Port<input type="number" min="1" max="65535" x-model.number="profile.port" class="cmp-field w-full mt-1"></label><label class="text-sm">Login<input x-model="profile.user" autocomplete="off" class="cmp-field w-full mt-1"></label>
        </div>
        <div x-show="profile.type === 'sftp'" class="space-y-3">
            <label class="block text-sm">Authentication<select x-model="profile.auth" class="cmp-field w-full mt-1"><option value="password">Password</option><option value="key">Private key</option></select></label>
            <label class="block text-sm">Pinned server public key<textarea x-model="profile.host_key" class="cmp-field w-full mt-1 mono" rows="2"></textarea></label>
            <button type="button" class="cmp-btn cmp-btn-secondary" :disabled="busy()" x-on:click="$wire.hostKey(profile.host, profile.port)">Read server fingerprints</button>
        </div>
        <div x-show="profile.type === 'ftp'" class="space-y-3"><label class="block text-sm">Transport<select x-model="profile.mode" class="cmp-field w-full mt-1"><option value="explicit">Explicit FTPS</option><option value="implicit">Implicit FTPS</option><option value="plain">Plain FTP</option></select></label><label x-show="profile.mode === 'plain'" class="text-sm"><input type="checkbox" x-model="profile.plain_ack"> I understand plain FTP exposes the server login on the network.</label></div>
        <div x-show="profile.type === 's3'" class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach (['bucket' => 'Bucket', 'region' => 'Region', 'endpoint' => 'Custom HTTPS endpoint (optional)'] as $key => $label)<label class="text-sm">{{ $label }}<input x-model="profile.{{ $key }}" class="cmp-field w-full mt-1"></label>@endforeach
            <label class="text-sm"><input type="checkbox" x-model="profile.path_style"> Path-style addressing</label>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3"><label class="text-sm">Path / prefix<input x-model="profile.path" class="cmp-field w-full mt-1"></label><label class="text-sm">Keep successful copies<input type="number" min="1" max="365" x-model.number="profile.keep" class="cmp-field w-full mt-1"></label></div>
        <p class="text-sm text-fg-muted">Saved credentials are never displayed. Leave blank to keep them, or select Clear to remove them.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach (['password' => 'Transport password', 'key_pem' => 'SFTP private key (PEM)', 'key_password' => 'Private-key passphrase', 'access_key' => 'S3 access key', 'secret_key' => 'S3 secret key', 'session_token' => 'S3 session token (optional)', 'ca_pem' => 'Custom CA certificate (PEM, optional)'] as $key => $label)
                <div x-show="{{ $key === 'ca_pem' ? 'true' : (in_array($key, ['access_key', 'secret_key', 'session_token']) ? "profile.type === 's3'" : ($key === 'password' ? "profile.type !== 's3'" : "profile.type === 'sftp' && profile.auth === 'key'")) }}"><label class="block text-sm">{{ $label }}@if (in_array($key, ['key_pem', 'ca_pem']))<textarea rows="3" autocomplete="off" x-model="profile.{{ $key }}" class="cmp-field w-full mt-1 mono"></textarea>@else<input type="password" autocomplete="new-password" x-model="profile.{{ $key }}" class="cmp-field w-full mt-1">@endif</label><label class="text-xs"><input type="checkbox" value="{{ $key }}" x-model="profile.clear"> Clear saved value</label></div>
            @endforeach
        </div>
        <div class="flex gap-2"><button class="cmp-btn" :disabled="busy()">Save destination</button><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="back()">Back</button></div>
    </form>
    <form x-show="screen === 'schedule'" x-cloak class="space-y-3" x-on:submit.prevent="$wire.saveSchedule(schedule)">
        <label class="text-sm"><input type="checkbox" x-model="schedule.enabled"> Enable scheduled backups</label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3"><label class="text-sm">Destination<select x-model="schedule.profile" class="cmp-field w-full mt-1"><option value="">Choose a saved destination</option><template x-for="item in (status.profiles || [])" :key="item.id"><option :value="item.id" x-text="item.name"></option></template></select></label><label class="text-sm">Frequency<select x-model="schedule.frequency" class="cmp-field w-full mt-1"><option value="daily">Daily</option><option value="weekly">Weekly</option></select></label><label class="text-sm">Local time<input type="time" x-model="schedule.time" class="cmp-field w-full mt-1"></label><label x-show="schedule.frequency === 'weekly'" class="text-sm">Weekday<select x-model.number="schedule.weekday" class="cmp-field w-full mt-1">@foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $day => $label)<option value="{{ $day }}">{{ $label }}</option>@endforeach</select></label><label class="text-sm">Keep successful copies<input type="number" min="1" max="365" x-model.number="schedule.keep" class="cmp-field w-full mt-1"></label></div>
        <div class="flex flex-wrap gap-4 text-sm"><label><input type="checkbox" x-model="schedule.images"> Include images</label><label><input type="checkbox" x-model="schedule.packages"> Include sensor packages</label></div>
        <p class="text-sm text-fg-muted">Uses the station time zone. Busy stations wait without interrupting provisioning; missed slots coalesce into one copy.</p>
        <div class="flex gap-2"><button class="cmp-btn" :disabled="busy()">Save schedule</button><button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="back()">Back</button></div>
    </form>
    @endif
</div>
