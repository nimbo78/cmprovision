<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (!\App\Models\Project::getActiveId())
            <div class="bg-white shadow sm:rounded-lg p-4 mb-6 text-gray-600">
                To get started add an Image and a Project, then make the project active.
            </div>
            @endif

            <livewire:provisioning-status />

            <div class="bg-white overflow-hidden shadow-xl sm:rounded-lg sm:px-20">
                <div class="mt-8 text-2xl">
                        Last 100 provisioning log entries
                </div><br>

                <table class="table-fixed min-w-full">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="w-1/6 px-4 py-2">Board</th>
                            <th class="w-1/6 px-4 py-2">CM serial</th>
                            <th class="px-4 py-2">Log message</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($log as $l)
                        @if ($l->loglevel == 'error')<tr class="bg-red-100">@else <tr>@endif 
                            <td class="border px-4 py-2">{{ $l->board }}</td>
                            <td class="border px-4 py-2">{{ $l->cm }}</td>
                            <td class="border px-4 py-2">{!! nl2br(e($l->created_at->local()->toTimeString().' '.$l->msg), false) !!}</td>
                        </tr>
                        @empty
                        <tr><td class="border px-4 py-2" colspan="3">No entries</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <br>
            </div>            
        </div>
    </div>
</x-app-layout>
