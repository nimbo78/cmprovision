<x-settings-card title="FPMS display" id="fpms-display" wire:poll.10s
    description="Show provisioning progress, ports and device diagnostics on a small front panel display.">
    <x-slot name="help">
        Requires fpms-lite with cmprovision support on Raspberry Pi OS Lite. The panel is read-only and can work alongside HDMI.
    </x-slot>
    <form wire:submit="save" class="space-y-4">
        <p class="text-sm text-fg-muted" role="status">FPMS: {{ $status['state'] }}</p>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="enabled" class="form-checkbox">Enable cmprovision in FPMS</label>
        @error('enabled') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm">Layout
                <select wire:model="theme" class="cmp-field mt-1 block w-full">
                    <option value="new">New</option>
                    <option value="classic">Classic</option>
                </select>
            </label>
            <label class="block text-sm">Color mode
                <select wire:model="color_mode" class="cmp-field mt-1 block w-full">
                    <option value="dark">Dark</option><option value="light">Light</option><option value="auto">Auto — sunrise and sunset</option>
                </select>
            </label>
            <label class="block text-sm">Ports map
                <select wire:model="ports_map" class="cmp-field mt-1 block w-full">
                    <option value="pages">Pages with numbers</option>
                    <option value="whole">Whole switch</option>
                </select>
            </label>
        </div>
        @error('theme') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        @error('color_mode') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        @error('ports_map') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        <p class="text-sm text-fg-muted">Color mode applies to both layouts. Auto uses the station time zone; without a sunrise or sunset, it uses Dark. The ports map applies to New.</p>
        <button type="submit" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Save FPMS settings</button>
        @if($notice) <p class="text-sm text-pos" role="status">{{ $notice }}</p> @endif
    </form>
</x-settings-card>
