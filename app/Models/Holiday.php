<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An Admin-managed date that pushes every Spot onto its weekend rate --
 * platform-wide, not per venue (agreed for V1: one calendar, not one per
 * Owner). See Spot::appliesWeekendRate().
 */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
