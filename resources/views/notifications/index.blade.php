@php
    // The notification centre is reachable from all three shells, so pick the
    // layout that matches who is looking at it.
    $isConsole = auth()->user()->isOwner() || auth()->user()->isAdmin();
@endphp

@if ($isConsole)
    <x-console-layout title="Notifications" :context="auth()->user()->isAdmin() ? 'admin' : 'owner'">
        @include('notifications.partials.list', ['notifications' => $notifications, 'unreadCount' => $unreadCount])
    </x-console-layout>
@else
    <x-app-layout title="Notifications">
        @include('notifications.partials.list', ['notifications' => $notifications, 'unreadCount' => $unreadCount])
    </x-app-layout>
@endif
