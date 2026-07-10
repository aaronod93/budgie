<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'account_uuid' => $this->whenLoaded('account', fn () => $this->account->uuid),
            'frequency' => $this->frequency,
            'next_date' => $this->next_date->toDateString(),
            'amount' => $this->amount,
            'memo' => $this->memo,
            'payee' => $this->whenLoaded('payee', fn () => $this->payee ? [
                'uuid' => $this->payee->uuid,
                'name' => $this->payee->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ] : null),
            'transfer_account_uuid' => $this->whenLoaded('transferAccount', fn () => $this->transferAccount?->uuid),
        ];
    }
}
