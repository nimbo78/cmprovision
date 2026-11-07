<div class="fixed z-50 inset-0 overflow-y-auto" x-data="{ wizardCredentials: {} }" x-trap.inert.noscroll="true" x-on:keydown.escape.stop.prevent="$wire.closeModal()">
    <div class="fixed inset-0" style="background: var(--scrim)"></div>
    <div class="relative mx-auto my-4 w-full max-w-2xl rounded-lg bg-panel shadow-xl overflow-hidden" role="dialog" aria-modal="true" aria-label="Create script">
        <div class="p-4 sm:p-6">
            <x-wizard-progress :steps="$this->wizardSteps()" :step="$wizardStep" />
            @if ($errors->any())<div class="cmp-flash text-neg mb-4" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            @switch($wizardStep)
                @case(1)
                    <p class="text-sm text-fg-muted mb-4">Give the script a name that describes its job. Start with the shell template or use Copy with wizard on an existing script.</p>
                    @include('livewire.script-fields.name')
                    @break
                @case(2)
                    @include('livewire.script-fields.phase')
                    <dl class="text-sm space-y-3">
                        <div><dt class="font-bold">Pre-install</dt><dd class="text-fg-muted">Runs in the utility OS before writing the image. Use it to prepare or check the hardware.</dd></div>
                        <div><dt class="font-bold">Post-install</dt><dd class="text-fg-muted">Runs after writing. The image partitions are available through $PART1 and $PART2.</dd></div>
                        <div><dt class="font-bold">First boot</dt><dd class="text-fg-muted">Runs as root in the written OS during its check. The project must enable OS check; failure fails the check.</dd></div>
                    </dl>
                    @break
                @case(3)
                    @include('livewire.script-fields.priority')
                    @include('livewire.script-fields.background')
                    @if ($script_type === 'firstboot')<p class="text-sm text-fg-muted">Sequential execution: first-boot scripts always finish before the next script starts.</p>@endif
                    @break
                @case(4)
                    @include('livewire.script-fields.content')
                    <p class="text-sm text-fg-muted">Saving stores the script. It runs only when a project uses it during provisioning.</p>
                    @break
                @case(5)
                    <dl class="grid grid-cols-2 gap-2 text-sm mb-4">
                        <dt class="text-fg-muted">Script</dt><dd class="break-words">{{ $name }}</dd>
                        <dt class="text-fg-muted">Phase</dt><dd>{{ $script_type }}</dd>
                        <dt class="text-fg-muted">Priority</dt><dd>{{ $priority }}</dd>
                        <dt class="text-fg-muted">Execution</dt><dd>{{ $script_type !== 'firstboot' && $bg ? 'Background' : 'Sequential' }}</dd>
                    </dl>
                    <pre class="font-mono text-sm whitespace-pre-wrap break-words bg-head p-3 rounded max-h-72 overflow-auto">{{ $script }}</pre>
                    @break
            @endswitch
        </div>
        <x-wizard-navigation :steps="$this->wizardSteps()" :step="$wizardStep" />
    </div>
</div>
