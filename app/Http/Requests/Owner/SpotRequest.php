<?php

namespace App\Http\Requests\Owner;

use App\Rules\ValidOperatingHours;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-2.4 -- a bookable unit's pricing, duration bound and optional hours override.
 *
 * The cross-field rules here are what make SRS 9.7 enforceable later: if a Spot
 * is allowed to exist with a 25-minute minimum on a 10-minute billing unit, no
 * amount of validation at booking time can give the customer a sensible answer.
 *
 * There is deliberately no maximum-duration field (SRS amendment): how long a
 * Spot can be booked for is bounded only by how much of a day's operating
 * hours are free, which BookingValidator enforces at booking time -- not
 * something an Owner configures here.
 */
class SpotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],

            'price_amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
            // Nullable: absent (or equal to the weekday rate) means "no
            // separate weekend rate", the same "null means inherit" shape as
            // operating_hours_override.
            'weekend_price_amount' => ['nullable', 'numeric', 'min:1', 'max:1000000'],
            'price_unit_minutes' => [
                'required', 'integer',
                Rule::in(config('booking.allowed_price_unit_minutes')),
            ],

            'min_duration_minutes' => ['required', 'integer', 'min:1', 'max:'.config('booking.duration_input_ceiling_minutes')],

            // Nullable: absent means "inherit the venue's hours", which is
            // deliberately different from an all-week-closed override.
            'override_hours' => ['nullable', 'boolean'],
            'operating_hours' => ['nullable', 'array', new ValidOperatingHours(requireOpenDay: false)],

            'images' => ['nullable', 'array', 'max:'.config('booking.max_spot_images')],
            'images.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('booking.max_image_kilobytes'),
            ],

            'videos' => ['nullable', 'array', 'max:'.config('booking.max_spot_videos')],
            'videos.*' => [
                'file',
                'mimes:'.implode(',', config('booking.allowed_video_mimes')),
                'max:'.config('booking.max_video_kilobytes'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $unit = (int) $this->input('price_unit_minutes');
            $min = (int) $this->input('min_duration_minutes');

            if ($unit <= 0 || $min <= 0) {
                return;
            }

            // Must be a whole billing unit, or the Spot advertises a minimum
            // it cannot actually price.
            if ($min % $unit !== 0) {
                $validator->errors()->add(
                    'min_duration_minutes',
                    'The minimum must be a multiple of '.Money::duration($unit)
                    .'. Try '.(int) (ceil($min / $unit) * $unit).' minutes.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'price_amount' => 'weekday price',
            'weekend_price_amount' => 'weekend price',
            'price_unit_minutes' => 'billing unit',
            'min_duration_minutes' => 'minimum booking length',
            'operating_hours' => 'opening hours',
        ];
    }
}
