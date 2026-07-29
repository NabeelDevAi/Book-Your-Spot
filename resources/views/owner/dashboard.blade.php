<x-console-layout title="Dashboard" context="owner">
    <x-ui.page-header
        title="Good day, {{ auth()->user()->name }}"
        :description="$today->format('l j F Y')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('owner.businesses.create')" variant="secondary" icon="plus">
                Add venue
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($businesses->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="building" title="Add your first venue">
                List your venue and the spots people can book — tables, courts or rooms.
                Our team reviews new venues before they go live.

                <x-slot:action>
                    <x-ui.button :href="route('owner.businesses.create')" variant="primary" icon="plus">
                        Add a venue
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        {{-- Decisions owed lead, because that is what an owner opens this page
             to deal with (FR-2.6, NFR-5). --}}
        <div class="stat-row" data-reveal-group>
            <x-ui.stat
                label="Awaiting your decision"
                :value="$pendingCount"
                :meta="$pendingCount > 0 ? 'Respond before they expire' : 'Nothing to action'"
                :tone="$pendingCount > 0 ? 'attention' : null"
                :href="route('owner.reservations.index', ['status' => 'pending'])"
            />
            <x-ui.stat
                label="Clashes to resolve"
                :value="$openConflicts"
                :meta="$openConflicts > 0 ? 'Customers waiting to hear from you' : 'All clear'"
                :tone="$openConflicts > 0 ? 'danger' : null"
                :href="route('owner.conflicts.index')"
            />
            <x-ui.stat label="Upcoming confirmed" :value="$upcomingCount" meta="Bookings ahead" />
            <x-ui.stat
                label="Completed this month"
                :value="$completedThisMonth"
                :meta="$noShowsThisMonth > 0 ? $noShowsThisMonth.' recorded as no-shows' : 'No no-shows'"
            />
        </div>

        <div class="dashboard-grid">
            <div class="stack-6">
                <x-ui.card title="Today's bookings" :subtitle="$today->format('D j M')" flush>
                    @if ($todaysBookings->isEmpty())
                        <x-ui.empty-state icon="calendar" title="Nothing booked today">
                            Bookings for today will appear here as customers request them.
                        </x-ui.empty-state>
                    @else
                        <div class="table-wrap">
                            <table class="table table-compact">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>Spot</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th class="cell-numeric">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($todaysBookings as $booking)
                                        <tr>
                                            <td class="cell-primary">{{ $booking->timeRangeLabel() }}</td>
                                            <td>{{ $booking->spot->name }}</td>
                                            <td>
                                                <a href="{{ route('owner.reservations.show', $booking) }}" class="link">
                                                    {{ $booking->user->name }}
                                                </a>
                                            </td>
                                            <td>
                                                <x-ui.badge :status="$booking->status->value">
                                                    {{ $booking->status->label() }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="cell-numeric">{{ $booking->totalPriceLabel() }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>

                @include('owner.partials.occupancy', ['occupancy' => $occupancy, 'today' => $today])
            </div>

            <div class="stack-6">
                <x-ui.card title="Needs a decision">
                    @if ($pending->isEmpty())
                        <p class="text-muted text-sm">Nothing waiting on you. Nice.</p>
                    @else
                        <div class="stack-3">
                            @foreach ($pending as $request)
                                <div class="stack-1" style="padding-bottom: var(--space-3); border-bottom: 1px solid var(--border-color);">
                                    <div class="split">
                                        <a href="{{ route('owner.reservations.show', $request) }}" class="link font-medium">
                                            {{ $request->user->name }}
                                        </a>
                                        @if ($request->user->no_show_count > 0)
                                            <x-ui.badge :variant="$request->user->isRepeatNoShow() ? 'danger' : 'warning'">
                                                {{ $request->user->no_show_count }} no-show{{ $request->user->no_show_count === 1 ? '' : 's' }}
                                            </x-ui.badge>
                                        @endif
                                    </div>

                                    <div class="text-sm text-muted">
                                        {{ $request->spot->name }} · {{ $request->dateLabel() }},
                                        {{ $request->timeRangeLabel() }}
                                    </div>

                                    <div class="text-xs text-warning">
                                        Expires {{ $request->response_deadline->diffForHumans() }}
                                    </div>

                                    <form method="POST" action="{{ route('owner.reservations.approve', $request) }}"
                                          style="margin-top: var(--space-2);">
                                        @csrf
                                        <x-ui.button type="submit" variant="success" size="sm" icon="check">
                                            Approve
                                        </x-ui.button>
                                    </form>
                                </div>
                            @endforeach
                        </div>

                        <x-slot:footer>
                            <a href="{{ route('owner.reservations.index', ['status' => 'pending']) }}" class="link text-sm">
                                See all {{ $pendingCount }} pending
                            </a>
                        </x-slot:footer>
                    @endif
                </x-ui.card>

                <x-ui.card title="Your venues">
                    <div class="stack-3">
                        @foreach ($businesses as $business)
                            <div class="split">
                                <div>
                                    <a href="{{ route('owner.businesses.spots.index', $business) }}" class="link font-medium">
                                        {{ $business->name }}
                                    </a>
                                    <div class="text-xs text-muted">
                                        {{ $business->spots_count }} {{ Str::plural('spot', $business->spots_count) }}
                                    </div>
                                </div>
                                <x-ui.badge :variant="$business->status->badge()">
                                    {{ $business->status->label() }}
                                </x-ui.badge>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            </div>
        </div>
    @endif
</x-console-layout>
