{{-- The services in one line on every tab but Status: a word beside each colour, the count, and the logs of all of them --}}
<div id="settings-health" class="cmp-card cmp-health mb-4" wire:poll.30s>
    <div class="cmp-health-chips" role="list" aria-label="Services">
        @foreach ($services as $service)
            <span role="listitem" class="cmp-chip {{ $service['running'] ? 'cmp-chip-ok' : 'cmp-chip-fail' }}"><span class="dot"></span>{{ $service['short'] }} {{ $service['running'] ? 'running' : 'stopped' }}</span>
        @endforeach
    </div>
    <a href="{{ route('settings', ['tab' => 'status']) }}" class="text-sm text-link hover:underline">{{ $runningCount }} of {{ count($services) }} running</a>
    {{-- a disclosure, not an arrow-key menu: Tab walks the list, Escape closes it and gives the focus back to the button --}}
    <div class="relative" x-data="{ open: false }" @keydown.escape.window="if (open) { open = false; $refs.logs.focus() }" @click.outside="open = false">
        <button type="button" x-ref="logs" class="cmp-btn cmp-btn-secondary cmp-btn-small" @click="open = ! open" aria-controls="settings-logs-list" :aria-expanded="open.toString()">Logs ▾</button>
        <div id="settings-logs-list" x-show="open" x-cloak class="cmp-menu" aria-label="Logs">
            @foreach ($services as $service)
                <button type="button" wire:click="{{ $service['method'] }}()" @click="open = false">{{ $service['label'] }}</button>
            @endforeach
        </div>
    </div>
</div>
