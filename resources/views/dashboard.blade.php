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

            <livewire:provisioning-log />
        </div>
    </div>
</x-app-layout>
