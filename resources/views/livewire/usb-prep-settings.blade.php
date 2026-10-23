<x-settings-card title="USB prep" id="usb-prep" description="The bootloader a board gets when it is prepared over USB: it boots from the network first, then the stand writes its image.">
    <x-slot name="help">
        Start the preparation with the button on the Dashboard, then connect the board over USB (nRPIBOOT set) and its Ethernet to the stand's
        switch, as for <a href="https://github.com/nimbo78/cmprovision/blob/main/docs/usb-provisioning.md" class="text-link hover:underline">USB provisioning</a>.
        The board loads the utility OS over USB, flashes this bootloader and shows ready; no image is written. Unplug it and plug it into the PoE switch.
        With <code>BOOT_ORDER=0xf12</code> it tries the network first and then its eMMC, so a board with somebody's system on it comes to the stand;
        a project that flashes its own EEPROM settings (for example <code>0xf21</code>, eMMC first) puts the order of the fleet back when it is provisioned,
        and without one the board keeps this order. With no DHCP server in reach such a board waits for the network (up to 45 seconds) before it starts from its eMMC.
    </x-slot>

    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @if ($problem)
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded mb-4" role="status">{{ $problem }}</div>
    @endif

    <div class="mb-4">
        <label for="usb-prep-firmware" class="block text-fg text-sm font-bold mb-2">EEPROM firmware</label>
        <select id="usb-prep-firmware" wire:model="firmware" class="cmp-field w-full">
            @foreach ($channels as $channel => $images)
                <optgroup label="{{ $channel }}">
                    @foreach ($images as $image)
                        <option value="{{ $image->path }}">{{ $image->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
            @if ($firmware !== '' && !collect($channels)->flatten()->contains(fn ($image) => $image->path === $firmware))
                <option value="{{ $firmware }}">{{ $firmware }} (not in the store)</option>
            @endif
        </select>
        @error('firmware') <span class="text-neg text-sm">{{ $message }}</span> @enderror
    </div>
    <div class="mb-4">
        <label for="usb-prep-settings" class="block text-fg text-sm font-bold mb-2">Bootloader settings</label>
        <textarea id="usb-prep-settings" wire:model="settings" rows="8" spellcheck="false" class="cmp-field w-full font-mono text-sm"></textarea>
        @error('settings') <span class="text-neg text-sm">{{ $message }}</span> @enderror
    </div>
    <button type="button" wire:click="save" class="cmp-btn cmp-btn-secondary">Save</button>
    <button type="button" wire:click="useDefaults" class="cmp-btn cmp-btn-secondary ml-2">Network first (defaults)</button>
    @if ($sha)
        <p class="mt-3 text-xs text-fg-muted font-mono break-all">Image checksum {{ $sha }}</p>
    @endif
</x-settings-card>
