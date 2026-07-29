<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @themeAttribute>
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ isset($title) ? $title.' — '.config('app.name') : config('app.name') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <x-assets />
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
