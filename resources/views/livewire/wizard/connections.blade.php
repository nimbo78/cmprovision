@php
    $uplink = $state['uplink'];
    $stand = $state['stand'];
@endphp
<x-wizard-disclosure title="Show connections">
    <ul class="cmp-wizard-map" aria-label="Management and provisioning connections">
        <li>
            <span class="cmp-wizard-equipment" aria-hidden="true"><span class="cmp-wizard-router"></span></span>
            <strong>{{ ($uplink['kind'] ?? '') === 'wifi' ? 'Management Wi-Fi' : 'Management uplink' }}</strong>
            <span>{{ $uplink ? implode(' · ', array_filter([$uplink['name'], $uplink['wifi']['ssid'] ?? null], 'filled')) : 'No uplink reported' }}</span>
            @if ($uplink)<span>{{ implode(', ', $uplink['addresses'] ?? []) ?: 'No IP address' }}</span>
                @if (($uplink['up'] ?? null) === false)<span>No carrier</span>@endif
            @endif
        </li>
        <li>
            <span class="cmp-wizard-equipment" aria-hidden="true"><x-application-mark gradient="wizard-station-mark" class="h-8 w-8" /></span>
            <strong>{{ gethostname() ?: 'This station' }}</strong><span>{{ ucfirst($state['role']) }}</span>
        </li>
        <li>
            <span class="cmp-wizard-equipment" aria-hidden="true"><span class="cmp-wizard-switch"><i></i><i></i><i></i><i></i></span></span>
            <strong>Provisioning stand</strong>
            <span>{{ $stand ? $stand['device'].' · '.$stand['address'].'/16' : ($state['role'] === 'unknown' ? 'Interface unknown' : 'No interface configured') }}</span>
        </li>
    </ul>
</x-wizard-disclosure>
