<div class="cmp-page" wire:poll.10s>
    <section class="cmp-card p-4">
        <h1 class="font-bold text-lg mb-2">USB diagnostics</h1>
        <p class="text-sm text-fg-muted">Ethernet is the primary report channel. The USB receiver requests a snapshot after 90 seconds without the expected network report.
            A report can arrive before a module is registered; it does not start provisioning or count as a successful run.</p>
        <p class="text-sm text-fg-muted mt-2">The target needs a utility OS with the USB diagnostic agent and a USB data connection to the provisioner.
            Check the receiver under <a href="{{ route('settings') }}" class="text-link hover:underline">Settings → Status</a>.
            Reports are kept for 14 days, up to 2,000 snapshots; the latest 50 are listed here.</p>
    </section>
    <section class="cmp-card p-4" aria-label="USB report list">
        <div class="cmp-card-scroll">
            <table class="cmp-table text-sm whitespace-nowrap">
                <thead><tr><th>Ethernet MAC</th><th>Serial</th><th>Received</th><th>Context</th></tr></thead>
                <tbody>
                @forelse ($rows as $item)
                    <tr>
                        <td><button wire:click="select({{ $item->id }})" class="font-mono text-link hover:underline">{{ $item->mac ?: 'not reported' }}</button></td>
                        <td class="font-mono text-fg-muted">{{ $item->serial }}</td>
                        <td>{{ $item->received_at->local()->format('d.m.Y H:i:s') }}</td>
                        <td>{{ $item->report['context'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-fg-muted">No USB fallback reports received yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
    @if ($row)
        <section class="cmp-card p-4" aria-label="USB diagnostic snapshot">
            <h2 class="font-bold mb-2 break-all">{{ $row->mac ?: $row->serial }}@if ($row->mac) <span class="font-normal text-fg-muted text-sm">serial {{ $row->serial }}</span>@endif</h2>
            <p class="text-sm text-fg-muted mb-2">USB connection {{ $row->usb_path }} · {{ $cm ? 'A module record exists; this report may describe another boot.' : 'No provisioning record yet.' }}</p>
            @include('livewire.diagnostics-snapshot', ['report' => $row->report, 'receivedAt' => $row->received_at, 'cm' => null])
            <button wire:click="download({{ $row->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Download report</button>
            @if ($cm)<a href="{{ route('cm', $cm->serial) }}" class="cmp-btn cmp-btn-secondary cmp-btn-small">Module card</a>@endif
        </section>
    @endif
</div>
