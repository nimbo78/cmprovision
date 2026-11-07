<div class="fixed z-50 inset-0 overflow-y-auto" x-data="{ wizardCredentials: {} }" x-trap.inert.noscroll="true" x-on:keydown.escape.stop.prevent="$wire.closeModal()">
    <div class="fixed inset-0" style="background: var(--scrim)"></div>
    <div class="relative mx-auto my-4 w-full max-w-2xl rounded-lg bg-panel shadow-xl overflow-hidden" role="dialog" aria-modal="true" aria-label="Create project">
        <div class="p-4 sm:p-6">
            <x-wizard-progress :steps="$this->wizardSteps()" :step="$wizardStep" />
            @if ($errors->any())
                <div class="cmp-flash text-neg mb-4" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            @switch($wizardStep)
                @case(1)
                    <p class="text-sm text-fg-muted mb-4">Create a sensor project or prepare a new cmprovision station. Save first; activation is an explicit choice at the end.</p>
                    @include('livewire.project-fields.name')
                    <label class="flex gap-2 items-center text-sm"><x-checkbox wire:model.live="stationEnabled" /> Set up a cmprovision station</label>
                    @break
                @case(2)
                    @include('livewire.project-fields.image')
                    @include('livewire.project-fields.storage')
                    <p class="text-sm text-fg-muted">Use /dev/mmcblk0 for the CM4's eMMC. A project without an image can run scripts or update EEPROM.</p>
                    @break
                @case(3)
                    @include('livewire.project-fields.checks')
                    @include('livewire.project-fields.firmware')
                    @break
                @case(4)
                    <p class="text-sm text-fg-muted mb-4">Pre-install scripts run before writing, post-install scripts after writing, and first-boot scripts inside the installed OS check. Lower priorities run first.</p>
                    @include('livewire.project-fields.scripts')
                    @include('livewire.project-fields.packages')
                    @if (!$scripts->count() && !$packages->count())<p class="text-fg-muted">No scripts or packages saved. Continue without them, or add them on Scripts first.</p>@endif
                    @break
                @case(5)
                    @include('livewire.project-fields.label')
                    @break
                @case(6)
                    @include('livewire.project-fields.station')
                    @break
                @case(7)
                    <dl class="grid grid-cols-2 gap-2 text-sm mb-5">
                        <dt class="text-fg-muted">Project</dt><dd class="break-words">{{ $name }}</dd>
                        <dt class="text-fg-muted">Purpose</dt><dd>{{ $stationEnabled ? 'Provisioner station' : 'Sensor' }}</dd>
                        <dt class="text-fg-muted">Image</dt><dd class="break-words">{{ $images->firstWhere('id', $image_id)?->filename ?? 'None' }}</dd>
                        <dt class="text-fg-muted">Storage</dt><dd class="font-mono">{{ $storage }}</dd>
                        <dt class="text-fg-muted">Verify / OS check / Split</dt><dd>{{ $verify ? 'On' : 'Off' }} / {{ $os_check ? 'On' : 'Off' }} / {{ $split_image ? 'On' : 'Off' }}</dd>
                        <dt class="text-fg-muted">Re-provision / mark boots</dt><dd>{{ $reprovision ? 'On' : 'Off' }} / {{ $mark_boots }}</dd>
                        <dt class="text-fg-muted">Scripts / packages</dt><dd>{{ count($selectedScripts ?: []) }} / {{ count($selectedPackages ?: []) }}</dd>
                        <dt class="text-fg-muted">Label</dt><dd>{{ $label_moment }}</dd>
                        @if ($stationEnabled)<dt class="text-fg-muted">Station access</dt><dd>{{ $station['username'] ?? '' }} · {{ ($station['wifi'] ?? false) ? 'Wi-Fi configured' : 'No Wi-Fi' }} · credentials retained privately</dd>@endif
                    </dl>
                    @include('livewire.project-fields.active')
                    @break
            @endswitch
        </div>
        <x-wizard-navigation :steps="$this->wizardSteps()" :step="$wizardStep" :confirmed-save="$stationEnabled" />
    </div>
</div>
