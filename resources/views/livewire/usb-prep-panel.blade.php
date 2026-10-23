{{-- USB prep (2.12): a board connected over USB gets the bootloader (network boot first) and no image; it then goes to the PoE switch --}}
<div class="cmp-card px-4 py-3" @if ($active) wire:poll.5s @else wire:poll.30s @endif>
    <div class="flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="font-bold">USB prep @if ($active)<span class="cmp-chip cmp-chip-run ml-1"><span class="dot"></span>on · {{ $minutes }} min</span>@endif</h2>
            @if ($active)
                <p class="text-sm text-fg-muted">A board that boots over USB gets only the bootloader, with network boot first: no image is written.
                    Wait for <strong>ready</strong> below (or the slow fade of both LEDs), unplug USB and power, and plug the board into the PoE switch.
                    Boards on the network are provisioned as usual. It switches off by itself after an hour without a board.</p>
            @else
                <p class="text-sm text-fg-muted">Set a board to boot from the network first: connect it over USB, wait until it is ready, then plug it into the PoE switch.
                    The bootloader it gets is set under <a href="{{ route('settings', ['tab' => 'provisioning']) }}#usb-prep" class="text-link hover:underline">Settings → Provisioning</a>.</p>
            @endif
        </div>
        @if ($active)
            <button wire:click="stop" class="cmp-btn">Stop USB prep</button>
        @else
            <button wire:click="start" class="cmp-btn cmp-btn-secondary" @disabled($problem !== null) @if ($problem) title="{{ $problem }}" @endif>Prepare boards over USB</button>
        @endif
    </div>
    @if ($error)
        <p class="mt-2 text-sm text-neg" role="alert">{{ $error }}</p>
    @elseif ($problem && !$active)
        <p class="mt-2 text-sm text-warn" role="status">{{ $problem }}</p>
    @endif

    @if ($boards->isNotEmpty())
        <div class="cmp-card-scroll mt-3">
            <table class="cmp-table text-sm">
                <thead><tr><th>Board</th><th>Result</th><th>Started</th></tr></thead>
                <tbody>
                @foreach ($boards as $cm)
                    <tr wire:key="prep-{{ $cm->id }}">
                        <td class="mono whitespace-nowrap"><a href="{{ route('cm', $cm->mac) }}" class="text-link hover:underline">{{ $cm->mac }}</a>@if (trim((string) $cm->hostname) !== '') <span class="text-fg-muted">{{ $cm->hostname }}</span>@endif</td>
                        <td>
                            @if ($cm->phase === 'done')
                                <span class="cmp-chip cmp-chip-ok"><span class="dot"></span>ready</span>
                                <span class="text-fg-muted">unplug it and plug it into the PoE switch</span>
                            @elseif ($cm->phase === 'failed')
                                <span class="cmp-chip cmp-chip-fail"><span class="dot"></span>failed</span>
                                <span class="text-neg">{{ $cm->phase_detail }}</span>
                            @else
                                <span class="cmp-chip cmp-chip-run"><span class="dot"></span>flashing</span>
                            @endif
                        </td>
                        <td class="tabular-nums text-fg-muted whitespace-nowrap">{{ $cm->usb_prep_at->local()->format('H:i:s') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
