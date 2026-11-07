<div class="cmp-page" x-data x-on:transfer-opened.window="const url = new URL(window.location.href); if ($event.detail.id) url.searchParams.set('operation', $event.detail.id); else url.searchParams.delete('operation'); history.replaceState({}, '', url); if ($event.detail.id) $nextTick(() => document.getElementById('current-transfer')?.focus())">
    <div class="cmp-title flex-wrap gap-3">
        <h1>Configuration transfer</h1>
        <a href="{{ route('settings', ['tab' => 'status']) }}#backup" class="cmp-btn cmp-btn-secondary ml-auto">Back to Backup</a>
    </div>
    <p class="text-fg-muted mb-4">Move selected projects, scripts, labels and settings to another station. Review dependencies and conflicts before importing.</p>
    @if ($notice)<div class="cmp-flash mb-4" role="status">{{ $notice }}</div>@endif
    @if (session('transferError'))<div class="cmp-flash mb-4 text-neg" role="alert">{{ session('transferError') }}</div>@endif
    @if ($errors->any())<div class="cmp-flash mb-4 text-neg" role="alert" tabindex="-1" x-data x-init="$nextTick(() => $el.focus())"><p class="font-bold">Check this transfer</p>@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

    @if ($operation)
        <section class="cmp-card p-4 mt-4" @if(in_array($status, ['queued', 'preparing', 'reviewing', 'applying'])) wire:poll.3s="refreshOperation" @endif>
            <h2 id="current-transfer" tabindex="-1" class="text-lg font-bold">{{ $status === 'applied' ? 'Import complete' : 'Current transfer' }}</h2>
            <p class="text-sm text-fg-muted my-2">{{ ucfirst($operation->direction) }} · {{ $status }} · expires {{ $operation->expires_at->format('d M H:i') }}</p>
            @if ($operation->error)<p class="text-neg" role="alert">{{ $operation->error }}</p>@endif
            @if (in_array($status, ['queued', 'preparing', 'reviewing', 'applying']))
                <p role="status">{{ match ($status) { 'queued' => 'Waiting for the images worker…', 'reviewing' => 'Checking choices and receiving files…', 'applying' => 'Applying reviewed configuration…', default => 'Preparing and verifying files…' } }}</p>
                <progress class="cmp-progress mt-2" aria-label="Transfer preparation" @if($operation->total_bytes) value="{{ $operation->progress_bytes }}" max="{{ $operation->total_bytes }}" @endif></progress>
            @elseif ($status === 'prepared')
                <button type="button" class="cmp-btn" wire:click="review">Prepare preview again</button>
            @elseif ($status === 'ready')
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('settings.transfer.download', $operationId) }}" class="cmp-btn">Download export</a>
                    @if ($operation->format === 'json')<x-confirms-password wire:then="showText"><button type="button" class="cmp-btn cmp-btn-secondary">Show text to copy</button></x-confirms-password>@endif
                </div>
                @if ($copyText !== '')
                    <div class="mt-3" x-data="{ copied: false }">
                        <textarea readonly rows="10" x-ref="exportText" class="cmp-field w-full font-mono" aria-label="Exported configuration">{{ $copyText }}</textarea>
                        <button type="button" class="cmp-btn cmp-btn-secondary mt-2" x-on:click="if (navigator.clipboard) { navigator.clipboard.writeText($refs.exportText.value).then(() => copied = true) } else { $refs.exportText.select(); copied = document.execCommand('copy') }">Copy text</button>
                        <span x-show="copied" class="text-sm text-pos">Copied</span>
                    </div>
                @endif
            @endif
            @if ($preview && $status === 'previewed')
                <div x-data="{ secrets: {} }">
                    <h3 class="font-bold mt-4">Review import</h3>
                    <p class="text-sm text-fg-muted my-2">Identical definitions are reused. Name conflicts create copies; fixed identities need an explicit choice. Projects remain inactive.</p>
                    <div class="space-y-3">
                        @foreach ($preview['objects'] as $item)
                            <div class="border border-pline rounded p-3" wire:key="transfer-{{ $item['ref'] }}">
                                <div class="flex gap-2 items-start">
                                    <x-checkbox aria-label="Select {{ $item['name'] }}" wire:model="choices.objects.{{ $item['ref'] }}.selected" />
                                    <div class="min-w-0"><p class="font-bold break-words">{{ $item['name'] }}</p><p class="text-xs text-fg-muted">{{ $item['type'] }} · {{ $item['ref'] }} @if($item['file_status'])· file: {{ $item['file_status'] }}@endif</p></div>
                                </div>
                                <div class="grid gap-2 sm:grid-cols-2 mt-2">
                                    <select class="cmp-field w-full" aria-label="Action for {{ $item['name'] }}" wire:model="choices.objects.{{ $item['ref'] }}.action">
                                        @foreach (['create' => 'Create', 'copy' => 'Create copy', 'reuse' => 'Reuse existing', 'replace' => 'Replace existing', 'skip' => 'Skip'] as $action => $label)<option value="{{ $action }}">{{ $label }}</option>@endforeach
                                    </select>
                                    <select class="cmp-field w-full" aria-label="Receiving object for {{ $item['name'] }}" wire:model="choices.objects.{{ $item['ref'] }}.target">
                                        <option value="">Choose receiving object</option>
                                        @foreach ($item['targets'] as $target)<option value="{{ $target['id'] }}">{{ $target['name'] }} ({{ $target['id'] }})</option>@endforeach
                                    </select>
                                </div>
                                @if (in_array($item['type'], ['project', 'script', 'label']))
                                    <label class="block text-sm mt-2">Name for a copy<input type="text" class="cmp-field w-full mt-1" wire:model="choices.objects.{{ $item['ref'] }}.name" placeholder="{{ $item['name'] }}"></label>
                                @endif
                                @if ($item['type'] === 'network')
                                    <div class="grid gap-2 sm:grid-cols-2 mt-2">
                                        <label class="text-sm">Receiving interface<input type="text" class="cmp-field w-full mt-1" wire:model="choices.interfaces.{{ $item['ref'] }}.device" placeholder="eth0.15"></label>
                                        <label class="text-sm">VLAN parent, if applicable<input type="text" class="cmp-field w-full mt-1" wire:model="choices.interfaces.{{ $item['ref'] }}.parent" placeholder="eth0"></label>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if ($preview['requirements'])
                        <div class="bg-warn-bg text-warn rounded p-3 my-3">
                            <p class="font-bold">Resolve before applying</p>
                            @foreach ($preview['requirements'] as $requirement)
                                <div class="mt-2 text-sm"><p>{{ $requirement['ref'] }} · {{ $requirement['field'] }}: {{ $requirement['message'] }}</p>
                                    @if (str_contains($requirement['message'], 'Supply the excluded secret'))
                                        <input type="password" autocomplete="off" class="cmp-field w-full mt-1" aria-label="{{ $requirement['ref'] }} {{ $requirement['field'] }}" x-on:input="secrets[{{ Js::from($requirement['ref']) }}] ??= {}; secrets[{{ Js::from($requirement['ref']) }}][{{ Js::from($requirement['field']) }}] = $event.target.value">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button type="button" class="cmp-btn cmp-btn-secondary" x-on:click="$wire.review(secrets).then(() => secrets = {})" wire:loading.attr="disabled">Update preview</button>
                        <x-confirms-password wire:then="applyImport"><button type="button" class="cmp-btn" @disabled(!$preview['can_apply'] || $previewDirty) wire:loading.attr="disabled">Apply reviewed import</button></x-confirms-password>
                    </div>
                </div>
            @endif
            @if ($report && $status === 'applied')
                <p class="text-sm my-3">Created {{ $report['counts']['created'] }} · reused {{ $report['counts']['reused'] }} · replaced {{ $report['counts']['replaced'] }} · skipped {{ $report['counts']['skipped'] }}</p>
                @if (!empty($operation->options['retired']))
                    <div class="rounded bg-warn-bg text-warn p-3 my-3">
                        <p class="text-sm">Superseded file cleanup is pending. The imported configuration is saved; old files will be retried on cleanup.</p>
                        <x-confirms-password wire:then="retryFileCleanup"><button type="button" class="cmp-btn cmp-btn-secondary mt-2" wire:loading.attr="disabled">Retry file cleanup</button></x-confirms-password>
                    </div>
                @endif
                <div class="space-y-2">
                    @foreach ($report['objects'] as $ref => $item)
                        <div class="text-sm">{{ $item['name'] ?? $ref }} · {{ $item['action'] }}
                            @if ($item['type'] === 'project')<a class="text-link underline" href="{{ route('projects') }}">Review and activate project</a>@endif
                            @if ($item['type'] === 'network')<button type="button" class="text-link underline" wire:click="openNetworkDraft('{{ $ref }}')">Open network draft</button>@endif
                        </div>
                    @endforeach
                </div>
                @if ($report['followups'])
                    <p class="text-sm text-warn mt-4">Additional actions are pending. Network and display services change only through their normal settings controls.</p>
                    <a class="cmp-btn cmp-btn-secondary mt-2" href="{{ route('settings', ['tab' => 'network']) }}">Network settings</a>
                    <a class="cmp-btn cmp-btn-secondary mt-2" href="{{ route('settings', ['tab' => 'provisioning']) }}">Provisioning settings</a>
                    @if (isset($report['followups']['display']))
                        <div class="mt-3 text-sm">
                            HDMI: {{ $report['followups']['display']['wallboard']['mode'] }} · {{ $report['followups']['display']['wallboard']['palette'] }}.
                            Front panel: {{ $report['followups']['display']['fpms_service']['enabled'] ? 'enabled' : 'disabled' }}.
                            <x-confirms-password wire:then="applyDisplayPreferences"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Apply imported display preferences</button></x-confirms-password>
                        </div>
                    @endif
                    @if (!empty($report['followups']['usb_prep']))
                        <x-confirms-password wire:then="rebuildUsbPrep"><button type="button" class="cmp-btn cmp-btn-secondary mt-3" wire:loading.attr="disabled">Rebuild imported USB-prep image</button></x-confirms-password>
                    @endif
                    @if (!empty($report['followups']['dhcp']))
                        <p class="text-sm mt-3">DHCP reservations are saved. Apply them when the stand is idle.</p>
                        <x-confirms-password wire:then="applyDhcpReservations"><button type="button" class="cmp-btn cmp-btn-secondary mt-2" wire:loading.attr="disabled">Apply imported DHCP reservations</button></x-confirms-password>
                    @endif
                @endif
            @endif
            @if ($status !== 'applied')<button type="button" class="cmp-btn cmp-btn-secondary mt-4" wire:click="cancelTransfer">Cancel transfer</button>@endif
        </section>
    @endif

    <section class="cmp-card p-4 mb-4" x-data="{ masterSecret: '' }" x-on:master-password-saved.window="masterSecret = ''">
        <h2 class="text-lg font-bold">Master password</h2>
        <p class="text-sm text-fg-muted my-2">{{ $masterConfigured ? 'A master password is saved on this station.' : 'No master password is saved.' }} Use the same password on another station, or enter a one-time password for a transfer. The password protects exported secrets.</p>
        <div class="flex flex-wrap gap-2 items-end">
            <label class="w-full sm:w-auto sm:flex-1 min-w-0"><span class="block text-sm font-bold mb-1">New master password</span>
                <input id="master-password" type="password" x-model="masterSecret" autocomplete="new-password" class="cmp-field w-full" minlength="12" maxlength="1024" aria-describedby="master-password-help">
            </label>
            <x-confirms-password wire:then="saveMasterPassword(masterSecret)"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Save password</button></x-confirms-password>
            @if ($masterConfigured)<x-confirms-password wire:then="forgetMasterPassword"><button type="button" class="cmp-btn cmp-btn-secondary">Forget</button></x-confirms-password>@endif
        </div>
        <p id="master-password-help" class="text-xs text-fg-muted mt-2">12–1024 characters. The saved password is encrypted with this station's application key.</p>
        @error('master_password')<p class="text-sm text-neg mt-1">{{ $message }}</p>@enderror
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="cmp-card p-4" x-data="{ exportPassword: '' }" x-on:transfer-queued.window="exportPassword = ''">
            <h2 class="text-lg font-bold">Export</h2>
            <p class="text-sm text-fg-muted mt-1 mb-4">Projects include their required scripts, label, image, packages and EEPROM definitions.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach (['projects' => 'Projects', 'scripts' => 'Scripts', 'labels' => 'Labels'] as $kind => $title)
                    <fieldset><legend class="font-bold mb-2">{{ $title }}</legend>
                        <div class="max-h-48 overflow-auto space-y-2">
                            @forelse ($catalog[$kind] as $item)
                                <label class="flex gap-2 items-start text-sm"><x-checkbox wire:model="selection.{{ $kind }}" value="{{ $item['id'] }}" /><span class="break-words min-w-0">{{ $item['name'] }}</span></label>
                            @empty<p class="text-sm text-fg-muted">None saved</p>@endforelse
                        </div>
                    </fieldset>
                @endforeach
                <fieldset><legend class="font-bold mb-2">Settings groups</legend>
                    <div class="space-y-2">@foreach ($catalog['groups'] as $key => $label)
                        <label class="flex gap-2 items-start text-sm"><x-checkbox wire:model="selection.groups" value="{{ $key }}" /><span>{{ $label }}</span></label>
                    @endforeach</div>
                </fieldset>
            </div>
            <div class="mt-4">
                <label for="transfer-format" class="block text-sm font-bold mb-1">Format</label>
                <select id="transfer-format" wire:model.live="format" class="cmp-field w-full">
                    <option value="json">JSON — download or copy as text</option><option value="archive">ZIP — optionally include binary files</option>
                </select>
            </div>
            @if ($format === 'archive')
                <fieldset class="mt-3"><legend class="text-sm font-bold">Include binary files</legend>
                    <div class="flex flex-wrap gap-4 mt-2">@foreach (['images' => 'Images', 'packages' => 'Packages', 'firmware' => 'EEPROM'] as $key => $label)
                        <label class="text-sm"><x-checkbox wire:model="selection.binaries" value="{{ $key }}" /> {{ $label }}</label>
                    @endforeach</div>
                </fieldset>
            @endif
            <div class="space-y-2 mt-4">
                <label class="block text-sm"><x-checkbox wire:model.live="includeSecrets" /> Include saved passwords, tokens and recognition key</label>
                <label class="block text-sm"><x-checkbox wire:model.live="protectExport" /> Protect this export with a password</label>
                <p class="text-xs text-fg-muted">Secrets always require protection. Scripts, printer commands and templates may also contain credentials you entered; protect those exports when needed.</p>
            </div>
            @if ($protectExport || $includeSecrets)
                <label class="block mt-3"><span class="block text-sm font-bold mb-1">Transfer password</span>
                    <input type="password" x-model="exportPassword" autocomplete="new-password" class="cmp-field w-full" placeholder="{{ $masterConfigured ? 'Leave empty to use saved master password' : 'At least 12 characters' }}">
                </label>
            @endif
            <div class="mt-4">
                <x-confirms-password wire:then="startExport(exportPassword || null)"><button type="button" class="cmp-btn" wire:loading.attr="disabled"><span wire:loading.remove wire:target="startExport">Prepare export</span><span wire:loading wire:target="startExport" role="status">Preparing export…</span></button></x-confirms-password>
            </div>
        </section>

        <section class="cmp-card p-4" x-data>
            <h2 class="text-lg font-bold">Import</h2>
            <p class="text-sm text-fg-muted mt-1 mb-4">Load a file or paste configuration text. Preparation checks the manifest and verifies included files before preview.</p>
            <form method="POST" action="{{ route('settings.transfer.import') }}" enctype="multipart/form-data">
                @csrf
                <label for="configuration-file" class="block text-sm font-bold mb-1">Configuration file</label>
                <input id="configuration-file" type="file" name="configuration" accept=".json,.zip,.cmp" required class="cmp-field w-full">
                <label for="configuration-password" class="block text-sm font-bold mb-1 mt-3">Password, if different from the saved master password</label>
                <input id="configuration-password" type="password" name="password" autocomplete="off" class="cmp-field w-full">
                <p class="text-xs text-fg-muted mt-2">JSON, ZIP or protected CMP. This server accepts uploads up to 8 GiB; the transfer format supports 64 GiB. Binary files can also be uploaded normally before importing JSON.</p>
                <button type="submit" class="cmp-btn mt-3">Upload and prepare</button>
            </form>
            <div class="border-t border-pline mt-5 pt-4">
                <label for="configuration-text" class="block text-sm font-bold mb-1">Or paste text</label>
                <textarea id="configuration-text" x-ref="importText" rows="7" class="cmp-field w-full font-mono" spellcheck="false"></textarea>
                <label class="block mt-3"><span class="block text-sm font-bold mb-1">Transfer password</span>
                    <input type="password" x-ref="importPassword" autocomplete="off" class="cmp-field w-full">
                </label>
                <button type="button" class="cmp-btn mt-3" x-on:click="$wire.startTextImport($refs.importText.value, $refs.importPassword.value || null).then(() => { $refs.importPassword.value = ''; $refs.importText.value = '' })" wire:loading.attr="disabled">Prepare pasted text</button>
            </div>
        </section>
    </div>


</div>
