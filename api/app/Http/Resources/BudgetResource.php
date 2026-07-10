<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'currency' => $this->currency,
            'role' => $request->user() ? $this->roleOf($request->user()) : null,
            'ready_to_assign_category_uuid' => $this->categories()
                ->where('internal_type', 'ready_to_assign')
                ->value('uuid'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
