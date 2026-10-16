{{-- The top bar (layout A): the mark and the pages; the open batch, the uplink, Appearance and the account on the right --}}
@php($pages = [['dashboard', 'Dashboard'], ['cms', 'CMs'], ['ports', 'Ports'], ['images', 'Images'], ['projects', 'Projects'], ['scripts', 'Scripts'], ['labels', 'Labels'], ['firmware', 'Firmware'], ['settings', 'Settings']])
<nav x-data="{ open: false }" class="cmp-topbar">
    <div class="cmp-topbar-row">
        <a href="{{ route('dashboard') }}" class="shrink-0" aria-label="Dashboard">
            <x-application-mark class="cmp-mark" />
        </a>

        {{-- Narrower than all the links, they scroll (app.css): bring the current page into view --}}
        <div class="cmp-nav hidden sm:flex" x-init="$el.querySelector('[aria-current=page]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })">
            @foreach ($pages as [$route, $label])
                <x-nav-link href="{{ route($route) }}" :active="request()->routeIs($route)">{{ $label }}</x-nav-link>
            @endforeach
        </div>

        <div class="ml-auto flex items-center gap-3">
            <livewire:batch-indicator />
            <livewire:uplink-indicator />

            <div x-data="appearancePopover()" class="relative" @keydown.escape.window="close()" @click.outside="open = false">
                <button type="button" x-ref="button" class="cmp-icon-btn" :class="open && 'is-open'" @click="open = ! open"
                        aria-haspopup="dialog" :aria-expanded="open.toString()" aria-label="Appearance" title="Appearance">
                    <svg x-show="choice.mode === 'light'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
                    <svg x-show="choice.mode === 'dark'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.6 6.6 0 0 0 10.7 10.7z"/></svg>
                    <svg x-show="choice.mode === 'auto'" x-cloak viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v6M8.5 5.5 12 2l3.5 3.5M4.2 10.2l1.4 1.4M2 18h2M20 18h2M18.4 11.6l1.4-1.4M22 22H2M16 18a4 4 0 0 0-8 0"/></svg>
                </button>
                <div x-show="open" x-cloak class="cmp-pop" role="dialog" aria-label="Appearance">
                    <div class="cmp-pop-title">Style</div>
                    <div class="cmp-pop-row" role="group" aria-label="Style">
                        <button type="button" class="cmp-swatch cmp-swatch-warm" :aria-pressed="(choice.style === 'warm').toString()" @click="set({ style: 'warm' })" aria-label="Warm" title="Warm"></button>
                        <button type="button" class="cmp-swatch cmp-swatch-neutral" :aria-pressed="(choice.style === 'neutral').toString()" @click="set({ style: 'neutral' })" aria-label="Neutral" title="Neutral"></button>
                    </div>
                    <div class="cmp-pop-title">Density</div>
                    <div class="cmp-seg" role="group" aria-label="Density">
                        <button type="button" :aria-pressed="(choice.density === 'compact').toString()" @click="set({ density: 'compact' })">Compact</button>
                        <button type="button" :aria-pressed="(choice.density === 'roomy').toString()" @click="set({ density: 'roomy' })">Roomy</button>
                    </div>
                    <div class="cmp-pop-title">Theme</div>
                    <div class="cmp-pop-row" role="group" aria-label="Theme">
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'light').toString()" @click="set({ mode: 'light' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>Light</button>
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'dark').toString()" @click="set({ mode: 'dark' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.6 6.6 0 0 0 10.7 10.7z"/></svg>Dark</button>
                        <button type="button" class="cmp-mode" :aria-pressed="(choice.mode === 'auto').toString()" @click="set({ mode: 'auto' })"><svg viewBox="0 0 24 24" class="w-[18px] h-[18px]" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2v6M8.5 5.5 12 2l3.5 3.5M4.2 10.2l1.4 1.4M2 18h2M20 18h2M18.4 11.6l1.4-1.4M22 22H2M16 18a4 4 0 0 0-8 0"/></svg>Auto</button>
                    </div>
                    <p class="cmp-pop-note" x-text="caption()"></p>
                </div>
            </div>

            <div class="hidden sm:block">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button" class="cmp-account">
                            {{ Auth::user()->name }}
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <div class="block px-4 py-2 text-xs text-fg-faint">{{ __('Manage Account') }}</div>
                        <x-dropdown-link href="{{ route('profile.show') }}">{{ __('Profile') }}</x-dropdown-link>
                        @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                            <x-dropdown-link href="{{ route('api-tokens.index') }}">{{ __('API Tokens') }}</x-dropdown-link>
                        @endif
                        <div class="border-t border-pline"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-dropdown-link>
                        </form>
                        <div class="border-t border-pline"></div>
                        <div class="block px-4 py-2 text-xs text-fg-faint">cmprovision {{ \App\Support\AppVersion::text() }}</div>
                    </x-slot>
                </x-dropdown>
            </div>

            <button type="button" @click="open = ! open" class="cmp-icon-btn sm:hidden" aria-label="Menu">
                <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </div>

    {{-- The phone menu (below sm): every page of the bar from the same list, the current one marked; then the account --}}
    <div :class="{'block': open, 'hidden': ! open}" class="hidden sm:hidden border-t border-pline">
        <div class="pt-2 pb-3 space-y-1" data-menu="phone">
            @foreach ($pages as [$route, $label])
                <x-responsive-nav-link href="{{ route($route) }}" :active="request()->routeIs($route)">{{ $label }}</x-responsive-nav-link>
            @endforeach
        </div>
        <div class="pt-4 pb-1 border-t border-pline">
            <div class="px-4">
                <div class="font-medium text-base text-fg">{{ Auth::user()->name }}</div>
                <div class="font-medium text-sm text-fg-muted">{{ Auth::user()->email }}</div>
            </div>
            <div class="mt-3 space-y-1">
                <x-responsive-nav-link href="{{ route('profile.show') }}" :active="request()->routeIs('profile.show')">{{ __('Profile') }}</x-responsive-nav-link>
                @if (Laravel\Jetstream\Jetstream::hasApiFeatures())
                    <x-responsive-nav-link href="{{ route('api-tokens.index') }}" :active="request()->routeIs('api-tokens.index')">{{ __('API Tokens') }}</x-responsive-nav-link>
                @endif
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-responsive-nav-link href="{{ route('logout') }}" onclick="event.preventDefault(); this.closest('form').submit();">{{ __('Log Out') }}</x-responsive-nav-link>
                </form>
                <div class="px-4 pt-2 text-xs text-fg-faint">cmprovision {{ \App\Support\AppVersion::text() }}</div>
            </div>
        </div>
    </div>
</nav>
