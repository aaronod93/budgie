<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'account_uuid' => $this->whenLoaded('account', fn () => $this->account->uuid),
            'date' => $this->date->toDateString(),
            'amount' => $this->amount,
            'memo' => $this->memo,
            'cleared' => $this->cleared,
            'approved' => $this->approved,
            'payee' => $this->whenLoaded('payee', fn () => $this->payee ? [
                'uuid' => $this->payee->uuid,
                'name' => $this->payee->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
            ] : null),
            'transfer_account_uuid' => $this->whenLoaded('transferTransaction', fn () => $this->transferTransaction?->account?->uuid),
            'splits' => $this->whenLoaded('subTransactions', fn () => $this->subTransactions->map(fn ($split) => [
                'uuid' => $split->uuid,
                'amount' => $split->amount,
                'category_uuid' => $split->category?->uuid,
                'memo' => $split->memo,
            ])->all()),
        ];
    }
}
