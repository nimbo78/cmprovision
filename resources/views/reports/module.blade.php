<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Device report · {{ $report['identity']['serial'] }}</title><style>{!! $style !!}</style></head>
<body><main>
    <header><div class="mark">cm</div><div><p class="muted">cmprovision · Device report</p><h1>{{ $report['identity']['hostname'] ?: $report['identity']['serial'] }}</h1><p class="mono muted">{{ $report['identity']['mac'] ?? 'MAC unknown' }}</p></div></header>
    <p class="muted">Generated {{ $report['generated_at'] }} · {{ $report['station']['name'] }} · {{ $report['station']['version'] }}</p>
    <div class="grid">
        <section><h2>Hardware</h2><dl>
            @foreach (array_merge(['serial'=>$report['identity']['serial']], $report['hardware']) as $key => $value)
                @if (is_scalar($value) || $value === null)<div><dt>{{ ucfirst(str_replace('_', ' ', $key)) }}</dt><dd>{{ $value ?? 'Unknown' }}</dd></div>@endif
            @endforeach
        </dl></section>
        <section><h2>Provisioning</h2>
            @if ($report['run'])
                <p class="badge">{{ \App\Models\Cm::PHASE_LABELS[$report['run']['result']] ?? 'Not recorded' }}</p>
                <dl>@foreach (['project'=>'Project', 'image'=>'Image', 'started_at'=>'Started', 'completed_at'=>'Completed', 'os_check'=>'OS check'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd>{{ $report['run'][$key] ?? 'Unknown' }}</dd></div>
                @endforeach</dl>
            @else<p>No provisioning result. This is a USB observation.</p>@endif
        </section>
    </div>
    @if ($report['eeprom'])
        <section><h2>EEPROM and recorded location</h2><dl>
            @foreach ($report['eeprom'] as $key => $value)<div><dt>{{ ucfirst($key) }}</dt><dd>{{ $value ?? 'Unknown' }}</dd></div>@endforeach
            <div><dt>Last recorded switch / port</dt><dd>{{ $report['location']['switch'] ?? 'Unknown' }} / {{ $report['location']['port'] ?? 'Unknown' }}</dd></div>
        </dl></section>
    @endif
    @if (!empty($report['run']['steps']))
        <section><h2>Latest run timeline</h2><table><thead><tr><th>Step</th><th>Recorded at</th><th>Duration</th></tr></thead><tbody>
        @foreach ($report['run']['steps'] as $step)<tr><td>{{ $step['label'] }}</td><td>{{ $step['at'] }}</td><td>{{ $step['seconds'] === null ? '—' : $step['seconds'].' s' }}</td></tr>@endforeach
        </tbody></table></section>
    @endif
    @forelse ($report['diagnostics'] as $observation)
        <section><h2>Diagnostics · {{ $observation['channel'] }}</h2><p class="muted">Received {{ $observation['received_at'] ?? 'time unknown' }} · {{ $observation['context'] }} · may describe another boot</p>
            <dl>@foreach ($observation['facts'] as $key => $value)<div><dt>{{ str_replace('_', ' ', $key) }}</dt><dd class="mono">{{ $value }}</dd></div>@endforeach</dl>
        </section>
    @empty<section><h2>Diagnostics</h2><p>No diagnostic snapshot was recorded.</p></section>@endforelse
    <footer><p>Report timestamps use UTC. Print or save this file from your browser; it does not require access to the station.</p>
        @foreach ($report['limitations'] as $limitation)<p>{{ $limitation }}</p>@endforeach
        <p>Credentials, script contents and arbitrary logs are excluded.</p>
    </footer>
</main></body></html>
