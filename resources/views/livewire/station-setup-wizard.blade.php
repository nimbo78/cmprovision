<div class="cmp-page" data-station-wizard data-wizard-step="{{ $step }}">
    <div class="cmp-title"><h1>Station setup</h1><span class="cmp-chip cmp-chip-idle ml-auto">Step {{ $step }} of 7</span></div>
    <div class="grid gap-5 lg:grid-cols-[13rem_minmax(0,1fr)]">
        <nav aria-label="Setup steps" class="cmp-card p-3 h-fit">
            <ol class="flex gap-1 overflow-x-auto lg:flex-col">
                @foreach ($steps as $number => $label)
                    <li class="shrink-0"><button type="button" data-wizard-go wire:click="go({{ $number }})" aria-label="Step {{ $number }}: {{ $label }}" @if ($step === $number) aria-current="step" @endif
                        @class(['w-full rounded p-2 flex items-center gap-2 text-left text-sm', 'bg-accent-soft text-accent font-bold' => $step === $number, 'text-fg-muted' => $step !== $number]) wire:loading.attr="disabled">
                        <span class="mono">{{ str_pad($number, 2, '0', STR_PAD_LEFT) }}</span><span class="hidden sm:inline">{{ $label }}</span>
                    </button></li>
                @endforeach
            </ol>
        </nav>
        <div class="min-w-0 space-y-4">
            <div class="cmp-card p-4">
                <h2 id="station-wizard-heading" tabindex="-1" class="font-bold text-lg">{{ $steps[$step] }}</h2>
                <p class="text-sm text-fg-muted mt-1">Save changes on this step before continuing. Next checks progress; configuration is applied only by its labelled action.</p>
                @error('wizard')<p class="cmp-flash mt-3 text-sm" role="alert">{{ $message }}</p>@enderror
                <p data-wizard-guard-message class="cmp-flash mt-3 text-sm hidden" role="alert" tabindex="-1">Save or cancel the open editor before leaving this step.</p>
                @if ($state['pending'] === true && $step !== 2 && $step !== 6)
                    <div class="cmp-flash mt-3 text-sm">A network change needs confirmation. Use the network controls below.</div><livewire:network-settings :section="'role'" :key="'wizard-pending-network'" />
                @endif
            </div>
            <div data-wizard-content class="space-y-4">
                @if ($step === 1)
                    <section class="cmp-card p-5 space-y-4">
                        <p class="text-fg">Prepare this station without losing its management connection. Existing stations can use the same steps to review their configuration.</p>
                        <dl class="grid gap-4 sm:grid-cols-3 text-sm">
                            <div><dt class="text-fg-muted">Current role</dt><dd class="font-bold mt-1">{{ ucfirst($state['role']) }}</dd></div>
                            <div><dt class="text-fg-muted">Managed switches</dt><dd class="font-bold mt-1">{{ $state['switches'] }}</dd></div>
                            <div><dt class="text-fg-muted">Active project</dt><dd class="font-bold mt-1 break-words">{{ $state['project']['name'] ?? 'None' }}</dd></div>
                        </dl>
                        <div class="border-t border-line pt-3 text-sm space-y-2"><p>1. Connect management Wi-Fi or Ethernet and confirm any network changes.</p><p>2. Set up switch access, station/sensor accounts and the provisioning project.</p><p>3. Choose the stand role, then review the actual network and service state.</p></div>
                        <p class="text-sm text-fg-muted">Primary serves DHCP/TFTP on an isolated stand. Secondary monitors the same stand while keeping its own boot services disabled. Disabled leaves provisioning services off.</p>
                    </section>
                @elseif ($step === 2)
                    <livewire:wifi-settings :key="'wizard-wifi'" />
                    <livewire:network-settings :section="'management'" :key="'wizard-management'" />
                    <livewire:vlan-status :key="'wizard-vlan'" />
                @elseif ($step === 3)
                    <p class="text-sm text-fg-muted px-1">Add and test the managed switch. For an unmanaged switch you can continue; port mapping and PoE controls will be unavailable.</p>
                    <livewire:switch-settings :key="'wizard-switch'" />
                @elseif ($step === 4)
                    <livewire:station-access-settings :key="'wizard-access'" />
                    <x-settings-card title="Sensor access" description="Optional accounts used to check and re-provision compatible sensors."><livewire:reprovision-accounts /><details class="mt-4 text-sm"><summary class="cursor-pointer text-fg-muted">Provisioner SSH key</summary><div class="mt-3"><livewire:reprovision-key /></div></details></x-settings-card>
                @elseif ($step === 5)
                    <p class="text-sm text-fg-muted px-1">Create or copy a project with the existing project wizard, then activate it explicitly. Images can be <a href="{{ route('images') }}" class="text-link" target="_blank" rel="noopener">prepared in Images</a>. Secondary and disabled stations may continue without a local project.</p>
                    <livewire:projects :embedded="true" :key="'wizard-projects'" />
                @elseif ($step === 6)
                    <p class="text-sm text-fg-muted px-1">Keep a separate management connection. Primary starts DHCP/TFTP only after the project is ready and you confirm the isolated network. Each role change must be confirmed before its rollback timer ends.</p>
                    <livewire:network-settings :section="'role'" :key="'wizard-role'" />
                @else
                    <section class="cmp-card p-5 space-y-4">
                        <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Actual station configuration</h3><button type="button" wire:click="$refresh" class="cmp-btn cmp-btn-secondary cmp-btn-small" wire:loading.attr="disabled">Refresh checks</button></div>
                        <dl class="grid gap-4 sm:grid-cols-2 text-sm">
                            <div><dt class="text-fg-muted">Stand role</dt><dd class="font-bold mt-1">{{ ucfirst($state['role']) }}</dd></div>
                            <div><dt class="text-fg-muted">Provisioning interface</dt><dd class="mono mt-1">{{ $state['stand'] ? $state['stand']['device'].' · '.$state['stand']['address'].'/16' : 'None' }}</dd></div>
                            <div><dt class="text-fg-muted">DHCP/TFTP</dt><dd class="mt-1">{{ ($state['services']['dhcp']['active'] ?? null) === null ? 'Unknown' : ($state['services']['dhcp']['active'] ? 'Running' : 'Stopped') }}</dd></div>
                            <div><dt class="text-fg-muted">USB boot</dt><dd class="mt-1">{{ ($state['services']['usb']['active'] ?? null) === null ? 'Unknown' : ($state['services']['usb']['active'] ? 'Running' : 'Stopped') }}</dd></div>
                            <div><dt class="text-fg-muted">Managed switches</dt><dd class="mt-1">{{ $state['switches'] }}</dd></div>
                            <div><dt class="text-fg-muted">Active project</dt><dd class="mt-1 break-words">{{ $state['project']['name'] ?? 'None' }}{{ $state['project']['ready'] ? ' · Ready' : '' }}</dd></div>
                            <div><dt class="text-fg-muted">Web sign-in</dt><dd class="mt-1 break-all">{{ auth()->user()->email }}</dd></div>
                        </dl>
                        @if ($state['links'])
                            <div class="border-t border-line pt-3">
                                <h3 class="font-bold mb-2">Network links</h3>
                                <dl class="text-sm space-y-2">
                                    @foreach ($state['links'] as $link)
                                        <div>
                                            <dt class="font-bold">
                                                {{ $link['name'] }} · {{ ($link['up'] ?? false) ? 'Up' : 'Down' }}
                                                @if ($link['speed'] ?? null)
                                                    · {{ $link['speed'] }} Mb/s
                                                @endif
                                            </dt>
                                            <dd class="mono break-all text-fg-muted">
                                                {{ implode(', ', array_filter($link['addresses'] ?? [], 'is_string')) ?: 'No IP address' }}
                                                @if ($link['wifi']['ssid'] ?? null)
                                                    · {{ $link['wifi']['ssid'] }}
                                                @endif
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endif
                        @if ($state['blockers'])<div class="rounded border border-line p-3"><h3 class="font-bold text-neg">Resolve before completing setup</h3><ul class="list-disc pl-5 mt-2 text-sm space-y-1">@foreach ($state['blockers'] as $issue)<li>{{ $issue }}</li>@endforeach</ul></div>@endif
                        @if ($state['warnings'])<div class="text-sm space-y-1 text-fg-muted">@foreach ($state['warnings'] as $warning)<p>{{ $warning }}</p>@endforeach</div>@endif
                        <p class="text-sm text-fg-muted">Complete setup records this check. It does not activate a project, enable services or change the network.</p>
                    </section>
                @endif
            </div>
            <footer class="cmp-card p-4 flex flex-wrap items-center gap-2">
                @if ($step > 1)<button type="button" data-wizard-go wire:click="go({{ $step-1 }})" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Back</button>@endif
                <button type="button" data-wizard-go wire:click="leave" class="cmp-btn cmp-btn-secondary" wire:loading.attr="disabled">Save and leave</button>
                @if ($step < 7)<button type="button" data-wizard-go wire:click="go({{ $step+1 }})" class="cmp-btn ml-auto" wire:loading.attr="disabled">{{ $step === 1 ? 'Start setup' : 'Next' }}</button>
                @else<button type="button" data-wizard-go wire:click="finish" class="cmp-btn ml-auto" wire:loading.attr="disabled" @disabled((bool) $state['blockers'])>Complete setup</button>@endif
                <span wire:loading class="text-sm text-fg-muted" role="status">Checking…</span>
            </footer>
        </div>
    </div>
</div>
