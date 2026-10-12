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
                        <td>{{ $s->script_type }}</td>
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
    </div>
</div>