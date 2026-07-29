{{--
    FR-2.6 -- occupancy at a glance.

    Each track is one spot's day. Bookings are solid, owner blocks are hatched,
    so downtime the owner created themselves is never mistaken for revenue.

    This view is where the ribbon pattern started: it was hand-rolled here as
    percentage-positioned spans over a 24-hour bar. It is now the shared
    <x-ui.ribbon> at density="dense", the same component the venue cards, the
    venue page and the booking board use. The owner and the customer are
    looking at the same object, which is the point -- when an owner blocks out
    a Tuesday afternoon, they can see exactly what a customer will see.
--}}
<x-ui.card title="Today's occupancy" subtitle="Across all your active spots">
    @if ($occupancy->isEmpty())
        <p class="text-muted text-sm">No active spots yet.</p>
    @else
        <div class="stack-2">
            @foreach ($occupancy as $row)
                <x-ui.ribbon
                    :day="$today"
                    :busy="$row['intervals']"
                    :label="$row['spot']->name"
                    :meta="$row['spot']->business->name"
                    density="dense"
                    :scale="$loop->last"
                />
            @endforeach
        </div>

        <x-slot:footer>
            <div class="ribbon-legend">
                <span><span class="ribbon-legend-swatch"></span> Free</span>
                <span><span class="ribbon-legend-swatch is-busy"></span> Booked</span>
                <span><span class="ribbon-legend-swatch is-block"></span> Blocked by you</span>
            </div>
        </x-slot:footer>
    @endif
</x-ui.card>
