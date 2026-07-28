@php
    $unread = auth()->user()->unreadNotifications()->count();
@endphp

<div class="dropdown">
    <button
        type="button"
        class="bell"
        data-dropdown-trigger
        data-bell
        data-unread-url="{{ route('notifications.unread') }}"
        data-read-url="{{ route('notifications.read', '__ID__') }}"
        aria-expanded="false"
        aria-haspopup="true"
        aria-label="Notifications{{ $unread > 0 ? " ({$unread} unread)" : '' }}"
    >
        <x-ui.icon name="bell" :size="18" />
        <span class="bell-badge" data-bell-badge @if ($unread === 0) hidden @endif>
            {{ $unread > 99 ? '99+' : $unread }}
        </span>
    </button>

    <div class="dropdown-menu notification-menu align-end">
        <div class="notification-menu-header">
            <span class="notification-menu-title">Notifications</span>

            @if ($unread > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="link text-xs">Mark all read</button>
                </form>
            @endif
        </div>

        {{-- Server-rendered on first paint so the list is useful without JS;
             the poller replaces it in place afterwards. --}}
        <div data-notification-list>
            @forelse (auth()->user()->unreadNotifications()->take(8)->get() as $notification)
                <a href="{{ route('notifications.read', $notification->id) }}" class="notification is-unread">
                    <span class="notification-icon tone-{{ $notification->data['tone'] ?? 'info' }}">
                        <x-ui.icon :name="$notification->data['icon'] ?? 'bell'" :size="16" />
                    </span>
                    <span class="notification-body">
                        <span class="notification-title">{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <span class="notification-text">{{ $notification->data['body'] ?? '' }}</span>
                        <span class="notification-time">{{ $notification->created_at->diffForHumans(short: true) }}</span>
                    </span>
                </a>
            @empty
                <p class="notification-text" style="padding: var(--space-5); text-align: center;">
                    Nothing new right now.
                </p>
            @endforelse
        </div>

        <div class="notification-menu-footer">
            <a href="{{ route('notifications.index') }}" class="link text-sm">See all notifications</a>
        </div>
    </div>
</div>
