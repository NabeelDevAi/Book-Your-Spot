<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['spot_id', 'path', 'caption', 'sort_order'])]
class SpotImage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }
}
