{{-- Settings (2.9): four tabs by purpose, each at its own address. Only the open tab is read and drawn; the strip with the services
     stays on the other three, so a stopped queue worker is seen whatever the operator is doing. --}}
<div class="cmp-page">
    @if($logModalOpen)
        @include('livewire.viewlog')
    @elseif($staticModalOpen)
        @include('livewire.addstaticip')
    @endif

    <div class="cmp-title">
        <h1>Settings</h1>
    </div>

    {{-- Narrower than the four tabs, they scroll (app.css): bring the current one into view --}}
    <nav class="cmp-tabs" aria-label="Settings sections" x-data x-init="$el.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })">
        @foreach ($tabs as $key => $name)
            <a href="{{ route('settings', ['tab' => $key]) }}" @if ($tab === $key) aria-current="page" @endif>{{ $name }}</a>
        @endforeach
    </nav>

    @if ($tab !== 'status')
        @include('livewire.settings.health')
    @endif

    @include('livewire.settings.'.$tab)

    <script src="{{ asset('js/settings-anchors.js') }}?v={{ @filemtime(public_path('js/settings-anchors.js')) }}"></script>
</div>
