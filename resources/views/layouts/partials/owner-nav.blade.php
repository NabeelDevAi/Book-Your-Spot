@php
    $ownerBusinessIds = auth()->user()->businesses()->pluck('id');
    $pendingCount = \App\Models\Reservation::whereIn('business_id', $ownerBusinessIds)
        ->where('status', \App\Enums\ReservationStatus::Pending)->count();
    $conflictCount = \App\Models\ReservationConflict::whereHas(
        'reservation', fn ($q) => $q->whereIn('business_id', $ownerBusinessIds)
    )->where('status', \App\Enums\ConflictStatus::Open)->count();
@endphp

<div class="sidebar-section">
    <a href="{{ route('owner.dashboard') }}"
       class="sidebar-link {{ request()->routeIs('owner.dashboard') ? 'is-active' : '' }}">
        <x-ui.icon name="grid" :size="16" /> Dashboard
    </a>

    {{-- The pending count sits in the navigation because clearing it is the
         owner's core daily job (NFR-5). --}}
    <a href="{{ route('owner.reservations.index') }}"
       class="sidebar-link {{ request()->routeIs('owner.reservations.*') ? 'is-active' : '' }}">
        <x-ui.icon name="calendar" :size="16" /> Bookings
        @if ($pendingCount > 0)<span class="counter">{{ $pendingCount }}</span>@endif
    </a>

    <a href="{{ route('owner.conflicts.index') }}"
       class="sidebar-link {{ request()->routeIs('owner.conflicts.*') ? 'is-active' : '' }}">
        <x-ui.icon name="alert" :size="16" /> Clashes
        @if ($conflictCount > 0)<span class="counter">{{ $conflictCount }}</span>@endif
    </a>
</div>

<div class="sidebar-section">
    <div class="sidebar-heading">Setup</div>

    <a href="{{ route('owner.businesses.index') }}"
       class="sidebar-link {{ request()->routeIs('owner.businesses.*') ? 'is-active' : '' }}">
        <x-ui.icon name="building" :size="16" /> Venues
    </a>
</div>
