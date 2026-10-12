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
            </p>
            <table class="cmp-table min-w-full">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Channel</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($firmware as $f)
                    <tr>
                        <td>{{ $f->name }}</td>
                        <td>{{ $f->channel }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="2">No entries</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
