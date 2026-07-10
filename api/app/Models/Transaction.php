<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['date', 'amount', 'payee_id', 'category_id', 'memo', 'cleared', 'approved', 'import_id'])]
class Transaction extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount' => 'integer',
            'approved' => 'boolean',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subTransactions(): HasMany
    {
        return $this->hasMany(SubTransaction::class);
    }

    public function transferTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transfer_transaction_id');
    }

    public function isTransfer(): bool
    {
        return $this->transfer_transaction_id !== null;
    }

    public function isSplit(): bool
    {
        return $this->subTransactions()->exists();
    }
}
