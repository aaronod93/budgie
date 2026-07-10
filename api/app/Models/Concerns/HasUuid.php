<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * BIGINT primary keys internally; a UUIDv7 as the public identifier the API
 * exposes and routes on (see PLAN.md §3).
 */
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            $model->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
