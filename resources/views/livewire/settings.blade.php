

<div class="cmp-page">
    <div class="md:grid md:grid-cols-3 md:gap-6">

        @if($logModalOpen)
            @include('livewire.viewlog')
        @elseif($staticModalOpen)
            @include('livewire.addstaticip')
        @endif

        <div class="md:col-span-1">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">DHCP server settings</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    Change the DHCP server configuration
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-tl-md sm:rounded-tr-md">
                @if (session()->has('message'))
                <div class="cmp-flash my-3" role="alert">
                    <div class="flex">
                        <div>
                            <p class="text-sm">{{ session('message') }}</p>
                        </div>
                    </div>
                </div>
                @endif
                <div class="mb-4">
                    <div class="block text-fg text-sm font-bold mb-2">Static IP assignments:</div>
                </div>

                <table class="cmp-table min-w-full">
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>MAC</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($hosts as $h)
                        <tr>
                            <td >{{ $h->ip }}</td>
                            <td >{{ $h->mac }}</td>
                            <td ><button wire:click="deleteStaticIP({{ $h->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button></td>
                        </tr>
                        @empty
                        <tr><td  colspan="3">No entries</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <button wire:click="addStaticIP()" class="cmp-btn my-3">Add new assignment</button>
            </div>
        </div>

        <div class="md:col-span-1" id="wifi">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Wi-Fi networks</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    The networks the provisioner connects to, the preferred first. Each one serves both radios: the
                    M.2 card carries the traffic, the built-in Wi-Fi stands by. A password is stored on the server and
                    never shown again; Check now tries a network on the idle built-in Wi-Fi while the card keeps working.
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <livewire:wifi-settings />
        </div>

        <div class="md:col-span-1">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Ethernet switches</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    With managed switches, each module gets the switch and port it is plugged into as its board
                    ("sw01 Gi0/3"): the server reads the switches' MAC address tables over SNMP when the module starts.
                    A port that leads to another switch of the list is never a module's port. Without a switch, the
                    board comes from the jumpers.
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <livewire:switch-settings />
        </div>

        <div class="md:col-span-1">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Notifications</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    Tell Mattermost, Telegram or any webhook when a module is done or failed.
                    Bots keep a thread per batch of modules with an up-to-date summary.
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <livewire:notification-settings />
        </div>

        <div class="md:col-span-1">
            <div class="px-4 sm:px-0">
                <h3 class="text-lg font-medium text-fg">Service status</h3>
                <p class="mt-1 text-sm text-fg-muted">
                    View the logs of server processes
                </p>
            </div>
        </div>
        <div class="mt-5 md:mt-0 md:col-span-2">
            <div class="px-4 py-5 bg-panel sm:p-6 shadow sm:rounded-md">
                <table class="cmp-table">
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($dnsmasqRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> dnsmasq (DHCP server)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogDnsmasq()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
                    </td>
                </tr>
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                        <li @if ($rpibootRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> rpiboot (USB file server)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogRpiboot()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>                                
                    </td>
                </tr>
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($queueRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> queue worker (switch ports, notifications)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogQueue()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
                    </td>
                </tr>
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($imagesQueueRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> queue worker (image checksums)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogImagesQueue()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
                    </td>
                </tr>
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li @if ($uplinkSpareRunning) class="text-pos" @else class="text-neg" @endif>
                                <span class="text-fg"> uplink cold spare (built-in Wi-Fi)</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogUplinkSpare()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
                    </td>
                </tr>
                <tr>
                    <td>
                        <ul class="list-outside list-disc ml-6">
                            <li class="text-pos">
                                <span class="text-fg"> web interface</span>
                            </li>
                        </ul>
                    </td>
                    <td>
                        <button wire:click="viewLogLaravel()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>                                
                    </td>
                </tr>
                </table>
            </div>

            @if (isset($actions))
                <div class="flex items-center justify-end px-4 py-3 bg-head text-right sm:px-6 shadow sm:rounded-bl-md sm:rounded-br-md">
                    {{ $actions }}
                </div>
            @endif
        </div>
    </div>
</div>
