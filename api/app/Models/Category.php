<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'hidden', 'sort_order', 'category_group_id'])]
class Category extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'hidden' => 'boolean',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CategoryGroup::class, 'category_group_id');
    }

    public function isReadyToAssign(): bool
    {
        return $this->internal_type === 'ready_to_assign';
    }
}
