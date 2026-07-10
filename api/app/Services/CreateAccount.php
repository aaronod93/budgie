<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
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

            // Every credit card gets a linked payment envelope: budgeted card
            // spending is moved into it by MonthService so the cash to pay the
            // bill is always reserved (PLAN.md §4).
            if ($account->type === 'credit') {
                $this->createPaymentCategory($budget, $account);
            }

            if ($startingBalance !== 0) {
                $payee = $budget->payees()->firstOrCreate(['name' => 'Starting Balance']);

                // On-budget cash flows to Ready to Assign. Pre-existing credit
                // card debt is just debt — it was never this budget's money.
                $category = $onBudget && ! ($account->type === 'credit' && $startingBalance < 0)
                    ? $budget->readyToAssignCategory()
                    : null;

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

    private function createPaymentCategory(Budget $budget, Account $account): void
    {
        $group = $budget->categoryGroups()->firstOrCreate(
            ['name' => 'Credit Card Payments'],
            ['sort_order' => ($budget->categoryGroups()->where('internal', false)->max('sort_order') ?? -1) + 1],
        );

        $category = new Category([
            'name' => $account->name,
            'category_group_id' => $group->id,
            'sort_order' => ($group->categories()->max('sort_order') ?? -1) + 1,
        ]);
        $category->budget_id = $budget->id;
        $category->internal_type = 'credit_card_payment';
        $category->linked_account_id = $account->id;
        $category->save();
    }
}
