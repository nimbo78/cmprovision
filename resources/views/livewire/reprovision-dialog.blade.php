<div wire:poll.5s>
    <button wire:click="begin" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small">Re-provision…</button>
    @if ($pending)
        <p class="mt-2 text-sm text-fg-muted" role="status">{{ $pending->message }}</p>
        @if (!$secondary)
            <button wire:click="confirmBoot" wire:confirm="Confirm {{ $project?->name ?? 'the current project' }} for this module's next network boot?" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary cmp-btn-small mt-2">Confirm current project for next boot</button>
        @endif
    @endif
    @if ($error && !$open)<p class="text-neg text-sm mt-2" role="alert">{{ $error }}</p>@endif
    @if ($open)
    <div class="fixed z-50 inset-0 overflow-y-auto" x-data="{password:'', next:'', repeat:''}" x-trap.inert.noscroll="true" @keydown.escape.prevent.stop="$wire.close()">
        <div class="fixed inset-0" style="background: var(--scrim)"></div>
        <div class="relative min-h-screen flex items-center justify-center p-4">
            <section class="relative bg-panel text-fg rounded-lg shadow-xl w-full max-w-lg p-6" role="dialog" aria-modal="true" aria-labelledby="{{ $dialogId }}">
                <h2 id="{{ $dialogId }}" class="text-lg font-semibold">Re-provision {{ $cm?->displayName() ?? 'module' }}</h2>
                @if ($error)<p class="text-neg text-sm mt-2" role="alert">{{ $error }}</p>@endif
                @if ($targetMac)<p class="text-sm text-fg-muted mt-2">MAC {{ $targetMac }}{{ $targetSerial ? ' · Serial '.$targetSerial : '' }}</p>@endif
                @if ($secondary)
                    <p class="text-sm mt-3">This is a secondary station. The current primary serves the next network boot and supplies its active project.</p>
                    @if ($handoff)
                        <a href="{{ $handoff }}" class="cmp-btn mt-4">Open current primary</a>
                        <p class="text-sm text-fg-muted mt-2">Sign in there and check access again. Only the device address hints are carried over.</p>
                    @else
                        <p class="text-sm mt-3">Set the current primary web address in <a class="text-link underline" href="{{ route('settings',['tab'=>'network']) }}#reprovision-primary">Settings → Network</a>.</p>
                    @endif
                @else
                    <p class="text-sm text-fg-muted mt-2">Next project: {{ $project?->name ?? 'none' }}<br>Image: {{ $project?->image?->filename ?? 'none' }}</p>
                    @if ($station['error'] ?? null)<p class="text-sm text-warn mt-2">{{ $station['error'] }}</p>@endif
                    @if ($blocker)<p class="text-sm text-warn mt-2">{{ $blocker }}</p>@endif
                    @if (!$operation)
                        <p class="text-sm text-fg-muted mt-3">Check access tries the provisioner key, then enabled accounts from Settings. It checks the device without erasing it.</p>
                        <label class="block text-sm mt-4" for="{{ $dialogId }}-address">Module IPv4 address{{ $targetMac ? ' (optional when a DHCP lease exists)' : '' }}</label>
                        <input id="{{ $dialogId }}-address" wire:model="addressInput" inputmode="decimal" maxlength="15" placeholder="172.20.…" class="cmp-field w-full mt-1">
                        <button wire:click="check" wire:loading.attr="disabled" @disabled($blocker !== null) class="cmp-btn mt-4">Check access</button>
                    @else
                        <p class="mt-3 text-sm" role="status">{{ $operation->message }}</p>
                        <p class="text-sm text-fg-muted">Address: {{ $operation->address }}</p>
                        @if ($operation->state === 'failed' && str_contains((string) $operation->message,'USB prep'))
                            <p class="text-sm mt-2"><a href="{{ route('settings',['tab'=>'provisioning']) }}#usb-prep" class="text-link underline">USB prep instructions</a></p>
                        @endif
                        @if ($operation->state === 'account')
                            <label class="block text-sm mt-4" for="{{ $dialogId }}-login">Sensor login</label>
                            <input id="{{ $dialogId }}-login" wire:model="username" autocomplete="username" class="cmp-field w-full mt-1">
                            <label class="block text-sm mt-3" for="{{ $dialogId }}-password">Sensor password</label>
                            <input id="{{ $dialogId }}-password" type="password" x-model="password" autocomplete="off" class="cmp-field w-full mt-1">
                            <button @click="const secret=password; password=''; $wire.check(secret)" wire:loading.attr="disabled" class="cmp-btn mt-4">Check access</button>
                        @elseif ($operation->state === 'password_expired')
                            <label class="block text-sm mt-4" for="{{ $dialogId }}-new">New sensor password</label>
                            <input id="{{ $dialogId }}-new" type="password" x-model="next" autocomplete="new-password" class="cmp-field w-full mt-1">
                            <label class="block text-sm mt-3" for="{{ $dialogId }}-repeat">Repeat new password</label>
                            <input id="{{ $dialogId }}-repeat" type="password" x-model="repeat" autocomplete="new-password" class="cmp-field w-full mt-1">
                            <button @click="const a=next,b=repeat; next=''; repeat=''; $wire.changePassword(a,b)" wire:loading.attr="disabled" class="cmp-btn mt-4">Change password and check</button>
                        @elseif ($operation->state === 'checked' && !$operation->expires_at->isPast())
                            <p class="text-sm mt-4">{{ ($operation->context['cm_id'] ?? null) ? 'Serial matches our records.' : 'Identity read from device.' }} Access over {{ $operation->method === 'key' ? "the provisioner's key" : 'account '.$operation->username }}.</p>
                            @if (($operation->identity['agent'] ?? '') === '2')<p class="text-sm mt-2">{{ $operation->identity['model'] }} · revision {{ $operation->identity['revision'] }}<br>{{ \App\Services\Sensor\TargetInspection::bootReady($operation->identity['boot_order']) ? 'Network boot ready' : 'Network boot not confirmed' }} · {{ $operation->identity['boot_order'] }}</p>@endif
                            <p class="text-sm mt-2">{{ $operation->identity['host'] }} · {{ $operation->identity['storage'] }} · {{ \App\Models\Image::size((int) $operation->identity['sectors'] * 512) }}</p>
                            <p class="text-xs font-mono text-fg-muted mt-2 break-all">Serial {{ $operation->serial }}<br>CID {{ $operation->identity['cid'] }}</p>
                            <p class="text-sm mt-3">Erases the first MiB of this disk and reboots the module for a new provisioning run.</p>
                            <button wire:click="wipe" wire:confirm="Wipe {{ $operation->identity['host'] }} ({{ $operation->mac }}) on {{ $operation->identity['storage'] }} and re-provision it with {{ $project?->name }}?" wire:loading.attr="disabled" class="cmp-btn cmp-btn-danger mt-4">Wipe and re-provision</button>
                        @elseif ($operation->state !== 'booted')
                            @if ($operation->state === 'checked')<p class="text-sm text-warn mt-3">The access check expired. Check again before confirming.</p>@endif
                            <button wire:click="check" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary mt-4">Check access again</button>
                        @endif
                    @endif
                    <div wire:loading wire:target="check,changePassword" class="text-sm text-fg-muted mt-3" role="status">Checking access and device…</div>
                    <div wire:loading wire:target="wipe" class="text-sm text-fg-muted mt-3" role="status">Sending the reset command…</div>
                @endif
                <div class="flex justify-end gap-2 mt-5">
                    @if ($operation && !in_array($operation->state,['dispatching','waiting','unknown','booted'],true))<button wire:click="back" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Back</button>@endif
                    <button wire:click="close" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">{{ $operation && in_array($operation->state,['dispatching','waiting','unknown','booted'],true) ? 'Close' : 'Cancel' }}</button>
                </div>
            </section>
        </div>
    </div>
    @endif
</div>
