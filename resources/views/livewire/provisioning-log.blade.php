{{-- Folded to its latest entry; open or folded is Alpine's (and the browser's) state, so polling keeps it --}}
<div wire:poll.5s class="rounded-lg overflow-hidden bg-log text-log-text"
     x-data="{ open: (() => { try { return localStorage.getItem('cmprovision-log-open') === '1' } catch (e) { return false } })() }"
     x-init="$watch('open', v => { try { localStorage.setItem('cmprovision-log-open', v ? '1' : '0') } catch (e) {} })">
    <button type="button" class="w-full flex items-center gap-3 px-[var(--edge)] h-10 text-left" @click="open = ! open"
            :aria-expanded="open.toString()" aria-controls="cmp-log-entries">
        <span class="font-bold">Log</span>
        <span class="text-log-muted whitespace-nowrap">Last {{ \App\Http\Livewire\ProvisioningLog::ROWS }} entries · Times in {{ $zone }}</span>
        @if ($first = $log->first())
            <span class="text-log-muted truncate min-w-0">{{ implode(' · ', array_filter([$first->created_at->local()->format('H:i:s'), $first->board, \Illuminate\Support\Str::limit($first->msg, 120)])) }}</span>
        @endif
        <span class="ml-auto font-semibold whitespace-nowrap" x-text="open ? 'Hide' : 'Show'">Show</span>
    </button>
    <div id="cmp-log-entries" x-show="open" x-cloak class="overflow-y-auto border-t border-log-line" style="max-height: var(--log-h)">
        <table class="cmp-table in-scroll">
            <tbody>
                @forelse ($log as $l)
                    @php($at = $l->created_at->local())
                    <tr wire:key="log-{{ $l->id }}" class="{{ $l->loglevel == 'error' ? 'text-log-err' : '' }}">
                        <td class="tabular-nums text-log-muted whitespace-nowrap border-log-line">{{ $at->toDateString() === $today ? $at->format('H:i:s') : $at->format('d.m H:i:s') }}</td>
                        <td class="text-log-muted whitespace-nowrap border-log-line">{{ $l->board }}</td>
                        <td class="mono border-log-line">@if ($l->cm)<a href="{{ route('cm', $l->cm) }}" class="hover:underline">{{ $macs[$l->cm] ?? $l->cm }}</a>@endif</td>
                        <td class="border-log-line">{!! nl2br(e($l->msg), false) !!}</td>
                    </tr>
                @empty
                    <tr><td class="border-log-line" colspan="4">No entries</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
