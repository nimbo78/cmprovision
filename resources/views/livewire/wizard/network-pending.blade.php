<div>
    @if ($pending['pending'] ?? false)
        <section @if ($section === 'all') wire:poll.10s @else wire:poll.10s="refreshPending" @endif
            class="rounded bg-warn-bg text-warn px-4 py-3" aria-label="Pending network change">
            <p class="font-bold">A network change is waiting for confirmation.</p>
            @if ($pending['can_confirm'] ?? false)
                <p class="text-sm mt-1">Interface {{ $pending['device'] ?? 'unknown' }} ·
                    {{ max(0, ($pending['expires_at'] ?? 0) - now()->getTimestamp()) }} seconds remain.
                    Check management access before automatic rollback.</p>
                <div class="flex flex-wrap gap-2 mt-3">
                    <x-confirms-password wire:then="confirm"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Confirm network</button></x-confirms-password>
                    <x-confirms-password wire:then="cancel"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Restore previous</button></x-confirms-password>
                </div>
            @else
                <p class="text-sm mt-1">Recovery is in progress; network changes are temporarily unavailable.</p>
            @endif
        </section>
    @endif
    @if ($section === 'pending' && ($notice || $statusError))
        <p class="cmp-flash text-sm" role="status">{{ $statusError ?? $notice }}</p>
    @endif
</div>
