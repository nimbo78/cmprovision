<div>
    <script src="{{ asset('js/station-power.js') }}?v={{ @filemtime(public_path('js/station-power.js')) }}"></script>
    <x-settings-card title="Station power" description="Restart or shut down this provisioner." id="station-power">
        <div x-data="StationPowerStatus.create(@js(route('settings.power.status')))"
             x-init="if (@js($pending)) start(@js($pending))"
             x-on:station-power-scheduled.window="start($event.detail)" class="space-y-3">
            <p class="text-sm">This station: <strong class="mono">{{ $host }}</strong>. Its web interface and provisioning services will go offline.</p>
            @if ($error)<p class="cmp-flash text-sm" role="alert">{{ $error }}</p>@endif
            @if (!$available)
                <p class="text-sm text-fg-muted">Power controls require the updated cmprovision4 package and systemd.</p>
            @else
                <div class="flex flex-wrap gap-2" x-show="state === 'idle'" x-cloak>
                    <x-confirms-password wire:then="begin('reboot')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Restart station</button></x-confirms-password>
                    <x-confirms-password wire:then="begin('poweroff')"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Shut down station</button></x-confirms-password>
                </div>
            @endif
            <div x-show="state !== 'idle'" x-cloak role="status" aria-live="polite" class="cmp-flash">
                <p class="text-sm" x-text="message()"></p>
                <a x-show="state === 'online' || state === 'signin' || state === 'timeout'" href="{{ route('settings', ['tab' => 'status']) }}" class="text-sm text-link hover:underline">Open station</a>
            </div>
        </div>
    </x-settings-card>
    @if ($open && $confirmation)
        <x-dialog-modal wire:model.live="open" maxWidth="lg">
            <x-slot name="title">{{ $confirmation['stage'] === 'interrupt' ? 'Interrupt provisioning?' : ($confirmation['action'] === 'reboot' ? 'Restart station?' : 'Shut down station?') }}</x-slot>
            <x-slot name="content">
                <div class="space-y-3">
                    <p>You are controlling <strong class="mono">{{ $host }}</strong>.</p>
                    @if ($confirmation['stage'] === 'interrupt')
                        <p class="text-neg font-bold">{{ $confirmation['active_count'] }} module(s) have unfinished provisioning runs.</p>
                        <p>Continuing interrupts their access to this station. Image or EEPROM writes may leave boards unbootable and require recovery or provisioning again.</p>
                    @else
                        <p>The web interface, DHCP/TFTP, USB boot and queue workers will stop. Confirm to {{ $confirmation['action'] === 'reboot' ? 'restart' : 'shut down' }} this station.</p>
                    @endif
                    @if ($confirmation['action'] === 'poweroff')
                        <p>To start the station again, cycle its PoE or external power.</p>
                    @endif
                    <p class="text-sm text-fg-muted">The action starts about five seconds after final confirmation. It cannot be cancelled after scheduling.</p>
                    @if ($error)<p class="text-neg" role="alert">{{ $error }}</p>@endif
                </div>
            </x-slot>
            <x-slot name="footer">
                <button type="button" wire:click="cancel" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Cancel</button>
                <button type="button" wire:click="confirm" wire:loading.attr="disabled" class="cmp-btn cmp-btn-danger ml-3">
                    {{ $confirmation['stage'] === 'interrupt' ? ($confirmation['action'] === 'reboot' ? 'Interrupt and restart' : 'Interrupt and shut down') : ($confirmation['action'] === 'reboot' ? 'Restart station' : 'Shut down station') }}
                </button>
            </x-slot>
        </x-dialog-modal>
    @endif
</div>
