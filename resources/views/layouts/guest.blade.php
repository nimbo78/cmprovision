<!DOCTYPE html>
@php($sun = \App\Support\SunPlace::forZone(config('app.display_timezone')))
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($sun) data-sun="{{ $sun['lat'] }},{{ $sun['lng'] }}" data-sun-city="{{ $sun['city'] }}" data-sun-zone="{{ $sun['zone'] }}" @endif>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Styles -->
        <link rel="icon" href="/favicon.svg?v={{ @filemtime(public_path('favicon.svg')) }}" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico?v={{ @filemtime(public_path('favicon.ico')) }}" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png?v={{ @filemtime(public_path('apple-touch-icon.png')) }}">
        <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
        <script src="{{ asset('js/appearance.js') }}?v={{ @filemtime(public_path('js/appearance.js')) }}"></script>

        @livewireStyles
    </head>
    <body>
        <div class="font-sans text-gray-900 antialiased">
            {{ $slot }}
        </div>

        {{-- Alpine comes with Livewire's scripts; the two-factor challenge needs it --}}
        @livewireScripts
    </body>
</html>
