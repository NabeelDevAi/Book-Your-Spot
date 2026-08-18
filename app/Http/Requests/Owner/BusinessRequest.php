<?php

namespace App\Http\Requests\Owner;

use App\Rules\ValidOperatingHours;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FR-2.1 -- venue profile. Used for both create and update; authorisation is
 * handled by the route's policy middleware.
 */
class BusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address' => ['required', 'string', 'max:255'],
            'area' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'contact_number' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{7,25}$/'],

            'operating_hours' => ['required', 'array', new ValidOperatingHours],

            'images' => ['nullable', 'array', 'max:'.config('booking.max_business_images')],
            'images.*' => [
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('booking.max_image_kilobytes'),
            ],

            'videos' => ['nullable', 'array', 'max:'.config('booking.max_business_videos')],
            'videos.*' => [
                'file',
                'mimes:'.implode(',', config('booking.allowed_video_mimes')),
                'max:'.config('booking.max_video_kilobytes'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_number.regex' => 'Enter a valid contact number, e.g. +92 21 3584 0001.',
            'images.max' => 'You can upload at most '.config('booking.max_business_images').' images.',
            'images.*.max' => 'Each image must be under '
                .round(config('booking.max_image_kilobytes') / 1024).' MB.',
            'videos.max' => 'You can upload at most '.config('booking.max_business_videos').' videos.',
            'videos.*.max' => 'Each video must be under '
                .round(config('booking.max_video_kilobytes') / 1024).' MB.',
        ];
    }

    public function attributes(): array
    {
        return [
            'contact_number' => 'contact number',
            'operating_hours' => 'opening hours',
        ];
    }
}
