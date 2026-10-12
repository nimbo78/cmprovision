@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-semibold text-sm text-fg']) }}>
    {{ $value ?? $slot }}
</label>
