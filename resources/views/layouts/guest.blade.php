<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="auth-shell">
        <div class="auth-main">
            <div class="auth-card {{ $wide ?? false ? 'auth-card-wide' : '' }}">
                <x-brand :href="route('home')" class="auth-brand" />
                <x-flash />
                {{ $slot }}
            </div>
        </div>

        @include('layouts.partials.auth-aside')
    </div>
</body>
</html>
