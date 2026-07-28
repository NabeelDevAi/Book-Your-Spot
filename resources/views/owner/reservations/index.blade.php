<x-console-layout title="Bookings" context="owner">
    <x-ui.page-header
        title="Bookings"
        description="Every request and confirmed booking across your venues."
    >
        <x-slot:actions>
            @if ($pendingCount > 0)
                <x-ui.button
                    :href="route('owner.reservations.index', ['status' => 'pending'])"
                    variant="accent"
                    icon="bell"
                >{{ $pendingCount }} awaiting your decision</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form method="GET" class="filter-bar">
        @if ($businesses->count() > 1)
            <x-ui.field label="Venue" name="business">
                <x-ui.select name="business" placeholder="All venues">
                    @foreach ($businesses as $business)
                        <option value="{{ $business->id }}" @selected((int) ($filters['business'] ?? 0) === $business->id)>
                            {{ $business->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif

        <x-ui.field label="Status" name="status">
            <x-ui.select name="status" placeholder="Any status">
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected(($filters['status'] ?? null) === $status->value)>
                        {{ $status->label() }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Spot" name="spot">
            <x-ui.select name="spot" placeholder="All spots">
                @foreach ($spots as $spot)
                    <option value="{{ $spot->id }}" @selected((int) ($filters['spot'] ?? 0) === $spot->id)>
                        {{ $spot->name }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="From" name="from">
            <x-ui.input name="from" type="date" :value="$filters['from'] ?? null" />
        </x-ui.field>

        <x-ui.field label="To" name="to">
            <x-ui.input name="to" type="date" :value="$filters['to'] ?? null" />
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Filter</x-ui.button>
            @if (collect($filters)->filter()->isNotEmpty())
                <x-ui.button :href="route('owner.reservations.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.card flush>
        @if ($reservations->isEmpty())
            <x-ui.empty-state icon="calendar" title="No bookings match those filters">
                Try clearing the status or date filters to see everything.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Spot</th>
                            <th>When</th>
                            <th class="cell-numeric">Price</th>
                            <th>Status</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservations as $reservation)
                            <tr class="{{ $reservation->open_conflicts_count > 0 ? 'is-danger' : ($reservation->isPending() ? 'is-attention' : '') }}">
                                <td>
                                    <a href="{{ route('owner.reservations.show', $reservation) }}" class="cell-primary link mono">
                                        {{ $reservation->reference }}
                                    </a>
                                    @if ($businesses->count() > 1)
                                        <div class="cell-secondary">{{ $reservation->business->name }}</div>
                                    @endif
                                </td>

                                <td>
                                    <div class="cell-primary">{{ $reservation->user->name }}</div>
                                    <div class="cell-secondary">
                                        {{ $reservation->user->phone }}
                                        {{-- SRS 9.12: surfaced right where the decision is made. --}}
                                        @if ($reservation->user->no_show_count > 0)
                                            <x-ui.badge :variant="$reservation->user->isRepeatNoShow() ? 'danger' : 'warning'">
                                                {{ $reservation->user->no_show_count }} no-show{{ $reservation->user->no_show_count === 1 ? '' : 's' }}
                                            </x-ui.badge>
                                        @endif
                                    </div>
                                </td>

                                <td>
                                    <div class="cell-primary">{{ $reservation->spot->name }}</div>
                                    <div class="cell-secondary">{{ $reservation->spot->businessGame->game->name }}</div>
                                </td>

                                <td>
                                    <div class="cell-primary">{{ $reservation->dateLabel() }}</div>
                                    <div class="cell-secondary">
                                        {{ $reservation->timeRangeLabel() }} · {{ $reservation->durationLabel() }}
                                    </div>
                                </td>

                                <td class="cell-numeric">{{ $reservation->totalPriceLabel() }}</td>

                                <td>
                                    <x-ui.badge :status="$reservation->status->value">
                                        {{ $reservation->status->label() }}
                                    </x-ui.badge>

                                    @if ($reservation->open_conflicts_count > 0)
                                        <div class="cell-secondary text-danger">Clash needs resolving</div>
                                    @elseif ($reservation->isPending())
                                        <div class="cell-secondary">
                                            Expires {{ $reservation->response_deadline->diffForHumans() }}
                                        </div>
                                    @elseif ($reservation->is_late_cancellation)
                                        <div class="cell-secondary text-warning">Late cancellation</div>
                                    @endif
                                </td>

                                <td class="cell-actions">
                                    @if ($reservation->isPending())
                                        {{-- One click to approve, straight from the list (NFR-5). --}}
                                        <div class="btn-group">
                                            <form method="POST" action="{{ route('owner.reservations.approve', $reservation) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="success" size="sm" icon="check">
                                                    Approve
                                                </x-ui.button>
                                            </form>
                                            <x-ui.button
                                                :href="route('owner.reservations.show', $reservation)"
                                                variant="secondary" size="sm"
                                            >Review</x-ui.button>
                                        </div>
                                    @else
                                        <x-ui.button
                                            :href="route('owner.reservations.show', $reservation)"
                                            variant="secondary" size="sm"
                                        >View</x-ui.button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">{{ $reservations->links() }}</div>
        @endif
    </x-ui.card>
</x-console-layout>
