<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'amount', 'target_date', 'cadence', 'starts_on', 'ends_on', 'repeat_times', 'snoozed_months', 'snoozed_until'])]
class Target extends Model
{
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'target_date' => 'immutable_date',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'repeat_times' => 'integer',
            'snoozed_months' => 'array',
            'snoozed_until' => 'immutable_date',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
