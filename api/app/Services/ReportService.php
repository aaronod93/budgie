<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\SubTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

class ReportService
{
    /**
     * Net categorized spending between two months (inclusive), grouped by
     * category or payee, with a per-month trend. Amounts are positive cents of
     * net outflow (refunds net off).
     */
    public function spending(Budget $budget, CarbonImmutable $from, CarbonImmutable $to, string $groupBy = 'category'): array
    {
        $groups = [];
        $monthly = [];

        foreach ($this->spendingRows($budget, $from, $to) as $row) {
            $spend = -$row['amount'];
            $key = $groupBy === 'payee' ? ($row['payee_uuid'] ?? 'none') : $row['category_uuid'];
            $name = $groupBy === 'payee' ? ($row['payee_name'] ?? 'No payee') : $row['category_name'];

            $groups[$key] ??= ['uuid' => $key === 'none' ? null : $key, 'name' => $name, 'amount' => 0];
            $groups[$key]['amount'] += $spend;
            $monthly[$row['month']] = ($monthly[$row['month']] ?? 0) + $spend;
        }

        $groups = array_values(array_filter($groups, fn ($g) => $g['amount'] !== 0));
        usort($groups, fn ($a, $b) => $b['amount'] <=> $a['amount']);

        return [
            'from' => $from->format('Y-m'),
            'to' => $to->format('Y-m'),
            'total' => array_sum(array_column($groups, 'amount')),
            'groups' => $groups,
            'monthly' => $this->fillMonths($from, $to, $monthly),
        ];
    }

    /** Assets, debts and net across ALL accounts (tracking included), per month. */
    public function netWorth(Budget $budget): array
    {
        $byAccountMonth = [];
        $rows = Transaction::query()
            ->where('budget_id', $budget->id)
            ->get(['account_id', 'date', 'amount']);

        if ($rows->isEmpty()) {
            return ['months' => []];
        }

        foreach ($rows as $row) {
            $key = $row->date->format('Y-m');
            $byAccountMonth[$row->account_id][$key] = ($byAccountMonth[$row->account_id][$key] ?? 0) + $row->amount;
        }

        $first = CarbonImmutable::createFromFormat(
            '!Y-m',
            min(array_map(fn ($months) => min(array_keys($months)), $byAccountMonth)),
        );
        $last = CarbonImmutable::now()->startOfMonth();

        $balances = array_fill_keys(array_keys($byAccountMonth), 0);
        $months = [];
        for ($month = $first; $month->lessThanOrEqualTo($last); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $assets = 0;
            $debts = 0;
            foreach ($balances as $accountId => $balance) {
                $balances[$accountId] = $balance + ($byAccountMonth[$accountId][$key] ?? 0);
                if ($balances[$accountId] >= 0) {
                    $assets += $balances[$accountId];
                } else {
                    $debts += $balances[$accountId];
                }
            }
            $months[] = ['month' => $key, 'assets' => $assets, 'debts' => $debts, 'net' => $assets + $debts];
        }

        return ['months' => $months];
    }

    /** Income (inflows to RTA) vs categorized spending, per month. */
    public function incomeExpense(Budget $budget, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rtaCategoryId = $budget->categories()->where('internal_type', 'ready_to_assign')->value('id');

        $income = [];
        $expense = [];

        $rows = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereNotNull('category_id')
            ->whereDoesntHave('subTransactions')
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereBetween('date', [$from->startOfMonth(), $to->endOfMonth()])
            ->get(['category_id', 'date', 'amount']);

        foreach ($rows as $row) {
            $key = $row->date->format('Y-m');
            if ($row->category_id === $rtaCategoryId) {
                $income[$key] = ($income[$key] ?? 0) + $row->amount;
            } else {
                $expense[$key] = ($expense[$key] ?? 0) - $row->amount;
            }
        }

        foreach ($this->splitRows($budget, $from, $to) as $row) {
            $expense[$row['month']] = ($expense[$row['month']] ?? 0) - $row['amount'];
        }

        $months = [];
        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = [
                'month' => $key,
                'income' => $income[$key] ?? 0,
                'expense' => $expense[$key] ?? 0,
                'net' => ($income[$key] ?? 0) - ($expense[$key] ?? 0),
            ];
        }

        return ['months' => $months];
    }

    /**
     * Age of Money: FIFO-match cash outflows against cash inflows;
     * the age of an outflow is how old the money that finished paying for it
     * was. Returns the average of the last 10 outflows in days, or null.
     */
    public function ageOfMoney(Budget $budget): ?int
    {
        $rows = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereHas('account', fn ($q) => $q->where('on_budget', true)->where('type', '!=', 'credit'))
            ->with('transferTransaction.account:id,on_budget')
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'date', 'amount', 'transfer_transaction_id']);

        $inflows = [];  // FIFO queue of ['date' => CarbonImmutable, 'remaining' => cents]
        $ages = [];

        foreach ($rows as $row) {
            // Transfers between on-budget accounts move money around without
            // aging or spending it.
            if ($row->transfer_transaction_id !== null
                && $row->transferTransaction?->account?->on_budget) {
                continue;
            }

            if ($row->amount > 0) {
                $inflows[] = ['date' => $row->date, 'remaining' => $row->amount];

                continue;
            }

            $toCover = -$row->amount;
            $age = null;
            while ($toCover > 0 && $inflows !== []) {
                $consumed = min($toCover, $inflows[0]['remaining']);
                $inflows[0]['remaining'] -= $consumed;
                $toCover -= $consumed;
                $age = (int) $inflows[0]['date']->diffInDays($row->date);
                if ($inflows[0]['remaining'] === 0) {
                    array_shift($inflows);
                }
            }
            if ($age !== null) {
                $ages[] = $age;
            }
        }

        if ($ages === []) {
            return null;
        }

        $recent = array_slice($ages, -10);

        return (int) round(array_sum($recent) / count($recent));
    }

    /**
     * Categorized spending rows (parents + splits) across on-budget accounts,
     * excluding Ready to Assign and hidden categories' internals.
     *
     * @return list<array{amount: int, month: string, category_uuid: string, category_name: string, payee_uuid: ?string, payee_name: ?string}>
     */
    private function spendingRows(Budget $budget, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = [];

        $parents = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereNotNull('category_id')
            ->whereDoesntHave('subTransactions')
            ->whereHas('category', fn ($q) => $q->whereNull('internal_type'))
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->whereBetween('date', [$from->startOfMonth(), $to->endOfMonth()])
            ->with(['category:id,uuid,name', 'payee:id,uuid,name'])
            ->get(['id', 'category_id', 'payee_id', 'date', 'amount']);

        foreach ($parents as $row) {
            $result[] = [
                'amount' => $row->amount,
                'month' => $row->date->format('Y-m'),
                'category_uuid' => $row->category->uuid,
                'category_name' => $row->category->name,
                'payee_uuid' => $row->payee?->uuid,
                'payee_name' => $row->payee?->name,
            ];
        }

        $splits = SubTransaction::query()
            ->whereNotNull('category_id')
            ->whereHas('category', fn ($q) => $q->whereNull('internal_type'))
            ->whereHas('transaction', fn ($q) => $q
                ->where('budget_id', $budget->id)
                ->whereBetween('date', [$from->startOfMonth(), $to->endOfMonth()])
                ->whereHas('account', fn ($a) => $a->where('on_budget', true)))
            ->with(['category:id,uuid,name', 'transaction.payee:id,uuid,name', 'transaction:id,date,payee_id'])
            ->get(['id', 'transaction_id', 'category_id', 'amount']);

        foreach ($splits as $row) {
            $result[] = [
                'amount' => $row->amount,
                'month' => $row->transaction->date->format('Y-m'),
                'category_uuid' => $row->category->uuid,
                'category_name' => $row->category->name,
                'payee_uuid' => $row->transaction->payee?->uuid,
                'payee_name' => $row->transaction->payee?->name,
            ];
        }

        return $result;
    }

    /** @return list<array{amount: int, month: string}> */
    private function splitRows(Budget $budget, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $splits = SubTransaction::query()
            ->whereNotNull('category_id')
            ->whereHas('category', fn ($q) => $q->whereNull('internal_type'))
            ->whereHas('transaction', fn ($q) => $q
                ->where('budget_id', $budget->id)
                ->whereBetween('date', [$from->startOfMonth(), $to->endOfMonth()])
                ->whereHas('account', fn ($a) => $a->where('on_budget', true)))
            ->with('transaction:id,date')
            ->get(['id', 'transaction_id', 'amount']);

        return $splits->map(fn ($row) => [
            'amount' => $row->amount,
            'month' => $row->transaction->date->format('Y-m'),
        ])->all();
    }

    private function fillMonths(CarbonImmutable $from, CarbonImmutable $to, array $values): array
    {
        $months = [];
        for ($month = $from; $month->lessThanOrEqualTo($to); $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = ['month' => $key, 'amount' => $values[$key] ?? 0];
        }

        return $months;
    }
}
