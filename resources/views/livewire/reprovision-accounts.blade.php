<div class="cmp-card p-6">
    <p class="text-sm text-fg-muted">Check access tries the provisioner key first, then these enabled accounts in order, then offers manual entry. Passwords stay on the server and are never shown again.</p>
    <p class="text-sm text-fg-muted mt-2">Enable up to 3 accounts. Automatic attempts run once per check and at most once per minute for each module. Wiping still needs a separate confirmation.</p>
    @if ($error)<p class="text-neg text-sm mt-3" role="alert">{{ $error }}</p>@endif
    <div class="mt-4 space-y-3">
        @forelse ($accounts as $a)
            <div wire:key="sensor-account-{{ $a['id'] }}" class="border border-line rounded-md p-3">
                <p class="font-semibold">{{ $a['name'] }}</p>
                <p class="text-sm text-fg-muted">Login: {{ $a['user'] }} · {{ $a['enabled'] ? 'automatic check on' : 'automatic check off' }}</p>
                <div class="flex flex-wrap gap-2 mt-2">
                    <button wire:click="edit('{{ $a['id'] }}')" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">Edit</button>
                    <x-confirms-password wire:then="toggle('{{ $a['id'] }}')"><button class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">{{ $a['enabled'] ? 'Disable' : 'Enable' }}</button></x-confirms-password>
                    @if (!$loop->first)<x-confirms-password wire:then="moveUp('{{ $a['id'] }}')"><button aria-label="Move {{ $a['name'] }} up" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">↑</button></x-confirms-password>@endif
                    @if (!$loop->last)<x-confirms-password wire:then="moveDown('{{ $a['id'] }}')"><button aria-label="Move {{ $a['name'] }} down" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">↓</button></x-confirms-password>@endif
                    <x-confirms-password wire:then="remove('{{ $a['id'] }}')"><button class="cmp-btn cmp-btn-danger cmp-btn-small" wire:loading.attr="disabled" x-on:click="if (!confirm('Delete this saved sensor account?')) $event.stopPropagation()">Delete</button></x-confirms-password>
                </div>
            </div>
        @empty
            <p class="text-sm text-fg-muted">No saved sensor accounts. Check access will offer manual entry when the key is not accepted.</p>
        @endforelse
    </div>
    <p class="text-xs text-fg-muted mt-3">WLAN Pi starts with wlanpi / wlanpi. The legacy Raspberry Pi profile uses pi / raspberry; current Raspberry Pi OS images use the account configured during installation.</p>
    @if (!$form)
        <button wire:click="add" class="cmp-btn cmp-btn-secondary mt-4" wire:loading.attr="disabled">Add sensor account</button>
    @else
        <div class="mt-4" wire:key="sensor-account-form-{{ $editing ?? 'new' }}" x-data="{secret:''}" @sensor-account-secret-cleared.window="secret=''">
            <label for="sensor-account-name" class="block text-sm mt-3">Account name</label>
            <input id="sensor-account-name" wire:model="name" class="cmp-field w-full mt-1" maxlength="80">
            @error('name')<p class="text-neg text-sm">{{ $message }}</p>@enderror
            <label for="sensor-account-user" class="block text-sm mt-3">Saved sensor login</label>
            <input id="sensor-account-user" wire:model="user" autocomplete="off" class="cmp-field w-full mt-1" maxlength="32">
            @error('user')<p class="text-neg text-sm">{{ $message }}</p>@enderror
            <label for="sensor-account-secret" class="block text-sm mt-3">Saved sensor password</label>
            <input id="sensor-account-secret" type="password" x-model="secret" autocomplete="new-password" class="cmp-field w-full mt-1" placeholder="{{ $editing ? 'Leave empty to keep the saved password' : '' }}">
            @error('password')<p class="text-neg text-sm">{{ $message }}</p>@enderror
            <label class="flex items-center text-sm mt-3"><input type="checkbox" wire:model="enabled" class="mr-2">Try automatically</label>
            <div class="flex gap-2 mt-4">
                <x-confirms-password wire:then="save(secret)"><button class="cmp-btn" wire:loading.attr="disabled">Save sensor account</button></x-confirms-password>
                <button wire:click="cancel" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Cancel</button>
            </div>
        </div>
    @endif
</div>
