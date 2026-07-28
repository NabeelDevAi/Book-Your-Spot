<x-console-layout title="Venues" context="admin">
    <x-ui.page-header
        title="Venues"
        description="Review, approve and moderate every venue on the platform."
    >
        <x-slot:actions>
            {{-- FR-3.8: early pilot onboarding happens over the phone, so Admin
                 needs to list a venue for an owner who hasn't used the site. --}}
            <x-ui.button :href="route('admin.businesses.create')" variant="secondary" icon="plus">
                Add on behalf of an owner
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($pendingCount > 0)
        <x-ui.alert variant="warning" style="margin-bottom: var(--space-5);">
            <strong>{{ $pendingCount }}</strong>
            {{ Str::plural('venue', $pendingCount) }} awaiting review.
            Owners can't take bookings until you approve them.
        </x-ui.alert>
    @endif

    <form method="GET" class="filter-bar">
        <x-ui.field label="Search" name="search" style="flex: 1 1 auto;">
            <x-ui.input name="search" :value="request('search')" placeholder="Name, area or phone" />
        </x-ui.field>

        <x-ui.field label="Status" name="status">
            <x-ui.select name="status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Flagged">
            <label class="checkbox" style="height: 34px;">
                <input type="checkbox" name="duplicates" value="1" @checked(request()->boolean('duplicates'))>
                <span>Possible duplicates ({{ $duplicateCount }})</span>
            </label>
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Filter</x-ui.button>
            @if (request()->hasAny(['search', 'status', 'duplicates']))
                <x-ui.button :href="route('admin.businesses.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.card flush>
        @if ($businesses->isEmpty())
            <x-ui.empty-state icon="building" title="No venues match those filters">
                Try clearing the status filter or broadening the search.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Venue</th>
                            <th>Owner</th>
                            <th class="cell-numeric">Spots</th>
                            <th class="cell-numeric">Bookings</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($businesses as $business)
                            <tr class="{{ $business->isPendingReview() ? 'is-attention' : ($business->duplicate_flagged ? 'is-danger' : '') }}">
                                <td>
                                    <a href="{{ route('admin.businesses.show', $business) }}" class="cell-primary link">
                                        {{ $business->name }}
                                    </a>
                                    <div class="cell-secondary">
                                        {{ $business->area }} · {{ $business->contact_number }}
                                    </div>
                                    @if ($business->duplicate_flagged)
                                        <div class="cell-secondary text-warning">{{ $business->duplicate_note }}</div>
                                    @endif
                                </td>

                                <td>
                                    <a href="{{ route('admin.users.show', $business->owner) }}" class="link">
                                        {{ $business->owner->name }}
                                    </a>
                                    <div class="cell-secondary">{{ $business->owner->phone }}</div>
                                </td>

                                <td class="cell-numeric">{{ $business->spots_count }}</td>
                                <td class="cell-numeric">{{ $business->reservations_count }}</td>

                                <td>
                                    <x-ui.badge :variant="$business->status->badge()">
                                        {{ $business->status->label() }}
                                    </x-ui.badge>
                                    @if ($business->isActive() && $business->spots_count === 0)
                                        {{-- SRS 9.17: approved but invisible in search. --}}
                                        <div class="cell-secondary text-warning">No spots — hidden from search</div>
                                    @endif
                                </td>

                                <td class="cell-secondary">{{ $business->created_at->format('j M Y') }}</td>

                                <td class="cell-actions">
                                    @if ($business->isPendingReview())
                                        <div class="btn-group">
                                            <form method="POST" action="{{ route('admin.businesses.approve', $business) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="success" size="sm" icon="check">
                                                    Approve
                                                </x-ui.button>
                                            </form>
                                            <x-ui.button :href="route('admin.businesses.show', $business)" variant="secondary" size="sm">
                                                Review
                                            </x-ui.button>
                                        </div>
                                    @else
                                        <x-ui.button :href="route('admin.businesses.show', $business)" variant="secondary" size="sm">
                                            View
                                        </x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">{{ $businesses->links() }}</div>
        @endif
    </x-ui.card>
</x-console-layout>
