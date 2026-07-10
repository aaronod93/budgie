<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'hidden', 'sort_order'])]
class CategoryGroup extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
            'internal' => 'boolean',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
