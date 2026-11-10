<div class="space-y-6" id="updates">
    <x-settings-card title="Updates" description="Verified stable releases from the configured publisher. Installation is always manual.">
        {{-- Keep the Alpine binding stable so Livewire preserves its timer and nested log scopes. --}}
        <div class="space-y-4" x-data="StationUpdatesStatus.create(@js(route('settings.updates.status')), JSON.parse($el.dataset.initialStatus))"
             data-initial-status="{{ json_encode($status, JSON_THROW_ON_ERROR) }}"
             x-on:station-update-started.window="track($event.detail.id)">
            @if ($error)<p class="cmp-flash text-neg" role="alert">{{ $error }}</p>@endif
            @if (!$available)
                <p class="text-fg-muted">Install the updated cmprovision4 package to enable the update controller.</p>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" wire:click="check" wire:loading.attr="disabled" class="cmp-btn" :disabled="busy() || !@js($available)">Check for updates</button>
                <span class="text-sm text-fg-muted">{{ $status['source']['repository'] ?? 'Publisher not initialized' }}</span>
            </div>
            <p class="text-sm text-fg-muted">Checks and downloads can run while boards are flashing. Apply is allowed only on an idle station and keeps a recovery snapshot. No automatic installation or station reboot.</p>
            <div x-show="operation || reconnecting" x-cloak class="cmp-flash space-y-2" role="status" aria-live="polite">
                <p class="text-sm" x-text="message()"></p>
                <a x-show="signin" href="{{ route('login') }}" class="text-link hover:underline text-sm">Sign in again</a>
                <template x-if="operation && operation.state === 'downloading' && operation.total > 0">
                    <div>
                        <div class="cmp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent()" :aria-label="'Downloaded ' + percent() + '%'"><span class="cmp-progress-bar" :style="{ width: percent() + '%' }"></span></div>
                        <p class="text-xs mono" x-text="bytes()"></p>
                    </div>
                </template>
                <template x-if="operation && busy() && operation.state !== 'downloading'">
                    <div class="text-sm flex items-center gap-2"><span class="animate-pulse" aria-hidden="true">●</span>Working…</div>
                </template>
                <button x-show="canCancel()" type="button" class="cmp-btn cmp-btn-secondary" x-on:click="$wire.cancel(operation.id)">Cancel operation</button>
                <a x-show="!busy() && !reconnecting" href="{{ route('settings', ['tab' => 'updates']) }}" class="text-link hover:underline text-sm">Refresh details</a>
                <template x-for="(line, index) in (operation?.log || [])" :key="index"><p class="text-xs mono break-words" x-text="line"></p></template>
                <template x-if="operation?.diagnostic">
                    <details class="text-sm space-y-2">
                        <summary class="cursor-pointer">Failure details</summary>
                        <p>Phase: <span class="mono" x-text="operation.diagnostic.phase"></span> · Error: <span class="mono" x-text="operation.diagnostic.type"></span></p>
                        <p class="break-all">Reference: <span class="mono" x-text="operation.diagnostic.reference"></span></p>
                        <template x-if="operation.recovery_diagnostic"><p class="text-neg">Automatic recovery also failed: <span x-text="operation.recovery_diagnostic.type"></span>. Keep this diagnostic reference when requesting help.</p></template>
                        <a class="cmp-btn cmp-btn-secondary" :href="@js(url('/settings/updates')) + '/' + operation.id + '/diagnostics'">Download diagnostics</a>
                        <p class="text-xs text-fg-muted">The report contains failure types and code locations. It excludes passwords, response bodies and private command output. Your account password may be requested again.</p>
                    </details>
                </template>
            </div>
        </div>
    </x-settings-card>
    @foreach (['application' => 'Application', 'utility-os' => 'Utility OS'] as $part => $label)
        @php
            $check = $status['checks'][$part] ?? [];
            $release = $check['release'] ?? null;
            $ready = collect($status['ready'] ?? [])->first(fn ($item) => $item['release']['component'] === $part && (!$release || $item['id'] === $check['id']));
        @endphp
        <x-settings-card :title="$label" :description="$part === 'application' ? 'cmprovision4 and matching FPMS Lite, when installed.' : 'The temporary OS boards boot for provisioning; updated independently of the application.'">
            <div class="space-y-3">
                <p>Installed: <strong class="mono">{{ $status['installed'][$part] ?? 'Not read yet' }}</strong>
                    @if ($part === 'application' && !empty($status['installed']['fpms']))<span class="text-sm text-fg-muted"> · FPMS {{ $status['installed']['fpms'] }}</span>@endif
                </p>
                <label class="flex items-center gap-2 text-sm"><x-checkbox wire:model.live="{{ $part === 'application' ? 'applicationCheck' : 'utilityCheck' }}" />Automatically check once a day</label>
                <p class="text-sm text-fg-muted">Last check: {{ !empty($check['checked_at']) ? \Carbon\Carbon::createFromTimestamp($check['checked_at'])->local()->format('Y-m-d H:i') : 'Never' }}</p>
                @if (!empty($check['error']))<p class="text-neg text-sm" role="alert">{{ $check['error'] }}</p>@endif
                @if ($release)
                    <p>Available: <strong class="mono">{{ $release['version'] }}</strong></p>
                    <p class="text-sm whitespace-pre-wrap break-words">{{ $release['notes'] }}</p>
                    @if (!empty($check['incompatible']))
                        <p class="text-neg text-sm">{{ $check['incompatible'] }}</p>
                    @elseif ($available)
                        <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="download(@js($part), @js($release['tag']))" wire:loading.attr="disabled">Download and verify</button>
                    @endif
                @elseif (!empty($check['checked_at']) && empty($check['error']))
                    <p class="text-sm">No newer signed stable release is available.</p>
                @endif
                @if ($ready && $available && empty($check['incompatible']))
                    <div class="space-y-2">
                        <p class="text-sm">Verified {{ $ready['release']['version'] }} is ready.</p>
                        @if ($ready['plan'])<details class="text-sm"><summary>Required package changes ({{ count($ready['plan']) }})</summary><ul class="mono text-xs">@foreach ($ready['plan'] as $item)<li>{{ $item['package'] }} → {{ $item['version'] }}</li>@endforeach</ul></details>@endif
                        <x-confirms-password wire:then="begin('apply', '{{ $ready['id'] }}')"><button type="button" class="cmp-btn cmp-btn-secondary">Review and apply</button></x-confirms-password>
                    </div>
                @endif
                @if ($part === 'utility-os' && $available)
                    <div class="flex flex-wrap gap-2">
                        <x-confirms-password wire:then="begin('activate', 'bundled-modern')"><button type="button" class="cmp-btn cmp-btn-secondary">Use bundled Utility OS</button></x-confirms-password>
                        <x-confirms-password wire:then="begin('activate', 'legacy')"><button type="button" class="cmp-btn cmp-btn-secondary">Use legacy Utility OS</button></x-confirms-password>
                    </div>
                @endif
            </div>
        </x-settings-card>
    @endforeach
    @if (!empty($status['recoveries']))
        <x-settings-card title="Recovery history" description="Restoring application data discards changes made after the selected snapshot.">
            <div class="space-y-3">@foreach ($status['recoveries'] as $previous)
                <div class="flex flex-wrap items-center gap-3 text-sm"><span>{{ $previous['component'] }} {{ $previous['version'] ?? '' }} → {{ $previous['previous_version'] ?? 'recorded previous version' }} · {{ $previous['state'] }}</span>
                    <x-confirms-password wire:then="begin('rollback', '{{ $previous['id'] }}')"><button class="cmp-btn cmp-btn-secondary" type="button">Review rollback</button></x-confirms-password>
                </div>
            @endforeach</div>
        </x-settings-card>
    @endif
    @if ($open && $confirmation)
        <x-dialog-modal wire:model.live="open" maxWidth="lg">
            <x-slot name="title">{{ $confirmation['action'] === 'rollback' ? 'Restore a previous version?' : 'Apply station update?' }}</x-slot>
            <x-slot name="content"><div class="space-y-3">
                <p>{{ $confirmation['description'] }}</p>
                <p>The station must be idle. Its web interface and boot services may briefly disconnect. A failed installation automatically restores the immediate recovery snapshot.</p>
                <p class="text-sm text-fg-muted">Do not disconnect station power. If automatic recovery fails, maintenance stays enabled and recovery requires SSH or the local console.</p>
                @if ($error)<p class="text-neg" role="alert">{{ $error }}</p>@endif
            </div></x-slot>
            <x-slot name="footer">
                <button type="button" wire:click="back" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Back</button>
                <button type="button" wire:click="confirm" class="cmp-btn cmp-btn-danger ml-3" wire:loading.attr="disabled">Confirm {{ $confirmation['action'] === 'rollback' ? 'rollback' : 'apply' }}</button>
            </x-slot>
        </x-dialog-modal>
    @endif
</div>
