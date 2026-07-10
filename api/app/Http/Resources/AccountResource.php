<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type,
            'on_budget' => $this->on_budget,
            'closed' => $this->closed,
            'note' => $this->note,
            'sort_order' => $this->sort_order,
            // Aggregates provided by withSum() in the controller.
            'balance' => (int) ($this->balance ?? 0),
            'cleared_balance' => (int) ($this->cleared_balance ?? 0),
        ];
    }
}
