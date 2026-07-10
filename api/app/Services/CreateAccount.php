<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use Illuminate\Support\Facades\DB;

class CreateAccount
{
    /**
     * @param  array{name: string, type: string, note?: ?string}  $attributes
     * @param  int  $startingBalance  minor units; positive = you have money, negative = debt
     */
    public function __invoke(Budget $budget, array $attributes, int $startingBalance = 0, ?string $date = null): Account
    {
        return DB::transaction(function () use ($budget, $attributes, $startingBalance, $date): Account {
            $onBudget = $attributes['type'] !== 'tracking';

            $account = $budget->accounts()->create([
                ...$attributes,
                'on_budget' => $onBudget,
                'sort_order' => ($budget->accounts()->max('sort_order') ?? -1) + 1,
            ]);

            if ($startingBalance !== 0) {
                $payee = $budget->payees()->firstOrCreate(['name' => 'Starting Balance']);
                // On-budget starting balances flow to Ready to Assign so the
                // money is immediately available to budget.
                $category = $onBudget ? $budget->readyToAssignCategory() : null;

                $transaction = $account->transactions()->make([
                    'date' => $date ?? now()->toDateString(),
                    'amount' => $startingBalance,
                    'payee_id' => $payee->id,
                    'category_id' => $category?->id,
                    'cleared' => 'cleared',
                ]);
                $transaction->budget_id = $budget->id;
                $transaction->save();
            }

            return $account;
        });
    }
}
