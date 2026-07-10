<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'icon' => $this->icon,
            'transfer_account_uuid' => $this->whenLoaded('transferAccount', fn () => $this->transferAccount?->uuid),
            'default_category' => $this->whenLoaded('defaultCategory', fn () => $this->defaultCategory ? [
                'uuid' => $this->defaultCategory->uuid,
                'name' => $this->defaultCategory->name,
            ] : null),
            // Payee memory (set by PayeeController::index): last-used category
            // and usual direction, for register pre-fill.
            'last_category_uuid' => $this->last_category_uuid ?? null,
            'last_flow' => $this->last_flow ?? null,
        ];
    }
}
