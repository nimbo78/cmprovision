<div wire:poll.5s
     x-data="{ filter: [], toggle(s) { this.filter = this.filter.includes(s) ? this.filter.filter(x => x !== s) : this.filter.concat([s]) }, dim(states, never) { return this.filter.length > 0 && (never || !states.some(s => this.filter.includes(s))) } }">
@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
@php($words = ['ok' => 'done', 'run' => 'running', 'fail' => 'failed', 'stale' => 'silent', 'unknown' => 'unknown', 'boot' => 'booting', 'foreign' => 'foreign', 'free' => 'free'])
<div class="cmp-page">
    <livewire:poe-outages />
    @if ($restartMessage)
        <div class="rounded px-4 py-2 text-sm font-semibold {{ $restartError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $restartMessage }}</div>
    @endif

@if (!$configured)
    <div class="cmp-card px-4 py-3 text-fg">
        No switch is set up. Enter its address under
        <a href="{{ route('settings') }}" class="text-link hover:underline">Settings → Ethernet switch</a>
        to see the stand by its ports.
    </div>
@else
    <div class="text-sm text-fg-muted">
        @if ($header['title'])<span class="font-bold text-fg">{{ $header['title'] }}</span> · @endif {{ $header['facts'] }}
        @if ($snapshot) · polled {{ max(0, $now - $snapshot['taken_at']) }} s ago @endif
        @if ($snapshot && $snapshot['poe'])
            · PoE {{ number_format(array_sum(array_map(function ($p) { return $p['poe']['watts'] ?? 0; }, $snapshot['ports'])), 1) }} W
        @endif
    </div>

    @if ($snapshot)
        @php($taken = \Illuminate\Support\Carbon::createFromTimestamp($snapshot['taken_at'])->local())
        @php($takenAt = $taken->format($taken->isToday() ? 'H:i:s' : 'j M H:i'))
    @endif
    @if ($error)
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded" role="alert">
            @if ($snapshot)
                The switch has not answered for {{ max(0, $now - $error['since']) }} s ({{ $error['message'] }});
                showing what it said at {{ $takenAt }}.
            @else
                Cannot read the switch: {{ $error['message'] }}
            @endif
        </div>
    @endif
    @if (!$snapshot && !$error)
        {{-- another page holds the poll: its snapshot comes with the next refresh --}}
        <div class="cmp-card px-4 py-3 text-fg">Asking the switch…</div>
    @endif
    @if ($snapshot && $snapshot['mac_method'] === null && in_array(true, array_column($snapshot['ports'], 'up'), true))
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded" role="status">
            The switch gave no MAC address table, so modules cannot be matched to ports. Check the MAC table method under
            <a href="{{ route('settings') }}" class="underline">Settings → Ethernet switch</a>.
        </div>
    @endif

    @if ($map)
        @php($s = $map['summary'])
        @php($verdictColor = ['running' => 'bg-info-bg text-info', 'failed' => 'bg-neg-bg text-neg', 'done' => 'bg-pos-bg text-pos', 'empty' => 'bg-head text-fg'][$s['verdict']])
        <section class="cmp-card px-4 py-3 grid gap-3 md:grid-cols-[1fr_auto] items-center" aria-label="The stand now">
            <div class="font-bold md:col-span-2">
                {{ $s['modules'] }} {{ $s['modules'] === 1 ? 'module' : 'modules' }} on the stand
                <span class="text-fg-muted font-semibold">· {{ $s['counts']['free'] }} of {{ $s['ports'] }} ports free</span>
            </div>
            <div class="flex flex-wrap gap-2 items-center" role="group" aria-label="Show only">
                @foreach ($words as $key => $word)
                    {{-- a chip at zero stays only while the filter holds it, so the operator can let it go --}}
                    <button type="button" class="ports-chip ports-{{ $key }}" x-on:click="toggle('{{ $key }}')"
                            @if (!$s['counts'][$key]) x-show="filter.includes('{{ $key }}')" style="display: none" @endif
                            x-bind:aria-pressed="filter.includes('{{ $key }}') ? 'true' : 'false'"
                            x-bind:class="{ 'pressed': filter.includes('{{ $key }}'), 'faded': filter.length > 0 && !filter.includes('{{ $key }}') }">
                        <span class="dot"></span><span class="label">{{ $s['counts'][$key] }} {{ $word }}</span>
                    </button>
                @endforeach
                <button type="button" x-show="filter.length > 0" x-cloak x-on:click="filter = []" class="text-sm font-semibold text-link px-2">Show all</button>
            </div>
            {{-- one-line php directives only: Blade would read a php block here, or even its closing word in a comment, as running from the first one-line directive above --}}
            @php($unknown = $s['counts']['unknown'] ? ', '.$s['counts']['unknown'].' without data' : '')
            @php($verdict = ['running' => $s['busy'].' still in progress, '.$s['counts']['fail'].' failed: keep the batch on the stand', 'failed' => 'All finished, '.$s['counts']['fail'].' failed'.$unknown.': restart or replace them', 'done' => 'All done: the batch can be replaced', 'empty' => 'No modules on the stand'][$s['verdict']])
            {{-- a map from before the switch went silent may no longer be the stand --}}
            <div class="text-sm font-bold rounded px-3 py-2 {{ $error ? 'bg-head text-fg' : $verdictColor }}">
                {{ ($error ? 'As of '.$takenAt.', before the switch went silent: ' : '').$verdict }}
            </div>
            @if ($writable && $failed)
                <button type="button" wire:click="restartFailed" wire:confirm="{{ \App\Services\Switch\PoeRestart::confirmFailed($failed) }}" wire:loading.attr="disabled"
                        class="cmp-btn cmp-btn-danger cmp-btn-small justify-self-start md:col-span-2">
                    <span wire:loading.remove wire:target="restartFailed">Restart failed ({{ $failed }})</span>
                    <span wire:loading wire:target="restartFailed">Restarting…</span>
                </button>
            @endif
        </section>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] items-start">
            <div class="cmp-card px-4 py-3 min-w-0">
                <div class="overflow-x-auto pb-1">
                    <div class="ports-chassis">
                        <div class="ports-model">
                            <span>{{ $snapshot['model'] }}</span>
                            <span>{{ $s['composition'] }}</span>
                        </div>
                        @foreach ($map['blocks'] as $block)
                            @if ($block['rows'] === 1)
                                <div class="ports-block one-row {{ $block['uplinks'] ? 'uplinks' : '' }}" style="grid-template-columns: repeat({{ count($block['tiles']) }}, 50px)">
                                    @foreach ($block['tiles'] as $t)<span class="ports-num">{{ $t['number'] }}</span>@endforeach
                                    @foreach ($block['tiles'] as $t)@include('livewire.ports-tile', ['t' => $t])@endforeach
                                </div>
                            @else
                                <div class="ports-block {{ $block['uplinks'] ? 'uplinks' : '' }}">
                                    @foreach (array_chunk($block['tiles'], 2) as $pair)
                                        <span class="ports-num">{{ $pair[0]['number'] }}</span>
                                        @include('livewire.ports-tile', ['t' => $pair[0]])
                                        @if (isset($pair[1]))
                                            @include('livewire.ports-tile', ['t' => $pair[1]])
                                            <span class="ports-num">{{ $pair[1]['number'] }}</span>
                                        @else
                                            <span></span><span></span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
                <p class="text-xs text-fg-muted mt-3">The last 4 characters of the serial and the result (✓, ✕, write %) per port; ⚡ the port powers its device; dashed: an SFP cage; a dashed corner: a combo port, RJ45 or SFP, filled when an SFP is in.</p>
            </div>

            <aside class="cmp-card px-4 py-3 text-sm" aria-live="polite">
                @if ($tile)
                    <h3 class="ports-serial font-bold text-base">{{ $tile['name'] }}</h3>
                    <div class="text-fg-muted mb-3">{{ $tile['alias'] !== '' ? $tile['alias'] : 'no description' }}</div>
                    @foreach ($tile['modules'] as $cm)
                        @php($advice = \App\Support\FailureAdvice::for($cm))
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 mb-3">
                            <dt class="text-fg-muted">Module</dt>
                            <dd><a href="{{ route('cm', $cm->serial) }}" class="ports-serial font-bold text-link hover:underline">{{ $cm->serial }}</a></dd>
                            <dt class="text-fg-muted">Step</dt>
                            <dd>
                                @if ($cm->phase)
                                    {{ $cm->phaseLabel() }}@if ($cm->isActive() && $cm->progressPercent() !== null) · {{ $cm->progressPercent() }}% @endif
                                @elseif ($cm->provisioning_complete_at)
                                    Finished; no details from before 1.6.6
                                @else
                                    Nothing is known about its runs
                                @endif
                            </dd>
                            @if ($cm->isStreaming() && $cm->bytesPerSecond())
                                <dt class="text-fg-muted">Speed</dt>
                                <dd>{{ $fmt::speed($cm->bytesPerSecond()) }}@if ($cm->secondsLeft() !== null), {{ $fmt::duration($cm->secondsLeft()) }} left @endif</dd>
                            @endif
                            @if ($cm->phase === 'done' && $cm->provisioning_started_at && $cm->provisioning_complete_at)
                                <dt class="text-fg-muted">Result</dt>
                                <dd>took {{ $fmt::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) }}@if ($cm->eeprom_result) · EEPROM {{ $cm->eeprom_result }} @endif</dd>
                            @endif
                        </dl>
                        @if ($advice)
                            <p class="mb-2 font-semibold {{ $cm->phase === 'failed' ? 'text-neg' : 'text-warn' }}">{{ $advice['advice'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2 mb-3">
                            <a href="{{ route('cm', $cm->serial) }}" class="cmp-btn cmp-btn-small">Module card</a>
                            @if ($cm->canIdentify())
                                @if ($cm->isIdentifying())
                                    <button type="button" wire:click="stopIdentify({{ $cm->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Stop</button>
                                @else
                                    <button type="button" wire:click="identify({{ $cm->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Identify</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                    @if ($tile['state'] === 'foreign')
                        <p class="mb-2">Not a module: <span class="ports-serial">{{ implode(', ', $tile['macs']) }}</span></p>
                    @elseif ($tile['state'] === 'boot' && $tile['macs'])
                        <p class="mb-2">A Raspberry Pi is on this port and has not asked the server yet: <span class="ports-serial">{{ implode(', ', $tile['macs']) }}</span></p>
                    @elseif ($tile['state'] === 'boot')
                        <p class="mb-2">Link up, nothing has talked to the server from this port yet.</p>
                    @elseif ($tile['state'] === 'quiet')
                        <p class="mb-2">Link up for {{ \Carbon\CarbonInterval::seconds($tile['changed'])->cascade()->forHumans(['parts' => 1]) }}, and nothing has talked from this port: a quiet device, a member of a link aggregation, or the provisioner's own port after a long idle time.</p>
                    @elseif ($tile['state'] === 'uplink')
                        <p class="mb-2">Uplink: {{ $tile['mac_count'] }} MAC {{ $tile['mac_count'] === 1 ? 'address' : 'addresses' }} behind it.</p>
                    @endif
                    @if ($writable && $restart['blocker'] === null)
                        <div class="mb-3">
                            <button type="button" wire:click="restart('{{ $tile['key'] }}')" wire:confirm="{{ $restart['confirm'] }}" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small">
                                <span wire:loading.remove wire:target="restart">Restart</span>
                                <span wire:loading wire:target="restart">Restarting…</span>
                            </button>
                            <span class="text-xs text-fg-muted ml-2">PoE off for 5 s, then on</span>
                        </div>
                    @elseif ($writable)
                        <p class="mb-3 text-xs text-fg-muted">No PoE restart: {{ $restart['blocker'] }}</p>
                    @endif
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-fg-muted">Link</dt>
                        <dd>
                            @if (!$tile['up']) down
                            @elseif ($tile['speed']) {{ $tile['speed'] >= 1000 ? ($tile['speed'] / 1000).' Gbit/s' : $tile['speed'].' Mbit/s' }}
                            @else up
                            @endif
                        </dd>
                        @if ($tile['sfp'] || $tile['combo'])
                            {{-- what the cage holds, when the switch tells it --}}
                            @php($connector = $tile['combo'] ? ['combo: RJ45 or SFP', ', SFP in', ', no SFP'] : ['SFP cage', ', module in', ', empty'])
                            <dt class="text-fg-muted">Connector</dt>
                            <dd>{{ $connector[0] }}{{ $tile['transceiver'] === null ? '' : $connector[$tile['transceiver'] ? 1 : 2] }}</dd>
                        @endif
                        <dt class="text-fg-muted">PoE</dt>
                        <dd>
                            @if (!$tile['poe']) none
                            @elseif ($tile['poe']['state'] === 'delivering') delivering{{ $tile['poe']['watts'] !== null ? ' '.number_format($tile['poe']['watts'], 1).' W' : '' }}{{ $tile['poe']['class'] !== null ? ', class '.$tile['poe']['class'] : '' }}
                            @elseif ($tile['poe']['state'] === 'searching') looking for a device
                            @elseif ($tile['poe']['state'] === 'fault') fault
                            @elseif ($tile['poe']['state'] === 'disabled') off
                            @else {{ $tile['poe']['state'] }}
                            @endif
                        </dd>
                    </dl>
                @else
                    <p class="text-fg-muted">Click a port for its details.</p>
                @endif
            </aside>
        </div>
    @endif
@endif
</div>
</div>
