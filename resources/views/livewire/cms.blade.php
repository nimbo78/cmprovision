<div class="cmp-page">
    <div class="cmp-title">
        <h1>CMs</h1>
        <span class="text-fg-muted">{{ $CMs->count() === $total ? number_format($total) : number_format($CMs->count()).' of '.number_format($total) }} {{ $total === 1 ? 'module' : 'modules' }}</span>
        <select wire:model.live="projectId" class="cmp-field pr-8" aria-label="Project">
            <option value="0">All projects</option>
            @foreach ($projects as $project)
                <option value="{{ $project->id }}">{{ $project->name }} ({{ $project->cms_count }})</option>
            @endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" class="cmp-field w-60" placeholder="MAC, host name or serial" aria-label="Search by MAC, host name or serial">
        <div class="ml-auto flex flex-wrap gap-2">
            {{-- a disclosure: Tab walks the files, Escape closes it and gives the focus back to the button --}}
            <div class="relative" x-data="{ open: false }" @keydown.escape.window="if (open) { open = false; $refs.netbox.focus() }" @click.outside="open = false">
                <button type="button" x-ref="netbox" class="cmp-btn cmp-btn-secondary" @click="open = ! open" aria-controls="cms-netbox-files" :aria-expanded="open.toString()">Export for NetBox ▾</button>
                <div id="cms-netbox-files" x-show="open" x-cloak class="cmp-menu" aria-label="Files for NetBox">
                    <p class="px-2.5 py-2 text-xs text-fg-muted">{{ $netboxCount === 0 ? 'No finished module in this list.' : $netboxCount.($netboxCount === 1 ? ' finished module' : ' finished modules').' of this list. Import in this order; the site, role and device type come from Settings → Provisioning.' }}</p>
                    <button type="button" wire:click="exportNetBox('devices')" @click="open = false">1. Devices (CSV)</button>
                    <button type="button" wire:click="exportNetBox('interfaces')" @click="open = false">2. Interfaces (CSV)</button>
                    @if ($netboxMacFile)
                        <button type="button" wire:click="exportNetBox('macs')" @click="open = false">3. MAC addresses (CSV)</button>
                    @endif
                </div>
            </div>
            <button type="button" wire:click="exportCSV()" class="cmp-btn cmp-btn-secondary">Export CSV</button>
        </div>
    </div>

    @if (session()->has('message'))
        <div class="cmp-flash" role="status">{{ session('message') }}</div>
    @endif

    <div class="cmp-card cmp-card-scroll">
        <table class="cmp-table">
            <thead>
                <tr>
                    <x-sort-th column="mac" :sort="$sortKey" :dir="$sortDir">MAC</x-sort-th>
                    <x-sort-th column="hostname" :sort="$sortKey" :dir="$sortDir">Host name</x-sort-th>
                    <x-sort-th column="serial" :sort="$sortKey" :dir="$sortDir">Serial</x-sort-th>
                    <x-sort-th column="model" :sort="$sortKey" :dir="$sortDir">Model</x-sort-th>
                    <x-sort-th column="project" :sort="$sortKey" :dir="$sortDir">Project</x-sort-th>
                    <x-sort-th column="board" :sort="$sortKey" :dir="$sortDir">Board</x-sort-th>
                    <x-sort-th column="result" :sort="$sortKey" :dir="$sortDir">Result</x-sort-th>
                    <x-sort-th column="finished" :sort="$sortKey" :dir="$sortDir">Finished</x-sort-th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($CMs as $c)
                    <tr wire:key="cm-{{ $c->id }}">
                        <td class="mono"><a href="{{ route('cm', $c->mac) }}" class="text-link hover:underline">{{ $c->mac ?: $c->serial }}</a></td>
                        <td class="mono">{{ $c->hostname }}</td>
                        <td class="mono text-fg-muted">{{ $c->serial }}</td>
                        <td>{{ $c->model }}</td>
                        <td>{{ $c->project?->name }}</td>
                        <td class="text-fg-muted whitespace-nowrap">{{ $c->provisioning_board }}</td>
                        <td>
                            @if ($c->phase === 'done')<span class="cmp-chip cmp-chip-ok">done</span>
                            @elseif ($c->phase === 'failed')<span class="cmp-chip cmp-chip-fail">failed</span>
                            @elseif ($c->isRunning())<span class="cmp-chip cmp-chip-run">running</span>
                            @else <span class="text-fg-faint">—</span>
                            @endif
                        </td>
                        <td class="tabular-nums text-fg-muted whitespace-nowrap">{{ $c->finishedAt() ? $c->finishedAt()->local()->format('Y-m-d H:i:s') : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('cm', $c->mac) }}" class="text-link font-semibold mr-3">View</a>
                            <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Delete CM {{ $c->displayName() }}?" class="text-fg-muted hover:text-neg" title="Delete" aria-label="Delete CM {{ $c->displayName() }}">
                                <svg class="inline w-4 h-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M8 2h4a1 1 0 011 1v1h4v2H3V4h4V3a1 1 0 011-1zm-3 6h10l-.8 9.2A2 2 0 0112.2 19H7.8a2 2 0 01-2-1.8L5 8z"/></svg>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-fg-muted">No entries</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
