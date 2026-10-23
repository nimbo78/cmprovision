<div @unless($paused) wire:poll.5s="refresh" @endunless>
    <pre x-data="{ stick: true }"
         x-init="$el.scrollTop = $el.scrollHeight; new MutationObserver(() => { if (stick) $el.scrollTop = $el.scrollHeight }).observe($el, { childList: true, characterData: true, subtree: true })"
         x-on:scroll="stick = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 24"
         class="cmp-field w-full h-96 overflow-auto whitespace-pre font-mono text-xs leading-snug" tabindex="0">{{ $text }}</pre>
    <div class="mt-2 flex items-center justify-between text-sm text-fg-muted">
        <span>@if ($paused) Paused: the window shows the log as it was when you paused. @else New lines appear here by themselves, every 5 seconds. @endif</span>
        <button type="button" wire:click="togglePause" class="cmp-btn cmp-btn-secondary cmp-btn-small">{{ $paused ? 'Resume' : 'Pause' }}</button>
    </div>
</div>
