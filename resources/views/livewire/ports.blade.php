<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">Ports</h2>
</x-slot>
<div class="py-8" wire:poll.5s
     x-data="{ filter: [], toggle(s) { this.filter = this.filter.includes(s) ? this.filter.filter(x => x !== s) : this.filter.concat([s]) }, dim(s, never) { return this.filter.length > 0 && (never || !this.filter.includes(s)) } }">
@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
@php($words = ['ok' => 'done', 'run' => 'running', 'fail' => 'failed', 'stale' => 'silent', 'unknown' => 'unknown', 'boot' => 'booting', 'foreign' => 'foreign', 'free' => 'free'])
<div class="max-w-7xl mx-auto sm:px-6 lg:px-8 px-4 space-y-4">

@if (!$configured)
    <div class="bg-white shadow-xl sm:rounded-lg px-6 py-6 text-gray-700">
        No switch is set up. Enter its address under
        <a href="{{ route('settings') }}" class="text-blue-700 hover:underline">Other settings → Ethernet switch</a>
        to see the stand by its ports.
    </div>
@else
    <div class="text-sm text-gray-600">
        <span class="font-bold text-gray-900">{{ $snapshot['model'] ?? $host }}</span> · {{ $host }}
        @if ($snapshot) · polled {{ max(0, $now - $snapshot['taken_at']) }} s ago @endif
        @if ($snapshot && $snapshot['poe'])
            · PoE {{ number_format(array_sum(array_map(function ($p) { return $p['poe']['watts'] ?? 0; }, $snapshot['ports'])), 1) }} W
        @endif
    </div>

    @if ($error)
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-900 px-4 py-3 rounded" role="alert">
            @if ($snapshot)
                The switch has not answered for {{ max(0, $now - $error['since']) }} s ({{ $error['message'] }});
                showing what it said at {{ \Illuminate\Support\Carbon::createFromTimestamp($snapshot['taken_at'])->local()->format('H:i:s') }}.
            @else
                Cannot read the switch: {{ $error['message'] }}
            @endif
        </div>
    @endif

    @if ($map)
        @php($s = $map['summary'])
        @php($verdictColor = ['running' => 'bg-blue-100 text-blue-800', 'failed' => 'bg-red-100 text-red-800', 'done' => 'bg-green-100 text-green-800', 'empty' => 'bg-gray-100 text-gray-700'][$s['verdict']])
        <section class="bg-white shadow-xl sm:rounded-lg px-4 py-4 grid gap-3 md:grid-cols-[1fr_auto] items-center" aria-label="The stand now">
            <div class="font-bold md:col-span-2">
                {{ $s['modules'] }} {{ $s['modules'] === 1 ? 'module' : 'modules' }} on the stand
                <span class="text-gray-500 font-semibold">· {{ $s['counts']['free'] }} of {{ $s['ports'] }} ports free</span>
            </div>
            <div class="flex flex-wrap gap-2 items-center" role="group" aria-label="Show only">
                @foreach ($words as $key => $word)
                    @if ($s['counts'][$key])
                        <button type="button" class="ports-chip ports-{{ $key }}" x-on:click="toggle('{{ $key }}')"
                                x-bind:aria-pressed="filter.includes('{{ $key }}') ? 'true' : 'false'"
                                x-bind:class="{ 'pressed': filter.includes('{{ $key }}'), 'faded': filter.length > 0 && !filter.includes('{{ $key }}') }">
                            <span class="dot"></span>{{ $s['counts'][$key] }} {{ $word }}
                        </button>
                    @endif
                @endforeach
                <button type="button" x-show="filter.length > 0" x-cloak x-on:click="filter = []" class="text-sm font-semibold text-indigo-600 px-2">Show all</button>
            </div>
            <div class="text-sm font-bold rounded px-3 py-2 {{ $verdictColor }}">
                @if ($s['verdict'] === 'running')
                    {{ $s['busy'] }} still running, {{ $s['counts']['fail'] }} failed: keep the batch on the stand
                @elseif ($s['verdict'] === 'failed')
                    All finished, {{ $s['counts']['fail'] }} failed{{ $s['counts']['unknown'] ? ', '.$s['counts']['unknown'].' without data' : '' }}: restart or replace them
                @elseif ($s['verdict'] === 'done')
                    All done: the batch can be replaced
                @else
                    No modules on the stand
                @endif
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem] items-start">
            <div class="bg-white shadow-xl sm:rounded-lg p-4 min-w-0">
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
                <p class="text-xs text-gray-500 mt-3">The last 4 characters of the serial and the result (✓, ✕, write %) per port; ⚡ the port powers its device; dashed: a cage for uplinks.</p>
            </div>

            <aside class="bg-white shadow-xl sm:rounded-lg p-4 text-sm" aria-live="polite">
                @if ($tile)
                    <h3 class="ports-serial font-bold text-base">{{ $tile['name'] }}</h3>
                    <div class="text-gray-500 mb-3">{{ $tile['alias'] !== '' ? $tile['alias'] : 'no description' }}</div>
                    @foreach ($tile['modules'] as $cm)
                        @php($advice = \App\Support\FailureAdvice::for($cm))
                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 mb-3">
                            <dt class="text-gray-500">Module</dt>
                            <dd><a href="{{ route('cm', $cm->serial) }}" class="ports-serial font-bold text-blue-700 hover:underline">{{ $cm->serial }}</a></dd>
                            <dt class="text-gray-500">Step</dt>
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
                                <dt class="text-gray-500">Speed</dt>
                                <dd>{{ $fmt::speed($cm->bytesPerSecond()) }}@if ($cm->secondsLeft() !== null), {{ $fmt::duration($cm->secondsLeft()) }} left @endif</dd>
                            @endif
                            @if ($cm->phase === 'done' && $cm->provisioning_started_at && $cm->provisioning_complete_at)
                                <dt class="text-gray-500">Result</dt>
                                <dd>took {{ $fmt::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) }}@if ($cm->eeprom_result) · EEPROM {{ $cm->eeprom_result }} @endif</dd>
                            @endif
                        </dl>
                        @if ($advice)
                            <p class="mb-2 font-semibold" style="color: {{ $cm->phase === 'failed' ? '#b91c1c' : '#b45309' }}">{{ $advice['advice'] }}</p>
                        @endif
                        <div class="flex flex-wrap gap-2 mb-3">
                            <a href="{{ route('cm', $cm->serial) }}" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Module card</a>
                            @if ($cm->canIdentify())
                                @if ($cm->isIdentifying())
                                    <button type="button" wire:click="stopIdentify({{ $cm->id }})" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Stop</button>
                                @else
                                    <button type="button" wire:click="identify({{ $cm->id }})" class="bg-blue-500 hover:bg-blue-700 text-white text-xs font-bold py-1 px-2 rounded">Identify</button>
                                @endif
                            @endif
                        </div>
                    @endforeach
                    @if ($tile['state'] === 'foreign')
                        <p class="mb-2">Not a module: <span class="ports-serial">{{ implode(', ', $tile['macs']) }}</span></p>
                    @elseif ($tile['state'] === 'boot')
                        <p class="mb-2">Link up, nothing has talked to the server from this port yet.</p>
                    @elseif ($tile['state'] === 'uplink')
                        <p class="mb-2">Uplink: {{ count($tile['macs']) }} MAC {{ count($tile['macs']) === 1 ? 'address' : 'addresses' }} behind it.</p>
                    @endif
                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                        <dt class="text-gray-500">Link</dt>
                        <dd>
                            @if (!$tile['up']) down
                            @elseif ($tile['speed']) {{ $tile['speed'] >= 1000 ? ($tile['speed'] / 1000).' Gbit/s' : $tile['speed'].' Mbit/s' }}
                            @else up
                            @endif
                        </dd>
                        <dt class="text-gray-500">PoE</dt>
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
                    <p class="text-gray-500">Click a port for its details.</p>
                @endif
            </aside>
        </div>
    @endif
@endif
</div>
</div>
