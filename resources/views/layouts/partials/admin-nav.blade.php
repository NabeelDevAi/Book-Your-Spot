@php
    $pendingBusinesses = \App\Models\Business::where('status', \App\Enums\BusinessStatus::PendingReview)->count();
    $flaggedDuplicates = \App\Models\Business::where('duplicate_flagged', true)->count();
    $openResets = \App\Models\PasswordResetRequest::open()->count();
    $pendingOwners = \App\Models\User::where('role', \App\Enums\UserRole::Owner)
        ->where('status', \App\Enums\UserStatus::PendingApproval)->count();
@endphp

<div class="sidebar-section">
    <a href="{{ route('admin.dashboard') }}"
       class="sidebar-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
        <x-ui.icon name="grid" :size="16" /> Overview
    </a>

    <a href="{{ route('admin.reports.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}">
        <x-ui.icon name="chart" :size="16" /> Reports
    </a>
</div>

<div class="sidebar-section">
    <div class="sidebar-heading">Moderation</div>

    <a href="{{ route('admin.businesses.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.businesses.*') ? 'is-active' : '' }}">
        <x-ui.icon name="building" :size="16" /> Venues
        @if ($pendingBusinesses > 0)<span class="counter">{{ $pendingBusinesses }}</span>@endif
    </a>

    @if ($flaggedDuplicates > 0)
        <a href="{{ route('admin.businesses.index', ['duplicates' => 1]) }}"
           class="sidebar-link {{ request()->boolean('duplicates') ? 'is-active' : '' }}">
            <x-ui.icon name="flag" :size="16" /> Possible duplicates
            <span class="counter counter-neutral">{{ $flaggedDuplicates }}</span>
        </a>
    @endif

    <a href="{{ route('admin.reservations.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.reservations.*') ? 'is-active' : '' }}">
        <x-ui.icon name="calendar" :size="16" /> Reservations
    </a>
</div>

<div class="sidebar-section">
    <div class="sidebar-heading">Platform</div>

    <a href="{{ route('admin.games.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.games.*') ? 'is-active' : '' }}">
        <x-ui.icon name="layers" :size="16" /> Categories
    </a>

    <a href="{{ route('admin.holidays.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.holidays.*') ? 'is-active' : '' }}">
        <x-ui.icon name="calendar" :size="16" /> Holidays
    </a>

    <a href="{{ route('admin.users.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
        <x-ui.icon name="users" :size="16" /> Users &amp; owners
        @if ($pendingOwners > 0)<span class="counter">{{ $pendingOwners }}</span>@endif
    </a>

    <a href="{{ route('admin.password-requests.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.password-requests.*') ? 'is-active' : '' }}">
        <x-ui.icon name="lock" :size="16" /> Password requests
        @if ($openResets > 0)<span class="counter">{{ $openResets }}</span>@endif
    </a>

    <a href="{{ route('admin.audit.index') }}"
       class="sidebar-link {{ request()->routeIs('admin.audit.*') ? 'is-active' : '' }}">
        <x-ui.icon name="shield" :size="16" /> Audit log
    </a>
</div>
