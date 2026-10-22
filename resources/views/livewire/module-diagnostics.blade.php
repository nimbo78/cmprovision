<section class="cmp-card px-4 py-3" aria-label="Module diagnostics">
    <div class="font-bold mb-2">Module diagnostics</div>
    @if ($moduleDiagnosis)
        @include('livewire.diagnostics-snapshot', ['report' => $cm->storage_diagnostics, 'receivedAt' => $cm->storage_diagnostics_at, 'diagnosis' => $moduleDiagnosis])
        <button wire:click="downloadModuleDiagnostics" class="cmp-btn cmp-btn-secondary cmp-btn-small">Download report</button>
    @else
        <p class="text-sm text-fg-muted mb-2">No diagnostics report for this run. A module must boot the utility OS and reach this server over Ethernet.
            Reports are collected by scripts served by this version of cmprovision; an older running script needs a new boot.</p>
    @endif
    @if ($usbReport)
        <p class="text-sm mt-2"><a href="{{ route('diagnostics', $usbReport->id) }}" class="text-link hover:underline">USB report received {{ $usbReport->received_at->local()->format('d.m.Y H:i:s') }}</a></p>
    @endif
    @if ($diagnosticsAvailable)
        <button wire:click="collectModuleDiagnostics" wire:loading.attr="disabled" @disabled($diagnosticsPending) class="cmp-btn cmp-btn-secondary cmp-btn-small">Collect again</button>
    @else
        <p class="text-sm text-fg-muted mt-2">A new snapshot can be requested while the module is online and waiting in the utility OS after a completed or failed run.</p>
    @endif
    @if ($diagnosticsPending)
        <p class="text-sm text-fg-muted mt-2" role="status">Waiting for the module to send a new snapshot…</p>
    @elseif ($cm->diagnostics_requested_at?->gt(now()->subSeconds(\App\Support\ModuleDiagnostics::REQUEST_EXPIRES_SECONDS)))
        <p class="text-sm text-warn mt-2" role="status">No Ethernet snapshot arrived within 90 seconds. A connected USB diagnostic agent can provide the fallback report; otherwise check module power and connectivity.</p>
    @endif
    @if ($diagnosticsMessage)<p class="text-sm text-warn mt-2" role="status">{{ $diagnosticsMessage }}</p>@endif
</section>
