{{-- USB prep (2.12; one tile since 2.14.0): the last tile of the summary row of the Dashboard (the root is display: contents, so the tile
     is a cell of that grid). A board connected over USB gets the bootloader (network boot first) and no image; it then goes to the
     PoE switch. The tile does it all: the text under the title is the next step for the board prepared last (connect one, wait,
     unplug it), so nothing opens below it while the mode is on and the layout of the page stays where it is. --}}
@php($latest = $boards->first())
<div class="contents" @if ($active) wire:poll.5s @else wire:poll.30s @endif>
    <section class="cmp-tile col-span-full min-[1440px]:col-span-1 grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1" aria-labelledby="usb-prep-title">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h2 id="usb-prep-title" class="font-bold">USB prep</h2>
            @if ($active)<span class="cmp-chip cmp-chip-run"><span class="dot"></span>on · {{ $minutes }} min left</span>@endif
        </div>
        {{-- the tile's title says what it is: the button says only what it does (its name for a screen reader has both) --}}
        @if ($active)
            <button type="button" wire:key="usb-prep-toggle" wire:click="stop" wire:loading.attr="disabled" class="cmp-btn" aria-label="Stop USB prep">Stop</button>
        @else
            <button type="button" wire:key="usb-prep-toggle" wire:click="start" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary" aria-label="Start USB prep"
                @disabled($problem !== null) @if ($problem) aria-describedby="usb-prep-note" @endif>Start</button>
        @endif
        <div class="col-span-2 text-sm" aria-live="polite">
            @if ($error)
                <p class="text-neg" role="alert">{{ $error }}</p>
            @elseif (!$active)
                @if ($problem)
                    <p id="usb-prep-note" class="text-warn" role="status">{{ $problem }}</p>
                @else
                    <p class="text-fg-muted">Set boards to boot from the network</p>
                    <p><a href="{{ route('settings', ['tab' => 'provisioning']) }}#usb-prep" class="text-link hover:underline">Bootloader settings</a></p>
                @endif
            @elseif (!$latest)
                <p>Connect a board over USB</p>
                <p class="text-fg-muted">Waiting for a board · <a href="{{ route('settings', ['tab' => 'provisioning']) }}#usb-prep" class="text-link hover:underline">Settings</a></p>
            @else
                <p>
                    @if ($latest->phase === 'done')
                        <span class="cmp-chip cmp-chip-ok"><span class="dot"></span>ready</span>
                    @elseif ($latest->phase === 'failed')
                        <span class="cmp-chip cmp-chip-fail"><span class="dot"></span>failed</span>
                    @elseif ($latest->silentFor())
                        <span class="cmp-chip cmp-chip-stale"><span class="dot"></span>no report</span>
                    @else
                        <span class="cmp-chip cmp-chip-run"><span class="dot"></span>flashing</span>
                    @endif
                    <a href="{{ route('cm', $latest->mac) }}" class="mono text-link hover:underline">{{ $latest->mac }}</a>
                    @if ($boards->count() > 1)<span class="text-fg-muted whitespace-nowrap">+{{ $boards->count() - 1 }} more</span>@endif
                </p>
                @if ($latest->phase === 'done')
                    <p class="text-fg-muted">Unplug USB and power, plug it into the PoE switch</p>
                @elseif ($latest->phase === 'failed')
                    <p class="text-neg line-clamp-2" title="{{ $latest->phase_detail }}">{{ $latest->phase_detail }}</p>
                @elseif ($latest->silentFor())
                    <p class="text-fg-muted">No report for {{ intdiv($latest->silentFor(), 60) }} min: check the board and its cables</p>
                @else
                    <p class="text-fg-muted">Wait for ready, or both LEDs fading in turn</p>
                @endif
            @endif
        </div>
    </section>
</div>
