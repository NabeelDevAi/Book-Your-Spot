@php
    $currentUser = auth()->user();
@endphp

<div class="dropdown">
    <button type="button" class="user-button" data-dropdown-trigger aria-expanded="false" aria-haspopup="true">
        <x-ui.avatar :name="$currentUser->name" />
        <span class="text-sm font-medium">{{ $currentUser->name }}</span>
        <span class="text-muted" style="line-height:0;"><x-ui.icon name="chevron-down" :size="14" /></span>
    </button>

    <div class="dropdown-menu align-end">
        <div class="dropdown-header">
            {{ $currentUser->email }}
            <div style="text-transform: none; letter-spacing: 0; font-weight: var(--weight-normal);">
                {{ $currentUser->role->label() }}
            </div>
        </div>
        <div class="dropdown-divider"></div>

        {{-- Each role has its own home; showing all three would advertise areas
             the account can't reach anyway (FR-1.6). --}}
        <a href="{{ route($currentUser->role->homeRoute()) }}" class="dropdown-item">
            <x-ui.icon name="grid" :size="15" /> Dashboard
        </a>

        <a href="{{ route('profile.edit') }}" class="dropdown-item">
            <x-ui.icon name="user" :size="15" /> Profile
        </a>

        <div class="dropdown-divider"></div>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="dropdown-item is-danger">
                <x-ui.icon name="logout" :size="15" /> Log out
            </button>
        </form>
    </div>
</div>
