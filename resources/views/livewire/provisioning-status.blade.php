@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<div @if ($activeCount) wire:poll.2s @else wire:poll.10s @endif class="flex flex-col gap-[var(--gap)]">
    @if ($restartMessage)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $restartError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $restartMessage }}</div>
    @endif
    {{-- the summary tiles and, at the end of the row, the USB prep tile (a child component: its own poll and its own state);
         under 1440 px the tile takes a row of its own under the batch tile --}}
    <div @class([
        'grid gap-[var(--gap)]',
        'grid-cols-3 lg:grid-cols-[repeat(3,minmax(0,160px))_minmax(0,1fr)] min-[1440px]:grid-cols-[repeat(3,minmax(0,160px))_minmax(0,1fr)_minmax(0,320px)]' => $summary,
        'grid-cols-1' => !$summary,
    ])>
    @if ($summary)
            <div class="cmp-tile"><div class="num text-info">{{ $summary->running }}</div><div class="label">running</div></div>
            <div class="cmp-tile"><div class="num text-pos">{{ $summary->counts['done'] }}</div><div class="label">done</div></div>
            <div @class(['cmp-tile', 'cmp-tile-fail' => $summary->counts['failed']])><div class="num text-neg">{{ $summary->counts['failed'] }}</div><div class="label">failed</div></div>
            <div class="cmp-tile col-span-3 lg:col-span-1 flex items-center gap-4">
                <div class="min-w-0">
                    <div class="font-bold">
                        @if ($summary->open)
                            Project {{ $summary->project?->name }} · batch since {{ $fmt::clock($summary->batch->started_at) }}
                        @else
                            @php($closed = ($summary->batch->closed_at ?? $summary->batch->last_event_at)->local())
                            Last batch, closed {{ $closed->isToday() ? 'at '.$closed->format('H:i') : 'on '.$closed->format('d.m').' at '.$closed->format('H:i') }} · project {{ $summary->project?->name }}
                        @endif
                    </div>
                    {{-- one string (escaped parts, the silent ones in the warning colour): Livewire's markers would split a sentence of inline ifs --}}
                    @php($facts = array_filter([e($summary->modules->count().($summary->modules->count() === 1 ? ' module' : ' modules')), $summary->averageSeconds ? e($fmt::duration($summary->averageSeconds).' per module') : null, $summary->silent ? '<span class="text-warn">'.$summary->silent.' no report</span>' : null, $summary->open && $summary->leftSeconds !== null ? e('the running ones finish in '.($summary->leftSeconds < 60 ? 'under a minute' : 'about '.ceil($summary->leftSeconds / 60).' min')) : null]))
                    <div class="text-fg-muted">{!! implode(' · ', $facts) !!}</div>
                </div>
                @if ($summary->open)
                    <div class="ml-auto flex flex-wrap gap-2">
                        @if ($failedToRestart)
                            <button type="button" wire:click="restartFailed" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirmFailed($failedToRestart, $resumingToRestart) }}" wire:loading.attr="disabled" class="cmp-btn cmp-btn-danger">
                                <span wire:loading.remove wire:target="restartFailed">Restart failed ({{ $failedToRestart }})</span>
                                <span wire:loading wire:target="restartFailed">Restarting…</span>
                            </button>
                        @endif
                        <button type="button" wire:click="newBatch" wire:confirm="Close this batch? The next module starts a new one, here and in the bots." class="cmp-btn cmp-btn-secondary">New batch</button>
                    </div>
                @endif
            </div>
    @endif
        <livewire:usb-prep-panel :key="'usb-prep'" />
    </div>

    {{-- cmp-card-scroll: narrower than xl the table scrolls sideways inside the card (app.css) --}}
    <div class="cmp-card cmp-card-scroll">
        @if ($modules->isEmpty())
            <div class="px-4 py-3 text-fg-muted">No modules are being provisioned.</div>
        @else
            <table class="cmp-table">
                <thead>
                    <tr>
                        <x-sort-th column="board" :sort="$sortKey" :dir="$sortDir">Board</x-sort-th>
                        <x-sort-th column="module" :sort="$sortKey" :dir="$sortDir">Module</x-sort-th>
                        <x-sort-th column="step" :sort="$sortKey" :dir="$sortDir">Step</x-sort-th>
                        <x-sort-th column="progress" :sort="$sortKey" :dir="$sortDir">Progress</x-sort-th>
                        <x-sort-th column="time" :sort="$sortKey" :dir="$sortDir">Time</x-sort-th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($modules as $m)
                    @php($percent = $m->progressPercent())
                    @php($silent = $m->silentFor())
                    @php($speed = $m->bytesPerSecond())
                    @php($left = $m->secondsLeft())
                    @php($step = $m->phase === 'failed' && $m->phase_detail ? 'Failed: '.$m->phase_detail : $m->phaseLabel())
                    <tr wire:key="cm-{{ $m->id }}" class="{{ $m->phase === 'failed' ? 'bg-neg-bg' : '' }}">
                        <td class="text-fg-muted whitespace-nowrap">@if ($m->board_pending_since)<span title="looking up the switch port">…</span>@else{{ $m->provisioning_board }}@endif</td>
                        <td class="mono">
                            <a href="{{ route('cm', $m->mac) }}" class="text-link hover:underline">{{ $m->mac ?: $m->serial }}</a>
                            @if (trim((string) $m->hostname) !== '')<div class="text-fg-muted">{{ $m->hostname }}</div>@endif
                        </td>
                        {{-- The step takes the width the other columns leave: a long reason wraps to two lines, all of it in the tooltip and on the module card --}}
                        <td>
                            <div class="line-clamp-2" title="{{ $step }}">
                                @if ($m->phase === 'done')
                                    {{-- a board that only had its bootloader set over USB (USB prep) is ready to move to the PoE switch, not provisioned --}}
                                    <span class="text-pos font-semibold">{{ $m->usb_prep_at ? 'Ready (USB prep)' : 'Done' }}</span>
                                @elseif ($m->phase === 'failed')
                                    <span class="text-neg font-semibold">Failed</span>@if ($m->phase_detail)<span class="text-neg">: {{ $m->phase_detail }}</span>@endif
                                @else
                                    {{ $m->phaseLabel() }}
                                @endif
                                @if ($silent)
                                    <span class="cmp-chip cmp-chip-stale ml-1">No report for {{ intdiv($silent, 60) }} min</span>
                                @endif
                            </div>
                        </td>
                        <td>
                            @if ($percent !== null)
                                <div class="flex items-center gap-2">
                                    <div class="cmp-bar"><span class="{{ $m->phase === 'failed' ? 'bg-neg-bar' : ($m->phase === 'done' ? 'bg-pos-bar' : ($silent ? 'bg-warn-bar' : 'bg-info-bar')) }}" style="width: {{ $percent }}%"></span></div>
                                    <span class="tabular-nums whitespace-nowrap">{{ $percent }}%
                                        @if ($m->progress_total) <span class="text-fg-muted">· {{ $fmt::bytes($m->progress_bytes ?? 0) }} of {{ $fmt::bytes($m->progress_total) }}</span> @endif
                                        @if ($speed && $m->isStreaming() && !$silent) <span class="text-fg-muted">· {{ $fmt::speed($speed) }}</span> @endif
                                        @if ($left !== null && $m->isStreaming() && !$silent) <span class="text-fg-muted">· {{ $fmt::duration($left) }} left</span> @endif
                                    </span>
                                </div>
                            @elseif ($m->isStreaming() && $m->progress_bytes)
                                <span class="text-fg-muted">{{ $fmt::bytes($m->progress_bytes) }} @if ($speed) · {{ $fmt::speed($speed) }} @endif</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap tabular-nums">
                            @if ($m->provisioning_started_at)
                                @if ($m->phase === 'done' && $m->provisioning_complete_at)
                                    took {{ $fmt::duration($m->provisioning_complete_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $fmt::clock($m->provisioning_complete_at) }}</span>
                                @elseif ($m->phase === 'failed' && $m->phase_started_at)
                                    failed after {{ $fmt::duration($m->phase_started_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $fmt::clock($m->phase_started_at) }}</span>
                                @else
                                    {{ $fmt::duration(now()->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">since {{ $fmt::clock($m->provisioning_started_at) }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
                            @if ($canRestart($m) && ($m->phase === 'failed' || $silent))
                                <button type="button" wire:click="restart({{ $m->id }})" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirm($m, $m->provisioning_board, $activeProject) }}" wire:loading.attr="disabled" wire:target="restart({{ $m->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small mr-1">
                                    <span wire:loading.remove wire:target="restart({{ $m->id }})">Restart</span>
                                    <span wire:loading wire:target="restart({{ $m->id }})">Restarting…</span>
                                </button>
                            @endif
                            @if ($m->canIdentify())
                                @if ($m->isIdentifying())
                                    <span class="text-xs font-semibold text-info mr-1">blinking</span>
                                    <button type="button" wire:click="stopIdentify({{ $m->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Stop</button>
                                @else
                                    <button type="button" wire:click="identify({{ $m->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Identify</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
