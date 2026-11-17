<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo"><x-authentication-card-logo /></x-slot>
        <h1 class="text-lg font-semibold text-fg">Set up this station</h1>
        @if ($available)
            <p class="mt-1 mb-4 text-sm text-fg-muted">Create the first web administrator. Find the setup code on the station display or its device card on the imaging station.</p>
            <x-validation-errors class="mb-4" />
            <form method="POST" action="{{ route('first-admin') }}">
                @csrf
                <x-label for="setup_code" value="Setup code" />
                <x-input id="setup_code" name="setup_code" class="block mt-1 w-full" required autofocus maxlength="64" autocomplete="one-time-code" spellcheck="false" />
                <div class="mt-4">
                    <x-label for="name" value="Name" />
                    <x-input id="name" name="name" class="block mt-1 w-full" :value="old('name')" required maxlength="255" autocomplete="name" />
                </div>
                <div class="mt-4">
                    <x-label for="email" value="Email" />
                    <x-input id="email" name="email" class="block mt-1 w-full" type="email" :value="old('email')" required maxlength="255" autocomplete="username" />
                </div>
                <div class="mt-4">
                    <x-label for="password" value="Password" />
                    <x-input id="password" name="password" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                </div>
                <div class="mt-4">
                    <x-label for="password_confirmation" value="Confirm password" />
                    <x-input id="password_confirmation" name="password_confirmation" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                </div>
                <x-button class="mt-4">Create administrator</x-button>
            </form>
        @else
            <p class="my-4 text-sm text-fg-muted">First-time setup is unavailable. Use an existing account or check the station at its local console.</p>
            <a class="underline text-sm text-fg" href="{{ route('login') }}">Sign in</a>
        @endif
    </x-authentication-card>
</x-guest-layout>
