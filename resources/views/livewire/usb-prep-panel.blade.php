{{-- USB prep (2.12): a tile at the end of the summary row of the Dashboard (its root is display: contents, so the tile and the card
     of the running mode are cells of that grid). A board connected over USB gets the bootloader (network boot first) and no image;
     it then goes to the PoE switch. While the mode is on, the steps and the boards it prepared take a row under the tiles. --}}
<div class="contents" @if ($active) wire:poll.5s @else wire:poll.30s @endif>
    <section class="cmp-tile col-span-full min-[1440px]:col-span-1 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1" aria-labelledby="usb-prep-title">
        <h2 id="usb-prep-title" class="font-bold">USB prep</h2>
        @if ($active)
            <button type="button" wire:key="usb-prep-toggle" wire:click="stop" wire:loading.attr="disabled" class="cmp-btn">Stop USB prep</button>
        @else
            <button type="button" wire:key="usb-prep-toggle" wire:click="start" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary"
                @disabled($problem !== null) @if ($problem) aria-describedby="usb-prep-note" @endif>Start USB prep</button>
        @endif
        @if ($error)
            <p class="col-span-2 text-sm text-neg" role="alert">{{ $error }}</p>
        @elseif ($active)
            <p class="col-span-2"><span class="cmp-chip cmp-chip-run"><span class="dot"></span>on · {{ $minutes }} min left</span></p>
        @elseif ($problem)
            <p id="usb-prep-note" class="col-span-2 text-sm text-warn" role="status">{{ $problem }}</p>
        @else
            <p class="col-span-2 text-sm text-fg-muted">Set boards to boot from the network</p>
        @endif
    </section>

    @if ($active)
        <div class="cmp-card col-span-full" wire:key="usb-prep-on">
            <div class="px-4 py-3 text-sm">
                <ol class="flex flex-wrap gap-x-6 gap-y-1">
                    <li><span class="font-bold tabular-nums">1</span> Connect a board over USB</li>
                    <li><span class="font-bold tabular-nums">2</span> Wait for <span class="cmp-chip cmp-chip-ok"><span class="dot"></span>ready</span> or both LEDs fading in turn</li>
                    <li><span class="font-bold tabular-nums">3</span> Unplug USB and power, plug it into the PoE switch</li>
                </ol>
                <p class="mt-1 text-fg-muted">Boards on the network are provisioned as usual. It turns off after an hour without a board.
                    Bootloader: <a href="{{ route('settings', ['tab' => 'provisioning']) }}#usb-prep" class="text-link hover:underline">Settings → Provisioning</a></p>
            </div>
            @if ($boards->isEmpty())
                <p class="px-4 pb-3 text-sm text-fg-muted">No board yet.</p>
            @else
                <div class="cmp-card-scroll border-t border-line">
                    <table class="cmp-table text-sm">
                        <thead><tr><th>Board</th><th>Result</th><th class="hidden sm:table-cell">Started</th></tr></thead>
                        <tbody>
                        @foreach ($boards as $cm)
                            <tr wire:key="prep-{{ $cm->id }}">
                                <td class="mono whitespace-nowrap"><a href="{{ route('cm', $cm->mac) }}" class="text-link hover:underline">{{ $cm->mac }}</a>@if (trim((string) $cm->hostname) !== '') <span class="text-fg-muted">{{ $cm->hostname }}</span>@endif</td>
                                <td>
                                    @if ($cm->phase === 'done')
                                        <span class="cmp-chip cmp-chip-ok"><span class="dot"></span>ready</span>
                                        <span class="text-fg-muted">move it to the PoE switch</span>
                                    @elseif ($cm->phase === 'failed')
                                        <span class="cmp-chip cmp-chip-fail"><span class="dot"></span>failed</span>
                                        <span class="text-neg">{{ $cm->phase_detail }}</span>
                                    @elseif ($cm->silentFor())
                                        <span class="cmp-chip cmp-chip-stale"><span class="dot"></span>no report</span>
                                        <span class="text-fg-muted">for {{ intdiv($cm->silentFor(), 60) }} min: check the board and its cables</span>
                                    @else
                                        <span class="cmp-chip cmp-chip-run"><span class="dot"></span>flashing</span>
                                    @endif
                                </td>
                                <td class="hidden sm:table-cell tabular-nums text-fg-muted whitespace-nowrap">{{ $cm->usb_prep_at->local()->format('H:i:s') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
