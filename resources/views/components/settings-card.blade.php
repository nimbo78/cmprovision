{{-- A section of the Settings page (2.9): title, one line of what it is, the actions at the right, the body, and the long
     explanation behind "How it works". The first element is the card itself, so a Livewire component may be one. The
     explanation opens in Alpine: an attribute of the markup (<details open>) would go back to the server's on every poll. --}}
@props(['title', 'description' => null, 'id' => null, 'helpTitle' => 'How it works', 'flush' => false])
@php($helpId = ($id ?? \Illuminate\Support\Str::slug($title)).'-help')
<section @if ($id) id="{{ $id }}" @endif x-data="{ help: false }" {{ $attributes->class(['cmp-card', 'cmp-section']) }}>
    <header class="cmp-section-head">
        <div class="min-w-0">
            <h2>{{ $title }}</h2>
            @if ($description)
                <p>{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="cmp-section-actions">{{ $actions }}</div>
        @endisset
    </header>
    <div @class(['cmp-section-body', 'cmp-section-body-flush' => $flush])>{{ $slot }}</div>
    @isset($help)
        <div class="cmp-help">
            <button type="button" @click="help = !help" :aria-expanded="help.toString()" aria-controls="{{ $helpId }}">{{ $helpTitle }}</button>
            <div id="{{ $helpId }}" x-show="help" x-cloak>{{ $help }}</div>
        </div>
    @endisset
</section>
