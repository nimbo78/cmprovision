{{-- Settings → Utility OS (2.7.0): the OSes of the package, the one modules get, the switch --}}
<x-settings-card title="Utility OS" id="utility-os" description="The system a module boots from the network or over USB to be provisioned.">
    <x-slot name="help">
        The package has two: ours, built from current versions (64-bit), and the legacy one from 2022 (32-bit). A switch
        takes effect for the next file a module asks for.
    </x-slot>
    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}" role="status">{{ $notice }}</div>
    @endif
    @if ($follows === false)
        <div class="bg-warn-bg border-l-4 border-warn-bar text-warn px-4 py-3 rounded mb-4" role="status">
            dnsmasq serves {{ $tftpRoot }} (tftp-root in etc/dnsmasq.conf), so this switch does not change what modules get over the network.
            Set tftp-root={{ $link }} there and restart dnsmasq.
        </div>
    @endif

    <div class="cmp-card-scroll">
        <table class="cmp-table">
            <thead><tr><th>Utility OS</th><th>Kernel</th><th>Built</th><th></th></tr></thead>
            <tbody>
            @foreach ($systems as $dir => $s)
                <tr wire:key="uos-{{ $dir }}">
                    <td class="font-semibold whitespace-nowrap">{{ $s['version'] }} <span class="font-normal text-fg-muted">{{ $s['bits'] ?? '?' }}-bit</span></td>
                    <td>{{ $s['kernel'] ?? '' }}</td>
                    <td class="whitespace-nowrap">{{ $s['built'] ?? '' }}</td>
                    <td class="whitespace-nowrap text-right">
                        @if ($current === $dir)
                            <span class="text-pos font-semibold">modules get this one</span>
                        @else
                            <button type="button" wire:click="serve('{{ $dir }}')"
                                    wire:confirm="Serve the {{ $s['version'] }} utility OS to modules? Switch when no module is booting: a module that is booting now may get the files of both."
                                    class="cmp-btn cmp-btn-secondary cmp-btn-small">Serve this one</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($current === null)
        <p class="mt-3 text-sm text-neg">The link {{ $link }} points at no utility OS of the package: modules get nothing. Choose one.</p>
    @endif
</x-settings-card>
