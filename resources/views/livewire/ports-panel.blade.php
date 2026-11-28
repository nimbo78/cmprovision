<div class="ports-panel-area" x-data="{ overflow: false, measure() { this.overflow = this.$refs.panel.scrollWidth > this.$refs.panel.clientWidth } }"
     x-init="$nextTick(() => measure())" x-on:resize.window.debounce.100ms="measure()">
<div class="ports-panel-scroll" x-ref="panel" tabindex="0" role="region" aria-label="{{ $sw['name'] }} front panel">
    @php($columns = ($sw['part']['panel']['layout'] ?? null) === 'industrial-columns')
    <div @class(['ports-panel', 'industrial-columns' => $columns])>
        @foreach ($sw['part']['blocks'] as $block)
            @if ($columns)
            <div @class(['ports-column', 'uplinks' => $block['uplinks']]) role="group" aria-label="{{ $block['label'] }}">
                @foreach ($block['tiles'] as $t)
                    <div class="ports-column-port" style="grid-row: {{ $t['panel_y'] + 1 }} / span 2">
                        @if ($loop->first)<span class="ports-bank-label">{{ $block['label'] }}</span>@endif
                        <span class="ports-num">{{ $t['panel_number'] }}</span>
                        @include('livewire.ports-tile', ['t' => $t])
                    </div>
                @endforeach
            </div>
            @else
            <div class="ports-bank {{ $block['rows'] === 1 ? 'one-row' : '' }} {{ $block['uplinks'] ? 'uplinks' : '' }}"
                 style="grid-template-columns: repeat({{ $block['rows'] === 1 ? count($block['tiles']) : (int) ceil(count($block['tiles']) / 2) }}, var(--tile))">
                <span class="ports-bank-label">{{ $block['label'] }}</span>
                @if ($block['rows'] === 1)
                    @foreach ($block['tiles'] as $t)
                        <span class="ports-num">{{ $t['panel_number'] }}</span>
                        @include('livewire.ports-tile', ['t' => $t])
                        <span aria-hidden="true"></span>
                    @endforeach
                @else
                    @foreach (array_chunk($block['tiles'], 2) as $pair)
                        <span class="ports-num">{{ $pair[0]['panel_number'] }}</span>
                        @include('livewire.ports-tile', ['t' => $pair[0]])
                        @include('livewire.ports-tile', ['t' => $pair[1]])
                        <span class="ports-num">{{ $pair[1]['panel_number'] }}</span>
                    @endforeach
                @endif
            </div>
            @endif
        @endforeach
    </div>
</div>
<p class="ports-panel-hint" x-show="overflow" x-cloak>Scroll sideways to see all ports.</p>
</div>
