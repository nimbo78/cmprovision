@php($fmt = \App\Http\Livewire\ProvisioningStatus::class)
<div @if ($cm->isActive()) wire:poll.5s @else wire:poll.15s @endif class="cmp-page">
    <div class="contents">

        @if ($advice)
            @if ($cm->phase === 'failed')
            <div class="bg-neg-bg border-t-4 border-neg-bar rounded-b text-neg px-4 py-3 shadow-md mb-6" role="alert">
                <p class="font-bold">Failed during {{ $advice['step'] ?? 'the start' }}</p>
                <p class="text-sm">{{ $cm->phase_detail }}</p>
                <p class="text-sm mt-2">{{ $advice['advice'] }}</p>
            </div>
            @else
            <div class="bg-warn-bg border-t-4 border-warn-bar rounded-b text-warn px-4 py-3 shadow-md mb-6" role="alert">
                <p class="font-bold">No report during {{ $advice['step'] }}</p>
                <p class="text-sm mt-2">{{ $advice['advice'] }}</p>
            </div>
            @endif
        @endif

        <div class="cmp-card px-4 py-3">
            <table class="cmp-table text-sm">
                <tr>
                    <td class="text-fg-muted">Status</td>
                    <td>
                        {{-- modules provisioned before 1.6.6 have no phase, only their start and completion --}}
                        @if ($cm->phase === 'done' || ($cm->phase === null && $cm->provisioning_complete_at))
                            <span class="font-semibold text-pos">Done</span>
                        @elseif ($cm->phase === 'failed')
                            <span class="font-semibold text-neg">Failed</span>
                        @elseif ($cm->isActive())
                            <span class="font-semibold">{{ $cm->phaseLabel() }}</span>
                            @if (($percent = $cm->progressPercent()) !== null) &middot; {{ $percent }}% @endif
                        @elseif ($cm->provisioning_started_at)
                            Not completed
                        @else
                            Not provisioned
                        @endif
                    </td>
                </tr>
                @if (in_array($cm->phase, ['done', 'failed'], true))
                <tr>
                    <td class="text-fg-muted">On the bench</td>
                    <td>
                        @if ($cm->canIdentify())
                            yes
                            @if ($cm->isIdentifying())
                                <button wire:click="stopIdentify" class="ml-2 cmp-btn cmp-btn-secondary cmp-btn-small">Stop</button>
                                <span class="ml-2 font-semibold">Blinking until {{ $cm->identify_until->local()->format('H:i:s') }}</span>
                            @else
                                <button wire:click="identify" class="ml-2 cmp-btn cmp-btn-secondary cmp-btn-small">Identify</button>
                                <span class="ml-2 text-xs text-fg-muted">ACT and PWR alternate fast for {{ \App\Models\Cm::IDENTIFY_SECONDS }} s, or until Stop</span>
                            @endif
                        @else
                            <span class="text-fg-muted">not known: the module does not ask the server (switched off, started its own OS, or provisioned by a version before 1.6.9)</span>
                        @endif
                    </td>
                </tr>
                @endif
                <tr><td class="text-fg-muted">MAC</td><td class="font-mono">{{ $cm->mac }}</td></tr>
                <tr><td class="text-fg-muted">Model</td><td>{{ $cm->model }}</td></tr>
                <tr><td class="text-fg-muted">Board</td><td>{{ $cm->provisioning_board }}</td></tr>
                <tr><td class="text-fg-muted">Project</td><td>{{ $cm->project ? $cm->project->name : '' }}</td></tr>
                <tr><td class="text-fg-muted">Image</td><td>{{ $cm->image_filename }} <span class="text-xs text-fg-muted font-mono">{{ $cm->image_sha256 }}</span></td></tr>
                <tr>
                    <td class="text-fg-muted">Started</td>
                    <td>{{ $cm->provisioning_started_at ? $cm->provisioning_started_at->local()->format('d.m.Y H:i:s') : '' }}</td>
                </tr>
                <tr>
                    <td class="text-fg-muted">Completed</td>
                    <td>
                        @if ($cm->provisioning_complete_at)
                            {{ $cm->provisioning_complete_at->local()->format('d.m.Y H:i:s') }}
                            &middot; took {{ $fmt::duration($cm->provisioning_complete_at->getTimestamp() - $cm->provisioning_started_at->getTimestamp()) }}
                        @else
                            no
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="text-fg-muted">Memory, storage</td>
                    <td>{{ $cm->memory_in_gb }} GiB &middot; {{ $cm->storage ? round($cm->storage / 1000 / 1000 / 1000).' GB' : 'no storage' }}</td>
                </tr>
                <tr><td class="text-fg-muted">Temperature</td><td>{{ $cm->temp1 }} at the start @if ($cm->temp2) &middot; {{ $cm->temp2 }} at the end @endif</td></tr>
                <tr><td class="text-fg-muted">eMMC CID, CSD</td><td class="text-xs font-mono">{{ $cm->cid }} {{ $cm->csd }}</td></tr>
                <tr><td class="text-fg-muted">First seen</td><td>{{ $cm->created_at ? $cm->created_at->local()->format('d.m.Y H:i:s') : '' }}</td></tr>
            </table>
        </div>

        <div class="cmp-card px-4 py-3">
            <div class="font-bold">Last run</div>
            <div class="text-sm text-fg-muted mb-2">Times in {{ $zone }}</div>
            @if ($cm->timeline === null)
                <div class="text-fg-muted">This module was provisioned before steps were recorded.</div>
            @elseif (empty($steps))
                <div class="text-fg-muted">No steps recorded yet.</div>
            @else
            <table class="cmp-table text-sm">
                <thead>
                    <tr>
                        <th>Step</th>
                        <th>Started</th>
                        <th>Took</th>
                        <th>Speed</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($steps as $s)
                    <tr @if ($s['phase'] === 'failed') class="bg-neg-bg" @endif>
                        <td >{{ $s['label'] }}@if ($s['detail'] !== null && $s['phase'] !== 'failed'): {{ $s['detail'] }}@endif</td>
                        <td class="font-mono">{{ $s['at']->local()->format('H:i:s') }}</td>
                        <td >{{ $s['seconds'] !== null ? $fmt::duration($s['seconds']) : '' }}</td>
                        <td >{{ $s['speed'] ? $fmt::speed($s['speed']) : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            @endif
        </div>

        <div class="cmp-card px-4 py-3">
            <div class="font-bold mb-2">Bootloader (EEPROM)</div>
            <table class="cmp-table mb-2">
                <tr><td class="text-fg-muted">Before</td><td class="font-mono">{{ $cm->eepromVersionBefore() ?? 'not reported' }}</td></tr>
                <tr><td class="text-fg-muted">After</td><td class="font-mono">{{ $cm->eepromVersionAfter() ?? 'unknown' }}</td></tr>
                <tr>
                    <td class="text-fg-muted">Result</td>
                    <td>
                        @if ($cm->eeprom_result === 'written')
                            written and verified by flashrom
                        @elseif ($cm->eeprom_result === 'identical')
                            unchanged: the EEPROM already held this image
                        @elseif ($cm->eeprom_result === 'failed')
                            <span class="text-neg">flashing failed</span>
                        @elseif ($cm->timeline === null)
                            not recorded for this run
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
                    <div class="text-sm text-fg-muted">{{ $title }}</div>
                    <div class="text-xs font-mono bg-head rounded p-2">
                        @if ($settings[$side] === null)
                            <span class="text-fg-muted">{{ $side === 'before' ? 'not reported' : 'not flashed' }}</span>
                        @else
                            @forelse ($settings[$side] as $line)
                                <div class="{{ $line['changed'] ? ($side === 'before' ? 'text-neg line-through' : 'text-pos font-semibold') : '' }}" data-diff="{{ $line['changed'] ? ($side === 'before' ? 'removed' : 'added') : 'same' }}">{{ $line['text'] }}</div>
                            @empty
                                <span class="text-fg-muted">(empty: the defaults of the firmware)</span>
                            @endforelse
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="cmp-card px-4 py-3">
            <div class="font-bold mb-2">Logs of the last run</div>
            @foreach (['Pre-install scripts' => $cm->pre_script_output, 'Post-install scripts' => $cm->post_script_output] as $title => $text)
                <details class="mb-2" @if ($text && $cm->phase === 'failed') open @endif>
                    <summary class="cursor-pointer text-sm font-semibold">{{ $title }}@if (!$text) <span class="font-normal text-fg-muted">(none)</span>@endif</summary>
                    @if ($text)
                        <pre class="text-xs font-mono bg-head rounded p-2 overflow-x-auto" style="max-height: 24rem">{{ $text }}</pre>
                    @endif
                </details>
            @endforeach
            <div class="text-sm text-fg-muted">The output of a failed image write is in the history below.</div>
        </div>

        <div class="cmp-card px-4 py-3">
            <div class="font-bold mb-2">History</div>
            <table class="cmp-table text-sm">
                <tbody>
                @forelse ($history as $l)
                    <tr wire:key="log-{{ $l->id }}" @if ($l->loglevel == 'error') class="bg-neg-bg" @endif>
                        <td class="font-mono align-top whitespace-nowrap" style="width: 1%">{{ $l->created_at->local()->format('d.m.Y H:i:s') }}</td>
                        <td >{!! nl2br(e(\Illuminate\Support\Str::limit($l->msg, \App\Http\Livewire\CmCard::HISTORY_MESSAGE_LENGTH)), false) !!}</td>
                    </tr>
                @empty
                    <tr><td class="text-fg-muted">No log entries.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
