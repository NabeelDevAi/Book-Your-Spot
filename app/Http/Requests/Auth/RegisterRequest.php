<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9\s\-]{7,20}$/', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],

            // FR-1.3: Admin accounts are never self-registerable, so only the
            // two public roles are accepted here. Anything else -- including a
            // hand-crafted "admin" POST -- fails validation.
            'role' => ['required', Rule::enum(UserRole::class)->only(UserRole::selfRegisterable())],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. +92 300 1234567.',
            'phone.unique' => 'An account already exists with this phone number.',
            'role.required' => 'Choose whether you want to book spots or list a venue.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalise the phone number before the unique check, so "+92 300 1234567"
        // and "+923001234567" cannot both register as separate accounts.
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => preg_replace('/[\s\-]/', '', (string) $this->input('phone')),
            ]);
        }
    }
}
