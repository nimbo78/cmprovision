@props(['for'])

@error($for)
    <p {{ $attributes->merge(['class' => 'text-sm text-neg']) }}>{{ $message }}</p>
@enderror
