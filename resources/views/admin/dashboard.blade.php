<x-console-layout title="Overview" context="admin">
    <x-ui.page-header
        title="Platform overview"
        description="What needs your attention, and how the platform is doing."
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.reports.index')" variant="secondary" icon="chart">
                Full reports
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Queues first: this page answers "what is waiting on me?" --}}
    <div class="stat-row" data-reveal-group>
        <x-ui.stat
            label="Venues awaiting review"
            :value="$pendingBusinesses"
            :meta="$pendingBusinesses > 0 ? 'Owners can\'t take bookings yet' : 'Queue clear'"
            :tone="$pendingBusinesses > 0 ? 'attention' : null"
            :href="route('admin.businesses.index', ['status' => 'pending_review'])"
        />
        <x-ui.stat
            label="Possible duplicates"
            :value="$flaggedDuplicates"
            meta="Matching phone or address"
            :tone="$flaggedDuplicates > 0 ? 'attention' : null"
            :href="route('admin.businesses.index', ['duplicates' => 1])"
        />
        <x-ui.stat
            label="Password requests"
            :value="$openPasswordRequests"
            meta="Users locked out"
            :tone="$openPasswordRequests > 0 ? 'danger' : null"
            :href="route('admin.password-requests.index')"
        />
        <x-ui.stat
            label="Suspended venues"
            :value="$suspendedBusinesses"
            meta="Hidden from search"
            :href="route('admin.businesses.index', ['status' => 'suspended'])"
        />
    </div>

    <div class="stat-row" data-reveal-group>
        <x-ui.stat label="Reservations" :value="number_format($headline['total_reservations'])" meta="All time" />
        <x-ui.stat label="Request to booking" :value="$headline['conversion_rate'].'%'" meta="Reached confirmed" />
        <x-ui.stat label="Booked today" :value="$todaysReservations" meta="Pending or confirmed" />
        <x-ui.stat
            label="Customers"
            :value="number_format($headline['total_users'])"
            :meta="number_format($headline['total_owners']).' owners'"
        />
    </div>

    <div class="dashboard-grid">
        <div class="stack-6">
            <x-ui.card title="Reservations, last 14 days">
                <x-chart.line
                    :labels="$overTime->map(fn ($row) => $row['date']->format('j M'))->all()"
                    :series="[[
                        'name' => 'Reservations',
                        'color' => 'var(--color-brand-500)',
                        'points' => $overTime->pluck('total')->all(),
                    ]]"
                />
            </x-ui.card>

            <x-ui.card title="Awaiting review" flush>
                @if ($awaitingReview->isEmpty())
                    <x-ui.empty-state icon="check-circle" title="Review queue is clear">
                        New venue submissions will appear here.
                    </x-ui.empty-state>
                @else
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr><th>Venue</th><th>Owner</th><th class="cell-numeric">Spots</th><th>Waiting</th><th class="cell-actions"></th></tr>
                            </thead>
                            <tbody>
                                @foreach ($awaitingReview as $business)
                                    <tr class="is-attention">
                                        <td>
                                            <a href="{{ route('admin.businesses.show', $business) }}" class="cell-primary link">
                                                {{ $business->name }}
                                            </a>
                                            <div class="cell-secondary">{{ $business->area }}</div>
                                        </td>
                                        <td>{{ $business->owner->name }}</td>
                                        <td class="cell-numeric">{{ $business->spots_count }}</td>
                                        <td class="cell-secondary">{{ $business->created_at->diffForHumans() }}</td>
                                        <td class="cell-actions">
                                            <x-ui.button :href="route('admin.businesses.show', $business)" variant="secondary" size="sm">
                                                Review
                                            </x-ui.button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <x-ui.card title="Recent activity">
            @if ($recentActivity->isEmpty())
                <p class="text-muted text-sm">Nothing logged yet.</p>
            @else
                <div class="stack-3">
                    @foreach ($recentActivity as $log)
                        <div>
                            <div class="text-sm font-medium">{{ str_replace('_', ' ', $log->action) }}</div>
                            <div class="text-xs text-muted">
                                {{ $log->actorLabel() }} · {{ $log->created_at->diffForHumans() }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <x-slot:footer>
                <a href="{{ route('admin.audit.index') }}" class="link text-sm">See the full audit log</a>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-console-layout>
