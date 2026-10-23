<x-settings-card title="Services" description="The processes of this server. View log opens the journal of one and follows it." id="services" flush>
    <ul class="cmp-rows">
        @foreach ($services as $service)
            <li class="cmp-row">
                <span class="cmp-row-name">{{ $service['label'] }}</span>
                <span class="cmp-chip {{ $service['running'] ? 'cmp-chip-ok' : 'cmp-chip-fail' }}"><span class="dot"></span>{{ $service['running'] ? 'running' : 'stopped' }}</span>
                <button wire:click="{{ $service['method'] }}()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
            </li>
        @endforeach
        <li class="cmp-row">
            <span class="cmp-row-name">utility OS: {{ $utilityOs }}</span>
            <span class="cmp-chip {{ $utilityOs !== 'none' ? 'cmp-chip-ok' : 'cmp-chip-fail' }}"><span class="dot"></span>{{ $utilityOs !== 'none' ? 'present' : 'missing' }}</span>
            <a href="{{ route('settings', ['tab' => 'provisioning']) }}#utility-os" class="text-sm text-link hover:underline">Change</a>
        </li>
    </ul>
</x-settings-card>
