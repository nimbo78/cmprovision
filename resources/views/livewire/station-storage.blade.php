<x-settings-card title="Storage" description="Disk usage, update preparation space and reviewed cleanup." id="storage" wire:init="refreshStatus">
    <x-slot name="actions">
        <button type="button" wire:click="refreshStatus(true)" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Refresh</button>
        <button type="button" wire:click="previewCleanup" wire:loading.attr="disabled" class="cmp-btn cmp-btn-secondary">Review cleanup</button>
    </x-slot>
    @if ($error)<p class="cmp-flash text-neg" role="alert">{{ $error }}</p>@endif
    @if ($notice)<p class="cmp-flash" role="status">{{ $notice }}</p>@endif
    <p wire:loading wire:target="refreshStatus" class="text-sm text-fg-muted" role="status">Measuring disk usage…</p>
    @if ($status)
        @if ($status['filesystem'])
            <p class="font-semibold">{{ \App\Models\Image::size($status['filesystem']['free']) }} free of {{ \App\Models\Image::size($status['filesystem']['total']) }}</p>
        @endif
        <dl class="mt-3 space-y-2 text-sm">
            @foreach ($categories as $key => $label)
                <div class="flex flex-wrap justify-between gap-2"><dt>{{ $label }}</dt><dd class="mono">{{ $status['categories'][$key]['bytes'] === null ? 'Unknown' : \App\Models\Image::size($status['categories'][$key]['bytes']) }}</dd></div>
            @endforeach
        </dl>
        @php($estimate = $status['snapshot'])
        <p class="mt-3 text-sm {{ $estimate['sufficient'] === false ? 'text-neg' : 'text-fg-muted' }}">
            Update recovery estimate: {{ $estimate['required'] === null ? 'unknown' : \App\Models\Image::size($estimate['required']).' required' }};
            {{ $estimate['free'] === null ? 'free space unknown' : \App\Models\Image::size($estimate['free']).' free at the recovery location' }}.
            This is a conservative estimate; the updater checks again after its retention cleanup.
        </p>
        <p class="mt-2 text-xs text-fg-muted">Measured {{ \Illuminate\Support\Carbon::createFromTimestamp($status['measured_at'])->local()->format('d.m.Y H:i:s') }}. Measurements are cached for one minute. Unreadable or timed-out categories stay unknown; other OS files are outside these categories.</p>
    @else
        <p class="text-sm text-fg-muted">Measurements load separately from the settings page. Refresh to measure again.</p>
    @endif
    <p class="mt-3 text-sm text-fg-muted">Cleanup reviews unused images only. Backups, recovery snapshots, active jobs and images referenced by projects or current runs stay protected.</p>
    @if ($previewOpen)
        <x-dialog-modal wire:model.live="previewOpen" maxWidth="lg">
            <x-slot name="title">Review storage cleanup</x-slot>
            <x-slot name="content">
                <p class="mb-3 text-sm">Only the images listed below will be considered. References are checked again when you confirm. At most 250 images are shown per review.</p>
                @forelse ($preview as $item)
                    <div class="flex flex-wrap justify-between gap-2 text-sm py-1"><span class="break-all">{{ $item['name'] }}</span><span class="mono">{{ \App\Models\Image::size($item['bytes']) }}</span></div>
                @empty
                    <p class="text-fg-muted">There are no unused images to remove.</p>
                @endforelse
            </x-slot>
            <x-slot name="footer">
                <button type="button" wire:click="$set('previewOpen', false)" class="cmp-btn cmp-btn-secondary">Back</button>
                @if ($preview)
                    <x-confirms-password wire:then="clean"><button type="button" class="cmp-btn cmp-btn-danger ml-3" wire:loading.attr="disabled">Delete reviewed images</button></x-confirms-password>
                @endif
            </x-slot>
        </x-dialog-modal>
    @endif
</x-settings-card>
