<x-settings-card title="Tagged VLANs" id="vlan" description="Tagged networks sharing a physical Ethernet port.">
    @if ($available && !$form)
        <x-slot name="actions"><button type="button" wire:click="$set('form', true)" class="cmp-btn">Add VLAN</button></x-slot>
    @endif
    <x-slot name="help">
        Tagged VLAN interfaces can share a physical Ethernet port with its untagged network. The switch port must
        carry the matching VLAN IDs. The first table shows current kernel state, including VLANs configured outside
        cmprovision. Additional managed VLANs can have a static IPv4 address or no address; they do not change the default route.
    </x-slot>
    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @if ($listError)
        <p class="text-sm text-neg mb-4">{{ $listError }}</p>
    @endif
    @if (!$available)
        <p class="text-sm text-fg-muted mb-4">Adding VLANs here requires a physical Ethernet device managed by NetworkManager.</p>
    @endif
    @if ($vlans === null)
        <p class="text-sm text-fg-muted">VLAN information is unavailable on this system.</p>
    @elseif (!$vlans)
        <p class="text-sm text-fg-muted">No tagged VLAN interfaces are currently present.</p>
    @else
        <div class="cmp-card-scroll">
            <table class="cmp-table">
                <thead><tr><th>Interface</th><th>Parent</th><th>VLAN</th><th>State</th><th>IPv4</th><th>Role</th></tr></thead>
                <tbody>
                    @foreach ($vlans as $vlan)
                        <tr wire:key="vlan-{{ $vlan['name'] }}">
                            <td>{{ $vlan['name'] }}</td><td>{{ $vlan['parent'] }}</td><td>{{ $vlan['id'] }}</td>
                            <td>{{ $vlan['state'] }}</td><td>{{ implode(', ', $vlan['addresses']) ?: '—' }}</td>
                            <td>{{ $vlan['stand'] ? 'Provisioning' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($managed)
        <h3 class="font-bold mt-4 mb-2">Managed by cmprovision</h3>
        <div class="cmp-card-scroll">
            <table class="cmp-table">
                <thead><tr><th>Parent</th><th>VLAN</th><th>Static IPv4</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($managed as $network)
                        <tr wire:key="managed-vlan-{{ $network['uuid'] }}">
                            <td>{{ $network['parent'] }}</td><td>{{ $network['id'] }}</td><td>{{ $network['address'] }}</td>
                            <td>{{ !$network['present'] ? 'Profile missing' : ($network['active'] ? 'Active' : 'Disconnected') }}</td>
                            <td>
                                @if ($network['present'] && !$network['active'])
                                    <x-confirms-password wire:then="remove('{{ $network['uuid'] }}')">
                                        <button type="button" class="cmp-btn cmp-btn-danger cmp-btn-small" wire:loading.attr="disabled"
                                            x-on:click="if (!confirm('Remove this VLAN?')) $event.stopPropagation()">Remove</button>
                                    </x-confirms-password>
                                @elseif ($network['active'])
                                    <span class="text-fg-muted text-sm">Disconnect before removal</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if ($available && $form)
        <div class="border-t border-pline mt-4 pt-4">
            <h3 class="font-bold mb-2">Add a static tagged network</h3>
            <p class="text-sm text-fg-muted mb-4">The IPv4 address is optional. Existing networks and the default route stay in place.</p>
            <div class="grid gap-3 md:grid-cols-3">
                <div><label for="vlan-parent" class="block text-sm font-bold mb-1">Ethernet interface</label>
                    <input id="vlan-parent" class="cmp-field w-full" wire:model="parent" placeholder="eth0">
                    @error('parent') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                <div><label for="vlan-id" class="block text-sm font-bold mb-1">VLAN ID</label>
                    <input id="vlan-id" type="number" min="1" max="4094" class="cmp-field w-full" wire:model="vlanId" placeholder="42">
                    @error('vlanId') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
                <div><label for="vlan-address" class="block text-sm font-bold mb-1">IPv4 address / prefix</label>
                    <input id="vlan-address" class="cmp-field w-full" wire:model="address" placeholder="Optional: 192.168.50.10/24">
                    @error('address') <p class="text-neg text-sm">{{ $message }}</p> @enderror</div>
            </div>
            <div class="mt-4 flex gap-2">
                <x-confirms-password wire:then="add"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Add VLAN</button></x-confirms-password>
                <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="$set('form', false)">Cancel</button>
            </div>
        </div>
    @endif
</x-settings-card>
