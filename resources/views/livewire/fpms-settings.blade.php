<x-settings-card title="FPMS display" id="fpms-display" wire:poll.10s
    description="Show provisioning progress, ports and device diagnostics on a small front panel display.">
    <x-slot name="help">
        Requires an FPMS package with cmprovision support. WLAN Pi keeps its existing menu; Lite uses a compact station menu.
        Disabling this integration keeps the normal WLAN Pi menu available. The panel is read-only and can work alongside HDMI.
    </x-slot>
    <form wire:submit="save" class="space-y-4">
        <p class="text-sm text-fg-muted" role="status">FPMS: {{ $status['state'] }}</p>
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="enabled" class="form-checkbox">Enable cmprovision in FPMS</label>
        @error('enabled') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        <label class="flex items-center gap-2"><input type="checkbox" wire:model="open_on_start" class="form-checkbox">Open cmprovision when FPMS starts</label>
        <p class="text-sm text-fg-muted">On WLAN Pi this selects the startup page. It does not change the page while someone is using the menu. Lite always opens the station overview.</p>
        @error('open_on_start') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        <button type="submit" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Save FPMS settings</button>
        @if($notice) <p class="text-sm text-pos" role="status">{{ $notice }}</p> @endif
    </form>
</x-settings-card>
