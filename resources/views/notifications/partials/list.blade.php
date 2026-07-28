<x-ui.page-header
    title="Notifications"
    description="Everything that's happened with your bookings."
>
    <x-slot:actions>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="check">
                    Mark all {{ $unreadCount }} as read
                </x-ui.button>
            </form>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<x-ui.card flush>
    @if ($notifications->isEmpty())
        <x-ui.empty-state icon="bell" title="No notifications yet">
            You'll be told here when a booking is requested, confirmed, declined or cancelled.
        </x-ui.empty-state>
    @else
        @foreach ($notifications as $notification)
            <a href="{{ route('notifications.read', $notification->id) }}"
               class="notification {{ $notification->read_at ? '' : 'is-unread' }}">
                <span class="notification-icon tone-{{ $notification->data['tone'] ?? 'info' }}">
                    <x-ui.icon :name="$notification->data['icon'] ?? 'bell'" :size="16" />
                </span>

                <span class="notification-body">
                    <span class="notification-title">{{ $notification->data['title'] ?? 'Notification' }}</span>
                    <span class="notification-text">{{ $notification->data['body'] ?? '' }}</span>
                    <span class="notification-time">
                        {{ $notification->created_at->format('j M Y, g:i A') }}
                        @if (! empty($notification->data['reference']))
                            · <span class="mono">{{ $notification->data['reference'] }}</span>
                        @endif
                    </span>
                </span>
            </a>
        @endforeach

        <div style="padding: var(--space-4) var(--space-5);">{{ $notifications->links() }}</div>
    @endif
</x-ui.card>
