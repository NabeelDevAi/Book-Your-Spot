<?php

namespace App\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Validates a top-up amount, in rupees as the customer typed it.
 *
 * The bounds are re-checked inside TopupService. That is not redundancy for its
 * own sake -- this class only guards the HTTP form, and the service is
 * reachable from the console and from future callers that never see a request.
 */
class StartTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isUser() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Plain string rules rather than Rule::numeric() builders: with
            // `numeric` already present, min/max compare by value, and the
            // builders add nothing here beyond a way to get the shape wrong.
            'amount' => [
                'required',
                'numeric',
                'min:'.Money::fromMinor((int) config('wallet.min_topup_minor')),
                'max:'.Money::fromMinor((int) config('wallet.max_topup_minor')),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.min' => 'The smallest top-up is '.Money::pkrMinor((int) config('wallet.min_topup_minor')).'.',
            'amount.max' => 'The largest single top-up is '.Money::pkrMinor((int) config('wallet.max_topup_minor')).'.',
        ];
    }

    /** The validated amount in paisa, which is the only form the service takes. */
    public function amountMinor(): int
    {
        return Money::toMinor((string) $this->validated()['amount']);
    }

    /*
    |--------------------------------------------------------------------------
    | Failure responses
    |--------------------------------------------------------------------------
    | A JSON request gets a JSON answer, spelled out rather than inherited.
    | bootstrap/app.php narrows `shouldRenderJsonWhen()` to `api/*` paths, which
    | replaces Laravel's default `expectsJson()` check outright -- so without
    | this a JSON caller gets a 302 it cannot use. An ordinary form post falls
    | through to the normal redirect-with-errors behaviour.
    */

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    protected function failedAuthorization(): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Only customer accounts hold a wallet.',
            ], 403));
        }

        parent::failedAuthorization();
    }
}
