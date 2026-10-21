<div class="cmp-page">
    <div class="contents">
        <div class="cmp-title">
            <h1>Projects</h1>
            <button wire:click="create()" class="cmp-btn ml-auto">Add project</button>
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
                @include('livewire.editproject')
            @endif
            <div class="cmp-card-scroll">
                <table class="cmp-table w-full">
                    <thead>
                        <tr>
                            <th>Project name</th>
                            <th>Uses image</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($projects as $p)
                        <tr>
                            <td>@if ($activeProject == $p->id) <b>{{ $p->name }}</b> (active project) @else {{ $p->name }} @endif</td>
                            <td>@if ($p->image) {{ $p->image->filename }} @else - @endif</td>
                            <td>
                                <button wire:click="edit({{ $p->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Edit</button>
                                <button wire:click="setActive({{ $p->id }})" class="cmp-btn cmp-btn-small">Set active</button>
                                <button wire:click="delete({{ $p->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button>
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