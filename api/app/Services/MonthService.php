<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\SubTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Computes the budget screen for a month. Months are virtual (PLAN.md §4):
 * nothing is stored per month except `assigned`; everything else folds forward
 * from the earliest data, so retroactive edits just work.
 *
 * Per category:   available(m) = max(available(m-1), 0) + assigned(m) + activity(m)
 * Ready to Assign: rta(m) = rta(m-1) + income(m) - assignedTotal(m)
 *                           + sum(min(available_c(m-1), 0))   [cash overspending reset]
 *
 * Invariant (tested): rta(m) + sum(available_c(m)) == on-budget balance through m,
 * provided every on-budget transaction is categorized or an on-budget transfer.
 */
class MonthService
{
    public function compute(Budget $budget, CarbonImmutable $target): array
    {
        $target = $target->startOfMonth();

        $categories = $budget->categories()
            ->whereNull('internal_type')
            ->with('group')
            ->orderBy('sort_order')
            ->get();

        $rtaCategoryId = $budget->categories()
            ->where('internal_type', 'ready_to_assign')
            ->value('id');

        $assigned = $this->assignedByCategoryAndMonth($budget);
        $activity = $this->activityByCategoryAndMonth($budget);

        $first = $this->firstMonth($assigned, $activity) ?? $target;
        if ($first->greaterThan($target)) {
            $first = $target;
        }

        $available = [];       // category_id => running available
        $rta = 0;
        $month = $first;

        while ($month->lessThanOrEqualTo($target)) {
            $key = $month->format('Y-m');

            // Roll last month's envelopes forward: positive balances carry,
            // negative (cash overspending) resets and comes out of RTA.
            foreach ($available as $categoryId => $balance) {
                if ($balance < 0) {
                    $rta += $balance;
                    $available[$categoryId] = 0;
                }
            }

            $monthAssignedTotal = 0;
            foreach ($categories as $category) {
                $rowAssigned = $assigned[$category->id][$key] ?? 0;
                $rowActivity = $activity[$category->id][$key] ?? 0;
                $monthAssignedTotal += $rowAssigned;
                $available[$category->id] = ($available[$category->id] ?? 0) + $rowAssigned + $rowActivity;
            }

            $income = $activity[$rtaCategoryId][$key] ?? 0;
            $rta += $income - $monthAssignedTotal;

            $month = $month->addMonth();
        }

        $key = $target->format('Y-m');
        $groups = [];
        foreach ($categories as $category) {
            if ($category->hidden) {
                continue;
            }
            $groupUuid = $category->group->uuid;
            $groups[$groupUuid] ??= [
                'uuid' => $groupUuid,
                'name' => $category->group->name,
                'sort_order' => $category->group->sort_order,
                'categories' => [],
            ];
            $groups[$groupUuid]['categories'][] = [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'assigned' => $assigned[$category->id][$key] ?? 0,
                'activity' => $activity[$category->id][$key] ?? 0,
                'available' => $available[$category->id] ?? 0,
            ];
        }

        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        return [
            'month' => $target->format('Y-m'),
            'ready_to_assign' => $rta,
            'income' => $activity[$rtaCategoryId][$key] ?? 0,
            'assigned_total' => array_sum(array_map(
                fn ($g) => array_sum(array_column($g['categories'], 'assigned')),
                $groups,
            )),
            'activity_total' => array_sum(array_map(
                fn ($g) => array_sum(array_column($g['categories'], 'activity')),
                $groups,
            )),
            'available_total' => array_sum(array_map(
                fn ($g) => array_sum(array_column($g['categories'], 'available')),
                $groups,
            )),
            'groups' => $groups,
        ];
    }

    /** @return array<int, array<string, int>> category_id => ['YYYY-MM' => assigned] */
    private function assignedByCategoryAndMonth(Budget $budget): array
    {
        $result = [];
        foreach ($budget->monthlyBudgets()->get(['category_id', 'month', 'assigned']) as $row) {
            $result[$row->category_id][$row->month->format('Y-m')] = $row->assigned;
        }

        return $result;
    }

    /**
     * Activity per category per month across on-budget accounts. Aggregated in
     * PHP to stay database-agnostic; personal-budget row counts make this cheap.
     *
     * @return array<int, array<string, int>>
     */
    private function activityByCategoryAndMonth(Budget $budget): array
    {
        $result = [];

        $parents = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereNotNull('category_id')
            ->whereDoesntHave('subTransactions')
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->get(['category_id', 'date', 'amount']);

        foreach ($parents as $row) {
            $key = $row->date->format('Y-m');
            $result[$row->category_id][$key] = ($result[$row->category_id][$key] ?? 0) + $row->amount;
        }

        $splits = SubTransaction::query()
            ->whereNotNull('category_id')
            ->whereHas('transaction', fn ($q) => $q
                ->where('budget_id', $budget->id)
                ->whereHas('account', fn ($a) => $a->where('on_budget', true)))
            ->with('transaction:id,date')
            ->get(['id', 'transaction_id', 'category_id', 'amount']);

        foreach ($splits as $row) {
            $key = $row->transaction->date->format('Y-m');
            $result[$row->category_id][$key] = ($result[$row->category_id][$key] ?? 0) + $row->amount;
        }

        return $result;
    }

    private function firstMonth(array $assigned, array $activity): ?CarbonImmutable
    {
        $keys = [];
        foreach ([$assigned, $activity] as $set) {
            foreach ($set as $months) {
                $keys = [...$keys, ...array_keys($months)];
            }
        }

        if ($keys === []) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', min($keys).'-01')->startOfMonth();
    }
}
