<div class="cmp-page cmp-wizard" data-station-wizard data-wizard-step="{{ $step }}">
    <div class="cmp-title"><h1>Station setup</h1></div>
    <div class="cmp-wizard-layout">
        <nav aria-label="Setup steps" class="cmp-wizard-nav">
            <label class="lg:hidden">
                <span class="sr-only">Choose a setup step</span>
                <select data-wizard-step-picker class="cmp-field w-full">
                    @foreach ($steps as $number => $label)
                        <option value="{{ $number }}" @selected($step === $number)>Step {{ $number }} of 7 · {{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <ol class="hidden space-y-1 lg:block">
                @foreach ($steps as $number => $label)
                    <li><button type="button" data-wizard-go data-wizard-destination="{{ $number }}" wire:click="go({{ $number }})"
                        aria-label="Step {{ $number }}: {{ $label }}" @if ($step === $number) aria-current="step" @endif
                        class="cmp-wizard-step" wire:loading.attr="disabled">
                        <span aria-hidden="true">{{ $number }}</span>{{ $label }}
                    </button></li>
                @endforeach
            </ol>
        </nav>

        <section class="cmp-card cmp-wizard-panel" aria-labelledby="station-wizard-heading">
            <header class="cmp-wizard-head">
                <h2 id="station-wizard-heading" tabindex="-1">{{ $steps[$step] }}</h2>
                <span class="hidden text-sm text-fg-muted lg:inline">Step {{ $step }} of 7</span>
            </header>
            <div class="cmp-wizard-body space-y-3">
                @error('wizard')<p class="cmp-flash text-sm" role="alert">{{ $message }}</p>@enderror
                <p data-wizard-guard-message class="cmp-flash text-sm hidden" role="alert" tabindex="-1">Save or cancel the open editor before leaving this step.</p>
                @if ($state['pending'] === true && !in_array($step, [2, 6], true))
                    <livewire:network-settings section="pending" :key="'wizard-pending-network'" />
                @elseif ($state['pending'] === null && $step !== 7)
                    <p class="rounded bg-warn-bg p-3 text-sm text-warn" role="status">Network transaction status is unavailable. Check the station connection.</p>
                @endif
                <div data-wizard-content wire:key="wizard-step-{{ $step }}" class="space-y-2">
                    @if ($step === 1)
                        <dl class="cmp-wizard-facts">
                            <div><dt>Stand role</dt><dd><span class="cmp-chip cmp-chip-idle">{{ ucfirst($state['role']) }}</span></dd></div>
                            <div><dt>Managed switches</dt><dd>{{ $state['switches'] }}</dd></div>
                            <div><dt>Active project</dt><dd>{{ $state['project']['name'] ?? 'None' }}</dd></div>
                        </dl>
                    @elseif ($step === 2)
                        <livewire:network-settings section="management" :key="'wizard-management'" />
                        <x-wizard-disclosure title="Wi-Fi" :value="$state['uplink']['wifi']['ssid'] ?? null">
                            <livewire:wifi-settings :key="'wizard-wifi'" />
                        </x-wizard-disclosure>
                        <x-wizard-disclosure title="Tagged VLANs"><livewire:vlan-status :key="'wizard-vlan'" /></x-wizard-disclosure>
                        @include('livewire.wizard.connections')
                    @elseif ($step === 3)
                        <p class="text-sm text-fg-muted">Port mapping and PoE controls require a managed switch.</p>
                        <x-wizard-disclosure title="Managed switches" value="SNMP settings">
                            <livewire:switch-settings :key="'wizard-switch'" />
                        </x-wizard-disclosure>
                    @elseif ($step === 4)
                        <livewire:station-access-settings :compact="true" :key="'wizard-access'" />
                        <x-wizard-disclosure title="Sensor accounts" value="Optional">
                            <livewire:reprovision-accounts />
                            <details class="mt-4 text-sm"><summary class="cursor-pointer text-link">Provisioner SSH key</summary>
                                <div class="mt-3"><livewire:reprovision-key /></div>
                            </details>
                        </x-wizard-disclosure>
                    @elseif ($step === 5)
                        <p class="text-sm text-fg-muted">A primary station needs a ready, active project.
                            <a href="{{ route('images') }}" class="text-link" target="_blank" rel="noopener">Prepare images</a></p>
                        <x-wizard-disclosure title="Provisioning projects" value="Create or activate">
                            <livewire:projects :embedded="true" :key="'wizard-projects'" />
                        </x-wizard-disclosure>
                    @elseif ($step === 6)
                        <p class="rounded bg-warn-bg p-3 text-sm text-warn">Keep a separate management connection. Enable DHCP/TFTP only on an isolated stand.</p>
                        <dl class="cmp-wizard-facts"><div><dt>Current role</dt><dd>{{ ucfirst($state['role']) }}</dd></div></dl>
                        <livewire:network-settings section="role" :key="'wizard-role'" />
                        @include('livewire.wizard.connections')
                    @else
                        @include('livewire.wizard.review')
                    @endif
                </div>
            </div>
            <footer class="cmp-wizard-footer">
                @if ($step > 1)
                    <button type="button" data-wizard-go wire:click="go({{ $step-1 }})" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Back</button>
                @endif
                <button type="button" data-wizard-go wire:click="leave" class="cmp-wizard-leave" wire:loading.attr="disabled">Save and leave</button>
                @if ($step < 7)
                    <button type="button" data-wizard-go wire:click="go({{ $step+1 }})" class="cmp-btn ml-auto" wire:loading.attr="disabled">{{ $step === 1 ? 'Continue to network' : 'Next' }} <span aria-hidden="true">→</span></button>
                @else
                    <button type="button" data-wizard-go wire:click="finish" class="cmp-btn ml-auto" wire:loading.attr="disabled" @disabled((bool) $state['blockers'])>Complete setup</button>
                @endif
                <span wire:loading class="text-sm text-fg-muted" role="status">Checking…</span>
            </footer>
        </section>
    </div>
</div>
