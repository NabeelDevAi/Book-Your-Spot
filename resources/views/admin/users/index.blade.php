<x-console-layout title="Users" context="admin">
    <x-ui.page-header
        title="Users &amp; owners"
        description="Every account on the platform (FR-3.4)."
    />

    @if ($pendingOwnerCount > 0)
        <x-ui.alert variant="warning" style="margin-bottom: var(--space-5);">
            <strong>{{ $pendingOwnerCount }}</strong>
            Owner {{ Str::plural('account', $pendingOwnerCount) }} awaiting approval.
            They can't log in until you approve or reject them.
        </x-ui.alert>
    @endif

    <form method="GET" class="filter-bar">
        <x-ui.field label="Search" name="search" style="flex: 1 1 auto;">
            <x-ui.input name="search" :value="request('search')" placeholder="Name, email or phone" />
        </x-ui.field>

        <x-ui.field label="Role" name="role">
            <x-ui.select name="role" placeholder="Any role" :selected="request('role')">
                @foreach ($roles as $role)
                    <option value="{{ $role->value }}" @selected(request('role') === $role->value)>
                        {{ $role->label() }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Status" name="status">
            <x-ui.select name="status" placeholder="Any status" :selected="request('status')">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Filter</x-ui.button>
            @if (request()->hasAny(['search', 'role', 'status']))
                <x-ui.button :href="route('admin.users.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.card flush>
        @if ($users->isEmpty())
            <x-ui.empty-state icon="users" title="No accounts match those filters">
                Try broadening the search or clearing the role and status filters.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="cell-numeric">Venues</th>
                            <th class="cell-numeric">No-shows</th>
                            <th>Joined</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="{{ $user->isSuspended() ? 'is-danger' : ($user->status === \App\Enums\UserStatus::PendingApproval ? 'is-attention' : '') }}">
                                <td>
                                    <div class="cluster-2">
                                        <x-ui.avatar :name="$user->name" size="sm" />
                                        <div>
                                            <div class="cell-primary">{{ $user->name }}</div>
                                            <div class="cell-secondary">{{ $user->email }} · {{ $user->phone }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $user->role->label() }}</td>
                                <td>
                                    <x-ui.badge :variant="$user->status->badge()">
                                        {{ $user->status->label() }}
                                    </x-ui.badge>
                                </td>
                                <td class="cell-numeric">{{ $user->businesses_count ?: '—' }}</td>
                                <td class="cell-numeric">
                                    @if ($user->no_show_count > 0)
                                        <x-ui.badge :variant="$user->isRepeatNoShow() ? 'danger' : 'warning'">
                                            {{ $user->no_show_count }}
                                        </x-ui.badge>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="cell-secondary">{{ $user->created_at->format('j M Y') }}</td>
                                <td class="cell-actions">
                                    @if ($user->isOwner() && $user->status === \App\Enums\UserStatus::PendingApproval)
                                        <div class="btn-group">
                                            <form method="POST" action="{{ route('admin.users.approve-owner', $user) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="success" size="sm" icon="check">
                                                    Approve
                                                </x-ui.button>
                                            </form>
                                            <x-ui.button :href="route('admin.users.show', $user)" variant="secondary" size="sm">
                                                Review
                                            </x-ui.button>
                                        </div>
                                    @else
                                        <x-ui.button :href="route('admin.users.show', $user)" variant="secondary" size="sm">
                                            View
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">
                {{ $users->links() }}
            </div>
        @endif
    </x-ui.card>
</x-console-layout>
