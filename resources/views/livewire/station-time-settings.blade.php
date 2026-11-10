<x-settings-card title="Time and synchronization" id="time"
    description="The clock and time zone of this provisioner. Saved records and API timestamps remain in UTC.">
    <x-slot name="actions">
        <button type="button" wire:click="refreshStatus" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small">
            <span wire:loading.remove wire:target="refreshStatus">Refresh status</span><span wire:loading wire:target="refreshStatus">Refreshing…</span>
        </button>
    </x-slot>
    <div class="space-y-5">
        @php($local = \Illuminate\Support\Carbon::createFromTimestampUTC($status['timestamp'])->setTimezone($status['timezone']))
        <dl class="grid gap-3 sm:grid-cols-2 text-sm">
            <div><dt class="text-fg-muted">Station local time</dt><dd class="mono break-words"><time datetime="{{ $local->toIso8601String() }}">{{ $local->format('Y-m-d H:i:s T (P)') }}</time></dd></div>
            <div><dt class="text-fg-muted">UTC time</dt><dd class="mono">{{ $local->copy()->utc()->format('Y-m-d H:i:s') }} UTC</dd></div>
            <div><dt class="text-fg-muted">Time synchronization</dt><dd><span class="cmp-chip {{ $status['synchronized'] === true ? 'cmp-chip-ok' : 'cmp-chip-idle' }}">{{ $status['synchronized'] === true ? 'Synchronized' : ($status['synchronized'] === false ? 'Not synchronized' : 'Unknown') }}</span> · NTP {{ !$status['available'] ? 'unknown' : ($status['ntp_active'] ? 'active' : 'inactive') }}</dd></div>
            <div><dt class="text-fg-muted">Current server</dt><dd class="mono break-all">{{ $status['server_name'] ?? 'None reported' }}@if ($status['server_address']) · {{ $status['server_address'] }}@endif</dd></div>
        </dl>
        @if ($error)<p class="cmp-flash text-sm" role="alert">{{ $error }}</p>@endif
        @if ($notice)<div class="cmp-flash text-sm" role="status"><p>{{ $notice }}</p><a class="text-link hover:underline" href="{{ route('settings', ['tab' => 'system']) }}#time">Reload page</a></div>@endif
        @if (!$status['available'])<p class="text-sm text-fg-muted">{{ $status['reason'] }}</p>@endif
        @if (config('app.display_timezone_override') && !\App\Support\SystemTimezone::isValid(config('app.display_timezone_override')))
            <p class="cmp-flash text-sm">APP_DISPLAY_TIMEZONE is invalid and is ignored. Displays follow the system time zone.</p>
        @elseif (config('app.display_timezone_override') && config('app.display_timezone_override') !== $status['timezone'])
            <p class="cmp-flash text-sm">APP_DISPLAY_TIMEZONE overrides the display zone with {{ config('app.display_timezone_override') }}. Remove that explicit server override to follow the system time zone.</p>
        @endif
        <form x-data x-on:submit.prevent="$el.querySelector('[data-time-submit]').click()" class="space-y-3">
            <div>
                <label for="station-timezone" class="block text-sm mb-2">System time zone</label>
                <input id="station-timezone" type="text" list="station-timezones" wire:model="timezone" class="cmp-field w-full" maxlength="128" autocomplete="off" aria-describedby="timezone-help" @disabled(!$status['available'])>
                <datalist id="station-timezones">@foreach ($status['timezones'] as $zone)<option value="{{ $zone }}"></option>@endforeach</datalist>
                <p id="timezone-help" class="text-sm text-fg-muted mt-2">Search an IANA zone, for example Asia/Jerusalem or Europe/Berlin. Web, HDMI and FPMS normally use this zone.</p>
                @error('timezone')<p class="text-sm text-neg" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-confirms-password wire:then="saveTimezone"><button type="button" data-time-submit class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled" @disabled(!$status['available'])><span wire:loading.remove wire:target="saveTimezone">Save time zone</span><span wire:loading wire:target="saveTimezone">Saving…</span></button></x-confirms-password>
        </form>
        <form x-data x-on:submit.prevent="$el.querySelector('[data-time-submit]').click()" class="space-y-3 border-t border-line pt-4">
            @if (!$status['ntp_available'])<p class="text-sm text-fg-muted">{{ $status['ntp_reason'] }}</p>@endif
            <label class="flex items-center gap-2 text-sm" for="station-ntp-enabled"><x-checkbox id="station-ntp-enabled" wire:model="ntpEnabled" :disabled="!$status['ntp_available']" />Synchronize time automatically (NTP)</label>
            <div>
                <label for="station-ntp-servers" class="block text-sm mb-2">NTP servers</label>
                <textarea id="station-ntp-servers" rows="3" maxlength="4096" wire:model="ntpServers" class="cmp-field w-full mono" aria-describedby="ntp-help" spellcheck="false" @disabled(!$status['ntp_available'])></textarea>
                <p id="ntp-help" class="text-sm text-fg-muted mt-2">Up to eight hostnames or IP addresses, separated by spaces, commas or new lines. Leave empty to use system servers. Interface-provided servers can also be used.</p>
                @error('ntpServers')<p class="text-sm text-neg" role="alert">{{ $message }}</p>@enderror
                @error('ntpEnabled')<p class="text-sm text-neg" role="alert">{{ $message }}</p>@enderror
            </div>
            <div class="flex flex-wrap gap-2">
                <x-confirms-password wire:then="saveNtp"><button type="button" data-time-submit class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled" @disabled(!$status['ntp_available'])><span wire:loading.remove wire:target="saveNtp">Save NTP settings</span><span wire:loading wire:target="saveNtp">Saving…</span></button></x-confirms-password>
                <button type="button" wire:click="$set('ntpServers', '')" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary" @disabled(!$status['ntp_available'])>Use system servers</button>
            </div>
            <p class="text-sm text-fg-muted">Use system servers clears this field; save to apply. Disabling NTP stops automatic clock synchronization.</p>
        </form>
        <details class="text-sm"><summary class="cursor-pointer text-fg-muted">Effective server lists</summary>
            <dl class="mt-2 space-y-2 break-all"><div><dt class="text-fg-muted">System</dt><dd class="mono">{{ implode(', ', $status['system_servers']) ?: 'None reported' }}</dd></div><div><dt class="text-fg-muted">From interfaces</dt><dd class="mono">{{ implode(', ', $status['link_servers']) ?: 'None reported' }}</dd></div><div><dt class="text-fg-muted">Fallback</dt><dd class="mono">{{ implode(', ', $status['fallback_servers']) ?: 'None reported' }}</dd></div></dl>
        </details>
        <p class="text-sm text-fg-muted">Time settings can be changed when no modules are provisioning. Saving servers does not mean the clock is synchronized yet; refresh status to check.</p>
    </div>
</x-settings-card>
