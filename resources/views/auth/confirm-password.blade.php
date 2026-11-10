<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-fg-muted">
            {{ __('This is a secure area of the application. Please confirm your password before continuing.') }}
        </div>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.confirm') }}" x-data="{ confirming: false }" x-on:submit="confirming = true">
            @csrf

            <div>
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
            </div>

            <p x-show="confirming" x-cloak role="status" class="text-sm text-fg-muted mt-4">Confirming password…</p>
            <div class="flex justify-end items-center gap-3 mt-4">
                @if (parse_url(session('url.intended', ''), PHP_URL_PATH) === parse_url(route('settings.backup'), PHP_URL_PATH))
                    <a href="{{ route('settings', ['tab' => 'system']) }}#backup" class="cmp-btn cmp-btn-secondary">Back to Backup</a>
                @endif
                <x-button x-bind:disabled="confirming">
                    {{ __('Confirm') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest-layout>
