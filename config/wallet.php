<?php

/*
|--------------------------------------------------------------------------
| Wallet, payments and refunds
|--------------------------------------------------------------------------
| Every amount here is in PAISA, matching the ledger. Naming the constants
| `*_minor` is deliberate: a bare `500` in a money config is ambiguous and
| someone will eventually read it as rupees.
|
| See claude-docs/wallet-payments-plan.md for the reasoning behind each value.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Top-up limits
    |--------------------------------------------------------------------------
    | Arbitrary amounts between the two bounds. The floor keeps gateway fees
    | proportionate; the ceiling caps chargeback exposure on any single charge.
    */

    'min_topup_minor' => 50_000,        // Rs 500

    'max_topup_minor' => 5_000_000,     // Rs 50,000

    /*
    |--------------------------------------------------------------------------
    | Withdrawals (Phase 6)
    |--------------------------------------------------------------------------
    | Settlement is a manual bank transfer performed by an Admin, so the floor
    | exists to keep that effort proportionate to the amount moved.
    */

    'min_withdrawal_minor' => 100_000,  // Rs 1,000

    /*
    |--------------------------------------------------------------------------
    | Earnings maturity
    |--------------------------------------------------------------------------
    | How long after a booking ends before the Owner's earnings move from
    | `pending` to withdrawable.
    |
    | This window is the reason a clawback is never needed. Refunds resolve
    | against pending earnings, which the Owner cannot have withdrawn yet; if
    | earnings settled at approval time, an Owner could bank the money for a
    | booking three weeks out and then have the customer cancel.
    */

    'maturity_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Refund tiers (Phase 4)
    |--------------------------------------------------------------------------
    | Applied to a customer-initiated cancellation, matched top-down on hours
    | remaining before the slot starts. Snapshotted onto the reservation at
    | booking time so a later policy change cannot rewrite what the customer
    | agreed to -- the same rule the price snapshot already follows (SRS 9.10).
    |
    | Owner- and Admin-initiated cancellations always refund 100% regardless of
    | timing, and that asymmetry is not configurable: an Owner must never be
    | able to cancel a paid booking at no cost to themselves.
    */

    'refund_tiers' => [
        ['min_hours_before' => 24, 'refund_percent' => 100],
        ['min_hours_before' => 2, 'refund_percent' => 50],
        ['min_hours_before' => 0, 'refund_percent' => 0],
    ],

];
