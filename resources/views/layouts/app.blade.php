<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Deliberately no viewport meta: V1 is a fixed-width desktop target (NFR-7). --}}

    <title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
