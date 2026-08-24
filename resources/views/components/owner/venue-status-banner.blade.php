@props(['business'])

{{--
    Shown at the top of every venue-scoped Owner page (business profile, categories,
    spots, spot edit, blocked times). Approval never blocks setup -- an Owner can
    build out the whole venue while it sits in the review queue -- so this exists
    purely to keep the venue's real-world status impossible to miss while they work.
--}}

@if ($business->status === \App\Enums\BusinessStatus::Rejected)
    <x-ui.alert variant="danger" title="Rejected — resubmit to continue" style="margin-bottom: var(--space-5);">
        <strong>{{ $business->rejection_reason }}</strong>
        <br>Fix the issue on the venue's profile and save — it goes back into the review queue
        automatically. Everything below stays fully editable while you do.
    </x-ui.alert>
@elseif ($business->isPendingReview())
    <x-ui.alert variant="warning" title="Awaiting approval — hidden from customers" style="margin-bottom: var(--space-5);">
        <strong>{{ $business->name }}</strong> won't appear in search or accept bookings until an
        admin approves it. That's the only thing on hold — keep adding categories, spots, pricing
        and photos now, and it's all ready to go live the moment approval comes through.
    </x-ui.alert>
@elseif ($business->isSuspended())
    <x-ui.alert variant="danger" title="Suspended" style="margin-bottom: var(--space-5);">
        <strong>{{ $business->suspension_reason }}</strong>
        <br>Hidden from customers and can't take new bookings until an admin lifts the suspension.
        You can still update its setup while this is resolved.
    </x-ui.alert>
@endif
