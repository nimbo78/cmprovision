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
        <input type="search" wire:model.live.debounce.300ms="search" class="cmp-field w-60" placeholder="Serial, MAC or host name" aria-label="Search by serial, MAC or host name">
        <button type="button" wire:click="exportCSV()" class="cmp-btn cmp-btn-secondary ml-auto">Export CSV</button>
    </div>

    @if (session()->has('message'))
        <div class="cmp-flash" role="status">{{ session('message') }}</div>
    @endif

    <div class="cmp-card cmp-card-scroll">
        <table class="cmp-table">
            <thead>
                <tr><th>Serial</th><th>MAC</th><th>Host name</th><th>Model</th><th>Project</th><th>Board</th><th>Result</th><th>Finished</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($CMs as $c)
                    <tr wire:key="cm-{{ $c->id }}">
                        <td class="mono"><a href="{{ route('cm', $c->serial) }}" class="text-link hover:underline">{{ $c->serial }}</a></td>
                        <td class="mono text-fg-muted">{{ $c->mac }}</td>
                        <td class="mono text-fg-muted">{{ $c->hostname }}</td>
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
                        <td class="tabular-nums text-fg-muted whitespace-nowrap">{{ $c->provisioning_complete_at ? $c->provisioning_complete_at->local()->format('Y-m-d H:i:s') : '—' }}</td>
                        <td class="text-right whitespace-nowrap">
                            <a href="{{ route('cm', $c->serial) }}" class="text-link font-semibold mr-3">View</a>
                            <button type="button" wire:click="delete({{ $c->id }})" wire:confirm="Delete CM {{ $c->serial }}?" class="text-fg-muted hover:text-neg" title="Delete" aria-label="Delete CM {{ $c->serial }}">
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
