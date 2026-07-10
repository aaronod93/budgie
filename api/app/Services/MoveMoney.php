<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MoveMoney
{
    /**
     * Move budgeted money between envelopes in a month (Rule 3: Roll With the
     * Punches). A null category means Ready to Assign: moving from RTA assigns
     * more to the target; moving to RTA un-assigns.
     */
    public function __invoke(Budget $budget, CarbonImmutable $month, ?Category $from, ?Category $to, int $amount): void
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be positive.']);
        }

        if ($from?->id === $to?->id) {
            throw ValidationException::withMessages(['to_category' => 'Choose two different envelopes.']);
        }

        DB::transaction(function () use ($budget, $month, $from, $to, $amount): void {
            if ($from !== null) {
                $this->adjust($budget, $month, $from, -$amount);
            }
            if ($to !== null) {
                $this->adjust($budget, $month, $to, $amount);
            }
        });
    }

    private function adjust(Budget $budget, CarbonImmutable $month, Category $category, int $delta): void
    {
        $row = $budget->monthlyBudgets()->firstOrCreate(
            ['category_id' => $category->id, 'month' => $month->startOfMonth()],
        );
        $row->increment('assigned', $delta);
    }
}
