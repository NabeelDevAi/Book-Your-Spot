<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A booking was refused for a business reason.
 *
 * Every message here is written for the customer, not the developer -- these
 * surface directly in the UI. "Slot no longer available" is a real answer;
 * "constraint violation" is not.
 *
 * `field` lets the controller attach the message to the right form input so the
 * user sees it next to the thing they need to change.
 */
class BookingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $field = 'start_datetime',
        public readonly ?array $suggestions = null,
    ) {
        parent::__construct($message);
    }

    /** Convert to a validation error so the form redisplays with the message inline. */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->field => $this->getMessage(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Named constructors -- one per rule in SRS section 9
    |--------------------------------------------------------------------------
    */

    /** SRS 9.1 -- lost the race at approval time. */
    public static function slotTaken(): self
    {
        return new self(
            'That slot has just been booked by someone else. Please choose another time.',
        );
    }

    /** SRS 9.5 -- outside the spot's (or venue's) operating hours. */
    public static function outsideOperatingHours(string $hoursSummary): self
    {
        return new self(
            "That time is outside opening hours. This spot is open: {$hoursSummary}",
        );
    }

    /** Overlaps an owner-declared block (FR-2.9). */
    public static function blockedByOwner(): self
    {
        return new self('The venue has made that time unavailable. Please choose another slot.');
    }

    /** SRS 9.7 -- duration is not a valid multiple, or is out of bounds. */
    public static function invalidDuration(string $message, array $suggestions): self
    {
        return new self($message, 'duration_minutes', $suggestions);
    }

    /** SRS 9.15 -- start time in the past. */
    public static function inThePast(): self
    {
        return new self('That time has already passed. Please pick a future slot.');
    }

    public static function tooSoon(int $minutes): self
    {
        return new self(
            "Bookings need at least {$minutes} minutes' notice so the venue can respond. "
            .'Please pick a later slot.',
        );
    }

    public static function tooFarAhead(int $days): self
    {
        return new self("Bookings can only be made up to {$days} days in advance.");
    }

    /** SRS 9.17 -- spot or venue not bookable. */
    public static function notBookable(): self
    {
        return new self('This spot isn\'t taking bookings right now.');
    }

    /** SRS 9.14 -- owners cannot book, on their own venue or anyone else's. */
    public static function ownersCannotBook(): self
    {
        return new self(
            'Owner accounts can\'t make bookings. Use "Blocked times" to reserve your own spot, '
            .'or register a separate customer account to book elsewhere.',
        );
    }

    public static function accountSuspended(): self
    {
        return new self('Your account is suspended, so you can\'t make bookings.');
    }

    /** SRS 9.18 -- speculative-request cap. */
    public static function tooManyPending(int $cap): self
    {
        return new self(
            "You already have {$cap} requests awaiting a response. "
            .'Wait for a venue to reply, or cancel one before requesting another.',
        );
    }

    /** The same customer already holds a request for this exact slot. */
    public static function duplicateRequest(): self
    {
        return new self('You already have a request for this spot at that time.');
    }

    public static function notPending(): self
    {
        return new self('This request has already been answered.');
    }
}
