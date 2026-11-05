<x-settings-card title="Ethernet and VLAN profiles" id="network-profiles" description="Connections, addressing, DNS and routes.">
    <x-slot name="help">
        Changes are temporary until confirmed. If access is lost, the provisioner restores the previous profile automatically.
        The interface carrying the provisioning network is protected and cannot be edited here.
    </x-slot>
    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @if (!$available)
        <p class="text-sm text-fg-muted">Network profile editing is unavailable on this system.</p>
    @elseif ($statusError)
        <p class="text-sm text-neg">{{ $statusError }}</p>
    @elseif ($pending['pending'] ?? false)
        <div wire:poll.10s class="rounded bg-warn-bg text-warn px-4 py-3" role="status">
            <p class="font-bold">A network change is waiting for confirmation.</p>
            @if ($pending['can_confirm'] ?? false)
                <p class="text-sm mt-1">Interface {{ $pending['device'] ?? 'unknown' }} ·
                    {{ max(0, ($pending['expires_at'] ?? 0) - now()->getTimestamp()) }} seconds remain.
                    Reconnect at the new address if needed, then confirm. Otherwise the previous settings are restored.</p>
                <div class="flex gap-2 mt-3">
                    <x-confirms-password wire:then="confirm"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Confirm network</button></x-confirms-password>
                    <x-confirms-password wire:then="cancel"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Restore previous</button></x-confirms-password>
                </div>
            @else
                <p class="text-sm mt-1">Recovery is in progress; network changes are temporarily unavailable.</p>
            @endif
        </div>
    @elseif ($device !== null)
        <div class="mb-3">
            <h3 class="font-bold">Edit {{ $device ?: 'saved profile' }}</h3>
            <p class="text-sm text-fg-muted">Reconnect using the new address and confirm within about two minutes; otherwise the change rolls back.</p>
            @if ($editingSaved && $device === '')
                <label for="edit-activation-device" class="block text-sm font-bold mt-3 mb-1">Ethernet interface</label>
                <select id="edit-activation-device" wire:model="activationDevice" class="cmp-field">
                    <option value="">Choose an interface</option>
                    @foreach ($ethernetDevices ?? [] as $interface)<option value="{{ $interface }}">{{ $interface }}</option>@endforeach
                </select>
            @endif
        </div>
        @foreach (['ipv4' => 'IPv4', 'ipv6' => 'IPv6'] as $family => $label)
            <fieldset class="border-t border-pline pt-4 mt-4">
                <legend class="font-bold">{{ $label }}</legend>
                <div class="grid gap-3 md:grid-cols-2">
                    <div><label for="{{ $family }}-method" class="block text-sm font-bold mb-1">Address assignment</label>
                        <select id="{{ $family }}-method" class="cmp-field w-full" wire:model="{{ $family }}.method">
                            <option value="auto">Automatic</option><option value="manual">Static</option>
                            @if ($family === 'ipv6') <option value="dhcp">DHCPv6</option><option value="ignore">Ignore</option> @endif
                            <option value="link-local">Link-local</option><option value="disabled">Disabled</option>
                        </select>
                        @error($family.'.method') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-gateway" class="block text-sm font-bold mb-1">Gateway</label>
                        <input id="{{ $family }}-gateway" class="cmp-field w-full" wire:model="{{ $family }}.gateway" placeholder="Optional">
                        @error($family.'.gateway') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-addresses" class="block text-sm font-bold mb-1">Addresses / prefix</label>
                        <textarea id="{{ $family }}-addresses" class="cmp-field w-full" rows="2" wire:model="{{ $family }}.addresses" placeholder="One address per line"></textarea>
                        @error($family.'.addresses') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-dns" class="block text-sm font-bold mb-1">DNS servers</label>
                        <textarea id="{{ $family }}-dns" class="cmp-field w-full" rows="2" wire:model="{{ $family }}.dns" placeholder="One server per line"></textarea>
                        @error($family.'.dns') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-routes" class="block text-sm font-bold mb-1">Routes</label>
                        <textarea id="{{ $family }}-routes" class="cmp-field w-full font-mono text-sm" rows="3" wire:model="{{ $family }}.routes" placeholder="Prefix via gateway metric N"></textarea>
                        <p class="text-xs text-fg-muted mt-1">One route per line. Optional attributes: table=100, mtu=1500, src=address, onlink=true, type=blackhole.</p>
                        @error($family.'.routes') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-metric" class="block text-sm font-bold mb-1">Default route metric</label>
                        <input id="{{ $family }}-metric" type="number" min="-1" class="cmp-field w-full" wire:model="{{ $family }}.route_metric">
                        <p class="text-xs text-fg-muted mt-1">−1 uses NetworkManager's default metric.</p>
                        @error($family.'.route_metric') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-table" class="block text-sm font-bold mb-1">Routing table</label>
                        <input id="{{ $family }}-table" type="number" min="0" max="4294967295" class="cmp-field w-full" wire:model="{{ $family }}.route_table">
                        <p class="text-xs text-fg-muted mt-1">0 uses the system default. A separate table needs a matching routing rule.</p>
                        @error($family.'.route_table') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div><label for="{{ $family }}-dns-search" class="block text-sm font-bold mb-1">DNS search and routing domains</label>
                        <textarea id="{{ $family }}-dns-search" class="cmp-field w-full" rows="2" wire:model="{{ $family }}.dns_search" placeholder="example.internal"></textarea>
                        <p class="text-xs text-fg-muted mt-1">One domain per line. Prefix with ~ for DNS routing only; the system DNS resolver must support split DNS.</p>
                        @error($family.'.dns_search') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                    <div class="md:col-span-2"><label for="{{ $family }}-rules" class="block text-sm font-bold mb-1">Routing rules</label>
                        <textarea id="{{ $family }}-rules" class="cmp-field w-full font-mono text-sm" rows="3" wire:model="{{ $family }}.routing_rules" placeholder="priority 100 from {{ $family === 'ipv4' ? '192.0.2.0/24' : 'fd42::/64' }} table 100"></textarea>
                        <p class="text-xs text-fg-muted mt-1">One rule per line, with a distinct priority and numeric table. Optional selectors: from, to, fwmark, iif, oif. Lower priority numbers run first.</p>
                        @error($family.'.routing_rules') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                </div>
                <div class="flex flex-wrap gap-4 mt-3 text-sm">
                    <label><input type="checkbox" wire:model="{{ $family }}.never_default"> Never use as the default route</label>
                    <label><input type="checkbox" wire:model="{{ $family }}.ignore_auto_dns"> Ignore automatic DNS</label>
                    <label><input type="checkbox" wire:model="{{ $family }}.ignore_auto_routes"> Ignore automatic routes</label>
                </div>
            </fieldset>
        @endforeach
        <div class="flex gap-2 mt-4">
            <x-confirms-password wire:then="apply"><button type="button" class="cmp-btn" wire:loading.attr="disabled">{{ $editingSaved ? 'Apply and connect temporarily' : 'Apply temporarily' }}</button></x-confirms-password>
            <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="close">Cancel</button>
        </div>
    @elseif ($profiles === null)
        <p class="text-sm text-fg-muted">NetworkManager information is unavailable.</p>
    @elseif (!$profiles)
        <p class="text-sm text-fg-muted">No active Ethernet or VLAN profiles.</p>
    @else
        <div class="cmp-card-scroll"><table class="cmp-table">
            <thead><tr><th>Interface</th><th>Type</th><th>Profile</th><th></th></tr></thead>
            <tbody>
                @foreach ($profiles as $profile)
                    <tr wire:key="network-profile-{{ $profile['device'] }}">
                        <td>{{ $profile['device'] }}</td><td>{{ $profile['type'] === 'vlan' ? 'Tagged VLAN'.(isset($profile['vlan_id']) ? ' '.$profile['vlan_id'] : '') : 'Ethernet' }}</td>
                        <td>{{ collect($savedProfiles ?? [])->firstWhere('uuid', $profile['uuid'])['name'] ?? $profile['uuid'] }}</td>
                        <td><button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small"
                            wire:click="edit('{{ $profile['device'] }}', '{{ $profile['uuid'] }}')">Edit</button>
                            @if (($stand['device'] ?? null) !== $profile['device'])
                                <x-confirms-password wire:then="deactivate('{{ $profile['device'] }}', '{{ $profile['uuid'] }}')"><button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">Disconnect</button></x-confirms-password>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    @endif
    @if ($available && !$statusError && !($pending['pending'] ?? false) && $device === null && $savedProfiles !== null)
        @php($inactiveProfiles = collect($savedProfiles)->reject(fn ($p) => in_array($p['uuid'], array_column($profiles ?? [], 'uuid'), true)))
        @if ($inactiveProfiles->isNotEmpty())
            <div class="border-t border-pline mt-4 pt-4">
                <h3 class="font-bold mb-2">Saved connections</h3>
                <p class="text-sm text-fg-muted mb-3">Connect a saved profile or edit its IP settings before connecting. Connecting replaces the current profile on the same interface. Confirm within two minutes or the previous connection is restored.</p>
                @if ($inactiveProfiles->contains(fn ($p) => $p['device'] === ''))
                    <label for="activation-device" class="block text-sm font-bold mb-1">Ethernet interface for profiles without a binding</label>
                    <select id="activation-device" wire:model="activationDevice" class="cmp-field mb-3">
                        <option value="">Choose an interface</option>
                        @foreach ($ethernetDevices ?? [] as $interface)<option value="{{ $interface }}">{{ $interface }}</option>@endforeach
                    </select>
                @endif
                <div class="cmp-card-scroll"><table class="cmp-table">
                    <thead><tr><th>Profile</th><th>Interface</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($inactiveProfiles as $profile)
                            <tr wire:key="saved-network-{{ $profile['uuid'] }}">
                                <td>{{ $profile['name'] }}</td><td>{{ $profile['device'] ?: 'Not bound' }}</td>
                                <td><button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:click="edit('{{ $profile['device'] }}', '{{ $profile['uuid'] }}', true)">Edit</button>
                                    <x-confirms-password wire:then="activate('{{ $profile['uuid'] }}')"><button type="button" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">Connect</button></x-confirms-password>
                                    <x-confirms-password wire:then="deleteProfile('{{ $profile['uuid'] }}')"><button type="button" class="cmp-btn cmp-btn-danger cmp-btn-small" wire:loading.attr="disabled" x-on:click="if (!confirm('Delete this saved connection and its settings?')) $event.stopPropagation()">Delete saved profile</button></x-confirms-password></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>
        @endif
        <div class="border-t border-pline mt-4 pt-4">
            <h3 class="font-bold mb-2">New Ethernet profile</h3>
            <p class="text-sm text-fg-muted mb-3">Create a disconnected profile, then edit its addresses and connect. Add tagged networks in the VLAN section.</p>
            <div class="flex flex-wrap items-end gap-2">
                <div><label for="new-network-device" class="block text-sm font-bold mb-1">Interface</label>
                    <select id="new-network-device" wire:model="newDevice" class="cmp-field">
                        <option value="">Choose an interface</option>
                        @foreach ($ethernetDevices ?? [] as $interface)<option value="{{ $interface }}">{{ $interface }}</option>@endforeach
                    </select></div>
                <div><label for="new-network-name" class="block text-sm font-bold mb-1">Profile name</label>
                    <input id="new-network-name" wire:model="newProfileName" class="cmp-field" maxlength="64" placeholder="Management uplink"></div>
                <x-confirms-password wire:then="createProfile"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Create profile</button></x-confirms-password>
            </div>
            @error('device') <p class="text-neg text-sm">{{ $message }}</p> @enderror
            @error('name') <p class="text-neg text-sm">{{ $message }}</p> @enderror
        </div>
    @endif
    @if ($available && !$statusError && !($pending['pending'] ?? false) && $device === null && $stand !== null)
        <div class="border-t border-pline mt-4 pt-4">
            <h3 class="font-bold">Provisioning network</h3>
            <p class="text-sm text-fg-muted mt-1 mb-3">{{ $stand['address'] }}/16 is on {{ $stand['device'] }}. Move it to another active interface on the same Ethernet port. Configure the corresponding tagged or untagged VLAN on the switch first.</p>
            <div class="flex flex-wrap items-end gap-2">
                <div><label for="stand-target" class="block text-sm font-bold mb-1">Target interface</label>
                    <select id="stand-target" class="cmp-field" wire:model="standTarget">
                        <option value="">Choose an interface</option>
                        @foreach ($profiles ?? [] as $profile)
                            @if ($profile['device'] !== $stand['device'])
                                <option value="{{ $profile['uuid'] }}">{{ $profile['device'] }} · {{ $profile['type'] === 'vlan' ? 'VLAN '.($profile['vlan_id'] ?? '?') : 'untagged Ethernet' }}</option>
                            @endif
                        @endforeach
                    </select></div>
                <x-confirms-password wire:then="moveStand"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Move temporarily</button></x-confirms-password>
            </div>
            <p class="text-sm text-fg-muted mt-2">DHCP/TFTP stays enabled or disabled as it was. Confirm the move after checking connectivity; otherwise it rolls back.</p>
        </div>
    @endif
</x-settings-card>
