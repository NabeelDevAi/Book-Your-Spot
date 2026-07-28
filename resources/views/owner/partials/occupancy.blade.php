{{--
    FR-2.6 -- occupancy at a glance.

    Each track is one spot's day rendered as a 24-hour bar. Bookings are solid,
    owner blocks are hatched, so downtime the owner created themselves is never
    mistaken for revenue.
--}}
<x-ui.card title="Today's occupancy" subtitle="Across all your active spots">
    @if ($occupancy->isEmpty())
        <p class="text-muted text-sm">No active spots yet.</p>
    @else
        <div class="occupancy">
            @foreach ($occupancy as $row)
                @php
                    $spot = $row['spot'];
                @endphp
                <div class="occupancy-row">
                    <div class="occupancy-label">
                        {{ $spot->name }}
                        <div class="occupancy-label-venue">{{ $spot->business->name }}</div>
                    </div>

                    <div class="occupancy-track">
                        @foreach ($row['intervals'] as $interval)
                            @php
                                // Clamp to the day so an overnight booking doesn't
                                // render off the end of its own track.
                                $dayStart = $today->copy()->startOfDay();
                                $start = $interval['start']->lessThan($dayStart) ? $dayStart : $interval['start'];
                                $end = $interval['end']->greaterThan($dayStart->copy()->addDay())
                                    ? $dayStart->copy()->addDay()
                                    : $interval['end'];

                                $left = ($start->diffInMinutes($dayStart, absolute: true) / 1440) * 100;
                                $width = max(0.6, ($start->diffInMinutes($end, absolute: true) / 1440) * 100);
                            @endphp

                            <span
                                class="occupancy-block {{ ($interval['type'] ?? 'booking') === 'block' ? 'is-block' : '' }}"
                                style="left: {{ round($left, 2) }}%; width: {{ round($width, 2) }}%;"
                                title="{{ $start->format('g:i A') }} – {{ $end->format('g:i A') }}"
                            ></span>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="occupancy-scale">
                <span></span>
                <span class="occupancy-scale-marks">
                    @foreach (['12 AM', '6 AM', '12 PM', '6 PM', '12 AM'] as $mark)
                        <span>{{ $mark }}</span>
                    @endforeach
                </span>
            </div>
        </div>

        <x-slot:footer>
            <div class="occupancy-legend">
                <span><span class="occupancy-legend-swatch" style="background: var(--color-brand-500);"></span>Booked</span>
                <span><span class="occupancy-legend-swatch is-block" style="background: var(--color-gray-400);"></span>Blocked by you</span>
            </div>
        </x-slot:footer>
    @endif
</x-ui.card>
