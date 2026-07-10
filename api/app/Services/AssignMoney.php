<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AssignMoney
{
    /**
     * Set the assigned amount for a category in a month (absolute, not delta —
     * this is the only user-editable number on the budget screen).
     */
    public function __invoke(Budget $budget, CarbonImmutable $month, Category $category, int $amount): void
    {
        if ($category->internal_type !== null) {
            throw ValidationException::withMessages([
                'category' => 'Cannot assign to an internal category.',
            ]);
        }

        $budget->monthlyBudgets()->updateOrCreate(
            ['category_id' => $category->id, 'month' => $month->startOfMonth()],
            ['assigned' => $amount],
        );
    }
}
