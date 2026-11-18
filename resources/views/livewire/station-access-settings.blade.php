<x-settings-card title="Station access" id="station-access" description="Your web account and the existing Linux administrator of this provisioner. Sensor accounts are separate."
    :bare="$compact" wire:loading.attr="data-wizard-saving">
    @if ($compact)<x-confirm-password-dialog />@endif
    <div x-data x-on:station-access-clear-secrets.window="$el.querySelectorAll('[data-access-secret]').forEach(input => { if (input.dataset.accessSecret === $event.detail.scope) input.value = '' })" @class([$compact ? 'space-y-2' : 'space-y-5'])>
        @if ($notice)<p class="cmp-flash text-sm" role="status">{{ $notice }}</p>@endif
        @if ($error)<p class="cmp-flash text-neg text-sm" role="alert">{{ $error }}</p>@endif
        <x-wizard-disclosure :enabled="$compact" title="Web account" :value="auth()->user()->email">
        <form id="station-web-access-form" data-wizard-form data-wizard-editor="closed" class="space-y-3" x-on:submit.prevent="$wire.saveWeb($refs.webCurrent.value, $refs.webNew.value, $refs.webConfirm.value)">
            @unless ($compact)<h3 class="font-bold">Current web account</h3>@endunless
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="access-name" class="block text-sm mb-1">Name</label><input id="access-name" wire:model="name" class="cmp-field w-full" maxlength="255">@error('name')<p class="text-neg text-sm">{{ $message }}</p>@enderror</div>
                <div><label for="access-email" class="block text-sm mb-1">Sign-in email</label><input id="access-email" type="email" wire:model="email" class="cmp-field w-full" maxlength="255">@error('email')<p class="text-neg text-sm">{{ $message }}</p>@enderror</div>
                <div><label for="access-current" class="block text-sm mb-1">Current web password</label><input id="access-current" x-ref="webCurrent" data-access-secret="web" type="password" autocomplete="current-password" class="cmp-field w-full">@error('current_password')<p class="text-neg text-sm">{{ $message }}</p>@enderror</div>
                <div><label for="access-new" class="block text-sm mb-1">New web password (optional)</label><input id="access-new" x-ref="webNew" data-access-secret="web" type="password" autocomplete="new-password" class="cmp-field w-full">@error('password')<p class="text-neg text-sm">{{ $message }}</p>@enderror</div>
                <div><label for="access-confirm" class="block text-sm mb-1">Confirm new web password</label><input id="access-confirm" x-ref="webConfirm" data-access-secret="web" type="password" autocomplete="new-password" class="cmp-field w-full"></div>
            </div>
            <button type="submit" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled"><span wire:loading.remove wire:target="saveWeb">Save web account</span><span wire:loading wire:target="saveWeb">Saving…</span></button>
            <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="discardWeb" wire:loading.attr="disabled">Discard web edits</button>
        </form>
        </x-wizard-disclosure>
        <x-wizard-disclosure :enabled="$compact" title="Station SSH"
            :value="$plan ? 'Awaiting approval' : (!$status ? 'Unavailable' : (($status['ssh_active'] ?? false) ? 'Running' : 'Stopped'))"
            :open="(bool) $plan" wire:key="ssh-disclosure-{{ $plan ? 'pending' : 'settings' }}">
        <div id="station-ssh-access-form" data-wizard-form data-wizard-editor="{{ $plan ? 'open' : 'closed' }}" @class(['space-y-3', 'border-t border-line pt-4' => !$compact])>
            @unless ($compact)<h3 class="font-bold">Linux / SSH access</h3>@endunless
            <p class="text-sm text-fg-muted">Existing users, SSH keys and sudo policy are retained. Keep your current SSH session open until you have tested a new login.</p>
            @if (!$status)
                <p class="text-sm text-fg-muted">SSH access control is unavailable. Use the station's existing SSH session or local console.</p>
            @elseif ($plan)
                <div class="rounded border border-line p-3 space-y-2" role="status">
                    <p class="font-bold">Authorize changes for {{ $plan['user'] }}</p>
                    <ul class="text-sm list-disc pl-5">@foreach ($plan['changes'] as $change)<li class="break-all">{{ $change }}</li>@endforeach</ul>
                    <p class="text-sm">In an existing administrator SSH session or local console, run:</p>
                    <pre class="mono text-sm overflow-x-auto">sudo cmprovision-access approve {{ $plan['id'] }}</pre>
                    <p class="text-sm text-fg-muted">Review the request in that terminal. Its one-time code lasts up to two minutes. The prepared request expires after five minutes.</p>
                    <form x-on:submit.prevent="$el.querySelector('[data-access-apply]').click()" class="space-y-2">
                        <label for="access-code" class="block text-sm">One-time authorization code</label>
                        <input id="access-code" x-ref="accessCode" data-access-secret="ssh" class="cmp-field mono w-full" maxlength="64" autocomplete="one-time-code" placeholder="ABCD-EFGH-IJKL">
                        <div class="flex flex-wrap gap-2"><x-confirms-password wire:then="applySsh($refs.accessCode.value)"><button type="button" data-access-apply class="cmp-btn" wire:loading.attr="disabled">Apply SSH changes</button></x-confirms-password>
                            <x-confirms-password wire:then="cancelSsh"><button type="button" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Cancel request</button></x-confirms-password>
                            <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="refreshStatus" wire:loading.attr="disabled">Refresh</button></div>
                    </form>
                </div>
            @else
                <form x-on:submit.prevent="$el.querySelector('[data-ssh-prepare]').click()" class="space-y-3">
                    <div><label for="access-linux-user" class="block text-sm mb-1">Existing Linux administrator</label><select id="access-linux-user" wire:model="linuxUser" class="cmp-field w-full">
                        <option value="">Choose an administrator</option>@foreach ($status['users'] ?? [] as $user)<option value="{{ $user['user'] }}">{{ $user['user'] }}</option>@endforeach
                    </select></div>
                    <div><label for="access-linux-password" class="block text-sm mb-1">New Linux password (optional)</label><input id="access-linux-password" x-ref="linuxPassword" data-access-secret="ssh" type="password" autocomplete="new-password" minlength="8" maxlength="256" class="cmp-field w-full"></div>
                    <div><label for="access-linux-keys" class="block text-sm mb-1">Add SSH public keys (optional)</label><textarea id="access-linux-keys" x-ref="linuxKeys" data-access-secret="ssh" rows="3" maxlength="16384" spellcheck="false" class="cmp-field mono w-full" placeholder="ssh-ed25519 AAAA…"></textarea></div>
                    <label class="flex gap-2 items-center text-sm"><input type="checkbox" wire:model="enableSsh">Enable SSH service</label>
                    <p class="text-sm text-fg-muted">SSH service: {{ ($status['ssh_active'] ?? false) ? 'running' : 'inactive' }}. Global SSH authentication rules are preserved; changing a Linux password does not override a disabled SSH password login.</p>
                    <x-confirms-password wire:then="prepareSsh($refs.linuxPassword.value, $refs.linuxKeys.value)"><button type="button" data-ssh-prepare class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled"><span wire:loading.remove wire:target="prepareSsh">Review SSH changes</span><span wire:loading wire:target="prepareSsh">Preparing…</span></button></x-confirms-password>
                    <button type="button" class="cmp-btn cmp-btn-secondary" wire:click="discardSsh" wire:loading.attr="disabled">Discard SSH edits</button>
                </form>
            @endif
        </div>
        </x-wizard-disclosure>
    </div>
</x-settings-card>
