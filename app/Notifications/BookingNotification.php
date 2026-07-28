<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base for every reservation notification.
 *
 * V1 delivers in-app only -- no email, no SMS (the FR-5.1 amendment) -- so the
 * database channel is the sole delivery mechanism. Adding `mail` later is a
 * matter of extending via() and adding a toMail(); the payload below already
 * carries everything an email template would need.
 */
abstract class BookingNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Reservation $reservation) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type' => $this->key(),
            'title' => $this->title(),
            'body' => $this->body(),
            'icon' => $this->icon(),
            'tone' => $this->tone(),
            'reservation_id' => $this->reservation->id,
            'reference' => $this->reservation->reference,
            'business_id' => $this->reservation->business_id,
            'business_name' => $this->reservation->business->name,
            'spot_name' => $this->reservation->spot->name,
            'starts_at' => $this->reservation->start_datetime->toIso8601String(),
            'when' => $this->reservation->dateLabel().', '.$this->reservation->timeRangeLabel(),
        ], $this->extra());
    }

    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    public function icon(): string
    {
        return 'calendar';
    }

    /** success | warning | danger | info -- drives the badge colour in the bell. */
    public function tone(): string
    {
        return 'info';
    }

    /** @return array<string, mixed> */
    protected function extra(): array
    {
        return [];
    }
}
