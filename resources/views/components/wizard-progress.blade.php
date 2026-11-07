@props(['steps', 'step'])
<div class="mb-5" tabindex="-1" x-ref="wizardHeading" x-on:wizard-step-changed.window="$nextTick(() => $refs.wizardHeading.focus())">
    <p class="text-sm text-fg-muted">Step {{ array_search($step, array_keys($steps), true) + 1 }} of {{ count($steps) }}</p>
    <h2 class="font-bold text-xl mt-1">{{ $steps[$step] }}</h2>
    <ol class="flex flex-wrap gap-2 mt-3 text-xs" aria-label="Creation steps">
        @foreach ($steps as $number => $label)
            <li class="rounded px-2 py-1 {{ $number === $step ? 'bg-accent-soft text-accent-text' : 'bg-head text-fg-muted' }}" @if($number === $step) aria-current="step" @endif>{{ $label }}</li>
        @endforeach
    </ol>
</div>
