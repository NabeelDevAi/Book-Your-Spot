@props([
    'reservation',
])

{{--
    A booking as a physical ticket stub.

    This is the emotional payoff of the entire product -- the moment a request
    becomes a thing you hold and quote at a door. Previously it was a dashed
    box around a monospace string, which is the least memorable possible
    treatment of the one artifact the customer actually came for.

    The perforation and notches are CSS masks, not images, so the stub inherits
    whatever surface it lands on and stays crisp at any zoom.

    Status leads. A stub for a pending request must not look like a confirmed
    one -- someone glancing at their phone in a car park has to be able to tell
    the difference instantly.
--}}

@php
    $status = $reservation->status;
    $isLive = $reservation->isConfirmed() || $reservation->isPending();
@endphp

<div {{ $attributes->merge(['class' => 'stub is-'.$status->value]) }}>
    <div class="stub-main">
        <div class="stub-head">
            <span class="stub-venue">{{ $reservation->business->name }}</span>
            <x-ui.badge :status="$status->value">{{ $status->label() }}</x-ui.badge>
        </div>

        <div class="stub-spot">{{ $reservation->spot->name }}</div>

        <div class="stub-when">
            <div class="stub-when-part">
                <span class="stub-when-label">Date</span>
                <span class="stub-when-value">{{ $reservation->start_datetime->format('D j M Y') }}</span>
            </div>
            <div class="stub-when-part">
                <span class="stub-when-label">Time</span>
                <span class="stub-when-value">{{ $reservation->timeRangeLabel() }}</span>
            </div>
            <div class="stub-when-part">
                <span class="stub-when-label">Total</span>
                <span class="stub-when-value">{{ $reservation->totalPriceLabel() }}</span>
            </div>
        </div>
    </div>

    {{-- The tear-off. Carries the one thing that has to be readable across a
         counter, so it gets the largest type on the page. --}}
    <div class="stub-tear">
        {{-- Exact wording matters: BookingFlowTest asserts on this string
             because the SRS requires the confirmation to tell the customer to
             quote their reference. Reword the design around it, not it. --}}
        <span class="stub-tear-label">{{ $isLive ? 'Quote this at the venue' : 'Reference' }}</span>
        <span class="stub-reference">{{ $reservation->reference }}</span>
        @if ($isLive)
            <span class="stub-tear-note">Pay when you arrive</span>
        @endif
    </div>
</div>
