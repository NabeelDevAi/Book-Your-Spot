<?php

namespace App\Http\Requests\Owner;

use App\Models\Spot;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * FR-2.9 -- owner-declared downtime on a Spot.
 */
class SpotBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_datetime' => ['required', 'date'],
            'end_datetime' => ['required', 'date', 'after:start_datetime'],
            'reason' => ['nullable', 'string', 'max:255'],
            // The owner must explicitly acknowledge clashing bookings before the
            // block is created (SRS 9.6).
            'acknowledge_conflicts' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = Carbon::parse($this->input('start_datetime'));
            $end = Carbon::parse($this->input('end_datetime'));

            // Blocking time that has already passed changes nothing and only
            // confuses the availability view.
            if ($end->isPast()) {
                $validator->errors()->add('end_datetime', 'That block is entirely in the past.');

                return;
            }

            if ($start->diffInDays($end) > 90) {
                $validator->errors()->add(
                    'end_datetime',
                    'Blocks longer than 90 days aren\'t supported — deactivate the spot instead.'
                );
            }
        });
    }

    public function spot(): Spot
    {
        return $this->route('spot');
    }

    public function attributes(): array
    {
        return [
            'start_datetime' => 'start time',
            'end_datetime' => 'end time',
        ];
    }
}
