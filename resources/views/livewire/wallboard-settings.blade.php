<x-settings-card title="HDMI display" id="hdmi-display" wire:poll.10s
    description="An optional terminal dashboard for the support bench: switch ports, provisioning progress and saved device diagnostics.">
    <x-slot name="help">
        No desktop or browser required. Auto starts the display at boot or when HDMI is connected, without login or key presses. Read-only display on tty8 (Alt+F8); Alt+F1 returns to the system console. Disconnecting HDMI in Auto stops the renderer; data collection stops when neither HDMI nor FPMS needs it. A TV in standby may still report HDMI connected.
    </x-slot>
    <form wire:submit="save" class="space-y-4">
        <p class="text-sm text-fg-muted">HDMI: {{ !empty($status['connected']) ? 'connected' : 'not detected' }} · Display: {{ $status['state'] }}@if(!empty($status['resolution'])) · {{ $status['resolution'] }}@endif</p>
        <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label for="wallboard-mode" class="block text-sm text-fg mb-2">HDMI activation</label>
            <select id="wallboard-mode" wire:model="mode" class="cmp-field w-full">
                <option value="off">Off</option><option value="auto">Auto — when HDMI is connected</option><option value="on">On — always render</option>
            </select>
            @error('mode') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="wallboard-view" class="block text-sm text-fg mb-2">Screen</label>
            <select id="wallboard-view" wire:model="view" class="cmp-field w-full">
                <option value="auto">Stand console; diagnostics when a device fails</option><option value="desk">Stand console</option>
                <option value="flow">All device cards</option><option value="engineer">Large device card and diagnostics</option>
            </select>
            @error('view') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="wallboard-style" class="block text-sm text-fg mb-2">Style</label>
            <select id="wallboard-style" wire:model="style" class="cmp-field w-full">
                <option value="warm">Warm</option><option value="neutral">Neutral</option>
            </select>
            @error('style') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
            <p class="text-sm text-fg-muted mt-2">Same colors as the web UI; this display's settings are shared.</p>
        </div>
        <div>
            <label for="wallboard-color-mode" class="block text-sm text-fg mb-2">Color mode</label>
            <select id="wallboard-color-mode" wire:model="color_mode" class="cmp-field w-full">
                <option value="dark">Dark</option><option value="light">Light</option><option value="auto">Auto — sunrise and sunset</option>
            </select>
            @error('color_mode') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
            <p class="text-sm text-fg-muted mt-2">Auto uses the station time zone; without a sunrise or sunset, it uses Dark.</p>
        </div>
        <div>
            <label for="wallboard-rotation" class="block text-sm text-fg mb-2">Seconds per device card (5–60)</label>
            <input id="wallboard-rotation" type="number" min="5" max="60" wire:model="rotation" class="cmp-field w-full">
            @error('rotation') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        </div>
        </div>
        <button type="submit" class="cmp-btn" wire:loading.attr="disabled">Save display settings</button>
        @if($notice) <p class="text-sm text-pos" role="status">{{ $notice }}</p> @endif
    </form>
</x-settings-card>
