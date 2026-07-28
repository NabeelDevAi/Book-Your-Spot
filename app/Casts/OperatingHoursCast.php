<?php

namespace App\Casts;

use App\Support\OperatingHours;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts an operating_hours JSON column to an OperatingHours value object.
 *
 * Nullable on Spot: a null override means "inherit the Business hours", which
 * is distinct from an all-days-closed schedule.
 *
 * @implements CastsAttributes<OperatingHours|null, OperatingHours|array|null>
 */
class OperatingHoursCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?OperatingHours
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return OperatingHours::fromArray(is_array($decoded) ? $decoded : []);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $hours = $value instanceof OperatingHours
            ? $value
            : OperatingHours::fromArray((array) $value);

        return json_encode($hours->toArray());
    }
}
