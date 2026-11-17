<x-app-layout title="Dashboard">
    <div class="cmp-page">
        <livewire:poe-outages />

        @if (\App\Services\StationWizardState::invitation())
            <div class="cmp-card px-4 py-3 flex flex-wrap items-center gap-3"><p class="text-sm text-fg-muted">New station? Configure its network, switch, access and first project step by step.</p><a href="{{ route('settings.setup') }}" class="cmp-btn cmp-btn-secondary ml-auto">Set up station</a></div>
        @endif

        @if (!\App\Models\Project::getActiveId())
            <div class="cmp-card px-4 py-3 text-fg-muted">
                To get started add an Image and a Project, then make the project active.
            </div>
        @endif

        <livewire:provisioning-status />

        <livewire:provisioning-log />
    </div>
</x-app-layout>
