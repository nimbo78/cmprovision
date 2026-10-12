{{-- display: contents, so an empty list takes no gap in the page's column --}}
<div wire:poll.10s class="contents">
    @if ($message)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $messageIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $message }}</div>
    @endif
    @foreach ($outages as $o)
        <div wire:key="poe-outage-{{ $o->id }}" class="bg-neg-bg border-l-4 border-neg-bar text-neg px-4 py-3 rounded flex flex-wrap items-center gap-3" role="alert">
            <span class="font-semibold">{{ 'PoE on '.$o->port.' stayed off after a restart at '.$o->off_at->local()->format('H:i').($o->user ? ' (by '.$o->user.')' : '').($o->error ? ': '.$o->error : '') }}</span>
            <button type="button" wire:click="powerOn({{ $o->id }})" wire:loading.attr="disabled" class="cmp-btn cmp-btn-small ml-auto">Power on</button>
            <button type="button" wire:click="forget({{ $o->id }})" wire:confirm="The port may still be off on the switch. Forget it?" class="cmp-btn cmp-btn-secondary cmp-btn-small">Forget</button>
        </div>
    @endforeach
</div>
