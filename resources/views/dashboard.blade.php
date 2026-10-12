<x-app-layout title="Dashboard">
    <div class="cmp-page">
        <livewire:poe-outages />

        @if (!\App\Models\Project::getActiveId())
            <div class="cmp-card px-4 py-3 text-fg-muted">
                To get started add an Image and a Project, then make the project active.
            </div>
        @endif

        <livewire:provisioning-status />

        <livewire:provisioning-log />
    </div>
</x-app-layout>
