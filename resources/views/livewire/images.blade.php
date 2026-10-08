<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 leading-tight">
        Images
    </h2>
</x-slot>
<div class="py-12" @if ($hashPending && !$isOpen) wire:poll.5s @endif>
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg px-4 py-4">
            @if (session()->has('message'))
                <div class="bg-teal-100 border-t-4 border-teal-500 rounded-b text-teal-900 px-4 py-3 shadow-md my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">{{ session('message') }}</p>
                    </div>
                  </div>
                </div>
            @endif
            @if ($errors->any())
            <div class="bg-orange-100 border-l-4 border-orange-500 text-orange-700 p-4" role="alert">
                <p>
                <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
                </ul>
                </p>
            </div>
            @endif            
            <button wire:click="create()" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded my-3">Add image</button>
            @if($isOpen)
                @include('livewire.addimage')
            @endif
            <table class="table-fixed min-w-full">
                <thead>
                    <tr class="bg-gray-100">
                        <th class="w-1/4 px-4 py-2">Filename</th>
                        <th class="w-1/8 px-4 py-2">Size</th>
                        <th class="w-1/4 px-4 py-2">SHA256</th>
                        <th class="w-1/8 px-4 py-2">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($images as $i)
                    <tr>
                        <td class="border px-4 py-2"><span style="word-break: break-all;">{{ $i->filename }}</span> (added {{ date_format($i->created_at, "d-M-Y") }})</td>
                        <td class="border px-4 py-2">
                            <nobr>Comp.: {{ number_format($i->filesize()/1000000000,1) }} GB</nobr><br>
                            <nobr>Uncomp.: {{ $i->uncompressed_size ? number_format($i->uncompressed_size/1000000000,1) : 'unknown' }} GB</nobr>
                        </td>
                        <td class="border px-4 py-2">
                            {{ $i->sha256 ? $i->sha256 : "...still computing hash..." }}<br>
                            {{ $i->uncompressed_sha256 ? $i->uncompressed_sha256 : "" }}
                        </td>
                        <td class="border px-4 py-2">
                            <button wire:click="delete({{ $i->id }})" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">Delete</button>
                        </td>
                    </tr>
                    @empty
                    <tr><td class="border px-4 py-2" colspan="4">No entries</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
<style>[x-cloak] { display: none !important; }</style>
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
