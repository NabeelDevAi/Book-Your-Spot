<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @themeAttribute>
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- V1 targets desktop (NFR-7), but the shell is fluid rather than fixed,
         so the viewport still has to be described honestly. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <x-assets />
    @stack('head')
</head>
<body>
    <div class="app-shell">
        @include('layouts.partials.topbar')

        <main class="app-main">
            <div class="container">
                <x-flash />
                {{ $slot }}
            </div>
        </main>

        @include('layouts.partials.footer')
    </div>

    @stack('modals')
    @stack('scripts')
</body>
</html>
