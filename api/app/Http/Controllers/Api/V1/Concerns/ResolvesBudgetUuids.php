<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Budget;
use Illuminate\Validation\ValidationException;

/**
 * The API speaks UUIDs; the database speaks BIGINT ids (PLAN.md §3). These
 * helpers translate incoming UUIDs into internal ids, scoped to the budget so
 * one user can never reference another budget's records.
 */
trait ResolvesBudgetUuids
{
    protected function accountId(Budget $budget, ?string $uuid, string $field = 'account_id'): ?int
    {
        return $this->resolve($budget->accounts(), $uuid, $field);
    }

    protected function categoryId(Budget $budget, ?string $uuid, string $field = 'category_id'): ?int
    {
        return $this->resolve($budget->categories(), $uuid, $field);
    }

    protected function payeeId(Budget $budget, ?string $uuid, string $field = 'payee_id'): ?int
    {
        return $this->resolve($budget->payees(), $uuid, $field);
    }

    private function resolve($query, ?string $uuid, string $field): ?int
    {
        if ($uuid === null) {
            return null;
        }

        $id = $query->where('uuid', $uuid)->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([$field => 'Unknown reference.']);
        }

        return $id;
    }
}
