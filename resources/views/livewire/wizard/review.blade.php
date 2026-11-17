@if ($state['blockers'])
    <div role="alert" class="rounded bg-warn-bg p-3 text-warn">
        <h3 class="font-bold">Resolve before completing setup</h3>
        <ul class="list-disc pl-5 mt-1 text-sm">@foreach ($state['blockers'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>
    </div>
@endif
<dl class="cmp-wizard-facts">
    <div><dt>Stand role</dt><dd>{{ ucfirst($state['role']) }}</dd></div>
    @foreach (['dhcp' => 'DHCP/TFTP', 'usb' => 'USB boot'] as $key => $label)
        @php($active = $state['services'][$key]['active'] ?? null)
        <div><dt>{{ $label }}</dt><dd>{{ $active === null ? 'Unknown' : ($active ? 'Running' : 'Stopped') }}</dd></div>
    @endforeach
    <div><dt>Active project</dt><dd>{{ $state['project']['name'] ?? 'None' }}{{ $state['project']['ready'] ? ' · Ready' : '' }}</dd></div>
</dl>
@foreach ($state['warnings'] as $warning)<p class="text-sm text-fg-muted">{{ $warning }}</p>@endforeach
<x-wizard-disclosure title="Other checks">
    <dl class="cmp-wizard-facts">
        <div><dt>Provisioning interface</dt><dd>{{ $state['stand'] ? $state['stand']['device'].' · '.$state['stand']['address'].'/16' : 'None reported' }}</dd></div>
        <div><dt>Managed switches</dt><dd>{{ $state['switches'] }}</dd></div>
        <div><dt>Web sign-in</dt><dd>{{ auth()->user()->email }}</dd></div>
        @foreach ($state['links'] as $link)
            <div><dt>{{ $link['name'] }} · {{ ($link['up'] ?? false) ? 'Up' : 'Down' }}</dt>
                <dd>{{ implode(', ', $link['addresses'] ?? []) ?: 'No IP address' }}
                    @if ($link['speed'] ?? null) · {{ $link['speed'] }} Mb/s @endif
                    @if ($link['wifi']['ssid'] ?? null) · {{ $link['wifi']['ssid'] }} @endif
                </dd></div>
        @endforeach
    </dl>
</x-wizard-disclosure>
<button type="button" wire:click="$refresh" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">Refresh checks</button>
