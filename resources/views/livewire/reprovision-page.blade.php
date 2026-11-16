<div class="cmp-page">
    <div class="cmp-title"><h1>Re-provision</h1><a href="{{ route('cms') }}" class="cmp-btn cmp-btn-secondary ml-auto">Back to CMs</a></div>
    <section class="cmp-card p-4">
        <p class="text-sm text-fg-muted mb-4">Reset a compatible CM4 or Raspberry Pi 4 on this stand. Its previous provisioner and OURS mark are not required.</p>
        <livewire:reprovision-dialog :mac="$mac" :address="$address" :auto-open="true" />
    </section>
</div>
