<div class="cmp-page">
    <div class="contents">
        <div class="cmp-title">
            <h1>Firmware</h1>
            <span wire:loading wire:target="update" class="text-sm text-fg-muted ml-auto">Checking GitHub and the rpi-eeprom package, please wait...</span>
            <button wire:click="update()" wire:loading.attr="disabled" class="cmp-btn ml-auto">Check for new firmware</button>
        </div>
        <div class="cmp-card px-4 py-3">
            @if (session()->has('message'))
                <div class="cmp-flash my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">{{ session('message') }}</p>
                    </div>
                  </div>
                </div>
            @endif
            <p class="text-sm text-fg-muted mb-3">
                Last successful check: {{ $lastUpdate ?: 'never' }}.
                Images come from the <code>default</code> (recommended by Raspberry Pi) and <code>latest</code> channels of
                <a class="underline" href="https://github.com/raspberrypi/rpi-eeprom/blob/master/firmware-2711/release-notes.md" target="_blank" rel="noopener">rpi-eeprom</a>
                (release notes). Downloaded images are kept even after Raspberry Pi retires them, so projects stay pinned to the image they were configured with.
                @if ($notesDate)
                    The release notes are kept beside the images (the newest entry is from {{ $notesDate }}); the list shows what each image changed.
                @else
                    Release notes arrive with the next check.
                @endif
            </p>
            <div class="cmp-card-scroll">
                <table class="cmp-table min-w-full">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Channel</th>
                            <th>What changed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($firmware as $f)
                        <tr data-new="{{ $f->isNew() ? 1 : 0 }}">
                            <td class="align-top">{{ $f->name }}@if ($f->isNew()) <span class="cmp-chip cmp-chip-run ml-1">new</span>@endif</td>
                            <td class="align-top">{{ $f->channel }}</td>
                            <td>
                                @if ($changes[$f->path] ?? null)
                                    @forelse ($changes[$f->path]['changes'] as $entry)
                                        @include('livewire.firmware-entry', ['entry' => $entry, 'open' => $f->isNew()])
                                    @empty
                                        <span class="text-sm text-fg-muted">The release notes have nothing for {{ $changes[$f->path]['date'] }}.</span>
                                    @endforelse
                                    @foreach ($changes[$f->path]['promotions'] as $promotion)
                                        <div class="text-sm text-fg-muted">Promoted: {{ $promotion['date'] }} · {{ $promotion['title'] }}</div>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="3">No entries</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
