@props(['steps', 'step', 'confirmedSave' => false])
<div class="flex flex-wrap items-center gap-2 border-t border-pline bg-head px-4 py-3" x-on:wizard-credentials-stored.window="wizardCredentials = {}">
    <button type="button" wire:click="closeModal" class="cmp-btn cmp-btn-secondary">Cancel</button>
    @if ($step !== array_key_first($steps))
        <button type="button" wire:click="previousWizardStep" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Back</button>
    @endif
    <div class="ml-auto">
        @if ($step === array_key_last($steps))
            @if ($confirmedSave)
                <x-confirms-password wire:then="store"><button type="button" class="cmp-btn" wire:loading.attr="disabled">Save</button></x-confirms-password>
            @else
                <button type="button" wire:click="store" class="cmp-btn" wire:loading.attr="disabled">Save</button>
            @endif
        @else
            <button type="button" class="cmp-btn" wire:loading.attr="disabled" x-on:click="$wire.nextWizardStep(wizardCredentials)">Next</button>
        @endif
    </div>
</div>
