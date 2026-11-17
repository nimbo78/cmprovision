<x-guest-layout>
    <x-authentication-card>
        <x-slot name="logo"><x-authentication-card-logo /></x-slot>
        <h1 class="text-lg font-semibold text-fg">First administrator setup code</h1>
        <p class="text-sm mt-1 mb-4 text-fg-muted">Open /setup on the new station. This device card can show the code until {{ $until }}.</p>
        <div x-data="{ copied: false }">
            <label for="run-code" class="block text-sm font-semibold">Station {{ $cm->serial }}</label>
            <input id="run-code" x-ref="code" class="cmp-field w-full mt-1 font-mono" readonly value="{{ $code }}">
            <button type="button" class="cmp-btn cmp-btn-secondary mt-2" x-on:click="$refs.code.select(); copied = document.execCommand('copy')" x-text="copied ? 'Copied' : 'Copy code'">Copy code</button>
        </div>
        <a href="{{ route('cm', $cm->mac) }}" class="inline-block mt-4 underline text-sm text-fg">Back to device</a>
    </x-authentication-card>
</x-guest-layout>
