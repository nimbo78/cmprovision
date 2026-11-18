@props(['title', 'value' => null, 'enabled' => true, 'open' => false])
@if ($enabled)
    <details {{ $attributes->class('cmp-wizard-fold') }} wire:ignore.self @if ($open) open @endif>
        <summary>
            <span class="font-bold">{{ $title }}</span>
            @if (filled($value))<span class="cmp-wizard-value">{{ $value }}</span>@endif
        </summary>
        <div class="cmp-wizard-fold-body">{{ $slot }}</div>
    </details>
@else
    {{ $slot }}
@endif
