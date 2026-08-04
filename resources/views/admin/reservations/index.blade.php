<x-console-layout title="Reservations" context="admin">
    <x-ui.page-header
        title="All reservations"
        description="Every booking on the platform (FR-3.5)."
    />

    <form method="GET" class="filter-bar">
        <x-ui.field label="Search" name="search" style="flex: 1 1 auto;">
            {{-- Reference first: an admin taking a support call has the code the
                 customer read off their screen. --}}
            <x-ui.input name="search" :value="request('search')" placeholder="Reference, customer name, email or phone" />
        </x-ui.field>

        <x-ui.field label="Venue" name="business">
            <x-ui.select name="business" placeholder="All venues">
                @foreach ($businesses as $business)
                    <option value="{{ $business->id }}" @selected((int) request('business') === $business->id)>
                        {{ $business->name }}
                    </option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Area" name="area">
            <x-ui.select name="area" placeholder="All areas">
                @foreach ($areas as $area)
                    <option value="{{ $area }}" @selected(request('area') === $area)>{{ $area }}</option>
                @endforeach
            </x-ui.select>
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

        <x-ui.field label="From" name="from">
            <x-ui.input name="from" type="date" :value="request('from')" />
        </x-ui.field>

        <x-ui.field label="To" name="to">
            <x-ui.input name="to" type="date" :value="request('to')" />
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Filter</x-ui.button>
            @if (collect(request()->query())->filter()->isNotEmpty())
                <x-ui.button :href="route('admin.reservations.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.card flush>
        @if ($reservations->isEmpty())
            <x-ui.empty-state icon="calendar" title="No reservations match those filters">
                Try clearing the date range or status.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Customer</th>
                            <th>Venue</th>
                            <th>When</th>
                            <th class="cell-numeric">Price</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reservations as $reservation)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.reservations.show', $reservation) }}" class="cell-primary link mono">
                                        {{ $reservation->reference }}
                                    </a>
                                </td>
                                <td>
                                    @if ($reservation->user)
                                        <a href="{{ route('admin.users.show', $reservation->user) }}" class="link">
                                            {{ $reservation->user->name }}
                                        </a>
                                    @else
                                        {{ $reservation->customerDisplayName() }}
                                    @endif
                                    <div class="cell-secondary">{{ $reservation->customerDisplayPhone() }}</div>
                                </td>
                                <td>
                                    <a href="{{ route('admin.businesses.show', $reservation->business) }}" class="link">
                                        {{ $reservation->business->name }}
                                    </a>
                                    <div class="cell-secondary">
                                        {{ $reservation->spot->name }} · {{ $reservation->business->area }}
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-primary">{{ $reservation->dateLabel() }}</div>
                                    <div class="cell-secondary">{{ $reservation->timeRangeLabel() }}</div>
                                </td>
                                <td class="cell-numeric">{{ $reservation->totalPriceLabel() }}</td>
                                <td>
                                    <x-ui.badge :status="$reservation->status->value">
                                        {{ $reservation->status->label() }}
                                    </x-ui.badge>
                                    @if ($reservation->is_late_cancellation)
                                        <div class="cell-secondary text-warning">Late</div>
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
