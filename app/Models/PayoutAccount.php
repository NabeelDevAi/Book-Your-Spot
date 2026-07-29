<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An Owner's bank details.
 */
#[Fillable(['owner_id', 'bank_name', 'account_title', 'account_number', 'iban'])]
class PayoutAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Last four digits only, for anywhere the account is shown back to its
     * owner. Enough to recognise, not enough to be worth shoulder-surfing.
     */
    public function maskedNumber(): string
    {
        $number = (string) $this->account_number;

        return strlen($number) <= 4
            ? $number
            : Str::repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }

    public function summary(): string
    {
        return $this->bank_name.' · '.$this->maskedNumber();
    }

    /**
     * The frozen copy stored on a withdrawal.
     *
     * Full number, not masked: this is the record an Admin works from when
     * making the transfer and the evidence if it is ever disputed.
     *
     * @return array<string, string|null>
     */
    public function toSnapshot(): array
    {
        return [
            'payout_account_id' => $this->id,
            'bank_name' => $this->bank_name,
            'account_title' => $this->account_title,
            'account_number' => $this->account_number,
            'iban' => $this->iban,
        ];
    }
}
