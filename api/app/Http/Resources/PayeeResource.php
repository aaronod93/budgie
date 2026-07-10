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
            'transfer_account_uuid' => $this->whenLoaded('transferAccount', fn () => $this->transferAccount?->uuid),
            'default_category' => $this->whenLoaded('defaultCategory', fn () => $this->defaultCategory ? [
                'uuid' => $this->defaultCategory->uuid,
                'name' => $this->defaultCategory->name,
            ] : null),
        ];
    }
}
