<x-settings-card title="NetBox export" id="netbox" description="What the files for NetBox say of every device. The names must exist in NetBox already.">
    <x-slot name="help">
        On the CMs page, <strong>Export for NetBox</strong> gives CSV files of the modules that finished (the ones in the list on the screen):
        the devices, their interfaces and, for NetBox 4.2 and newer, the MAC addresses. In NetBox open Devices → Import, paste or choose the
        devices file, then the same for the interfaces and the MAC addresses, in this order. The site, role, manufacturer and device type
        must be there before: NetBox refuses a device whose site or type it does not know. A module is called by its host name when it has a
        plain one that no other module has, and <code>cm-</code> and its MAC address without the colons otherwise. The serial number is the one of the module.
    </x-slot>

    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold bg-pos-bg text-pos" role="status">{{ $notice }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (['site' => 'Site', 'role' => 'Device role', 'manufacturer' => 'Manufacturer', 'device_type' => 'Device type (model)', 'interface' => 'Interface name', 'interface_type' => 'Interface type'] as $field => $label)
            <div>
                <label for="netbox-{{ $field }}" class="block text-fg text-sm font-bold mb-2">{{ $label }}</label>
                <input id="netbox-{{ $field }}" type="text" wire:model="{{ $field }}" maxlength="100" class="cmp-field w-full">
                @error($field) <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>
        @endforeach
        <div>
            <label for="netbox-status" class="block text-fg text-sm font-bold mb-2">Status</label>
            <select id="netbox-status" wire:model="status" class="cmp-field w-full">
                @foreach ($statuses as $value)
                    <option value="{{ $value }}">{{ $value }}</option>
                @endforeach
            </select>
            @error('status') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        </div>
        <div>
            <label for="netbox-mac-style" class="block text-fg text-sm font-bold mb-2">Version of NetBox</label>
            <select id="netbox-mac-style" wire:model="mac_style" class="cmp-field w-full">
                @foreach ($macStyles as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('mac_style') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        </div>
    </div>
    <div class="mt-4">
        <button type="button" wire:click="save" class="cmp-btn cmp-btn-secondary">Save</button>
        <button type="button" wire:click="useDefaults" class="cmp-btn cmp-btn-secondary ml-2">Defaults</button>
    </div>
</x-settings-card>
