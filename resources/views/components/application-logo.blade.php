{{-- cmprovision's mark (docs/brand); a gradient id of its own, as the top bar's mark may share the page --}}
<svg viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="cmprovision" {{ $attributes }}>
    <defs>
        <linearGradient id="cmp-logo-tile" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" style="stop-color: var(--mark-a)"/>
            <stop offset="1" style="stop-color: var(--mark-b)"/>
        </linearGradient>
    </defs>
    <rect x="2" y="2" width="44" height="44" rx="13" fill="url(#cmp-logo-tile)"/>
    <path d="M19.58 18.54A6.1 6.1 0 1 0 17.49 30.37Q23.69 30.37 23.69 24.97V20.97A3 3 0 0 1 29.69 20.97V30.17M29.69 20.97A3 3 0 0 1 35.69 20.97V30.17" fill="none" style="stroke: var(--mark-ink)" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
