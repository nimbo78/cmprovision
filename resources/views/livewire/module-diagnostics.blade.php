<section class="cmp-card px-4 py-3" aria-label="Module diagnostics">
    <div class="font-bold mb-2">Module diagnostics</div>
    @if ($moduleDiagnosis)
        <p class="font-semibold {{ $moduleDiagnosis['severity'] === 'error' ? 'text-neg' : ($moduleDiagnosis['severity'] === 'warning' ? 'text-warn' : 'text-fg') }}">{{ $moduleDiagnosis['title'] }}</p>
        <p class="text-sm text-fg-muted mb-2">Snapshot received {{ $cm->storage_diagnostics_at->local()->format('d.m.Y H:i:s') }} · {{ $cm->storage_diagnostics['context'] }}</p>
        <div class="grid sm:grid-cols-2 gap-2 mb-3">
            @foreach ($moduleDiagnosis['checks'] as $check)
                <div class="bg-head rounded p-2 text-sm min-w-0">
                    <div class="text-fg-muted">{{ $check['label'] }}</div>
                    <p class="break-words {{ $check['severity'] === 'error' ? 'text-neg' : ($check['severity'] === 'warning' ? 'text-warn' : 'text-fg') }}">{{ $check['value'] }}</p>
                    @if ($check['severity'] !== 'info')<span class="text-xs font-semibold">{{ $check['severity'] === 'error' ? 'Error reported' : 'Warning reported' }}</span>@endif
                </div>
            @endforeach
        </div>
        @forelse ($moduleDiagnosis['issues'] as $issue)
            <div class="mb-3 text-sm">
                @if (!$loop->first)<p class="font-semibold">{{ $issue['title'] }}</p>@endif
                <p>{{ $issue['advice'] }}</p>
                @if ($issue['evidence'])<p class="font-mono text-xs break-words mt-1">{{ $issue['evidence'] }}</p>@endif
            </div>
        @empty
            <p class="text-sm text-fg-muted mb-2">No warnings in the collected snapshot. Unavailable readings are not a pass. This snapshot is not a storage, RAM or network stress test.</p>
        @endforelse
        @php($facts = $cm->storage_diagnostics['facts'])
        <details class="mb-2">
            <summary class="cursor-pointer text-sm font-semibold">Device facts and kernel output</summary>
            <div class="cmp-card-scroll mt-2">
                <table class="cmp-table text-sm">
                    @foreach ([
                        'Storage' => ['target' => 'Storage path', 'block' => 'Block device', 'controller' => 'MMC controller', 'host' => 'MMC host',
                            'sectors' => 'Size (512-byte sectors)', 'card_type' => 'Card type', 'card_name' => 'Card name', 'manfid' => 'Manufacturer ID',
                            'cid' => 'CID', 'fwrev' => 'Card firmware', 'life_time' => 'eMMC lifetime estimate', 'pre_eol_info' => 'eMMC pre-EOL', 'readonly' => 'Read-only'],
                        'Power & temperature' => ['throttled' => 'Firmware flags (current and since boot)', 'thermal_sensor' => 'Temperature sensor',
                            'temperature_mc' => 'Temperature (millidegrees Celsius)', 'thermal_trip_mc' => 'Lowest passive / hot / critical trip (millidegrees Celsius)'],
                        'Memory' => ['mem_total_kb' => 'Total RAM (KiB)', 'mem_available_kb' => 'Available RAM (KiB)'],
                        'Ethernet · eth0' => ['ethernet' => 'Interface', 'eth_carrier' => 'Carrier (1 = up)', 'eth_operstate' => 'Operational state',
                            'eth_speed_mbps' => 'Speed (Mbps)', 'eth_duplex' => 'Duplex', 'eth_rx_errors' => 'RX errors', 'eth_tx_errors' => 'TX errors',
                            'eth_rx_dropped' => 'RX dropped', 'eth_tx_dropped' => 'TX dropped'],
                        'Boot & system' => ['kernel' => 'Kernel', 'architecture' => 'Architecture', 'model' => 'Model', 'utility_os' => 'Utility OS',
                            'bootloader_version' => 'Running bootloader Git version', 'uptime_seconds' => 'Uptime (seconds)'],
                    ] as $group => $fields)
                        <tr><th colspan="2" class="text-left">{{ $group }}</th></tr>
                        @foreach ($fields as $key => $label)
                            <tr><td class="text-fg-muted">{{ $label }}</td><td class="font-mono {{ $key === 'model' ? 'break-words' : 'break-all' }}">{{ ($facts[$key] ?? '') !== '' ? $facts[$key] : 'not reported' }}</td></tr>
                        @endforeach
                    @endforeach
                    <tr><td class="text-fg-muted">Boot mode</td><td>{{ \App\Support\ModuleDiagnostics::bootMode($facts['boot_mode'] ?? null) }}</td></tr>
                    <tr><td class="text-fg-muted">EEPROM before this run</td><td>{{ $cm->eepromVersionBefore() ?? 'not reported' }}</td></tr>
                    <tr><td class="text-fg-muted">EEPROM after this run</td><td>{{ $cm->eepromVersionAfter() ?? 'not reported' }}</td></tr>
                </table>
            </div>
            <p class="text-xs text-fg-muted mt-2">Ethernet counters are totals since interface initialization. A non-zero count alone does not diagnose a faulty cable. The running bootloader may have been supplied over USB; EEPROM dates above use the separate EEPROM report.</p>
            @foreach (['kernel' => 'Relevant kernel messages', 'partitions' => 'Block devices', 'mmc_ios' => 'MMC bus state', 'network' => 'IPv4 addresses and routes on eth0'] as $key => $label)
                <div class="text-sm text-fg-muted mt-2">{{ $label }}</div>
                <pre class="text-xs font-mono bg-head rounded p-2 overflow-x-auto" style="max-height: 18rem">{{ ($cm->storage_diagnostics['sections'][$key] ?? '') ?: 'not reported' }}</pre>
            @endforeach
        </details>
        <button wire:click="downloadModuleDiagnostics" class="cmp-btn cmp-btn-secondary cmp-btn-small">Download report</button>
    @else
        <p class="text-sm text-fg-muted mb-2">No diagnostics report for this run. A module must boot the utility OS and reach this server over Ethernet.
            Reports are collected by scripts served by this version of cmprovision; an older running script needs a new boot.</p>
    @endif
    @if ($diagnosticsAvailable)
        <button wire:click="collectModuleDiagnostics" wire:loading.attr="disabled" @disabled($diagnosticsPending) class="cmp-btn cmp-btn-secondary cmp-btn-small">Collect again</button>
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
