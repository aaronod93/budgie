<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Payee;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTransaction
{
    /**
     * @param array{
     *   account_id: int,
     *   date: string,
     *   amount: int,
     *   payee_id?: ?int,
     *   payee_name?: ?string,
     *   category_id?: ?int,
     *   memo?: ?string,
     *   cleared?: string,
     *   transfer_account_id?: ?int,
     *   splits?: array<array{amount: int, category_id: ?int, memo?: ?string}>,
     * } $data
     */
    public function create(Budget $budget, array $data): Transaction
    {
        return DB::transaction(function () use ($budget, $data): Transaction {
            if (isset($data['transfer_account_id'])) {
                return $this->createTransfer($budget, $data);
            }

            $transaction = $this->makeTransaction($budget, $data);
            $transaction->save();

            $this->syncSplits($budget, $transaction, $data['splits'] ?? []);

            return $transaction;
        });
    }

    public function update(Transaction $transaction, array $data): Transaction
    {
        return DB::transaction(function () use ($transaction, $data): Transaction {
            $budget = $transaction->budget;

            if (array_key_exists('payee_name', $data)) {
                $data['payee_id'] = $this->resolvePayee($budget, $data)?->id;
            }

            $transaction->fill(array_intersect_key($data, array_flip([
                'date', 'amount', 'payee_id', 'category_id', 'memo', 'cleared', 'approved',
            ])));

            // A transfer's two rows must stay mirrored in amount and date. Only
            // an on-budget -> off-budget transfer may carry a category.
            if ($transaction->isTransfer()) {
                $pair = $transaction->transferTransaction;

                if (! ($transaction->account->on_budget && ! $pair->account->on_budget)) {
                    $transaction->category_id = null;
                }

                $pair->amount = -$transaction->amount;
                $pair->date = $transaction->date;
                $pair->save();
            }

            $transaction->save();

            if (array_key_exists('splits', $data) && ! $transaction->isTransfer()) {
                $this->syncSplits($budget, $transaction, $data['splits'] ?? []);
            }

            return $transaction;
        });
    }

    public function delete(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $transaction->subTransactions()->delete();

            if ($pair = $transaction->transferTransaction) {
                $pair->subTransactions()->delete();
                $pair->delete();
            }

            $transaction->delete();
        });
    }

    private function createTransfer(Budget $budget, array $data): Transaction
    {
        $from = $budget->accounts()->findOrFail($data['account_id']);
        $to = $budget->accounts()->findOrFail($data['transfer_account_id']);

        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'transfer_account_id' => 'Cannot transfer to the same account.',
            ]);
        }

        // Money moving between two on-budget accounts never leaves the budget,
        // so neither side takes a category. If one side is off-budget the
        // on-budget side may carry a category (it is real spending/income).
        $bothOnBudget = $from->on_budget && $to->on_budget;

        $outgoing = $this->makeTransaction($budget, [
            ...$data,
            'payee_id' => $this->transferPayee($budget, $to)->id,
            'category_id' => $bothOnBudget || ! $from->on_budget ? null : ($data['category_id'] ?? null),
        ]);
        $outgoing->save();

        $incoming = $this->makeTransaction($budget, [
            'account_id' => $to->id,
            'date' => $data['date'],
            'amount' => -$data['amount'],
            'payee_id' => $this->transferPayee($budget, $from)->id,
            'category_id' => null,
            'memo' => $data['memo'] ?? null,
        ]);
        $incoming->save();

        $outgoing->transfer_transaction_id = $incoming->id;
        $outgoing->save();
        $incoming->transfer_transaction_id = $outgoing->id;
        $incoming->save();

        return $outgoing;
    }

    private function makeTransaction(Budget $budget, array $data): Transaction
    {
        $account = $budget->accounts()->findOrFail($data['account_id']);

        $transaction = new Transaction(array_intersect_key($data, array_flip([
            'date', 'amount', 'category_id', 'memo', 'cleared', 'import_id',
        ])));
        $transaction->budget_id = $budget->id;
        $transaction->account_id = $account->id;
        $transaction->payee_id = $data['payee_id'] ?? $this->resolvePayee($budget, $data)?->id;

        return $transaction;
    }

    private function syncSplits(Budget $budget, Transaction $transaction, array $splits): void
    {
        $transaction->subTransactions()->delete();

        if ($splits === []) {
            return;
        }

        if (array_sum(array_column($splits, 'amount')) !== $transaction->amount) {
            throw ValidationException::withMessages([
                'splits' => 'Split amounts must add up to the transaction amount.',
            ]);
        }

        // A split parent holds no category of its own; the splits carry them.
        $transaction->category_id = null;
        $transaction->save();

        foreach ($splits as $split) {
            $transaction->subTransactions()->create([
                'amount' => $split['amount'],
                'category_id' => $split['category_id'] ?? null,
                'memo' => $split['memo'] ?? null,
            ]);
        }
    }

    private function resolvePayee(Budget $budget, array $data): ?Payee
    {
        if (! empty($data['payee_name'])) {
            return $budget->payees()->firstOrCreate(['name' => trim($data['payee_name'])]);
        }

        return null;
    }

    private function transferPayee(Budget $budget, Account $account): Payee
    {
        $payee = $budget->payees()->firstOrNew(['name' => "Transfer : {$account->name}"]);
        $payee->transfer_account_id = $account->id;
        $payee->save();

        return $payee;
    }
}
