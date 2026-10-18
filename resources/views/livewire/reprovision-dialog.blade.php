<div wire:poll.5s>
    <button wire:click="begin" wire:loading.attr="disabled" @disabled($blocker !== null) title="{{ $blocker ?? 'Check the installed system before re-provisioning' }}" class="cmp-btn cmp-btn-secondary cmp-btn-small">Re-provision…</button>
    @if ($blocker)<span class="ml-2 text-xs text-fg-muted">{{ $blocker }}</span>@endif
    @if ($pending)
        <p class="mt-2 text-sm text-fg-muted" role="status">{{ $pending->message }}</p>
        <button wire:click="confirmBoot" wire:confirm="Use {{ $project?->name ?? 'the current project' }} at the next network boot of {{ $serial }}? Then restart the module if it is waiting in the utility OS." wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small mt-2">Confirm current project for next boot</button>
    @endif
    @if ($error && !$open)<p class="text-neg text-sm mt-2" role="alert">{{ $error }}</p>@endif
    @if ($open)
    <div class="fixed z-50 inset-0 overflow-y-auto" x-data="{password:'', next:'', repeat:''}" x-trap.inert.noscroll="true" @keydown.escape.prevent.stop="$wire.close()">
        <div class="fixed inset-0" style="background: var(--scrim)"></div>
        <div class="relative min-h-screen flex items-center justify-center p-4">
            <section class="relative bg-panel text-fg rounded-lg shadow-xl w-full max-w-lg p-6" role="dialog" aria-modal="true" aria-labelledby="reprovision-title">
                <h2 id="reprovision-title" class="text-lg font-semibold">Re-provision {{ $cm->hostname ?: $serial }}</h2>
                @if ($error)<p class="text-neg text-sm mt-2" role="alert">{{ $error }}</p>@endif
                <p class="text-sm text-fg-muted mt-2">Serial {{ $serial }} · MAC {{ $cm->mac }}<br>Next project: {{ $project?->name ?? 'none' }}</p>
                @if (!$operation)<p class="text-sm text-fg-muted mt-3">Check access tries the provisioner key, then enabled accounts from Settings, before asking for a sensor password.</p>@endif
                @if ($operation)
                    <p class="mt-3 text-sm" role="status">{{ $operation->message }}</p>
                    <p class="text-sm text-fg-muted">Address: {{ $operation->address }}</p>
                @endif
                @if (!$operation || $operation->state === 'account')
                    @if ($operation)
                        <label class="block text-sm mt-4" for="sensor-login">Sensor login</label>
                        <input id="sensor-login" wire:model="username" autocomplete="username" class="cmp-field w-full mt-1">
                        <label class="block text-sm mt-3" for="sensor-password">Sensor password</label>
                        <input id="sensor-password" type="password" x-model="password" autocomplete="off" class="cmp-field w-full mt-1">
                    @endif
                    <button @click="const secret=password; password=''; $wire.check(secret)" wire:loading.attr="disabled" class="cmp-btn mt-4">Check access</button>
                @elseif ($operation->state === 'password_expired')
                    <label class="block text-sm mt-4" for="sensor-new-password">New sensor password</label>
                    <input id="sensor-new-password" type="password" x-model="next" autocomplete="new-password" class="cmp-field w-full mt-1">
                    <label class="block text-sm mt-3" for="sensor-repeat-password">Repeat new password</label>
                    <input id="sensor-repeat-password" type="password" x-model="repeat" autocomplete="new-password" class="cmp-field w-full mt-1">
                    <button @click="const a=next,b=repeat; next=''; repeat=''; $wire.changePassword(a,b)" wire:loading.attr="disabled" class="cmp-btn mt-4">Change password and check</button>
                @elseif ($operation->state === 'checked')
                    <p class="text-sm mt-4">Access over {{ $operation->method === 'key' ? "the provisioner's key" : 'account '.$operation->username }}. Serial matches.</p>
                    <p class="text-sm font-mono mt-2">{{ $operation->identity['host'] }} · {{ $operation->identity['storage'] }}<br>CID {{ $operation->identity['cid'] }}</p>
                    <p class="text-sm mt-3">Erases the first MiB of this disk and reboots the sensor for a new provisioning run.</p>
                    <button wire:click="wipe" wire:confirm="Wipe {{ $operation->identity['host'] }} ({{ $serial }}) on {{ $operation->identity['storage'] }} and re-provision it with {{ $project?->name }}?" wire:loading.attr="disabled" class="cmp-btn cmp-btn-danger mt-4">Wipe and re-provision</button>
                @elseif (in_array($operation->state, ['failed','unknown','waiting','booted'], true))
                    @if ($operation->state !== 'booted')<button wire:click="check" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary mt-4">Check access again</button>@endif
                @endif
                <div wire:loading class="text-sm text-fg-muted mt-3" role="status">Working…</div>
                <div class="flex justify-end mt-5"><button wire:click="close" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Close</button></div>
            </section>
        </div>
    </div>
    @endif
</div>
