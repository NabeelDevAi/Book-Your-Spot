<?php

namespace App\Services\Business;

use App\Models\Business;

/**
 * SRS 9.11 -- flags likely duplicate venue registrations for Admin review.
 *
 * Deliberately a flag, not a block. One owner may genuinely run two venues from
 * adjacent units in the same building, and a family may share a landline. Hard
 * blocking would turn a moderation signal into a support ticket; flagging lets
 * an Admin glance at it and approve both if they are real.
 */
class DuplicateDetector
{
    /**
     * Returns a human-readable reason if this Business looks like a duplicate,
     * or null if it looks clean.
     */
    public function inspect(Business $business): ?string
    {
        $reasons = [];

        if ($match = $this->matchingContactNumber($business)) {
            $reasons[] = "Contact number matches \"{$match->name}\".";
        }

        if ($match = $this->matchingAddress($business)) {
            $reasons[] = "Address closely matches \"{$match->name}\".";
        }

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    /** Apply the flag (or clear it) on the given Business. */
    public function flag(Business $business): void
    {
        $reason = $this->inspect($business);

        $business->forceFill([
            'duplicate_flagged' => $reason !== null,
            'duplicate_note' => $reason,
        ])->save();
    }

    private function matchingContactNumber(Business $business): ?Business
    {
        $normalised = $this->normaliseNumber($business->contact_number);

        if ($normalised === '') {
            return null;
        }

        return Business::query()
            ->whereKeyNot($business->getKey())
            // Strip formatting on both sides so "+92 21 3584 0001" and
            // "+922135840001" are recognised as the same line.
            ->whereRaw(
                "REPLACE(REPLACE(REPLACE(REPLACE(contact_number, ' ', ''), '-', ''), '(', ''), ')', '') = ?",
                [$normalised]
            )
            ->first();
    }

    /**
     * Address comparison is intentionally fuzzy but cheap: same area, and the
     * address reduced to alphanumerics matches. That catches
     * "Ground Floor, Dolmen Mall" vs "ground floor dolmen mall" without needing
     * a geocoder or a similarity index in V1.
     */
    private function matchingAddress(Business $business): ?Business
    {
        $normalised = $this->normaliseAddress($business->address);

        if ($normalised === '') {
            return null;
        }

        return Business::query()
            ->whereKeyNot($business->getKey())
            ->where('area', $business->area)
            ->get(['id', 'name', 'address'])
            ->first(fn (Business $other) => $this->normaliseAddress($other->address) === $normalised);
    }

    private function normaliseNumber(?string $number): string
    {
        return preg_replace('/[^0-9+]/', '', (string) $number) ?? '';
    }

    private function normaliseAddress(?string $address): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $address) ?? '');
    }
}
