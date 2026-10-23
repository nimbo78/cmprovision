<div class="cmp-page">
    <div class="contents">
        <div class="cmp-title">
            <h1>Labels</h1>
            <button wire:click="create()" class="cmp-btn ml-auto">Add label</button>
        </div>
        <div class="cmp-card px-4 py-3">
            @if (session()->has('message') && !$isOpen)
                <div class="cmp-flash my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">{{ session('message') }}</p>
                    </div>
                  </div>
                </div>
            @endif
            @if($isOpen)
                @include('livewire.editlabel')
            @endif
            <div class="cmp-card-scroll">
                <table class="cmp-table min-w-full">
                    <thead>
                        <tr>
                            <x-sort-th column="name" :sort="$sortKey" :dir="$sortDir">Name</x-sort-th>
                            <x-sort-th column="type" :sort="$sortKey" :dir="$sortDir">Type</x-sort-th>
                            <x-sort-th column="host" :sort="$sortKey" :dir="$sortDir">Printer FTP hostname</x-sort-th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($labels as $l)
                        <tr>
                            <td>{{ $l->name }}</td>
                            <td>{{ $l->printer_type }}</td>
                            <td>@if ($l->printer_type == "ftp"){{ $l->ftp_hostname }}@else N/A @endif</td>
                            <td>
                                <button wire:click="edit({{ $l->id }})" class="cmp-btn cmp-btn-secondary cmp-btn-small">Edit</button>
                                <button wire:click="delete({{ $l->id }})" class="cmp-btn cmp-btn-danger cmp-btn-small">Delete</button>
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
</div>