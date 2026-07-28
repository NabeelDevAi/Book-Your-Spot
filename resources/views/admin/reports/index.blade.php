<x-console-layout title="Reports" context="admin">
    <x-ui.page-header
        title="Reports"
        description="Platform-wide activity and venue quality signals (SRS section 8)."
    />

    <form method="GET" class="filter-bar">
        <x-ui.field label="Bookings from" name="from">
            <x-ui.input name="from" type="date" :value="request('from')" />
        </x-ui.field>

        <x-ui.field label="To" name="to">
            <x-ui.input name="to" type="date" :value="request('to')" />
        </x-ui.field>

        <x-ui.field label="Trend window" name="days">
            <x-ui.select name="days" :selected="$days">
                @foreach ([7, 30, 90, 180] as $option)
                    <option value="{{ $option }}" @selected($days === $option)>Last {{ $option }} days</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Apply</x-ui.button>
            @if (request()->hasAny(['from', 'to', 'days']))
                <x-ui.button :href="route('admin.reports.index')" variant="ghost">Reset</x-ui.button>
            @endif
        </div>
    </form>

    <div class="stat-row">
        <x-ui.stat
            label="Reservations"
            :value="number_format($headline['total_reservations'])"
            meta="All statuses in range"
        />
        <x-ui.stat
            label="Request to booking"
            :value="$headline['conversion_rate'].'%'"
            :meta="number_format($headline['confirmed_reservations']).' reached confirmed'"
        />
        <x-ui.stat
            label="Booking value"
            :value="\App\Support\Money::compact($headline['gross_booking_value'])"
            meta="Collected at venues, not by us"
        />
        <x-ui.stat
            label="Active venues"
            :value="$headline['active_businesses']"
            :meta="number_format($headline['total_owners']).' owners registered'"
        />
    </div>

    <div class="stack-6">
        <x-ui.card title="Reservations over time" :subtitle="'Requests per day, last '.$days.' days'">
            <x-chart.line
                :labels="$overTime->map(fn ($row) => $row['date']->format('j M'))->all()"
                :series="[[
                    'name' => 'Reservations',
                    'color' => 'var(--color-brand-500)',
                    'points' => $overTime->pluck('total')->all(),
                ]]"
            />
        </x-ui.card>

        <div class="grid-2 grid-gap-6" style="align-items: start;">
            <x-ui.card title="Most booked categories" subtitle="Which games actually get played">
                <x-chart.bars :data="$byGame->all()" />
            </x-ui.card>

            <x-ui.card title="Busiest areas" subtitle="Where demand is concentrated">
                <x-chart.bars :data="$byArea->all()" color="var(--color-accent-500)" />
            </x-ui.card>
        </div>

        <x-ui.card title="Reservation outcomes" subtitle="Where requests end up">
            @php
                $total = max(1, array_sum($reservationsByStatus));
                $tones = [
                    'confirmed' => 'var(--color-success-solid)',
                    'completed' => 'var(--color-info-solid)',
                    'pending' => 'var(--color-warning-solid)',
                    'rejected' => 'var(--color-danger-solid)',
                    'no_show' => '#8b1c4d',
                    'expired' => 'var(--color-gray-400)',
                    'cancelled' => 'var(--color-gray-300)',
                ];
            @endphp

            <div class="status-strip">
                @foreach ($reservationsByStatus as $status => $count)
                    @continue($count === 0)
                    <span
                        class="status-strip-segment"
                        style="width: {{ round(($count / $total) * 100, 2) }}%; background: {{ $tones[$status] ?? 'var(--color-gray-400)' }};"
                        title="{{ ucfirst(str_replace('_', ' ', $status)) }}: {{ $count }}"
                    >{{ round(($count / $total) * 100) >= 6 ? $count : '' }}</span>
                @endforeach
            </div>

            <div class="status-strip-legend">
                @foreach ($reservationsByStatus as $status => $count)
                    <span class="status-strip-legend-item">
                        <span class="chart-legend-swatch" style="background: {{ $tones[$status] ?? 'var(--color-gray-400)' }};"></span>
                        {{ ucfirst(str_replace('_', ' ', $status)) }} <strong>{{ number_format($count) }}</strong>
                    </span>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card
            title="Venue quality signals"
            subtitle="Early warning for reliability and fraud — venues with at least 5 bookings"
            flush
        >
            @if ($quality->isEmpty())
                <x-ui.empty-state icon="chart" title="Not enough booking history yet">
                    Quality rates need a handful of bookings per venue before they mean anything.
                    One rejection out of one request is 100%, and tells you nothing.
                </x-ui.empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Venue</th>
                                <th class="cell-numeric">Bookings</th>
                                <th class="cell-numeric">Declined</th>
                                <th class="cell-numeric">Never answered</th>
                                <th class="cell-numeric">No-shows</th>
                                <th class="cell-numeric">Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($quality as $row)
                                @php
                                    $rate = fn (int $value) => $value >= 40 ? 'is-poor' : ($value >= 20 ? 'is-watch' : 'is-good');
                                @endphp
                                <tr class="{{ $row['rejection_rate'] + $row['unanswered_rate'] >= 50 ? 'is-danger' : '' }}">
                                    <td>
                                        <a href="{{ route('admin.businesses.show', $row['id']) }}" class="cell-primary link">
                                            {{ $row['name'] }}
                                        </a>
                                        <div class="cell-secondary">{{ $row['area'] }}</div>
                                    </td>
                                    <td class="cell-numeric">{{ $row['total'] }}</td>
                                    <td class="cell-numeric">
                                        <span class="rate {{ $rate($row['rejection_rate']) }}">{{ $row['rejection_rate'] }}%</span>
                                        <div class="cell-secondary">{{ $row['rejected'] }}</div>
                                    </td>
                                    <td class="cell-numeric">
                                        {{-- Distinct from declining: the owner simply never
                                             replied and the customer waited for nothing. --}}
                                        <span class="rate {{ $rate($row['unanswered_rate']) }}">{{ $row['unanswered_rate'] }}%</span>
                                        <div class="cell-secondary">{{ $row['expired'] }}</div>
                                    </td>
                                    <td class="cell-numeric">
                                        <span class="rate {{ $rate($row['no_show_rate']) }}">{{ $row['no_show_rate'] }}%</span>
                                        <div class="cell-secondary">{{ $row['no_shows'] }}</div>
                                    </td>
                                    <td class="cell-numeric">{{ $row['completed'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <div class="grid-2 grid-gap-6" style="align-items: start;">
            <x-ui.card title="Signups over time">
                <x-chart.line
                    :labels="$signups->map(fn ($row) => $row['date']->format('j M'))->all()"
                    :series="[
                        ['name' => 'Customers', 'color' => 'var(--color-brand-500)', 'points' => $signups->pluck('users')->all()],
                        ['name' => 'Owners', 'color' => 'var(--color-accent-500)', 'points' => $signups->pluck('owners')->all()],
                    ]"
                />
            </x-ui.card>

            <x-ui.card title="Venues by status">
                <x-chart.bars
                    :data="collect($businessesByStatus)->map(fn ($total, $status) => [
                        'label' => ucfirst(str_replace('_', ' ', $status)),
                        'total' => $total,
                    ])->values()->all()"
                    color="var(--color-brand-400)"
                />
            </x-ui.card>
        </div>
    </div>
</x-console-layout>
