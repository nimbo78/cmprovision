@php($never = $t['state'] === 'uplink' || ($t['sfp'] && $t['state'] === 'free'))
<button type="button" wire:click="select('{{ $t['key'] }}')" wire:key="{{ $t['key'] }}"
        class="ports-tile ports-{{ $t['filter'] }} {{ $t['sfp'] ? 'cage' : '' }} {{ $selected === $t['key'] ? 'selected' : '' }}"
        x-bind:class="{ 'dim': dim(@js($t['filters']), {{ $never ? 'true' : 'false' }}) }"
        title="{{ $t['name'] }}{{ $t['alias'] !== '' ? ' · '.$t['alias'] : '' }}"
        aria-label="{{ $t['name'] }}: {{ $words[$t['filter']] ?? $t['filter'] }}">
    @if (($t['poe']['state'] ?? null) === 'delivering')<span class="bolt" aria-hidden="true">⚡</span>@endif
    <span class="short">{{ $t['short'] }}</span>
    <span class="mark">{{ $t['mark'] }}</span>
</button>
