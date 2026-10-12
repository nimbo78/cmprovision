<div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md">
    @php($input = 'cmp-field w-full')
    @php($label = 'block text-fg text-sm font-bold mb-2')

    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}">{{ $notice }}</div>
    @endif

    <div class="flex mb-4">
        <div class="w-full" style="margin-right: 1rem">
            <label class="{{ $label }}">Switch address</label>
            <input type="text" wire:model="host" class="{{ $input }}" placeholder="192.168.25.2 (empty: lookup off)">
            @error('host') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        </div>
        <div style="min-width: 9rem">
            <label class="{{ $label }}">SNMP</label>
            <select wire:model.live="version" class="{{ $input }}">
                <option value="2c">v2c</option>
                <option value="3">v3</option>
            </select>
        </div>
    </div>

    @if ($version === '2c')
        <div class="mb-4">
            <label class="{{ $label }}">Community (read-only)</label>
            <input type="password" wire:model="community" class="{{ $input }}" autocomplete="new-password"
                   placeholder="{{ ($stored['community'] ?? false) ? 'leave empty to keep the stored community' : 'e.g. public' }}">
            @error('community') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        </div>
    @else
        <div class="mb-4">
            <label class="{{ $label }}">User</label>
            <input type="text" wire:model="user" class="{{ $input }}">
            @error('user') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        </div>
        <div class="flex mb-4">
            <div style="min-width: 9rem; margin-right: 1rem">
                <label class="{{ $label }}">Authentication</label>
                <select wire:model.live="authProtocol" class="{{ $input }}">
                    <option value="">none</option>
                    @foreach (\App\Services\Snmp\PhpSnmpClient::authProtocols() as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </div>
            <div class="w-full">
                <label class="{{ $label }}">Authentication password</label>
                <input type="password" wire:model="authPassword" class="{{ $input }}" autocomplete="new-password"
                       placeholder="{{ ($stored['auth_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                @error('authPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
        <div class="flex mb-4">
            <div style="min-width: 9rem; margin-right: 1rem">
                <label class="{{ $label }}">Privacy</label>
                <select wire:model.live="privProtocol" class="{{ $input }}">
                    <option value="">none</option>
                    @foreach (\App\Services\Snmp\PhpSnmpClient::PRIV_PROTOCOLS as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </div>
            <div class="w-full">
                <label class="{{ $label }}">Privacy password</label>
                <input type="password" wire:model="privPassword" class="{{ $input }}" autocomplete="new-password"
                       placeholder="{{ ($stored['priv_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                @error('privPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>
        </div>
    @endif

    <div class="mb-4">
        <label class="{{ $label }}">Lookup method</label>
        <select wire:model="method" class="{{ $input }}">
            <option value="auto">automatic (tries all{{ ($stored['detected'] ?? null) ? ', last worked: '.\App\Services\SwitchPortFinder::METHODS[$stored['detected']] : '' }})</option>
            @foreach (\App\Services\SwitchPortFinder::METHODS as $key => $title)<option value="{{ $key }}">{{ $title }}</option>@endforeach
        </select>
    </div>

    <button wire:click="test()" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Test</button>
    <button wire:click="save()" class="cmp-btn ml-2">Save</button>
    <span wire:loading wire:target="test" class="text-sm text-fg-muted ml-2">asking the switch...</span>

    @if ($result)
        <div class="mt-4 text-sm">
            @if ($result['method'])
                <div class="mb-2">MAC table read with <span class="font-semibold">{{ $result['method'] }}</span>: {{ count($result['rows']) }} addresses.</div>
            @endif
            @if ($result['rows'])
                <table class="cmp-table mb-2">
                    <thead><tr>
                        <th>Port</th><th>MAC</th>
                        <th>VLAN</th><th>Module</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($result['rows'] as $row)
                        <tr>
                            <td >{{ $row['port'] }}</td>
                            <td class="font-mono">{{ $row['mac'] }}</td>
                            <td >{{ $row['vlan'] }}</td>
                            <td class="font-mono">{{ $row['serial'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
            <div class="text-xs text-fg-muted">
                @foreach ($result['tried'] as $method => $answer)
                    <div>{{ $method }}: {{ $answer }}</div>
                @endforeach
            </div>
        </div>
    @endif

    @include('livewire.switch-snmp-hints')
</div>
