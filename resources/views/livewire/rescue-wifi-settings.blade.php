<div wire:poll.15s x-data="{ details: null, secret: '', visible: false, copied: false }"
     x-on:rescue-wifi-details.window="details = $event.detail.details"
     x-on:rescue-wifi-password-saved.window="secret = ''; details = null">
    <x-settings-card title="Rescue Wi-Fi access point" id="rescue-wifi" description="Automatic engineer access to this station and attached sensors when management networks are unavailable.">
        <x-slot name="help">
            With two independent Wi-Fi radios, the built-in radio can provide a protected hotspot while the other keeps trying saved networks.
            By default the AP starts after 10 minutes without management connectivity. It stays on after recovery while clients are connected.
            AP clients can reach routed sensor and management networks through this station. Its DHCP stays separate from flashing DHCP.
        </x-slot>
        @if (!$available)
            <p class="text-sm text-fg-muted">Rescue Wi-Fi requires the station helper and NetworkManager on Raspberry Pi OS.</p>
        @else
            <div class="space-y-4">
                @if ($notice)<p role="status" class="cmp-flash">{{ $notice }}</p>@endif
                @error('enabled')<p role="alert" class="text-sm text-neg">{{ $message }}</p>@enderror
                @if ($readinessError)
                    <div class="text-sm text-warn" role="status">{{ $readinessError }} <a class="underline" href="#network-profiles">Open network settings</a></div>
                @endif
                <div class="rounded border border-line p-3 space-y-1" role="status" aria-live="polite">
                    <p class="font-bold {{ ($status['active'] ?? false) ? 'text-accent' : 'text-fg' }}">{{ $statusLabel }}</p>
                    @if ($reason)<p class="text-sm text-fg-muted">{{ $reason }}</p>@endif
                    @if ($status['remaining'] ?? null)<p class="text-sm">Next transition in {{ gmdate('i:s', $status['remaining']) }}</p>@endif
                    @if ($status['active'] ?? false)
                        <p class="text-sm"><span class="mono">{{ $status['ssid'] ?? '' }}</span> ·
                            @if ($status['address'] ?? null)<a class="mono underline" href="http://{{ $status['address'] }}">{{ $status['address'] }}</a> ·@endif
                            {{ $status['clients'] ?? '?' }} clients</p>
                    @endif
                    @if ($status['pending_settings'] ?? false)<p class="text-sm text-warn">Saved for next start. Connection details show both current and saved credentials.</p>@endif
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="enabled" class="form-checkbox">Enable automatic rescue Wi-Fi</label>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="text-sm font-bold">Search timeout, minutes
                        <input type="number" min="1" max="30" wire:model="delayMinutes" class="cmp-field w-full mt-1">
                        @error('delayMinutes')<span class="text-neg block">{{ $message }}</span>@enderror
                    </label>
                    <label class="text-sm font-bold">Rescue SSID
                        <input type="text" wire:model="ssid" maxlength="32" placeholder="Automatic station name" class="cmp-field w-full mt-1">
                        @error('ssid')<span class="text-neg block">{{ $message }}</span>@enderror
                    </label>
                </div>
                <details class="text-sm">
                    <summary class="cursor-pointer font-bold">Radio and network options</summary>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                        <label>AP radio<select wire:model="ap_mac" class="cmp-field w-full mt-1"><option value="">Automatic built-in radio</option>
                            @foreach ($radios as $radio)<option value="{{ $radio['mac'] }}">{{ $radio['device'] }} · {{ $radio['mac'] }}{{ empty($radio['ap']) ? ' · AP unavailable' : '' }}</option>@endforeach</select>
                            @error('ap_mac')<span class="text-neg">{{ $message }}</span>@enderror</label>
                        <label>Client radio<select wire:model="client_mac" class="cmp-field w-full mt-1"><option value="">Automatic second radio</option>
                            @foreach ($radios as $radio)<option value="{{ $radio['mac'] }}">{{ $radio['device'] }} · {{ $radio['mac'] }}</option>@endforeach</select>
                            @error('client_mac')<span class="text-neg">{{ $message }}</span>@enderror</label>
                        <label>AP subnet<input type="text" wire:model="subnet" placeholder="Automatic private /24" class="cmp-field w-full mt-1">
                            @error('subnet')<span class="text-neg">{{ $message }}</span>@enderror</label>
                        <label>2.4 GHz channel<select wire:model="channel" class="cmp-field w-full mt-1"><option value="1">1</option><option value="6">6</option><option value="11">11</option></select></label>
                        @if ($managementOptions)
                            <fieldset class="md:col-span-2"><legend class="font-bold mb-2">Also treat these wired/VLAN profiles as management</legend>
                                @foreach ($managementOptions as $profile)
                                    <label class="flex gap-2 items-center mb-2"><input type="checkbox" wire:model="management_profiles" value="{{ $profile['uuid'] }}" class="form-checkbox">
                                        {{ $profile['device'] ?: 'Saved profile' }} · <span class="mono">{{ $profile['uuid'] }}</span></label>
                                @endforeach
                                <p class="text-fg-muted">An address on a selected profile prevents automatic AP startup. The flashing link is always excluded.</p>
                            </fieldset>
                        @endif
                    </div>
                </details>
                <div class="flex flex-wrap items-center gap-2">
                    <x-confirms-password wire:then="save"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Save rescue settings</button></x-confirms-password>
                    <x-confirms-password wire:then="showCredentials"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Connection details</button></x-confirms-password>
                    @if ($enabled)
                        @if ($status['active'] ?? false)
                            <x-confirms-password wire:then="begin('pause')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Stop for 15 minutes</button></x-confirms-password>
                            @if ($status['pending_settings'] ?? false)<x-confirms-password wire:then="begin('restart')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Restart with saved settings</button></x-confirms-password>@endif
                        @else
                            <x-confirms-password wire:then="begin('start')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Start now</button></x-confirms-password>
                        @endif
                        @if (($status['state'] ?? '') === 'paused')<x-confirms-password wire:then="begin('resume')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Resume automatic policy</button></x-confirms-password>@endif
                    @endif
                    <span wire:loading class="text-sm text-fg-muted" role="status">Applying request…</span>
                </div>
                <div x-show="details" x-cloak class="rounded border border-line p-3 space-y-3">
                    <div class="flex items-center justify-between gap-2"><p class="font-bold">Saved connection details</p><button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small" @click="details = null; visible = false">Close</button></div>
                    <p class="mono text-sm" x-text="details?.ssid"></p>
                    <div class="flex items-center gap-2"><input readonly :type="visible ? 'text' : 'password'" :value="details?.password || ''" x-ref="apPassword" class="cmp-field w-full mono" aria-label="Saved AP password">
                        <button type="button" @click="visible = !visible" class="cmp-btn cmp-btn-secondary cmp-btn-small" x-text="visible ? 'Hide' : 'Show'"></button>
                        <button type="button" @click="const field = $refs.apPassword; const type = field.type; field.type = 'text'; field.select(); copied = document.execCommand('copy'); field.type = type" class="cmp-btn cmp-btn-secondary cmp-btn-small">Copy</button></div>
                    <p x-show="copied" role="status" class="text-sm text-fg-muted">Password copied.</p>
                    <template x-if="details?.active"><p class="text-sm">Current AP: <span class="mono" x-text="details.active.ssid"></span> · Password: <span class="mono" x-text="visible ? details.active.password : '••••••••'"></span></p></template>
                    <p class="text-xs text-fg-muted">Save these credentials before taking the station offline. The AP password is separate from web and SSH accounts.</p>
                </div>
                <details class="text-sm"><summary class="cursor-pointer font-bold">Change AP password</summary>
                    <div class="flex flex-wrap items-center gap-2 mt-3"><input type="password" x-model="secret" class="cmp-field" autocomplete="new-password" placeholder="12–63 printable characters" aria-label="New rescue AP password">
                        <x-confirms-password wire:then="changePassword(secret)"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Save new password</button></x-confirms-password></div>
                    @error('password')<p class="text-neg mt-1">{{ $message }}</p>@enderror
                </details>
            </div>
        @endif
    </x-settings-card>
    <x-confirmation-modal wire:model.live="confirmingDisconnect">
        <x-slot name="title">Disconnect rescue Wi-Fi clients?</x-slot>
        <x-slot name="content">This action stops the active AP and may disconnect this browser. Ensure another way to reach the station. @if ($pendingAction === 'restart')Reconnect using the saved SSID and password after restart.@endif</x-slot>
        <x-slot name="footer"><button type="button" wire:click="cancelDisconnect" class="cmp-btn cmp-btn-secondary mr-2">Back</button><button type="button" wire:click="confirmDisconnect" class="cmp-btn cmp-btn-danger" wire:loading.attr="disabled">Confirm disconnect</button></x-slot>
    </x-confirmation-modal>
</div>
