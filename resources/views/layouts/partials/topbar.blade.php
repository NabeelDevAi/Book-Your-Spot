<header class="topbar">
    <div class="container-box topbar-inner">
        <x-brand :href="route('home')" />

        <nav class="topbar-nav" aria-label="Main">
            <a href="{{ route('home') }}" class="nav-link {{ request()->routeIs('home') ? 'is-active' : '' }}">
                Browse venues
            </a>

            @auth
                {{-- Only customers book, so only customers get a bookings link
                     (SRS 9.14). Owners and admins get a console link instead. --}}
                @if (auth()->user()->isUser() && Route::has('bookings.index'))
                    <a href="{{ route('bookings.index') }}"
                       class="nav-link {{ request()->routeIs('bookings.*') ? 'is-active' : '' }}">
                        My bookings
                    </a>
                @elseif (auth()->user()->isOwner())
                    <a href="{{ route('owner.dashboard') }}" class="nav-link">Owner console</a>
                @elseif (auth()->user()->isAdmin())
                    <a href="{{ route('admin.dashboard') }}" class="nav-link">Admin console</a>
                @endif
            @endauth
        </nav>

        <div class="cluster-2 ml-auto">
            <x-ui.theme-toggle />

            @guest
                <a href="{{ route('login') }}" class="btn btn-ghost btn-sm">Log in</a>
                <x-ui.button :href="route('register')" variant="primary" size="sm">Sign up</x-ui.button>
            @else
                @include('layouts.partials.notification-bell')
                @include('layouts.partials.user-menu')
            @endguest
        </div>
    </div>
</header>
