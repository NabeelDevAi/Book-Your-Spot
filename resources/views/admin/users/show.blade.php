<x-console-layout :title="$user->name" context="admin">
    <x-ui.page-header :title="$user->name">
        <x-slot:breadcrumb>
            <a href="{{ route('admin.users.index') }}" class="link-muted">Users</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $user->name }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            @if ($user->isAdmin())
                {{-- Admin accounts cannot be self-registered, so suspending one
                     could lock the platform out of its own moderation tools. --}}
                <span class="text-muted text-sm">Administrator accounts can't be moderated here.</span>
            @elseif ($user->isSuspended())
                <form method="POST" action="{{ route('admin.users.reinstate', $user) }}"
                      data-confirm="Reinstate this account?"
                      data-confirm-detail="{{ $user->name }} will be able to log in again."
                      data-confirm-action="Reinstate"
                      data-confirm-tone="info">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" icon="refresh">Reinstate account</x-ui.button>
                </form>
            @else
                <x-ui.button variant="danger-outline" type="button" icon="ban" data-modal-open="suspend-user">
                    Suspend account
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($user->isSuspended())
        <x-ui.alert variant="danger" title="This account is suspended" style="margin-bottom: var(--space-5);">
            {{ $user->suspension_reason }}
            @if ($user->suspended_at)
                <span class="text-sm"> — {{ $user->suspended_at->format('j M Y, g:i A') }}</span>
            @endif
        </x-ui.alert>
    @endif

    <div class="grid-2 grid-gap-6" style="align-items: start;">
        <x-ui.card title="Account details">
            <dl class="detail-list">
                <dt>Role</dt><dd>{{ $user->role->label() }}</dd>
                <dt>Email</dt><dd>{{ $user->email }}</dd>
                <dt>Phone</dt><dd>{{ $user->phone ?? '—' }}</dd>
                <dt>Status</dt>
                <dd><x-ui.badge :variant="$user->status->badge()">{{ $user->status->label() }}</x-ui.badge></dd>
                <dt>Joined</dt><dd>{{ $user->created_at->format('j M Y, g:i A') }}</dd>
                <dt>Must change password</dt>
                <dd>{{ $user->must_change_password ? 'Yes — temporary password issued' : 'No' }}</dd>
            </dl>
        </x-ui.card>

        <x-ui.card title="Activity">
            <dl class="detail-list">
                <dt>Venues</dt><dd>{{ $user->businesses_count }}</dd>
                <dt>Reservations</dt><dd>{{ $user->reservations_count }}</dd>
                <dt>No-show flags</dt>
                <dd>
                    @if ($user->no_show_count > 0)
                        <x-ui.badge :variant="$user->isRepeatNoShow() ? 'danger' : 'warning'">
                            {{ $user->no_show_count }}
                        </x-ui.badge>
                        @if ($user->isRepeatNoShow())
                            <span class="text-sm text-muted"> — shown to owners at approval time</span>
                        @endif
                    @else
                        None
                    @endif
                </dd>
            </dl>
        </x-ui.card>
    </div>

    @unless ($user->isAdmin() || $user->isSuspended())
        <x-ui.modal id="suspend-user" title="Suspend this account" subtitle="{{ $user->name }} will be logged out and unable to sign back in.">
            <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="form" id="suspend-user-form">
                @csrf
                <x-ui.field
                    label="Reason"
                    name="reason"
                    required
                    hint="Recorded in the audit log and shown to the user at login."
                >
                    <x-ui.textarea name="reason" required rows="3"
                                   placeholder="e.g. Repeated no-shows across multiple venues" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" data-modal-close>Cancel</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="suspend-user-form">Suspend account</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endunless
</x-console-layout>
