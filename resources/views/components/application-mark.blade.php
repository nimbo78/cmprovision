{{-- cmprovision's mark: one line writes c and runs on into m (docs/brand). Every copy on a page needs a gradient id of its own --}}
@props(['gradient' => 'cmp-mark-tile'])
<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="cmprovision" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gradient }}" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" style="stop-color: var(--mark-a)"/>
            <stop offset="1" style="stop-color: var(--mark-b)"/>
        </linearGradient>
    </defs>
    <rect x="2" y="2" width="44" height="44" rx="13" fill="url(#{{ $gradient }})"/>
    <path d="M20.79 18.77A6.1 6.1 0 1 0 18.7 30.6Q24.9 30.6 24.9 25.2V21.2A3 3 0 0 1 30.9 21.2V30.4M30.9 21.2A3 3 0 0 1 36.9 21.2V30.4" fill="none" style="stroke: var(--mark-ink)" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
