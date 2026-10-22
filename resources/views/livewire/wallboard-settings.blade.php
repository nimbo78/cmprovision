<div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md" wire:poll.10s>
    <form wire:submit="save" class="space-y-4">
        <p class="text-sm text-fg-muted">HDMI: {{ !empty($status['connected']) ? 'connected' : 'not detected' }} · Display: {{ $status['state'] }}</p>
        <div>
            <label for="wallboard-mode" class="block text-sm text-fg mb-2">Display mode</label>
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
            <label for="wallboard-palette" class="block text-sm text-fg mb-2">Terminal colors</label>
            <select id="wallboard-palette" wire:model="palette" class="cmp-field w-full">
                <option value="warm">Warm terminal</option><option value="phosphor">Phosphor</option><option value="mono">Monochrome</option>
            </select>
            @error('palette') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="wallboard-rotation" class="block text-sm text-fg mb-2">Seconds per device card (5–60)</label>
            <input id="wallboard-rotation" type="number" min="5" max="60" wire:model="rotation" class="cmp-field w-full">
            @error('rotation') <p class="text-sm text-neg" role="alert">{{ $message }}</p> @enderror
        </div>
        <p class="text-sm text-fg-muted">Read-only display on tty8 (Alt+F8). Alt+F1 returns to the system console. In Auto, disconnecting HDMI stops the renderer and its data reader. A TV in standby may still report HDMI connected.</p>
        <button type="submit" class="cmp-btn" wire:loading.attr="disabled">Save display settings</button>
        @if($notice) <p class="text-sm text-pos" role="status">{{ $notice }}</p> @endif
    </form>
</div>
