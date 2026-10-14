<div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md">
    @php($input = 'cmp-field w-full')
    @php($label = 'block text-fg text-sm font-bold mb-2')

    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @foreach ($duplicates as $name)
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded mb-4" role="status">
            Two switches are called {{ $name }}: their boards look the same. Rename one on the switch.
        </div>
    @endforeach

    @if ($switches)
        <div class="overflow-x-auto mb-4">
            <table class="cmp-table">
                <thead><tr><th>Name</th><th>Address</th><th>SNMP</th><th>Write access</th><th>Last answer</th><th></th></tr></thead>
                <tbody>
                @foreach ($switches as $s)
                    <tr wire:key="switch-{{ $s['id'] }}" @class(['bg-head' => $form && (int) $editingId === $s['id']])>
                        <td class="font-semibold">{{ $s['name'] }}</td>
                        <td class="font-mono">{{ $s['host'] }}</td>
                        <td>{{ $s['version'] }}</td>
                        <td>{{ $s['writable'] ? 'PoE restart' : 'none' }}</td>
                        <td class="text-fg-muted">{{ $s['state'] }}</td>
                        <td class="whitespace-nowrap text-right">
                            <button type="button" wire:click="edit({{ $s['id'] }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Edit</button>
                            <button type="button" wire:click="delete({{ $s['id'] }})" wire:confirm="Remove {{ $s['name'] }} ({{ $s['host'] }}) from the stand? Its modules keep their boards."
                                    class="cmp-btn cmp-btn-secondary cmp-btn-small ml-1">Delete</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="text-sm text-fg-muted mb-4">No switch yet: boards come from the jumpers.</p>
    @endif

    @if (!$form)
        <button type="button" wire:click="add" class="cmp-btn">Add switch</button>
    @else
    <div class="border-t border-pline pt-4">
    <div class="font-bold mb-3">{{ $editingId ? 'Edit switch' : 'New switch' }}</div>
    <div class="flex mb-4">
        <div class="w-full" style="margin-right: 1rem">
            <label class="{{ $label }}">Switch address</label>
            <input type="text" wire:model="host" class="{{ $input }}" placeholder="192.168.25.2">
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

    <div class="mb-4 border-t border-pline pt-4">
        <div class="font-bold">Write access (PoE restart)</div>
        <p class="text-sm text-fg-muted mb-3">Lets cmprovision switch a module's PoE port off and on again. Empty: no PoE restarts.</p>
        @if ($version === '2c')
            <label class="{{ $label }}">Write community</label>
            <input type="password" wire:model="writeCommunity" class="{{ $input }}" autocomplete="new-password"
                   placeholder="{{ ($stored['write_community'] ?? false) ? 'leave empty to keep the stored write community' : 'empty: no PoE restarts' }}">
            @error('writeCommunity') <span class="text-neg text-sm">{{ $message }}</span> @enderror
        @else
            <div class="mb-4">
                <label class="{{ $label }}">Write user</label>
                <input type="text" wire:model="writeUser" class="{{ $input }}" placeholder="empty: no PoE restarts">
                @error('writeUser') <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>
            <div class="flex mb-4">
                <div style="min-width: 9rem; margin-right: 1rem">
                    <label class="{{ $label }}">Authentication</label>
                    <select wire:model.live="writeAuthProtocol" class="{{ $input }}">
                        <option value="">none</option>
                        @foreach (\App\Services\Snmp\PhpSnmpClient::authProtocols() as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                    </select>
                </div>
                <div class="w-full">
                    <label class="{{ $label }}">Authentication password</label>
                    <input type="password" wire:model="writeAuthPassword" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ ($stored['write_auth_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                    @error('writeAuthPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="flex">
                <div style="min-width: 9rem; margin-right: 1rem">
                    <label class="{{ $label }}">Privacy</label>
                    <select wire:model.live="writePrivProtocol" class="{{ $input }}">
                        <option value="">none</option>
                        @foreach (\App\Services\Snmp\PhpSnmpClient::PRIV_PROTOCOLS as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                    </select>
                </div>
                <div class="w-full">
                    <label class="{{ $label }}">Privacy password</label>
                    <input type="password" wire:model="writePrivPassword" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ ($stored['write_priv_password'] ?? false) ? 'leave empty to keep the stored password' : 'at least 8 characters' }}">
                    @error('writePrivPassword') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
            </div>
        @endif
        @if (($stored['write_community'] ?? false) || $writeUser !== '')
            <button type="button" wire:click="forgetWrite" wire:confirm="Remove the write access? PoE restarts stop working."
                    class="cmp-btn cmp-btn-secondary cmp-btn-small mt-3">Remove write access</button>
        @endif
    </div>

    <button wire:click="test()" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Test</button>
    <button wire:click="checkWrite()" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary ml-2">Check write access</button>
    <button wire:click="save()" wire:loading.attr="disabled" class="cmp-btn ml-2">Save</button>
    <button type="button" wire:click="cancel" class="cmp-btn cmp-btn-secondary ml-2">Cancel</button>
    <span wire:loading wire:target="test,checkWrite,save" class="text-sm text-fg-muted ml-2">asking the switch...</span>
    </div>
    @endif

    @if ($result)
        <div class="mt-4 text-sm">
            @if ($result['name'] || $result['mac'])
                <div class="mb-2">The switch calls itself <span class="font-semibold">{{ $result['name'] ?? 'nothing' }}</span>@if ($result['mac']), base MAC <span class="font-mono">{{ $result['mac'] }}</span>@endif.</div>
            @endif
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
