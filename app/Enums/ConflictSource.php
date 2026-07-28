<?php

namespace App\Enums;

/**
 * What disrupted an existing reservation.
 *
 * The SRS is internally inconsistent here: case 9.6 (owner blocks a spot over
 * existing bookings) says do NOT auto-cancel and instead flag for manual
 * resolution, while 9.8 (admin suspends a business) says DO auto-cancel, and
 * 9.9 says spot deactivation follows "the same pattern as case 8".
 *
 * Reconciled as one mechanism with a per-source policy:
 *
 *   SpotBlock / SpotDeactivation -> raise an OPEN conflict for the Owner to
 *       resolve by contacting the customer. 9.6 wins here because silently
 *       cancelling a confirmed customer booking is the trust-breaking failure
 *       mode the SRS explicitly names, and the Owner caused the clash so the
 *       Owner should own the fix.
 *
 *   BusinessSuspension -> auto-cancel and notify. The venue is genuinely
 *       unavailable and the suspended Owner cannot be trusted to resolve it.
 */
enum ConflictSource: string
{
    case SpotBlock = 'spot_block';
    case SpotDeactivation = 'spot_deactivation';
    case BusinessSuspension = 'business_suspension';

    public function label(): string
    {
        return match ($this) {
            self::SpotBlock => 'Spot blocked by owner',
            self::SpotDeactivation => 'Spot deactivated',
            self::BusinessSuspension => 'Business suspended',
        };
    }

    /**
     * Whether affected reservations are cancelled automatically, or held open
     * for the Owner to resolve by hand.
     */
    public function autoCancels(): bool
    {
        return $this === self::BusinessSuspension;
    }

    public function userMessage(): string
    {
        return match ($this) {
            self::SpotBlock => 'The venue has flagged a clash with your booking and will be in touch.',
            self::SpotDeactivation => 'This spot has been taken out of service; the venue will be in touch.',
            self::BusinessSuspension => 'This venue is temporarily unavailable, so your booking was cancelled.',
        };
    }
}
