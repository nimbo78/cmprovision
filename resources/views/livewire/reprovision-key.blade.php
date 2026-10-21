<div class="cmp-card p-6">
    @if ($public)
        <label class="block text-sm font-semibold" for="reprovision-public-key">Provisioner public key</label>
        <textarea id="reprovision-public-key" readonly class="cmp-field font-mono text-xs w-full mt-2" rows="3">{{ $public }}</textarea>
        <p class="text-xs text-fg-muted font-mono mt-2 break-all">{{ $fingerprint }}</p>
        <button x-data @click="navigator.clipboard.writeText(document.getElementById('reprovision-public-key').value)" class="cmp-btn cmp-btn-secondary mt-3">Copy public key</button>
        <p class="text-sm text-fg-muted mt-3">For manual installation, use a restricted authorized_keys entry with command="/usr/local/sbin/cmprovision-agent" and the matching agent. Adding a plain unrestricted key grants broader access.</p>
    @else
        <button wire:click="prepare" wire:loading.attr="disabled" class="cmp-btn">Create provisioner key</button>
    @endif
    @if ($error)<p class="text-neg text-sm mt-2" role="alert">{{ $error }}</p>@endif
    <p class="text-sm text-fg-muted mt-3">Projects with Re-provision enabled install this public key and its restricted agent. The private key stays on this server and is included in backups.</p>
</div>
