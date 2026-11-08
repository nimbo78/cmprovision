@props(['value' => null, 'max' => null, 'label' => 'Progress'])

@php
    $determinate = is_numeric($value) && is_numeric($max) && is_finite((float) $value) && is_finite((float) $max) && (float) $max > 0;
    $percent = $determinate ? number_format(max(0, min(100, 100 * (float) $value / (float) $max)), 2, '.', '') : null;
@endphp

<div {{ $attributes->class(['cmp-progress', 'cmp-progress-indeterminate' => !$determinate]) }}
     role="progressbar" aria-label="{{ $label }}"
     @if ($determinate) aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" @else aria-valuetext="In progress" @endif>
    <span class="cmp-progress-bar" aria-hidden="true" @if ($determinate) style="width: {{ $percent }}%" @endif></span>
</div>
