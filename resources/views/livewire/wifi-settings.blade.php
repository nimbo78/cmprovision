@php($input = 'cmp-field w-full')
@php($label = 'block text-fg text-sm font-bold mb-2')
@php($small = 'cmp-btn cmp-btn-secondary cmp-btn-small')
<x-settings-card title="Wi-Fi networks" id="wifi" description="The networks the provisioner connects to, the preferred first.">
    @if ($available && !$form)
        <x-slot name="actions">
            <button type="button" wire:click="showLog" class="cmp-btn cmp-btn-secondary">Changes log</button>
            <button type="button" wire:click="add" class="cmp-btn">Add network</button>
        </x-slot>
    @endif
    <x-slot name="help">
        Each network serves both radios: the M.2 card carries the traffic, the built-in Wi-Fi stands by. A password is
        stored on the server and never shown again; Check now tries a network on the idle built-in Wi-Fi while the card
        keeps working.
    </x-slot>

    @if ($logModalOpen)
        @include('livewire.viewlog')
    @endif

    @if (!$available)
        <p class="text-sm text-fg-muted">Wi-Fi is not managed by NetworkManager here: its networks are set up with the system's own tools.</p>
    @else
        @if ($notice)
            <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
        @endif

        @if ($networks || $others)
            <div class="cmp-card-scroll mb-4">
                <table class="cmp-table">
                    <thead><tr><th>Order</th><th>Network</th><th>Security</th><th>State</th><th>Check</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($networks as $i => $n)
                        <tr wire:key="wifi-{{ $n['uuid'] }}">
                            <td class="whitespace-nowrap">
                                @if ($i > 0)
                                    <x-confirms-password wire:then="moveUp('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" aria-label="Move {{ $n['ssid'] }} up">↑</button>
                                    </x-confirms-password>
                                @endif
                                @if ($i < count($networks) - 1)
                                    <x-confirms-password wire:then="moveDown('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" aria-label="Move {{ $n['ssid'] }} down">↓</button>
                                    </x-confirms-password>
                                @endif
                            </td>
                            <td>{{ $n['ssid'] }} @if ($n['hidden'])<span class="text-fg-muted">· hidden</span>@endif</td>
                            <td>{{ $securities[$n['security']] }}</td>
                            <td>{{ $n['devices'] ? 'in use · '.implode(', ', $n['devices']) : 'saved' }}</td>
                            <td>
                                @isset($checks[$n['uuid']])
                                    <span class="{{ $checks[$n['uuid']]['ok'] ? 'text-pos' : 'text-neg' }}">{{ $checks[$n['uuid']]['at'] }}: {{ $checks[$n['uuid']]['text'] }}</span>
                                @endisset
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($blocker === null)
                                    <x-confirms-password wire:then="checkNow('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }}" wire:loading.attr="disabled">Check now</button>
                                    </x-confirms-password>
                                @else
                                    <button type="button" class="{{ $small }}" disabled title="Check now: {{ $blocker }}">Check now</button>
                                @endif
                                <button type="button" wire:click="edit('{{ $n['uuid'] }}')" class="{{ $small }} ml-1">Edit</button>
                                @if ($n['devices'])
                                    <button type="button" class="{{ $small }} ml-1" disabled title="{{ $n['ssid'] }} is in use: connect another network first">Delete</button>
                                @else
                                    <x-confirms-password wire:then="delete('{{ $n['uuid'] }}')">
                                        <button type="button" class="{{ $small }} ml-1" x-on:click="if (!confirm('Delete ' + @js($n['ssid']) + '?')) $event.stopPropagation()">Delete</button>
                                    </x-confirms-password>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach ($others as $o)
                        <tr class="text-fg-faint">
                            <td></td>
                            <td>{{ $o['ssid'] !== '' ? $o['ssid'] : $o['name'] }}</td>
                            <td colspan="4">managed outside cmprovision ({{ $o['why'] }})</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($blocker !== null)
                <p class="text-sm text-fg-muted mb-4">Check now is not available: {{ $blocker }}.</p>
            @endif
        @endif

        @if ($form)
            {{-- the network password lives in Alpine and goes as a parameter of save: no snapshot ever holds it --}}
            <div class="border-t border-pline pt-4" x-data="{ secret: '' }">
                <div class="font-bold mb-3">{{ $editingUuid ? 'Edit network' : 'New network' }}</div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-ssid">Network name (SSID)</label>
                    <div class="flex">
                        <input id="wifi-ssid" type="text" wire:model="ssid" class="{{ $input }}" autocomplete="off">
                        <button type="button" wire:click="findNetworks" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary ml-2 whitespace-nowrap">Find networks</button>
                    </div>
                    @error('ssid') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                    @if ($found)
                        <div class="cmp-card-scroll mt-2">
                            <table class="cmp-table">
                                <thead><tr><th>Network</th><th>Signal</th><th>Security</th><th>Band</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($found as $f)
                                    <tr wire:key="found-{{ $loop->index }}">
                                        <td>{{ $f['ssid'] }}</td>
                                        <td>{{ $f['signal'] }}%</td>
                                        <td>{{ $securities[$f['security']] ?? (['enterprise' => 'Enterprise (802.1X): not supported', 'open' => 'open: not supported'][$f['security']] ?? $f['security']) }}</td>
                                        <td>{{ implode(', ', $f['bands']) }}</td>
                                        <td>
                                            @isset($securities[$f['security']])
                                                <button type="button" wire:click="pick({{ $loop->index }})" class="{{ $small }}">Use</button>
                                            @endisset
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-security">Security</label>
                    <select id="wifi-security" wire:model="security" class="{{ $input }}">
                        @foreach ($securities as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('security') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}" for="wifi-password">Password</label>
                    <input id="wifi-password" type="password" x-model="secret" class="{{ $input }}" autocomplete="new-password"
                           placeholder="{{ $editingUuid ? 'leave empty to keep' : '' }}">
                    @error('password') <div class="text-neg text-sm mt-1">{{ $message }}</div> @enderror
                </div>
                <label class="flex items-center mb-4 text-sm text-fg">
                    <input type="checkbox" wire:model="hidden" class="form-checkbox h-6 w-6 text-accent mr-2">
                    Hidden network: the provisioner looks for it by name
                </label>
                <p class="text-sm text-fg-muted mb-4">
                    The provisioner joins the first network of the list it finds when it connects; it does not leave a
                    working network for a higher one. The password is stored on the server and never shown again.
                </p>
                <x-confirms-password wire:then="save(secret)">
                    <button type="button" class="cmp-btn" wire:loading.attr="disabled">Save</button>
                </x-confirms-password>
                @if ($blocker === null)
                    <x-confirms-password wire:then="saveAndCheck(secret)">
                        <button type="button" class="cmp-btn cmp-btn-secondary ml-2" wire:loading.attr="disabled">Save and check</button>
                    </x-confirms-password>
                @endif
                <button type="button" wire:click="cancel" class="cmp-btn cmp-btn-secondary ml-2">Cancel</button>
            </div>
        @endif
    @endif
</x-settings-card>
