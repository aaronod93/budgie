<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'currency'])]
class Budget extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BudgetMember::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BudgetInvitation::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /** The user's role on this budget: 'owner', 'editor', 'viewer', or null. */
    public function roleOf(User $user): ?string
    {
        if ($this->user_id === $user->id) {
            return 'owner';
        }

        return $this->memberships()->where('user_id', $user->id)->value('role');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categoryGroups(): HasMany
    {
        return $this->hasMany(CategoryGroup::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function monthlyBudgets(): HasMany
    {
        return $this->hasMany(MonthlyBudget::class);
    }

    public function scheduledTransactions(): HasMany
    {
        return $this->hasMany(ScheduledTransaction::class);
    }

    public function readyToAssignCategory(): Category
    {
        return $this->categories()->where('internal_type', 'ready_to_assign')->firstOrFail();
    }
}
