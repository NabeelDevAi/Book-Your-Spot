<?php

namespace App\Services\Booking;

use App\Models\Reservation;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * The downloadable/shareable PDF for a single booking.
 *
 * One builder shared by the customer-facing route, the owner console route
 * and the signed WhatsApp link (bookings.invoice.shared) -- all three show
 * the exact same document, so the rendering lives in one place rather than
 * three controllers each half-reinventing it.
 */
class ReservationInvoicePdf
{
    public function build(Reservation $reservation): PdfDocument
    {
        $reservation->loadMissing(['spot.businessGame.game', 'business', 'user']);

        return Pdf::loadView('pdf.reservation', ['reservation' => $reservation])
            ->setPaper('a5', 'portrait');
    }
}
