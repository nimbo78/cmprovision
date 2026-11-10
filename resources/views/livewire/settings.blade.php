{{-- Each settings tab has its own address. Service status and logs belong to Status. --}}
<div class="cmp-page">
    @if($logModalOpen)
        @include('livewire.viewlog')
    @elseif($staticModalOpen)
        @include('livewire.addstaticip')
    @endif

    <div class="cmp-title">
        <h1>Settings</h1>
    </div>

    {{-- Bring the current tab into view sideways, keeping section anchors in place. --}}
    <nav class="cmp-tabs" aria-label="Settings sections" x-data x-init="const cur = $el.querySelector('[aria-current=page]'); if (cur) $el.scrollLeft = Math.max(0, cur.offsetLeft - 16)">
        @foreach ($tabs as $key => $name)
            <a href="{{ route('settings', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $name }}</a>
        @endforeach
    </nav>

    @include('livewire.settings.'.$tab)

    <script src="{{ asset('js/settings-anchors.js') }}?v={{ @filemtime(public_path('js/settings-anchors.js')) }}"></script>
</div>
