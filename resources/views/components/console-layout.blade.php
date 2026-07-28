@props([
    'title' => null,
    'context' => 'owner',   // owner | admin
])

{{--
    Shared shell for the Owner and Admin consoles: persistent left sidebar,
    slim topbar, scrollable content well. The nav itself differs per console
    and is supplied by the matching partial.
--}}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' — '.config('app.name') : config('app.name') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <div class="console-shell">
        <aside class="console-sidebar">
            <div class="console-sidebar-header">
                <x-brand :href="route($context === 'admin' ? 'admin.dashboard' : 'owner.dashboard')" :context="$context" />
            </div>

            <nav class="console-sidebar-nav" aria-label="{{ ucfirst($context) }} navigation">
                @include('layouts.partials.'.$context.'-nav')
            </nav>

            <div class="console-sidebar-footer">
                <a href="{{ route('home') }}" class="sidebar-link">
                    <x-ui.icon name="external" :size="16" /> View public site
                </a>
            </div>
        </aside>

        <div class="console-main">
            <header class="console-topbar">
                <div class="cluster-2">
                    @isset($topbar)
                        {{ $topbar }}
                    @endisset
                </div>

                <div class="cluster-2 ml-auto">
                    @include('layouts.partials.notification-bell')
                    @include('layouts.partials.user-menu')
                </div>
            </header>

            <main class="console-content">
                <x-flash />
                {{ $slot }}
            </main>
        </div>
    </div>

    @stack('modals')
    @stack('scripts')
</body>
</html>
