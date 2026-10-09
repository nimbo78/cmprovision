<div wire:poll.5s class="bg-white overflow-hidden shadow-xl sm:rounded-lg sm:px-20 px-4 pb-4">
    <div class="mt-6 text-2xl">Last {{ \App\Http\Livewire\ProvisioningLog::ROWS }} provisioning log entries</div>
    <div class="text-sm text-gray-600 mb-2">Times in {{ $zone }}</div>

    <table class="table-fixed min-w-full">
        <thead>
            <tr class="bg-gray-100">
                <th class="w-1/6 px-4 py-2">Board</th>
                <th class="w-1/6 px-4 py-2">CM serial</th>
                <th class="px-4 py-2">Log message</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($log as $l)
                @php($at = $l->created_at->local())
                <tr wire:key="log-{{ $l->id }}" @if ($l->loglevel == 'error') class="bg-red-100" @endif>
                    <td class="border px-4 py-2">{{ $l->board }}</td>
                    <td class="border px-4 py-2">{{ $l->cm }}</td>
                    <td class="border px-4 py-2">{!! nl2br(e(($at->toDateString() === $today ? $at->format('H:i:s') : $at->format('d.m H:i:s')).' '.$l->msg), false) !!}</td>
                </tr>
            @empty
                <tr><td class="border px-4 py-2" colspan="3">No entries</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
