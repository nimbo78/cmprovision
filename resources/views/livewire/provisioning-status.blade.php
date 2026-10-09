@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<div @if ($activeCount) wire:poll.2s @else wire:poll.10s @endif class="bg-white overflow-hidden shadow-xl sm:rounded-lg sm:px-20 px-4 pb-4 mb-6">
    <div class="mt-6 text-2xl">Provisioning now</div>
    <div class="text-sm text-gray-600 mb-2">
        {{ $activeCount }} in progress &middot; {{ $doneCount }} done &middot; {{ $failedCount }} failed in the last {{ $fmt::RECENT_HOURS }} hours
    </div>

    @if ($modules->isEmpty())
        <div class="text-gray-500 py-4">No modules are being provisioned.</div>
    @else
    <table class="table-auto min-w-full text-sm">
        <thead>
            <tr class="bg-gray-100">
                <th class="px-2 py-1 text-left">Board</th>
                <th class="px-2 py-1 text-left">CM serial</th>
                <th class="px-2 py-1 text-left">Step</th>
                <th class="px-2 py-1 text-left" style="width: 38%">Progress</th>
                <th class="px-2 py-1 text-left">Time</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($modules as $m)
            @php($percent = $m->progressPercent())
            @php($silent = $m->silentFor())
            @php($speed = $m->bytesPerSecond())
            @php($left = $m->secondsLeft())
            @php($bar = $m->phase === 'failed' ? 'bg-red-500' : ($m->phase === 'done' ? 'bg-green-500' : 'bg-blue-500'))
            <tr @if ($m->phase === 'failed') class="bg-red-100" @endif wire:key="cm-{{ $m->id }}">
                <td class="border px-2 py-1" style="white-space: nowrap">{{ $m->provisioning_board }}</td>
                <td class="border px-2 py-1"><span class="font-mono">{{ $m->serial }}</span><div class="text-xs text-gray-500 font-mono">{{ $m->mac }}</div></td>
                <td class="border px-2 py-1">
                    {{ $m->phaseLabel() }}
                    @if ($m->phase === 'failed' && $m->phase_detail)
                        <div class="text-xs text-red-600">{{ $m->phase_detail }}</div>
                    @endif
                    @if ($silent)
                        <div class="text-xs font-semibold" style="color: #b45309">No report for {{ intdiv($silent, 60) }} min</div>
                    @endif
                </td>
                <td class="border px-2 py-1">
                    @if ($percent !== null)
                        <div class="w-full bg-gray-200 rounded-full overflow-hidden" style="height: 0.6rem">
                            <div class="{{ $bar }}" style="height: 100%; width: {{ $percent }}%; @if ($silent) background-color: #f59e0b; @endif"></div>
                        </div>
                        <div class="text-xs text-gray-600">
                            {{ $percent }}%
                            @if ($m->progress_total) &middot; {{ $fmt::bytes($m->progress_bytes ?? 0) }} of {{ $fmt::bytes($m->progress_total) }} @endif
                            @if ($speed && $m->isStreaming() && !$silent) &middot; {{ $fmt::speed($speed) }} @endif
                            @if ($left !== null && $m->isStreaming() && !$silent) &middot; {{ $fmt::duration($left) }} left @endif
                        </div>
                    @elseif ($m->isStreaming() && $m->progress_bytes)
                        <div class="text-xs text-gray-600">
                            {{ $fmt::bytes($m->progress_bytes) }} @if ($speed) &middot; {{ $fmt::speed($speed) }} @endif
                        </div>
                    @endif
                </td>
                <td class="border px-2 py-1 text-xs text-gray-600">
                    @if ($m->provisioning_started_at)
                        @if ($m->phase === 'done' && $m->provisioning_complete_at)
                            took {{ $fmt::duration($m->provisioning_complete_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }}
                            <div>{{ $m->provisioning_complete_at->local()->format('H:i') }}</div>
                        @elseif ($m->phase === 'failed' && $m->phase_started_at)
                            failed after {{ $fmt::duration($m->phase_started_at->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }}
                            <div>{{ $m->phase_started_at->local()->format('H:i') }}</div>
                        @else
                            {{ $fmt::duration(now()->getTimestamp() - $m->provisioning_started_at->getTimestamp()) }}
                            <div>since {{ $m->provisioning_started_at->local()->format('H:i') }}</div>
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>
