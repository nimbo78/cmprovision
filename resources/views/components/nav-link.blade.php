@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center h-full border-b-2 border-accent text-fg font-semibold focus:outline-none focus-visible:text-accent'
            : 'inline-flex items-center h-full border-b-2 border-transparent text-fg-muted font-semibold hover:text-fg hover:border-pline focus:outline-none focus-visible:text-fg';
@endphp

<a {{ $attributes->merge(['class' => $classes] + (($active ?? false) ? ['aria-current' => 'page'] : [])) }}>
    {{ $slot }}
</a>
