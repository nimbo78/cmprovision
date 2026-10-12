@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<div @if ($activeCount) wire:poll.2s @else wire:poll.10s @endif class="flex flex-col gap-[var(--gap)]">
    @if ($summary)
        <div class="grid gap-[var(--gap)] grid-cols-3 lg:grid-cols-[repeat(3,minmax(0,160px))_minmax(0,1fr)]">
            <div class="cmp-tile"><div class="num text-info">{{ $summary->counts['active'] }}</div><div class="label">running</div></div>
            <div class="cmp-tile"><div class="num text-pos">{{ $summary->counts['done'] }}</div><div class="label">done</div></div>
            <div @class(['cmp-tile', 'cmp-tile-fail' => $summary->counts['failed']])><div class="num text-neg">{{ $summary->counts['failed'] }}</div><div class="label">failed</div></div>
            <div class="cmp-tile col-span-3 lg:col-span-1 flex items-center gap-4">
                <div class="min-w-0">
                    <div class="font-bold">
                        @if ($summary->open)
                            Project {{ $summary->project?->name }} · batch since {{ $summary->batch->started_at->local()->format('H:i') }}
                        @else
                            Last batch, closed at {{ ($summary->batch->closed_at ?? $summary->batch->last_event_at)->local()->format('H:i') }} · project {{ $summary->project?->name }}
                        @endif
                    </div>
                    @php($facts = array_filter([$summary->modules->count().($summary->modules->count() === 1 ? ' module' : ' modules'), $summary->averageSeconds ? $fmt::duration($summary->averageSeconds).' per module' : null, $summary->open && $summary->leftSeconds !== null ? 'the running ones finish in '.($summary->leftSeconds < 60 ? 'under a minute' : 'about '.ceil($summary->leftSeconds / 60).' min') : null]))
                    <div class="text-fg-muted">{{ implode(' · ', $facts) }}</div>
                </div>
                @if ($summary->open)
                    <button type="button" wire:click="newBatch" wire:confirm="Close this batch? The next module starts a new one, here and in the bots." class="cmp-btn cmp-btn-secondary ml-auto">New batch</button>
                @endif
            </div>
        </div>
    @endif

    {{-- cmp-card-scroll: narrower than xl the table scrolls sideways inside the card (app.css) --}}
    <div class="cmp-card cmp-card-scroll">
        @if ($modules->isEmpty())
            <div class="px-4 py-3 text-fg-muted">No modules are being provisioned.</div>
        @else
            <table class="cmp-table">
                <thead>
                    <tr><th>Board</th><th>Serial</th><th>MAC</th><th>Step</th><th>Progress</th><th>Time</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($modules as $m)
                    @php($percent = $m->progressPercent())
                    @php($silent = $m->silentFor())
                    @php($speed = $m->bytesPerSecond())
                    @php($left = $m->secondsLeft())
                    @php($step = $m->phase === 'failed' && $m->phase_detail ? 'Failed: '.$m->phase_detail : $m->phaseLabel())
                    <tr wire:key="cm-{{ $m->id }}" class="{{ $m->phase === 'failed' ? 'bg-neg-bg' : '' }}">
                        <td class="text-fg-muted whitespace-nowrap">{{ $m->provisioning_board }}</td>
                        <td class="mono"><a href="{{ route('cm', $m->serial) }}" class="text-link hover:underline">{{ $m->serial }}</a></td>
                        <td class="mono text-fg-muted">{{ $m->mac }}</td>
                        {{-- The step takes the width the other columns leave: a long reason wraps to two lines, all of it in the tooltip and on the module card --}}
                        <td>
                            <div class="line-clamp-2" title="{{ $step }}">
                                @if ($m->phase === 'done')
                                    <span class="text-pos font-semibold">Done</span>
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
                                    took {{ $fmt::duration($m->provisioning_complete_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $m->provisioning_complete_at->local()->format('H:i') }}</span>
                                @elseif ($m->phase === 'failed' && $m->phase_started_at)
                                    failed after {{ $fmt::duration($m->phase_started_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">at {{ $m->phase_started_at->local()->format('H:i') }}</span>
                                @else
                                    {{ $fmt::duration(now()->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }} <span class="text-fg-faint">since {{ $m->provisioning_started_at->local()->format('H:i') }}</span>
                                @endif
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">
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
