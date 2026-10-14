{{-- free ports of the uplinks block are not counted by the summary chips, so a chip never lights them --}}
@php($never = $t['state'] === 'uplink' || ($t['aside'] && $t['state'] === 'free'))
<button type="button" wire:click="select('{{ $t['key'] }}')" wire:key="{{ $t['key'] }}"
        class="ports-tile ports-{{ $t['filter'] }} {{ $t['sfp'] ? 'cage' : '' }} {{ $t['combo'] ? 'combo' : '' }} {{ $selected === $t['key'] ? 'selected' : '' }}"
        x-bind:class="{ 'dim': dim(@js($t['filters']), {{ $never ? 'true' : 'false' }}) }"
        title="{{ $t['label'] }}{{ $t['link'] ? ' · link to '.$t['link'] : '' }}{{ $t['alias'] !== '' ? ' · '.$t['alias'] : '' }}{{ $t['combo'] ? ' · combo: RJ45 or SFP' : '' }}"
        aria-label="{{ $t['label'] }}: {{ $t['link'] ? 'link to '.$t['link'] : ($words[$t['filter']] ?? $t['filter']) }}">
    @if (($t['poe']['state'] ?? null) === 'delivering')<span class="bolt" aria-hidden="true">⚡</span>@endif
    @if ($t['combo'])<span class="{{ $t['transceiver'] ? 'combo-cage filled' : 'combo-cage' }}" aria-hidden="true"></span>@endif
    <span class="short">{{ $t['short'] }}</span>
    <span class="mark">{{ $t['mark'] }}</span>
</button>
