<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Services\Booking\ReservationInvoicePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The booking as a PDF -- ticket for the customer, invoice for the owner.
 *
 * Shared by both consoles rather than duplicated per role: ReservationPolicy
 * already grants `view` to both the customer and the venue owner, which is
 * exactly who is allowed to see this document. Reached from three places --
 * `bookings.invoice` (customer, auth), `owner.reservations.invoice` (owner,
 * auth) and `bookings.invoice.shared` (the signed WhatsApp link, no login).
 */
class ReservationInvoiceController extends Controller
{
    public function __construct(private readonly ReservationInvoicePdf $pdf) {}

    public function download(Request $request, Reservation $reservation): Response
    {
        $this->authorize('view', $reservation);

        return $this->stream($reservation);
    }

    /**
     * No policy check here -- the `signed` route middleware is the access
     * control. Anyone holding the link (a customer's WhatsApp contact, a
     * venue forwarding a confirmation) can open it without an account, which
     * is the entire point of sharing it that way.
     */
    public function shared(Reservation $reservation): Response
    {
        return $this->stream($reservation);
    }

    private function stream(Reservation $reservation): Response
    {
        return $this->pdf->build($reservation)
            ->stream($reservation->invoiceFilename());
    }
}
