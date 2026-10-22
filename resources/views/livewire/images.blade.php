<div class="cmp-page" @if (($hashPending || $splitPending || $fetching) && !$isOpen && !$sourcesOpen) wire:poll.5s @endif>
    <div class="contents">
        <div class="cmp-title">
            <h1>Images</h1>
            <button wire:click="openSources()" class="cmp-btn cmp-btn-secondary ml-auto">Add from the internet</button>
            <button wire:click="create()" class="cmp-btn">Add image</button>
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
            @if ($errors->any())
            <div class="bg-warn-bg border-l-4 border-warn-bar text-warn p-4" role="alert">
                <p>
                <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
                </ul>
                </p>
            </div>
            @endif            
            @if($isOpen)
                @include('livewire.addimage')
            @endif
            @if ($sourcesOpen)
                <livewire:image-sources key="image-sources" />
            @endif
            <p class="text-sm text-fg-muted mb-3">Free disk space for images: {{ \App\Models\Image::size((int) $imageSpace) }}.</p>
            <div class="cmp-card-scroll">
                <table class="cmp-table min-w-full">
                    <thead>
                        <tr>
                            <th>Filename</th>
                            <th>Size</th>
                            <th>SHA256</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($images as $i)
                        <tr>
                            <td><span style="word-break: break-all;">{{ $i->filename }}</span> (added {{ $i->created_at->local()->format("d-M-Y") }})
                                @if ($i->source_type)
                                <div class="text-xs text-fg-muted">From {{ $i->sourceLabel() }}@if ($i->checksumLabel()) · {{ $i->checksumLabel() }}@endif</div>
                                @endif
                            </td>
                            <td>
                                @if (!$i->isReady())
                                @php($pct = $i->download_total ? min(100, floor($i->download_bytes * 100 / $i->download_total)) : 0)
                                <div class="w-full bg-track rounded-full h-2 overflow-hidden mb-1"><div class="bg-info-bar h-2" style="width: {{ $pct }}%"></div></div>
                                <span class="{{ $i->download_state === 'failed' ? 'text-neg' : '' }}">{{ $i->downloadLabel() }}{{ $i->downloadRate($speeds[$i->id] ?? null) }}</span>
                                @elseif ($i->filesize() === null)
                                <nobr class="text-neg font-bold">file missing on disk</nobr><br>
                                @else
                                <nobr>Comp.: {{ number_format($i->filesize()/1000000000,1) }} GB</nobr><br>
                                @endif
                                @if ($i->isReady())
                                <nobr>Uncomp.: {{ $i->uncompressed_size ? number_format($i->uncompressed_size/1000000000,1) : 'unknown' }} GB</nobr>
                                @if ($i->split_filename || $i->split_queued_at || $i->split_error || $i->splitNeeded())
                                <br><nobr>Pieces: {{ $i->splitState() }}</nobr>
                                @endif
                                @endif
                            </td>
                            <td class="break-all">
                                @if (!$i->isReady())
                                —
                                @else
                                {{ $i->sha256 ? $i->sha256 : "...still computing hash..." }}<br>
                                {{ $i->uncompressed_sha256 ? $i->uncompressed_sha256 : "" }}
                                @endif
                            </td>
                            <td>
                                @if ($i->download_state === 'failed')
                                <button wire:click="retry({{ $i->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Retry</button>
                                @endif
                                <button wire:click="delete({{ $i->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button>
                                @if (($i->split_filename || $i->split_error) && !$i->splitPreparing() && !$i->splitNeeded())
                                <button wire:click="removeSplit({{ $i->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Remove pieces</button>
                                @elseif (!$i->splitReady() && !$i->splitPreparing() && $i->splitNeeded())
                                <button wire:click="retrySplit({{ $i->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Retry pieces</button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4">No entries</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<script src="{{ asset('js/upload-progress.js') }}?v={{ filemtime(public_path('js/upload-progress.js')) }}"></script>
<script>
/* Alpine component for the upload dialog (resources/views/livewire/addimage.blade.php). */
function imageUploader(cfg) {
    return {
        state: 'idle',          // idle | uploading | processing | done
        percent: 0,
        statusLine: '',
        error: '',
        xhr: null,
        tracker: null,

        start(input) {
            var file = input.files && input.files[0];
            this.error = '';
            if (!file) { this.error = 'Choose an image file first.'; return; }
            var ext = (file.name.split('.').pop() || '').toLowerCase();
            if (['gz', 'bz2', 'xz'].indexOf(ext) < 0) { this.error = 'Only .gz, .bz2 and .xz images are supported.'; return; }
            if (cfg.maxSize && file.size > cfg.maxSize) { this.error = 'The file (' + UploadProgress.formatBytes(file.size) + ') exceeds the upload limit of ' + UploadProgress.formatBytes(cfg.maxSize) + ' configured in php.ini.'; return; }
            if (cfg.freeSpace && file.size > cfg.freeSpace) { this.error = 'Not enough free disk space on the server for ' + UploadProgress.formatBytes(file.size) + '.'; return; }

            var self = this;
            var form = new FormData();
            form.append('image', file);
            form.append('_token', cfg.csrf);

            this.tracker = UploadProgress.createTracker({ windowMs: 5000 });
            this.state = 'uploading';
            this.percent = 0;
            this.statusLine = 'Starting upload of ' + UploadProgress.formatBytes(file.size) + '...';

            var xhr = this.xhr = new XMLHttpRequest();
            xhr.open('POST', '/addImage');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.addEventListener('progress', function (e) {
                if (!e.lengthComputable) return;
                var s = self.tracker.update(e.loaded, e.total);
                self.percent = s.percent;
                var line = s.percent + ' %  ' + UploadProgress.formatBytes(s.loaded) + ' of ' + UploadProgress.formatBytes(s.total);
                if (s.speed !== null) line += '  ' + UploadProgress.formatBytes(s.speed) + '/s';
                if (s.eta !== null) line += '  ' + UploadProgress.formatDuration(s.eta) + ' left';
                self.statusLine = line;
                if (s.loaded >= s.total) { self.state = 'processing'; self.statusLine = 'Upload finished, the server is storing the file...'; }
            });
            xhr.addEventListener('load', function () {
                if (xhr.status >= 200 && xhr.status < 300) { self.state = 'done'; window.location.href = cfg.done; return; }
                self.fail(self.explain(xhr));
            });
            xhr.addEventListener('error', function () { self.fail('Connection to the server was lost during the upload.'); });
            xhr.addEventListener('abort', function () { self.fail('Upload cancelled.'); });
            xhr.send(form);
        },

        explain(xhr) {
            if (xhr.status == 413) return 'The server refused the file as too large (nginx client_max_body_size).';
            if (xhr.status == 419) return 'Your session expired. Reload the page and try again.';
            try {
                var body = JSON.parse(xhr.responseText);
                if (body.errors) return Object.values(body.errors).flat().join(' ');
                if (body.message) return body.message;
            } catch (e) {}
            return 'Upload failed (HTTP ' + xhr.status + ').';
        },

        fail(message) {
            this.state = 'idle';
            this.percent = 0;
            this.error = message;
            this.xhr = null;
        },

        cancel() {
            if (this.xhr) this.xhr.abort();
        },

        init() {
            var self = this;
            window.addEventListener('beforeunload', function (e) {
                if (self.state == 'uploading' || self.state == 'processing') { e.preventDefault(); e.returnValue = ''; }
            });
        }
    };
}
</script>
</div>
