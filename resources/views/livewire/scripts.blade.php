<div class="cmp-page">
    <div class="contents">
        <div class="cmp-title">
            <h1>Scripts</h1>
            <button wire:click="create()" class="cmp-btn ml-auto">Add script</button>
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
            @if($isOpen)
                @include('livewire.editscript')
            @endif
            <table class="cmp-table min-w-full">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Priority</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($scripts as $s)
                    <tr>
                        <td>{{ $s->name }}</td>
                        <td>{{ $s->script_type === 'firstboot' ? 'first boot' : $s->script_type }}</td>
                        <td>{{ $s->priority }}</td>
                        <td>
                            <button wire:click="edit({{ $s->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Edit</button>
                            <button wire:click="delete({{ $s->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="4">No entries</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="cmp-card px-4 py-3 mt-4">
            <div class="font-bold">Packages</div>
            <p class="text-sm text-fg-muted mb-2">Installed by the OS check on a module's first boot, before its first-boot scripts. The stand has no internet: what a package depends on must be in the image or uploaded here too.</p>
            <form method="POST" action="{{ route('packages.add') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2 mb-3">
                @csrf
                <input type="file" name="package" accept=".deb" required class="cmp-field" aria-label="Debian package">
                <button type="submit" class="cmp-btn">Upload package</button>
            </form>
            <table class="cmp-table min-w-full">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th>Version</th>
                        <th>Architecture</th>
                        <th>File</th>
                        <th>Size</th>
                        <th>SHA256</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($packages as $p)
                    <tr wire:key="package-{{ $p->id }}">
                        <td>{{ $p->deb_package }}</td>
                        <td>{{ $p->deb_version }}</td>
                        <td>{{ $p->deb_architecture }}</td>
                        <td class="text-fg-muted">{{ $p->filename }}</td>
                        <td class="tabular-nums">{{ $p->size >= 1048576 ? number_format($p->size / 1048576, 1).' MB' : number_format(max(1, round($p->size / 1024))).' KB' }}</td>
                        <td class="font-mono text-xs" title="{{ $p->sha256 }}">{{ substr($p->sha256, 0, 12) }}…</td>
                        <td><button wire:click="deletePackage({{ $p->id }})" wire:confirm="Delete package {{ $p->deb_package }}?" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button></td>
                    </tr>
                    @empty
                    <tr><td colspan="7">No packages</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>