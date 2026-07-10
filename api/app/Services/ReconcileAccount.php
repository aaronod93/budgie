<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ReconcileAccount
{
    /**
     * Reconcile an account against a real statement balance: if the cleared
     * balance disagrees, a cleared adjustment transaction closes the gap, then
     * every cleared transaction is locked as reconciled.
     *
     * @return array{account: Account, adjustment: ?Transaction}
     */
    public function __invoke(Account $account, int $statementBalance): array
    {
        return DB::transaction(function () use ($account, $statementBalance): array {
            $budget = $account->budget;

            $clearedBalance = (int) $account->transactions()
                ->whereIn('cleared', ['cleared', 'reconciled'])
                ->sum('amount');

            $adjustment = null;
            $difference = $statementBalance - $clearedBalance;

            if ($difference !== 0) {
                $payee = $budget->payees()->firstOrCreate(['name' => 'Reconciliation Balance Adjustment']);

                // The adjustment is real money appearing/disappearing, so on
                // budget it flows through Ready to Assign.
                $category = $account->on_budget ? $budget->readyToAssignCategory() : null;

                $adjustment = $account->transactions()->make([
                    'date' => now()->toDateString(),
                    'amount' => $difference,
                    'payee_id' => $payee->id,
                    'category_id' => $category?->id,
                    'cleared' => 'cleared',
                    'memo' => 'Reconciliation adjustment',
                ]);
                $adjustment->budget_id = $budget->id;
                $adjustment->save();
            }

            $account->transactions()->where('cleared', 'cleared')->update(['cleared' => 'reconciled']);

            return ['account' => $account, 'adjustment' => $adjustment?->refresh()];
        });
    }
}
