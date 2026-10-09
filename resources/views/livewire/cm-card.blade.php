@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Module <span class="font-mono">{{ $cm->serial }}</span>
    </h2>
</x-slot>
<div @if ($cm->isActive()) wire:poll.5s @else wire:poll.15s @endif class="py-12">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

        @if ($advice)
            @if ($cm->phase === 'failed')
            <div class="bg-red-100 border-t-4 border-red-500 rounded-b text-red-900 px-4 py-3 shadow-md mb-6" role="alert">
                <p class="font-bold">Failed during {{ $advice['step'] ?? 'the start' }}</p>
                <p class="text-sm">{{ $cm->phase_detail }}</p>
                <p class="text-sm mt-2">{{ $advice['advice'] }}</p>
            </div>
            @else
            <div class="bg-orange-100 border-t-4 border-orange-500 rounded-b text-orange-700 px-4 py-3 shadow-md mb-6" role="alert">
                <p class="font-bold">No report during {{ $advice['step'] }}</p>
                <p class="text-sm mt-2">{{ $advice['advice'] }}</p>
            </div>
            @endif
        @endif

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4 mb-6">
            <table class="table-auto text-sm">
                <tr>
                    <td class="pr-4 py-1 text-gray-600">Status</td>
                    <td class="py-1">
                        @if ($cm->phase === 'done')
                            <span class="font-semibold text-green-700">Done</span>
                        @elseif ($cm->phase === 'failed')
                            <span class="font-semibold text-red-700">Failed</span>
                        @elseif ($cm->isActive())
                            <span class="font-semibold">{{ $cm->phaseLabel() }}</span>
                            @if (($percent = $cm->progressPercent()) !== null) &middot; {{ $percent }}% @endif
                        @else
                            Not provisioned
                        @endif
                    </td>
                </tr>
                <tr><td class="pr-4 py-1 text-gray-600">MAC</td><td class="py-1 font-mono">{{ $cm->mac }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">Model</td><td class="py-1">{{ $cm->model }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">Board</td><td class="py-1">{{ $cm->provisioning_board }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">Project</td><td class="py-1">{{ $cm->project ? $cm->project->name : '' }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">Image</td><td class="py-1">{{ $cm->image_filename }} <span class="text-xs text-gray-500 font-mono">{{ $cm->image_sha256 }}</span></td></tr>
                <tr>
                    <td class="pr-4 py-1 text-gray-600">Started</td>
                    <td class="py-1">{{ $cm->provisioning_started_at ? $cm->provisioning_started_at->local()->format('d.m.Y H:i:s') : '' }}</td>
                </tr>
                <tr>
                    <td class="pr-4 py-1 text-gray-600">Completed</td>
                    <td class="py-1">
                        @if ($cm->provisioning_complete_at)
                            {{ $cm->provisioning_complete_at->local()->format('d.m.Y H:i:s') }}
                            &middot; took {{ $fmt::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) }}
                        @else
                            no
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="pr-4 py-1 text-gray-600">Memory, storage</td>
                    <td class="py-1">{{ $cm->memory_in_gb }} GiB &middot; {{ $cm->storage ? round($cm->storage / 1000 / 1000 / 1000).' GB' : 'no storage' }}</td>
                </tr>
                <tr><td class="pr-4 py-1 text-gray-600">Temperature</td><td class="py-1">{{ $cm->temp1 }} at the start @if ($cm->temp2) &middot; {{ $cm->temp2 }} at the end @endif</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">eMMC CID, CSD</td><td class="py-1 text-xs font-mono">{{ $cm->cid }} {{ $cm->csd }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">First seen</td><td class="py-1">{{ $cm->created_at ? $cm->created_at->local()->format('d.m.Y H:i:s') : '' }}</td></tr>
            </table>
        </div>

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4 mb-6">
            <div class="text-xl">Last run</div>
            <div class="text-sm text-gray-600 mb-2">Times in {{ $zone }}</div>
            @if (empty($steps))
                <div class="text-gray-500">No steps recorded yet.</div>
            @else
            <table class="table-auto min-w-full text-sm">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="px-2 py-1 text-left">Step</th>
                        <th class="px-2 py-1 text-left">Started</th>
                        <th class="px-2 py-1 text-left">Took</th>
                        <th class="px-2 py-1 text-left">Speed</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($steps as $s)
                    <tr @if ($s['phase'] === 'failed') class="bg-red-100" @endif>
                        <td class="border px-2 py-1">{{ $s['label'] }}@if ($s['detail'] !== null && $s['phase'] !== 'failed'): {{ $s['detail'] }}@endif</td>
                        <td class="border px-2 py-1 font-mono">{{ $s['at']->local()->format('H:i:s') }}</td>
                        <td class="border px-2 py-1">{{ $s['seconds'] !== null ? $fmt::duration($s['seconds']) : '' }}</td>
                        <td class="border px-2 py-1">{{ $s['speed'] ? $fmt::speed($s['speed']) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4 mb-6">
            <div class="text-xl mb-2">Bootloader (EEPROM)</div>
            <table class="table-auto text-sm mb-2">
                <tr><td class="pr-4 py-1 text-gray-600">Before</td><td class="py-1 font-mono">{{ $cm->eepromVersionBefore() ?? 'not reported' }}</td></tr>
                <tr><td class="pr-4 py-1 text-gray-600">After</td><td class="py-1 font-mono">{{ $cm->eepromVersionAfter() ?? 'unknown' }}</td></tr>
                <tr>
                    <td class="pr-4 py-1 text-gray-600">Result</td>
                    <td class="py-1">
                        @if ($cm->eeprom_result === 'written')
                            written and verified by flashrom
                        @elseif ($cm->eeprom_result === 'identical')
                            unchanged: the EEPROM already held this image
                        @elseif ($cm->eeprom_result === 'failed')
                            <span class="text-red-700">flashing failed</span>
                        @elseif ($cm->eeprom_config_after === null)
                            not flashed: the project has no EEPROM firmware
                        @else
                            not flashed yet
                        @endif
                    </td>
                </tr>
            </table>
            @if ($settings['before'] !== null || $settings['after'] !== null)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach (['before' => 'Settings before', 'after' => 'Settings after'] as $side => $title)
                <div>
                    <div class="text-sm text-gray-600">{{ $title }}</div>
                    <div class="text-xs font-mono bg-gray-100 rounded p-2">
                        @if ($settings[$side] === null)
                            <span class="text-gray-500">{{ $side === 'before' ? 'not reported' : 'not flashed' }}</span>
                        @else
                            @forelse ($settings[$side] as $line)
                                <div class="{{ $line['changed'] ? ($side === 'before' ? 'text-red-700 line-through' : 'text-green-700 font-semibold') : '' }}" data-diff="{{ $line['changed'] ? ($side === 'before' ? 'removed' : 'added') : 'same' }}">{{ $line['text'] }}</div>
                            @empty
                                <span class="text-gray-500">(empty: the defaults of the firmware)</span>
                            @endforelse
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4 mb-6">
            <div class="text-xl mb-2">Logs of the last run</div>
            @foreach (['Pre-install scripts' => $cm->pre_script_output, 'Post-install scripts' => $cm->post_script_output] as $title => $text)
                <details class="mb-2" @if ($text && $cm->phase === 'failed') open @endif>
                    <summary class="cursor-pointer text-sm font-semibold">{{ $title }}@if (!$text) <span class="font-normal text-gray-500">(none)</span>@endif</summary>
                    @if ($text)
                        <pre class="text-xs font-mono bg-gray-100 rounded p-2 overflow-x-auto" style="max-height: 24rem">{{ $text }}</pre>
                    @endif
                </details>
            @endforeach
            <div class="text-sm text-gray-500">The output of a failed image write is in the history below.</div>
        </div>

        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4">
            <div class="text-xl mb-2">History</div>
            <table class="table-auto min-w-full text-sm">
                <tbody>
                @forelse ($history as $l)
                    <tr wire:key="log-{{ $l->id }}" @if ($l->loglevel == 'error') class="bg-red-100" @endif>
                        <td class="border px-2 py-1 font-mono align-top whitespace-nowrap">{{ $l->created_at->local()->format('d.m.Y H:i:s') }}</td>
                        <td class="border px-2 py-1">{!! nl2br(e(\Illuminate\Support\Str::limit($l->msg, \App\Http\Livewire\CmCard::HISTORY_MESSAGE_LENGTH)), false) !!}</td>
                    </tr>
                @empty
                    <tr><td class="border px-2 py-1 text-gray-500">No log entries.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
