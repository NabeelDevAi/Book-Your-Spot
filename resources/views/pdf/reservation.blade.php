<?php
/**
 * The booking as a printable PDF -- ticket for the customer, invoice for
 * the venue. Same document either way: there is nothing on it one side is
 * allowed to see that the other isn't (ReservationPolicy::view agrees).
 *
 * Rendered by dompdf, which only understands a plain CSS subset -- no custom
 * properties, no flexbox/grid, no @import. Colours are hardcoded here rather
 * than pulled from the app's design tokens, and the layout is table-based on
 * purpose. This file is intentionally self-contained.
 */
$statusColors = [
    'pending' => ['bg' => '#fef3c7', 'text' => '#92400e'],
    'confirmed' => ['bg' => '#dcfce7', 'text' => '#166534'],
    'completed' => ['bg' => '#dbeafe', 'text' => '#1e40af'],
    'rejected' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    'no_show' => ['bg' => '#fee2e2', 'text' => '#991b1b'],
    'expired' => ['bg' => '#f1f5f9', 'text' => '#475569'],
    'cancelled' => ['bg' => '#f1f5f9', 'text' => '#475569'],
];
$status = $reservation->status;
$color = $statusColors[$status->value] ?? $statusColors['expired'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 28px 32px; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Helvetica', 'Arial', sans-serif;
        color: #1e2126;
        font-size: 11px;
        line-height: 1.5;
    }
    .masthead {
        width: 100%;
        border-bottom: 2px solid #1e2126;
        padding-bottom: 10px;
        margin-bottom: 16px;
    }
    .masthead td { vertical-align: bottom; }
    .brand { font-size: 18px; font-weight: bold; letter-spacing: -0.02em; }
    .doc-label {
        text-align: right;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
    }
    .ticket {
        width: 100%;
        border: 1.5px solid #1e2126;
        border-radius: 6px;
        padding: 16px 18px;
        margin-bottom: 18px;
    }
    .ticket-top { width: 100%; margin-bottom: 12px; }
    .venue-name { font-size: 15px; font-weight: bold; }
    .spot-name { font-size: 11px; color: #6b7280; margin-top: 2px; }
    .status-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 10px;
        font-size: 9px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        background-color: <?= $color['bg'] ?>;
        color: <?= $color['text'] ?>;
    }
    .reference-row {
        border-top: 1px dashed #9ca3af;
        padding-top: 10px;
        margin-top: 4px;
    }
    .reference-label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
    }
    .reference-value {
        font-size: 22px;
        font-weight: bold;
        letter-spacing: 0.06em;
        font-family: 'Courier New', monospace;
    }
    table.details { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.details td {
        padding: 7px 0;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: top;
    }
    table.details tr:last-child td { border-bottom: none; }
    .label { color: #6b7280; width: 34%; }
    .value { font-weight: bold; }
    .value .sub { display: block; font-weight: normal; color: #6b7280; font-size: 10px; margin-top: 1px; }
    .section-title {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6b7280;
        margin: 16px 0 6px;
    }
    .notice {
        margin-top: 16px;
        padding: 10px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        background-color: #f9fafb;
        font-size: 10px;
        color: #4b5563;
    }
    .footer {
        margin-top: 22px;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        font-size: 9px;
        color: #9ca3af;
    }
</style>
</head>
<body>

    <table class="masthead">
        <tr>
            <td><span class="brand"><?= e(config('app.name')) ?></span></td>
            <td class="doc-label">Booking {{ $status->isTerminal() && $status !== \App\Enums\ReservationStatus::Completed ? 'record' : ($status === \App\Enums\ReservationStatus::Pending ? 'request' : 'ticket') }}</td>
        </tr>
    </table>

    <div class="ticket">
        <table class="ticket-top">
            <tr>
                <td>
                    <div class="venue-name">{{ $reservation->business->name }}</div>
                    <div class="spot-name">{{ $reservation->spot->name }} · {{ $reservation->spot->businessGame->game->name }}</div>
                </td>
                <td style="text-align: right; vertical-align: top;">
                    <span class="status-pill">{{ $status->label() }}</span>
                </td>
            </tr>
        </table>

        <div class="reference-row">
            <div class="reference-label">
                {{ $status->isCancellable() ? 'Quote this at the venue' : 'Reference' }}
            </div>
            <div class="reference-value">{{ $reservation->reference }}</div>
        </div>
    </div>

    <table class="details">
        <tr>
            <td class="label">Date</td>
            <td class="value">{{ $reservation->dateLabel() }}</td>
        </tr>
        <tr>
            <td class="label">Time</td>
            <td class="value">{{ $reservation->timeRangeLabel() }}<span class="sub">{{ $reservation->durationLabel() }}</span></td>
        </tr>
        <tr>
            <td class="label">Venue address</td>
            <td class="value">{{ $reservation->business->address }}, {{ $reservation->business->area }}</td>
        </tr>
        <tr>
            <td class="label">Venue contact</td>
            <td class="value">{{ $reservation->business->contact_number }}</td>
        </tr>
        <tr>
            <td class="label">Customer</td>
            <td class="value">
                {{ $reservation->customerDisplayName() }}
                <span class="sub">{{ $reservation->customerDisplayPhone() ?? 'No phone on record' }}</span>
            </td>
        </tr>
        <tr>
            <td class="label">Total</td>
            <td class="value">
                {{ $reservation->totalPriceLabel() }}
                <span class="sub">
                    {{ \App\Support\Money::pkr($reservation->price_amount_snapshot) }}
                    per {{ \App\Support\Money::duration($reservation->price_unit_minutes_snapshot) }}
                    · payable in cash at the venue
                </span>
            </td>
        </tr>
        @if ($reservation->customer_note)
            <tr>
                <td class="label">Note</td>
                <td class="value">{{ $reservation->customer_note }}</td>
            </tr>
        @endif
        @if ($reservation->rejection_reason_code)
            <tr>
                <td class="label">Declined because</td>
                <td class="value">
                    {{ $reservation->rejection_reason_code->label() }}
                    @if ($reservation->rejection_reason_text)
                        <span class="sub">{{ $reservation->rejection_reason_text }}</span>
                    @endif
                </td>
            </tr>
        @endif
        @if ($reservation->cancelled_at)
            <tr>
                <td class="label">Cancelled</td>
                <td class="value">
                    {{ $reservation->cancelled_at->format('j M Y, g:i A') }}
                    <span class="sub">{{ $reservation->cancellation_reason ?? 'No reason given' }}</span>
                </td>
            </tr>
        @endif
    </table>

    @if ($status->isCancellable())
        <div class="notice">
            {{ $status === \App\Enums\ReservationStatus::Pending
                ? 'Awaiting confirmation from the venue. This is not yet a confirmed booking.'
                : 'This booking is confirmed. No online payment is taken — pay the venue directly when you arrive.' }}
        </div>
    @endif

    <div class="footer">
        Generated {{ now()->format('j M Y, g:i A') }} by {{ config('app.name') }} · {{ config('app.url') }}
    </div>

</body>
</html>
