<section class="cmp-card px-4 py-3" aria-label="Storage diagnostics">
    <div class="font-bold mb-2">Storage diagnostics</div>
    @if ($storageDiagnosis)
        <p class="font-semibold {{ $storageDiagnosis['severity'] === 'error' ? 'text-neg' : ($storageDiagnosis['severity'] === 'warning' ? 'text-warn' : 'text-fg') }}">{{ $storageDiagnosis['title'] }}</p>
        <p class="text-sm text-fg-muted mb-2">Snapshot received {{ $cm->storage_diagnostics_at->local()->format('d.m.Y H:i:s') }} · {{ $cm->storage_diagnostics['context'] }}</p>
        @forelse ($storageDiagnosis['issues'] as $issue)
            <div class="mb-3 text-sm">
                @if (!$loop->first)<p class="font-semibold">{{ $issue['title'] }}</p>@endif
                <p>{{ $issue['advice'] }}</p>
                @if ($issue['evidence'])<p class="font-mono text-xs break-words mt-1">{{ $issue['evidence'] }}</p>@endif
            </div>
        @empty
            <p class="text-sm text-fg-muted mb-2">No warnings in the collected snapshot. This is not a full storage read/write test.</p>
        @endforelse
        @php($facts = $cm->storage_diagnostics['facts'])
        <details class="mb-2">
            <summary class="cursor-pointer text-sm font-semibold">Device facts and kernel output</summary>
            <div class="cmp-card-scroll mt-2">
                <table class="cmp-table text-sm">
                    @foreach (['target' => 'Storage path', 'block' => 'Block device', 'controller' => 'MMC controller', 'host' => 'MMC host',
                        'sectors' => 'Size (512-byte sectors)', 'card_type' => 'Card type', 'card_name' => 'Card name', 'manfid' => 'Manufacturer ID',
                        'cid' => 'CID', 'fwrev' => 'Card firmware', 'life_time' => 'eMMC lifetime estimate', 'pre_eol_info' => 'eMMC pre-EOL',
                        'readonly' => 'Read-only', 'throttled' => 'Power / throttle flags', 'kernel' => 'Kernel', 'model' => 'Model'] as $key => $label)
                        <tr><td class="text-fg-muted">{{ $label }}</td><td class="font-mono {{ $key === 'model' ? 'break-words' : 'break-all' }}">{{ ($facts[$key] ?? '') !== '' ? $facts[$key] : 'not reported' }}</td></tr>
                    @endforeach
                </table>
            </div>
            @foreach (['kernel' => 'Relevant kernel messages', 'partitions' => 'Block devices', 'mmc_ios' => 'MMC bus state'] as $key => $label)
                <div class="text-sm text-fg-muted mt-2">{{ $label }}</div>
                <pre class="text-xs font-mono bg-head rounded p-2 overflow-x-auto" style="max-height: 18rem">{{ $cm->storage_diagnostics['sections'][$key] ?: 'not reported' }}</pre>
            @endforeach
        </details>
        <button wire:click="downloadStorageDiagnostics" class="cmp-btn cmp-btn-secondary cmp-btn-small">Download report</button>
    @else
        <p class="text-sm text-fg-muted mb-2">No diagnostics report for this run. A module must boot the utility OS and reach this server over Ethernet.
            Reports are collected by scripts served by this version of cmprovision; an older running script needs a new boot.</p>
    @endif
    @if ($diagnosticsAvailable)
        <button wire:click="collectStorageDiagnostics" wire:loading.attr="disabled" @disabled($diagnosticsPending) class="cmp-btn cmp-btn-secondary cmp-btn-small">Collect again</button>
    @else
        <p class="text-sm text-fg-muted mt-2">A new snapshot can be requested while the module is online and waiting in the utility OS after a completed or failed run.</p>
    @endif
    @if ($diagnosticsPending)
        <p class="text-sm text-fg-muted mt-2" role="status">Waiting for the module to send a new snapshot…</p>
    @elseif ($cm->diagnostics_requested_at)
        <p class="text-sm text-warn mt-2" role="status">No new snapshot arrived within 90 seconds. Check module power and Ethernet connectivity before retrying.</p>
    @endif
    @if ($diagnosticsMessage)<p class="text-sm text-warn mt-2" role="status">{{ $diagnosticsMessage }}</p>@endif
</section>
