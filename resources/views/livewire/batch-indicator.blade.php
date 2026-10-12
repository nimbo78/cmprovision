<div wire:poll.10s.keep-alive class="flex items-center" data-tab-title="{{ $summary ? $summary->title() : '' }}" data-tab-icon="{{ $summary ? $summary->state : 'idle' }}">
    @if ($summary)
        <a href="{{ route('dashboard') }}" class="cmp-batch" title="Project {{ $summary->project?->name }}, batch since {{ $summary->batch->started_at->local()->format('H:i') }}">
            <span class="text-fg-muted hidden xl:inline">{{ $summary->project?->name }}</span>
            @if ($summary->counts['active'])
                <span class="cmp-chip cmp-chip-run"><span class="dot"></span>{{ $summary->counts['active'] }}<span class="hidden md:inline">&nbsp;running</span></span>
            @endif
            <span class="cmp-chip cmp-chip-ok">✓ {{ $summary->counts['done'] }}</span>
            @if ($summary->counts['failed'])
                <span class="cmp-chip cmp-chip-fail">✕ {{ $summary->counts['failed'] }}<span class="hidden md:inline">&nbsp;failed</span></span>
            @endif
        </a>
    @endif
</div>
