<div class="fixed z-50 inset-0 overflow-y-auto" x-data="{ wizardCredentials: {} }" x-trap.inert.noscroll="true" x-on:keydown.escape.stop.prevent="$wire.closeModal()">
    <div class="fixed inset-0" style="background: var(--scrim)"></div>
    <div class="relative mx-auto my-4 w-full max-w-2xl rounded-lg bg-panel shadow-xl overflow-hidden" role="dialog" aria-modal="true" aria-label="Create label">
        <div class="p-4 sm:p-6">
            <x-wizard-progress :steps="$this->wizardSteps()" :step="$wizardStep" />
            @if ($errors->any())<div class="cmp-flash text-neg mb-4" role="alert">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            @if (session('message'))<div class="cmp-flash mb-4" role="status">{{ session('message') }}</div>@endif
            @switch($wizardStep)
                @case(1)
                    <p class="text-sm text-fg-muted mb-4">Choose how this label reaches its printer. Copy an existing label to retain its printer language and layout.</p>
                    @include('livewire.label-fields.name')
                    @include('livewire.label-fields.method')
                    @include('livewire.label-fields.extension')
                    @break
                @case(2)
                    @include('livewire.label-fields.connection')
                    <p class="text-sm text-fg-muted">The printer is contacted only when you explicitly print a test label. A saved FTP password is retained only for the same host and user.</p>
                    @break
                @case(3)
                    @include('livewire.label-fields.template')
                    <h3 class="font-bold mt-4">Sample expansion</h3>
                    <pre class="font-mono text-sm whitespace-pre-wrap break-words bg-head p-3 rounded mt-2">{{ $this->previewLabel() }}</pre>
                    @break
                @case(4)
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <dt class="text-fg-muted">Label</dt><dd class="break-words">{{ $name }}</dd>
                        <dt class="text-fg-muted">Output</dt><dd>{{ $printer_type }} · .{{ $file_extension }}</dd>
                        <dt class="text-fg-muted">Destination</dt><dd class="break-words">{{ $printer_type === 'ftp' ? $ftp_hostname : $print_command }}</dd>
                    </dl>
                    <h3 class="font-bold mt-4">Sample expansion</h3>
                    <pre class="font-mono text-sm whitespace-pre-wrap break-words bg-head p-3 rounded mt-2">{{ $this->previewLabel() }}</pre>
                    <button type="button" wire:click="printTestLabel" class="cmp-btn cmp-btn-secondary mt-4" wire:loading.attr="disabled">Print test label</button>
                    <p class="text-sm text-fg-muted mt-2">Save creates the definition. It does not send a print job.</p>
                    @break
            @endswitch
        </div>
        <x-wizard-navigation :steps="$this->wizardSteps()" :step="$wizardStep" />
    </div>
</div>
