<x-app-layout>
    <script src="{{ asset('js/download-status.js') }}?v={{ @filemtime(public_path('js/download-status.js')) }}"></script>
    <div class="cmp-page">
        <div class="cmp-title">
            <h1>Download backup</h1>
            <a href="{{ route('settings', ['tab' => 'status']) }}#backup" class="cmp-btn cmp-btn-secondary ml-auto">Back to Backup</a>
        </div>
        <section class="cmp-card p-6 max-w-2xl" x-data="DownloadStatus.create('{{ $downloadId }}')"
                 x-init="$nextTick(() => $refs.downloadForm.requestSubmit())" :aria-busy="state === 'preparing'">
            <p class="text-sm text-fg-muted mb-4">The backup is prepared on this station, then downloaded directly by your browser. It contains saved credentials: keep the file private.</p>
            <div role="status" aria-live="polite" aria-atomic="true">
                <div x-show="state === 'preparing'" x-cloak>
                    <p class="font-bold mb-3">Preparing backup…</p>
                    <progress class="cmp-progress" aria-label="Preparing backup"></progress>
                    <p class="text-sm text-fg-muted mt-3">This can take a few minutes. You can return to Backup at any time.</p>
                </div>
                <div x-show="state === 'started'" x-cloak>
                    <p class="font-bold text-pos">Download started</p>
                    <p class="text-sm text-fg-muted mt-2">Open your browser's downloads to see file progress and completion.</p>
                </div>
                <p x-show="state === 'delayed'" x-cloak class="text-warn">Preparation is taking longer than expected. Check your browser's downloads before trying again.</p>
            </div>
            <p x-show="state === 'failed'" x-cloak role="alert" class="text-neg mt-3" x-text="failure"></p>
            <iframe name="backup-download-{{ $downloadId }}" title="Backup download" class="hidden" x-on:load="frameLoaded($event.target)"></iframe>
            <form x-ref="downloadForm" method="POST" target="backup-download-{{ $downloadId }}" action="{{ route('settings.backup.download') }}" x-on:submit="start($event)">
                @csrf
                <input type="hidden" name="download_id" value="{{ $downloadId }}">
                <button type="submit" class="cmp-btn mt-4" :disabled="state === 'preparing'"
                        x-text="state === 'preparing' ? 'Preparing…' : (state === 'started' ? 'Download again' : 'Prepare and download')">Prepare and download</button>
            </form>
        </section>
    </div>
</x-app-layout>
