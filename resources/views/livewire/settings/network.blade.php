<livewire:wifi-settings />

<livewire:switch-settings />

<x-settings-card title="DHCP static addresses" description="Modules and devices that always get the same address from the DHCP server." id="dhcp">
    <x-slot name="actions">
        <button wire:click="addStaticIP()" class="cmp-btn cmp-btn-secondary">Add new assignment</button>
    </x-slot>
    @if (session()->has('message'))
        <div class="cmp-flash mb-3" role="alert">
            <p class="text-sm">{{ session('message') }}</p>
        </div>
    @endif
    <div class="cmp-card-scroll">
        <table class="cmp-table min-w-full">
            <thead>
                <tr>
                    <th>IP</th>
                    <th>MAC</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($hosts as $h)
                <tr>
                    <td>{{ $h->ip }}</td>
                    <td>{{ $h->mac }}</td>
                    <td><button wire:click="deleteStaticIP({{ $h->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button></td>
                </tr>
                @empty
                <tr><td colspan="3">No entries</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-settings-card>
