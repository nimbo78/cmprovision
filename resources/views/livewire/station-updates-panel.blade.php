<div id="updates">
    @php
        $part = $component;
        $label = $part === 'application' ? 'Application' : 'Utility OS';
        $check = $status['checks'][$part] ?? [];
        $release = $check['release'] ?? null;
        $ready = collect($status['ready'] ?? [])->first(fn ($item) => ($item['release']['component'] ?? null) === $part && (!$release || $item['id'] === ($check['id'] ?? null)));
        $candidate = $release ?? ($ready['release'] ?? null);
        $canPrepare = $available && empty($check['incompatible']) && $candidate && !empty($candidate['tag']);
        $hasNext = $available && empty($check['incompatible']) && ($ready || $canPrepare);
    @endphp
    {{-- Keep this binding stable so polling and reconnect state survive Livewire renders. --}}
    <div class="cmp-updates" x-data="StationUpdatesStatus.create(@js(route('settings.updates.status')), JSON.parse($el.dataset.initialStatus), $el.dataset.timezone)"
         data-initial-status="{{ json_encode($status, JSON_THROW_ON_ERROR) }}" data-timezone="{{ config('app.display_timezone') }}"
         x-on:station-update-started.window="track($event.detail.id)">
        <header class="cmp-updates-heading">
            <div><h2>Updates</h2><p>Signed stable releases · Installation is always manual · This station only</p></div>
            <button type="button" wire:click="check" wire:loading.attr="disabled" @class(['cmp-btn', 'cmp-btn-secondary' => $hasNext]) :disabled="blocked() || !@js($available)">Check for updates</button>
        </header>
        @if ($error)<p class="cmp-updates-alert text-neg bg-neg-bg" role="alert">{{ $error }}</p>@endif
        @if (!$available)<p class="cmp-updates-alert text-fg-muted bg-head">Install the updated cmprovision4 package to enable the update controller.</p>@endif
        <div x-show="reconnecting || signin || pending" x-cloak class="cmp-updates-alert text-warn bg-warn-bg space-y-2" role="status" aria-live="polite">
            <p x-show="reconnecting || signin" x-text="connectionMessage()"></p>
            <p x-show="pending">Update maintenance is active. New boots and other updates remain paused until recovery finishes.</p>
            <a x-show="signin" href="{{ route('login') }}" class="text-link hover:underline">Sign in again</a>
        </div>
        <div x-show="operation && !operationFor($wire.component) && showOperation()" x-cloak class="cmp-updates-alert text-warn bg-warn-bg">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div><strong x-text="operationLabel(operation)"></strong><p x-text="phaseLabel(operation)"></p></div>
                <button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="$wire.selectComponent(operation.component)" x-show="['application', 'utility-os'].includes(operation?.component)">View operation</button>
            </div>
        </div>
        <div class="cmp-updates-layout">
            <aside class="cmp-updates-rail" aria-label="Update components">
                @foreach (['application' => 'Application', 'utility-os' => 'Utility OS'] as $key => $name)
                    @php
                        $componentCheck = $status['checks'][$key] ?? [];
                    @endphp
                    <div class="cmp-updates-component">
                        <button type="button" wire:click="selectComponent('{{ $key }}')" aria-pressed="{{ $component === $key ? 'true' : 'false' }}" class="cmp-updates-selector">
                            <strong>{{ $name }}</strong><span class="cmp-updates-version">{{ $status['installed'][$key] ?? 'Not read yet' }}</span>
                            @if ($key === 'application' && !empty($status['installed']['fpms']))<span class="cmp-updates-meta">FPMS {{ $status['installed']['fpms'] }}</span>@endif
                            @if ($key === 'utility-os')<span class="cmp-updates-meta">Temporary boot OS</span>@endif
                            @if (!empty($componentCheck['error']) || !empty($componentCheck['incompatible']))
                                <span class="cmp-updates-badge text-neg bg-neg-bg">Needs attention</span>
                            @elseif (!empty($componentCheck['release']))
                                <span class="cmp-updates-badge text-info bg-info-bg">Update available</span>
                            @elseif (!empty($componentCheck['checked_at']))
                                <span class="cmp-updates-badge text-pos bg-pos-bg">Up to date</span>
                            @else
                                <span class="cmp-updates-badge text-fg-muted bg-head">Not checked yet</span>
                            @endif
                        </button>
                        <label class="cmp-updates-autocheck"><x-checkbox wire:model.live="{{ $key === 'application' ? 'applicationCheck' : 'utilityCheck' }}" />Check daily<span class="sr-only"> · {{ $name }}</span></label>
                    </div>
                @endforeach
                <p class="cmp-updates-rail-note">Background checks only. Updates are never installed automatically.</p>
            </aside>
            <div class="cmp-updates-journal" aria-label="Update journal">
                <section x-show="operation && operationFor($wire.component) && showOperation()" x-cloak class="cmp-updates-current cmp-card" aria-label="Current update operation" aria-live="polite">
                    <div class="cmp-updates-meta"><span x-text="operationLabel(operation)"></span><span x-show="operation?.created_at" x-text="formatTime(operation?.created_at)"></span></div>
                    <h3 x-text="phaseLabel(operation)"></h3>
                    <ol class="cmp-updates-stages" aria-label="Operation stages">
                        <template x-for="(step, index) in stages()" :key="index"><li :data-state="step.state"><span class="cmp-updates-step" x-text="step.state === 'done' ? '✓' : index + 1"></span><span x-text="step.label"></span></li></template>
                    </ol>
                    <p class="text-sm break-words" x-text="operation?.message"></p>
                    <template x-if="operation?.state === 'downloading' && operation.total > 0">
                        <div class="space-y-2 mt-3">
                            <div class="cmp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="percent()" :aria-label="'Downloaded ' + percent() + '%'"><span class="cmp-progress-bar" :style="{ width: percent() + '%' }"></span></div>
                            <p class="text-xs mono" x-text="bytes() + ' · ' + percent() + '%'"></p>
                        </div>
                    </template>
                    <p x-show="busy()" class="cmp-updates-meta mt-3"><span aria-hidden="true">●</span><span x-show="elapsed()" x-text="'Elapsed ' + elapsed()"></span><span>Waiting for a confirmed result</span></p>
                    <div class="flex flex-wrap items-center gap-3 mt-4">
                        <button x-show="canCancel()" type="button" class="cmp-btn cmp-btn-secondary" x-on:click="$wire.cancel(operation.id)" wire:loading.attr="disabled">Cancel operation</button>
                        <a x-show="!blocked()" href="{{ route('settings', ['tab' => 'updates']) }}" class="text-link hover:underline text-sm">Refresh details</a>
                        <a x-show="operation?.diagnostic?.phase === 'preparing'" href="{{ route('settings', ['tab' => 'system']) }}#storage" class="text-link hover:underline text-sm">Review storage</a>
                    </div>
                    <div class="cmp-updates-operation-details" x-data="{ details: false }">
                        <button type="button" class="cmp-updates-disclosure" x-on:click="details = !details" :aria-expanded="details" aria-controls="updates-operation-details">Operation details and diagnostics <span aria-hidden="true" x-text="details ? '⌃' : '⌄'"></span></button>
                        <div id="updates-operation-details" x-show="details" x-cloak class="space-y-2 mt-3">
                            <template x-for="(line, index) in (operation?.log || [])" :key="index"><p class="text-xs mono break-words" x-text="line"></p></template>
                            <template x-if="operation?.diagnostic">
                                <div class="text-sm space-y-2">
                                    <p>Phase: <span class="mono" x-text="operation.diagnostic.phase"></span> · Error: <span class="mono" x-text="operation.diagnostic.type"></span></p>
                                    <p class="break-all">Reference: <span class="mono" x-text="operation.diagnostic.reference"></span></p>
                                    <template x-if="operation.recovery_diagnostic"><p class="text-neg">Automatic recovery also failed: <span x-text="operation.recovery_diagnostic.type"></span>. Keep this diagnostic reference when requesting help.</p></template>
                                    <a class="cmp-btn cmp-btn-secondary" :href="@js(url('/settings/updates')) + '/' + encodeURIComponent(operation.id) + '/diagnostics'">Download diagnostics</a>
                                    <p class="text-xs text-fg-muted">The report excludes passwords, response bodies and private command output. Your account password may be requested again.</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </section>
                <section class="cmp-card cmp-updates-candidate" aria-label="{{ $label }} release" wire:key="updates-candidate-{{ $part }}" x-data="{ notes: false, plan: false }">
                    <div class="cmp-updates-meta">{{ $label }} · Last check: {{ !empty($check['checked_at']) ? \Carbon\Carbon::createFromTimestamp($check['checked_at'])->local()->format('Y-m-d H:i') : 'Never' }}</div>
                    <h3>{{ $label }} {{ $candidate['version'] ?? ($status['installed'][$part] ?? '') }}</h3>
                    @if (!empty($check['error']))<p class="text-neg text-sm mt-3" role="alert">{{ $check['error'] }}</p>@endif
                    @if (!empty($check['error']) && !empty($check['verified_at']))
                        <p class="text-sm text-fg-muted">Last successful check: {{ \Carbon\Carbon::createFromTimestamp($check['verified_at'])->local()->format('Y-m-d H:i') }}</p>
                    @endif
                    @if (!empty($check['incompatible']))<p class="text-neg text-sm mt-3" role="alert">{{ $check['incompatible'] }}</p>@endif
                    @if ($candidate)
                        @if ($ready && empty($check['incompatible']))
                            <p class="cmp-updates-ready"><strong>Downloaded and verified.</strong> Signature, checksums and compatibility passed.</p>
                        @else
                            <p class="text-sm text-fg-muted mt-2">{{ $part === 'application' ? 'cmprovision4 and matching FPMS Lite, when installed.' : 'The temporary OS boards boot for provisioning; updated independently of the application.' }}</p>
                        @endif
                        @if (!empty($candidate['notes']))
                            <div class="mt-3"><button type="button" class="cmp-updates-disclosure" x-on:click="notes = !notes" :aria-expanded="notes" aria-controls="updates-release-notes">What’s new <span aria-hidden="true" x-text="notes ? '⌃' : '⌄'"></span></button>
                                <p id="updates-release-notes" x-show="notes" x-cloak class="text-sm whitespace-pre-wrap break-words mt-3">{{ $candidate['notes'] }}</p>
                            </div>
                        @endif
                        @if ($ready && !empty($ready['plan']))
                            <div class="mt-3"><button type="button" class="cmp-updates-disclosure" x-on:click="plan = !plan" :aria-expanded="plan" aria-controls="updates-package-plan">Required package changes ({{ count($ready['plan']) }}) <span aria-hidden="true" x-text="plan ? '⌃' : '⌄'"></span></button>
                                <ul id="updates-package-plan" x-show="plan" x-cloak class="mono text-xs mt-3">@foreach ($ready['plan'] as $item)<li>{{ $item['package'] }} → {{ $item['version'] }}</li>@endforeach</ul>
                            </div>
                        @endif
                        @if ($hasNext)
                            <div class="cmp-updates-next-action">
                                <p>{{ $ready ? 'Review the changes before installation. The station must be idle to apply.' : 'Download and verify first. This does not install the update.' }}</p>
                                @if ($ready)
                                    <x-confirms-password wire:then="begin('apply', '{{ $ready['id'] }}')"><button type="button" class="cmp-btn" :disabled="blocked()" wire:loading.attr="disabled">Review and apply</button></x-confirms-password>
                                    @if ($part === 'application')
                                        <span class="text-xs text-fg-muted" role="status" wire:loading.delay wire:target="begin('apply', '{{ $ready['id'] }}')">Checking disk space…</span>
                                    @endif
                                @else
                                    <button type="button" class="cmp-btn" wire:click="download(@js($part), @js($candidate['tag']))" :disabled="blocked()" wire:loading.attr="disabled">Download and verify</button>
                                @endif
                            </div>
                            @if ($ready && $canPrepare)
                                <div class="flex flex-wrap items-center justify-between gap-3 mt-4 text-xs text-fg-muted">
                                    <span>Station components or dependencies changed since preparation?</span>
                                    <button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:click="download(@js($part), @js($candidate['tag']))" :disabled="blocked()" wire:loading.attr="disabled">Download and verify again</button>
                                </div>
                            @endif
                        @endif
                    @elseif (!empty($check['checked_at']) && empty($check['error']))
                        <p class="text-sm text-pos mt-3">No newer signed stable release is available.</p>
                    @elseif (empty($check['error']))
                        <p class="text-sm text-fg-muted mt-3">Check for updates to find a compatible signed stable release.</p>
                    @endif
                </section>
                <ol class="cmp-updates-history" aria-label="Recent operations" wire:ignore>
                    <template x-for="entry in historyFor($wire.component, !showOperation())" :key="entry.id">
                        <li class="cmp-updates-event">
                            <p class="cmp-updates-meta" x-text="formatTime(entry.created_at)"></p><h4 x-text="operationLabel(entry)"></h4>
                            <span class="cmp-updates-history-label" :data-state="entry.state" x-text="phaseLabel(entry)"></span>
                            <p class="text-sm text-fg-muted break-words mt-1" x-text="entry.message"></p>
                            <template x-if="entry.diagnostic"><a class="text-link hover:underline text-sm" :href="@js(url('/settings/updates')) + '/' + encodeURIComponent(entry.id) + '/diagnostics'">Download diagnostics</a></template>
                        </li>
                    </template>
                </ol>
                <p x-show="!operation && !historyFor($wire.component).length" x-cloak class="text-sm text-fg-muted">No operations recorded yet.</p>
            </div>
        </div>
        <div class="cmp-updates-advanced" x-data="{ advanced: false }">
            <button type="button" class="cmp-updates-disclosure" x-on:click="advanced = !advanced" :aria-expanded="advanced" aria-controls="updates-advanced">Recovery and other versions <span aria-hidden="true" x-text="advanced ? '⌃' : '⌄'"></span></button>
            <div id="updates-advanced" x-show="advanced" x-cloak class="space-y-5 mt-4">
                <div>
                    <h3>Recovery history</h3>
                    <p class="text-sm text-fg-muted mt-2">One recovery point is kept for 7 days. Reviewing disk space keeps that point. A confirmed application update removes it before the final space check and the new snapshot. Active recovery stays protected.</p>
                    <p class="text-sm text-fg-muted mt-2">Restoring application data discards changes made after the selected snapshot.</p>
                    @forelse ($status['recoveries'] ?? [] as $previous)
                        <div class="flex flex-wrap items-center justify-between gap-3 text-sm mt-3"><span>{{ $previous['component'] }} {{ $previous['version'] ?? '' }} → {{ $previous['previous_version'] ?? 'recorded previous version' }} · {{ $previous['state'] }}</span>
                            <x-confirms-password wire:then="begin('rollback', '{{ $previous['id'] }}')"><button class="cmp-btn cmp-btn-secondary" type="button" :disabled="blocked() || !@js($available)">Review rollback</button></x-confirms-password>
                            @if ($previous['component'] === 'application')
                                <span class="text-xs text-fg-muted" role="status" wire:loading.delay wire:target="begin('rollback', '{{ $previous['id'] }}')">Checking disk space…</span>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-fg-muted mt-3">No retained recovery point.</p>
                    @endforelse
                </div>
                <div class="cmp-updates-source">
                    <div><h3>Utility OS source</h3><p class="text-sm text-fg-muted mt-2">Active: {{ $status['installed']['utility_selector'] ?? 'Not read yet' }}. Select another source for troubleshooting or compatibility.</p></div>
                    @if ($available)
                        <div class="flex flex-wrap gap-2">
                            <x-confirms-password wire:then="begin('activate', 'bundled-modern')"><button type="button" class="cmp-btn cmp-btn-secondary" :disabled="blocked()">Use bundled Utility OS</button></x-confirms-password>
                            <x-confirms-password wire:then="begin('activate', 'legacy')"><button type="button" class="cmp-btn cmp-btn-secondary" :disabled="blocked()">Use legacy Utility OS</button></x-confirms-password>
                        </div>
                    @endif
                </div>
                <p class="text-xs text-fg-muted">Publisher: {{ $status['source']['repository'] ?? 'Not initialized' }} · Checks and downloads can run while boards are flashing. Apply is allowed only on an idle station. No automatic installation or station reboot.</p>
            </div>
        </div>
        @if ($open && $confirmation)
            <x-dialog-modal wire:model.live="open" maxWidth="lg">
                <x-slot name="title">{{ $confirmation['action'] === 'rollback' ? 'Restore a previous version?' : 'Apply station update?' }}</x-slot>
                <x-slot name="content"><div class="space-y-3">
                    <p>{{ $confirmation['description'] }}</p>
                    @if (isset($confirmation['space']))
                        @php($space = $confirmation['space'])
                        <section class="space-y-2" aria-label="Disk space estimate">
                            <h3 class="font-semibold text-fg">Disk space estimate</h3>
                            <p class="text-xs text-fg-muted">Potential release counts eligible recovery files. The updater checks free space again when the operation starts.</p>
                            @foreach ($space['volumes'] as $volume)
                                <div class="border border-line rounded-lg p-3">
                                    <p class="text-sm font-semibold text-fg break-all">{{ $volume['location'] }}</p>
                                    <dl class="grid grid-cols-2 sm:flex justify-between gap-2 mt-2 text-xs">
                                        @foreach (['required' => 'Required', 'free' => 'Free now', 'reclaimable' => 'Potential release', 'shortfall' => 'Shortfall'] as $field => $name)
                                            @php($bytes = $volume[$field])
                                            <div>
                                                <dt>{{ $name }}</dt>
                                                <dd @class(['font-semibold', 'text-neg' => $field === 'shortfall' && $bytes > 0]) title="{{ number_format($bytes) }} bytes">
                                                    @if ($bytes >= 1073741824){{ number_format($bytes / 1073741824, 2) }} GiB
                                                    @elseif ($bytes >= 1048576){{ number_format($bytes / 1048576, 1) }} MiB
                                                    @else{{ number_format($bytes) }} B
                                                    @endif
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                </div>
                            @endforeach
                            @if (isset($space['error']))
                                <p class="text-neg" role="alert">Disk space estimate unavailable. {{ $space['error'] }}</p>
                            @elseif (!$space['sufficient'])
                                <p class="text-neg" role="alert">Free the shortfall on each affected filesystem, then recheck space.</p>
                            @endif
                            <button type="button" wire:click="recheckSpace" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small">Recheck space</button>
                            <span class="text-xs text-fg-muted" role="status" wire:loading.delay wire:target="recheckSpace">Checking disk space…</span>
                        </section>
                    @endif
                    @if ($confirmation['action'] === 'apply' && !empty($status['recoveries']))<p class="text-sm text-fg-muted">Before a new application snapshot, the previous recovery point is retired. If preparation fails, it cannot be used for rollback.</p>@endif
                    <p>The station must be idle. Its web interface and boot services may briefly disconnect. A failed installation automatically restores the immediate recovery state.</p>
                    <p class="text-sm text-fg-muted">Do not disconnect station power. If automatic recovery fails, maintenance stays enabled and recovery requires SSH or the local console.</p>
                    @if ($error)<p class="text-neg" role="alert">{{ $error }}</p>@endif
                </div></x-slot>
                <x-slot name="footer">
                    <button type="button" wire:click="back" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Back</button>
                    @if (isset($confirmation['token']))
                        <button type="button" wire:click="confirm" class="cmp-btn cmp-btn-danger ml-3" :disabled="blocked()" wire:loading.attr="disabled">Confirm {{ $confirmation['action'] === 'rollback' ? 'rollback' : 'apply' }}</button>
                    @endif
                </x-slot>
            </x-dialog-modal>
        @endif
    </div>
</div>
