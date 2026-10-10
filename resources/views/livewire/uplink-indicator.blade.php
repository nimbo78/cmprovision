{{-- How the provisioner reaches the network: the icon follows the default route, the tooltip has the details.
     Hovering or tapping the icon reads fresh data (NetworkStatus::FRESH_MAX_AGE), otherwise it refreshes every $poll s. --}}
@if ($v === null)
<div></div>
@else
@php
    $ink = ['normal' => 'text-gray-700', 'warn' => 'text-yellow-600', 'bad' => 'text-gray-300'][$v['tone']];
    $arcs = [3 => 'M3 9.5a12.7 12.7 0 0 1 18 0', 2 => 'M5.8 12.3a8.8 8.8 0 0 1 12.4 0', 1 => 'M8.6 15.1a4.8 4.8 0 0 1 6.8 0'];
@endphp
<div wire:poll.{{ $poll }}s="refreshPassive" class="relative flex items-center"
     x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false" @click.away="open = false" @keydown.escape.window="open = false">
    <button type="button" wire:mouseenter="refreshNow" wire:click="refreshNow" @click="open = true" @focus="open = true" @blur="open = false"
            class="p-1.5 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition duration-150 ease-in-out"
            aria-label="{{ $v['label'] }}" :aria-expanded="open ? 'true' : 'false'">
        <span class="relative block w-6 h-6">
            @if ($v['icon'] === 'wired')
                <svg class="w-6 h-6 {{ $ink }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                    <rect x="5" y="5.5" width="14" height="10" rx="1.6"/>
                    <path d="M9.5 15.5v3h5v-3"/>
                    <path stroke-width="1.4" stroke-linecap="round" d="M8.5 8.5v2.2M11 8.5v2.2M13.5 8.5v2.2M16 8.5v2.2"/>
                </svg>
            @else
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                    @foreach ($arcs as $arc => $path)
                        <path d="{{ $path }}" stroke="currentColor" class="{{ $v['arcs'] >= $arc ? $ink : 'text-gray-300' }}"/>
                    @endforeach
                    <circle cx="12" cy="18.5" r="1.6" fill="currentColor" stroke="none" class="{{ $ink }}"/>
                    @if ($v['icon'] === 'offline')
                        <path d="M4.5 4.5l15 15" stroke="currentColor" stroke-width="2.2" class="text-red-600"/>
                    @endif
                </svg>
            @endif
            @if ($v['badge'] !== null)
                <span class="absolute -right-1 -bottom-0.5 px-px rounded-sm bg-white font-extrabold leading-none {{ $ink }}" style="font-size: 9.5px">{{ $v['badge'] }}</span>
            @endif
        </span>
    </button>

    <div x-show="open" style="display: none;" role="tooltip"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="transform opacity-0 scale-95"
         x-transition:enter-end="transform opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="transform opacity-100 scale-100"
         x-transition:leave-end="transform opacity-0 scale-95"
         class="absolute right-0 top-full z-50 pt-2 w-72 sm:w-80 origin-top-right">
        <div class="rounded-md shadow-lg ring-1 ring-black ring-opacity-5 bg-white px-4 py-3 text-xs text-gray-700">
            <div class="flex justify-between items-baseline">
                <span class="text-sm font-bold text-gray-800">{{ $v['heading'] }}</span>
                @if ($v['note'] !== null)
                    <span class="ml-3 font-semibold text-gray-500 whitespace-nowrap">{{ $v['note'] }}</span>
                @endif
            </div>
            @if ($v['warning'] !== null)
                <p class="mt-1 {{ $v['tone'] === 'bad' ? 'text-red-600' : 'text-yellow-700' }}">{{ $v['warning'] }}</p>
            @endif
            @if ($v['rows'])
                <dl class="mt-2 grid grid-cols-3 gap-x-3 gap-y-1">
                    @foreach ($v['rows'] as [$label, $value])
                        <dt class="text-gray-500">{{ $label }}</dt>
                        <dd class="col-span-2 text-gray-800 tabular-nums">
                            {{ $value }}
                            @if ($label === 'Signal' && $v['bars'] !== null)
                                <span class="inline-flex items-end ml-1 align-middle" aria-hidden="true">
                                    @foreach (['h-1.5', 'h-2', 'h-2.5', 'h-3'] as $i => $height)
                                        <span class="block w-1 {{ $height }} mr-px rounded-sm {{ $i < $v['bars'] ? 'bg-gray-700' : 'bg-gray-300' }}"></span>
                                    @endforeach
                                </span>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endif
            @foreach ($v['sections'] as [$title, $line])
                <div class="mt-2 pt-2 border-t border-gray-100">
                    <div class="font-bold text-gray-800">{{ $title }}</div>
                    <div class="text-gray-500 tabular-nums">{{ $line }}</div>
                </div>
            @endforeach
            <div class="mt-2 text-gray-400">{{ $v['checked'] }}</div>
        </div>
    </div>
</div>
@endif
