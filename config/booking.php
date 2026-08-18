<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Owner response window
    |--------------------------------------------------------------------------
    | How long an Owner has to approve or reject a `pending` reservation before
    | it auto-expires and the slot is released (SRS 9.2, FR-4.7).
    |
    | The rule, as agreed for V1:
    |
    |   if (now < start - response_lead_hours)
    |       deadline = start - response_lead_hours
    |   else
    |       deadline = min(now + response_window_hours, start - late_response_floor_minutes)
    |
    |   deadline is then clamped to at least (now + min_response_buffer_minutes)
    |   and never past the slot start, so a late booking can never be created
    |   already-expired.
    |
    | Example: a request for Saturday 8pm made on Thursday expires Saturday 8am.
    | A request for 8pm made at 3pm the same day expires at 5pm.
    */

    'response_lead_hours' => 12,

    'response_window_hours' => 2,

    'late_response_floor_minutes' => 60,

    'min_response_buffer_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Cancellation
    |--------------------------------------------------------------------------
    | Users may cancel at any time (there is no payment to forfeit in V1), but
    | a cancellation inside this window is flagged as a late cancellation so
    | Owners and Admin can see the pattern (SRS FR-4.9, 9.3).
    */

    'cancellation_cutoff_hours' => 1,

    /*
    |--------------------------------------------------------------------------
    | Booking window
    |--------------------------------------------------------------------------
    | How far ahead a slot may be booked, and how close to the start time a
    | request may still be submitted. The minimum lead keeps the deadline
    | calculation above well-defined and gives the Owner a usable window.
    */

    'max_advance_days' => 30,

    'min_booking_lead_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Pending request caps
    |--------------------------------------------------------------------------
    | Stops one User tying up several Owners' response queues speculatively
    | (SRS 9.18). Note that multiple Users MAY hold pending requests on the
    | same slot -- the first approval wins and the rest are auto-rejected --
    | so this cap is per User, not per slot.
    */

    'max_pending_per_user' => 3,

    /*
    |--------------------------------------------------------------------------
    | Reminders
    |--------------------------------------------------------------------------
    | How long before a confirmed booking starts to send the reminder
    | notification (SRS FR-5.1).
    */

    'reminder_hours_before' => 2,

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    */

    'max_business_images' => 5,

    'max_spot_images' => 5,

    'max_image_kilobytes' => 4096,

    // Videos get their own cap, separate from the photo cap above -- a venue
    // can carry its full 5 photos AND up to 2 walkthrough clips.
    'max_business_videos' => 2,

    'max_spot_videos' => 2,

    'max_video_kilobytes' => 51200, // 50 MB

    // Extensions for the `mimes:` validation rule, NOT MIME types -- .mov
    // already covers QuickTime files, there is no ".quicktime" extension.
    'allowed_video_mimes' => ['mp4', 'webm', 'mov'],

    /*
    |--------------------------------------------------------------------------
    | Spot pricing & duration guardrails
    |--------------------------------------------------------------------------
    | Bounds applied when an Owner defines a Spot, so nobody creates a table
    | billed in 7-minute blocks. There is deliberately no "maximum booking"
    | concept any more -- how long a Spot can be booked for is bounded only by
    | its minimum and by how much of that day's operating hours are free
    | (BookingValidator + AvailabilityService). This ceiling exists purely as
    | a sanity bound on raw minute inputs (the minimum-length field, the
    | search filter) so nobody types in a nonsense number of minutes.
    */

    'allowed_price_unit_minutes' => [5, 10, 15, 20, 30, 45, 60, 90, 120],

    'duration_input_ceiling_minutes' => 1440,

    /*
    |--------------------------------------------------------------------------
    | Weekend & holiday pricing
    |--------------------------------------------------------------------------
    | A Spot bills its `weekend_price_amount` (falling back to the weekday
    | `price_amount` when unset) on any date that is a weekend day, a listed
    | Holiday, or the calendar day immediately before a listed Holiday.
    */

    'weekend_days' => [\Carbon\CarbonInterface::SATURDAY, \Carbon\CarbonInterface::SUNDAY],

    /*
    |--------------------------------------------------------------------------
    | Reference codes
    |--------------------------------------------------------------------------
    | Human-readable booking reference, quoted by the customer at the venue
    | since payment is collected in person.
    */

    'reference_prefix' => 'V365',

    /*
    |--------------------------------------------------------------------------
    | Quality signals
    |--------------------------------------------------------------------------
    | A User at or above this many no-show flags is surfaced to the Owner at
    | approval time -- the only deterrent available without payments (SRS 9.12).
    */

    'no_show_warning_threshold' => 2,

];
