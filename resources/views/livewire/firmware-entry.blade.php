{{-- one entry of the EEPROM release notes: the title, then what it changed (the notes are text: escaped, never markup) --}}
<details class="text-sm mb-1" @if ($open ?? false) open @endif>
    <summary class="cursor-pointer"><span class="font-semibold">{{ $entry['title'] }}</span> <span class="text-fg-muted">{{ $entry['date'] }}</span></summary>
    @if ($entry['intro'] !== '')
        <p class="whitespace-pre-line text-fg-muted mt-1">{{ $entry['intro'] }}</p>
    @endif
    @if ($entry['items'])
        <ul class="list-disc pl-5 mt-1">
            @foreach ($entry['items'] as $item)
                <li>
                    <span class="font-semibold">{{ $item['title'] }}</span>
                    @if ($item['detail'] !== '')
                        <div class="whitespace-pre-line text-fg-muted">{{ $item['detail'] }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</details>
