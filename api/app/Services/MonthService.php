<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\SubTransaction;
use App\Models\Target;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Computes the budget screen for a month. Months are virtual (PLAN.md §4):
 * nothing is stored per month except `assigned`; everything else folds forward
 * from the earliest data, so retroactive edits just work.
 *
 * Spending category:  available(m) = carryover + assigned(m) + activity(m)
 * Rollover: positive available carries; negative resets — the portion caused
 * by cash spending reduces next month's RTA, the portion caused by unfunded
 * card spending simply remains card debt (credit overspending).
 *
 * Credit card payment category (one per card, linked_account_id set):
 * budgeted (funded) card spending is moved in automatically, capped at what
 * the spending envelope could cover; card payments (on-budget transfers into
 * the card) draw it down. Derived here on every read — never stored — so
 * editing a card transaction months later recalculates cleanly.
 *
 * Ready to Assign:    rta(m) = rta(m-1) + income(m) - assignedTotal(m)
 *                              + cash-overspending corrections at rollover
 *
 * Invariant (tested): rta(m) + sum(available(m)) + creditOverspend(m)
 *                     == cash balance of non-credit on-budget accounts through m.
 */
class MonthService
{
    public function compute(Budget $budget, CarbonImmutable $target): array
    {
        $target = $target->startOfMonth();

        $categories = $budget->categories()
            ->where(fn ($q) => $q->whereNull('internal_type')->orWhere('internal_type', 'credit_card_payment'))
            ->with(['group', 'target'])
            ->orderBy('sort_order')
            ->get();

        $spendingCategories = $categories->whereNull('internal_type')->values();
        $paymentCategories = $categories->where('internal_type', 'credit_card_payment')->values();

        $rtaCategoryId = $budget->categories()
            ->where('internal_type', 'ready_to_assign')
            ->value('id');

        $assigned = $this->assignedByCategoryAndMonth($budget);
        [$activity, $cardActivity] = $this->activityByCategoryAndMonth($budget);
        $cardTransfersIn = $this->cardTransfersInByMonth($budget);

        $first = $this->firstMonth($assigned, $activity, $cardTransfersIn) ?? $target;
        if ($first->greaterThan($target)) {
            $first = $target;
        }

        $available = [];        // category_id => running available
        $creditShortfall = [];  // category_id => credit-overspent portion of the CURRENT month
        $paymentActivity = [];  // payment category_id => derived activity of the CURRENT month
        $rta = 0;
        $creditOverspend = 0;
        $month = $first;

        while ($month->lessThanOrEqualTo($target)) {
            $key = $month->format('Y-m');

            // --- Rollover from the previous month ---
            foreach ($spendingCategories as $category) {
                $balance = $available[$category->id] ?? 0;
                if ($balance < 0) {
                    // Only the cash-caused portion comes out of RTA; unfunded
                    // card spending already lives on as card debt.
                    $rta += $balance + ($creditShortfall[$category->id] ?? 0);
                    $available[$category->id] = 0;
                }
            }
            foreach ($paymentCategories as $category) {
                $balance = $available[$category->id] ?? 0;
                if ($balance < 0) {
                    // Payment envelopes hold reserved cash, so negatives are
                    // always cash-like.
                    $rta += $balance;
                    $available[$category->id] = 0;
                }
            }
            $creditShortfall = [];
            $paymentActivity = [];

            // --- Spending envelopes ---
            $monthAssignedTotal = 0;
            foreach ($spendingCategories as $category) {
                $rowAssigned = $assigned[$category->id][$key] ?? 0;
                $monthAssignedTotal += $rowAssigned;
                $available[$category->id] = ($available[$category->id] ?? 0)
                    + $rowAssigned
                    + ($activity[$category->id][$key] ?? 0);
            }

            // --- Attribute card spending: funded moves to the payment envelope,
            //     the unfunded remainder is credit overspending ---
            $funded = []; // card account_id => amount moved into its payment envelope
            foreach ($spendingCategories as $category) {
                $shortfallLeft = max(0, -($available[$category->id] ?? 0));
                foreach ($cardActivity[$category->id][$key] ?? [] as $cardAccountId => $cardAmount) {
                    $netSpend = -$cardAmount; // positive = net spending on the card
                    $short = min($shortfallLeft, max($netSpend, 0));
                    $shortfallLeft -= $short;
                    $creditShortfall[$category->id] = ($creditShortfall[$category->id] ?? 0) + $short;
                    $funded[$cardAccountId] = ($funded[$cardAccountId] ?? 0) + $netSpend - $short;
                }
            }

            // --- Payment envelopes ---
            foreach ($paymentCategories as $category) {
                $rowAssigned = $assigned[$category->id][$key] ?? 0;
                $monthAssignedTotal += $rowAssigned;
                $derived = ($funded[$category->linked_account_id] ?? 0)
                    - ($cardTransfersIn[$category->linked_account_id][$key] ?? 0);
                $paymentActivity[$category->id] = $derived;
                $available[$category->id] = ($available[$category->id] ?? 0) + $rowAssigned + $derived;
            }

            $income = $activity[$rtaCategoryId][$key] ?? 0;
            $rta += $income - $monthAssignedTotal;
            $creditOverspend = array_sum($creditShortfall);

            $month = $month->addMonth();
        }

        // --- Build the payload for the target month ---
        $key = $target->format('Y-m');
        $groups = [];
        foreach ($categories as $category) {
            if ($category->hidden) {
                continue;
            }
            $isPayment = $category->internal_type === 'credit_card_payment';
            $groupUuid = $category->group->uuid;
            $groups[$groupUuid] ??= [
                'uuid' => $groupUuid,
                'name' => $category->group->name,
                'sort_order' => $category->group->sort_order,
                'categories' => [],
            ];
            $rowAssigned = $assigned[$category->id][$key] ?? 0;
            $rowAvailable = $available[$category->id] ?? 0;
            $groups[$groupUuid]['categories'][] = [
                'uuid' => $category->uuid,
                'name' => $category->name,
                'icon' => $category->icon,
                'is_credit_card_payment' => $isPayment,
                'assigned' => $rowAssigned,
                'activity' => $isPayment
                    ? ($paymentActivity[$category->id] ?? 0)
                    : ($activity[$category->id][$key] ?? 0),
                'available' => $rowAvailable,
                'target' => $category->target === null
                    ? null
                    : $this->targetPayload($category->target, $rowAssigned, $rowAvailable, $target),
            ];
        }

        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $a['sort_order'] <=> $b['sort_order']);

        $sumOf = fn (string $field) => array_sum(array_map(
            fn ($g) => array_sum(array_column($g['categories'], $field)),
            $groups,
        ));

        return [
            'month' => $target->format('Y-m'),
            'ready_to_assign' => $rta,
            'income' => $activity[$rtaCategoryId][$key] ?? 0,
            'credit_overspend' => $creditOverspend,
            'assigned_total' => $sumOf('assigned'),
            'activity_total' => $sumOf('activity'),
            'available_total' => $sumOf('available'),
            'underfunded_total' => array_sum(array_map(
                fn ($g) => array_sum(array_map(
                    fn ($c) => $c['target']['underfunded'] ?? 0,
                    $g['categories'],
                )),
                $groups,
            )),
            'groups' => $groups,
        ];
    }

    /**
     * Target progress for one category in the viewed month (saving ahead for
     * irregular expenses). Returns null when the target is not active in the
     * viewed month (before its start, after its end, or past its repeats).
     */
    private function targetPayload(Target $goal, int $assigned, int $available, CarbonImmutable $month): ?array
    {
        if (! $this->targetIsActive($goal, $month)) {
            return null;
        }

        // How much this month asks for, translated from the target's cadence.
        $monthAmount = $this->cadenceAmountForMonth($goal, $month);

        $pct = fn (int $basis, int $of) => $of <= 0
            ? 100
            : max(0, min(100, (int) round($basis / $of * 100)));

        [$underfunded, $progress, $neededThisMonth] = match ($goal->type) {
            // "Needed for spending": refill the pot by each due date.
            'refill_monthly' => $this->refillNeed($goal, $assigned, $available, $month, $pct, $monthAmount),
            // "Savings builder": assign this month's amount each month.
            'monthly_builder' => [max(0, $monthAmount - $assigned), $pct($assigned, $monthAmount), $monthAmount],
            // "Balance by date": spread what is still needed over the months left.
            'balance_by_date' => $this->balanceByDate($goal, $assigned, $available, $month, $pct),
        };

        $snoozed = $this->targetIsSnoozed($goal, $month);

        return [
            'type' => $goal->type,
            'amount' => $goal->amount,
            'target_date' => $goal->target_date?->toDateString(),
            'cadence' => $goal->cadence ?? 'month',
            'starts_on' => $goal->starts_on?->toDateString(),
            'ends_on' => $goal->ends_on?->toDateString(),
            'repeat_times' => $goal->repeat_times,
            'needed_this_month' => $neededThisMonth,
            'snoozed' => $snoozed,
            'snoozed_months' => $goal->snoozed_months ?? [],
            'snoozed_until' => $goal->snoozed_until?->toDateString(),
            'underfunded' => $snoozed ? 0 : $underfunded,
            'progress' => $progress,
        ];
    }

    /**
     * A refill target's ask for the viewed month.
     *
     * Week/fortnight/month cadences fall due inside the month, so the pot must
     * cover the month's occurrences outright. Quarter/year cadences save ahead
     * toward the window's due month: whatever is STILL needed is spread as
     * evenly as possible over the months remaining (like balance-by-date, but
     * recurring per window) — start late and the shares grow, save ahead and
     * they shrink.
     *
     * @return array{0: int, 1: int, 2: int} [underfunded, progress %, needed this month]
     */
    private function refillNeed(Target $goal, int $assigned, int $available, CarbonImmutable $month, callable $pct, int $monthAmount): array
    {
        $periodMonths = match ($goal->cadence ?? 'month') {
            'quarter' => 3,
            'year' => 12,
            default => null,
        };

        if ($periodMonths === null) {
            return [max(0, $monthAmount - $available), $pct($available, $monthAmount), $monthAmount];
        }

        $anchor = $this->savingStartMonth($goal) ?? $month->startOfMonth();
        $index = ($month->year - $anchor->year) * 12 + ($month->month - $anchor->month);
        $monthsRemaining = $periodMonths - max(0, $index % $periodMonths);

        // What the pot would hold with nothing assigned this month.
        $baseline = $available - $assigned;
        $stillNeeded = max(0, $goal->amount - $baseline);
        $neededThisMonth = (int) ceil($stillNeeded / $monthsRemaining);

        return [max(0, $neededThisMonth - $assigned), $pct($available, $goal->amount), $neededThisMonth];
    }

    /** Within [starts_on, ends_on] (or starts_on + repeat_times periods)? */
    private function targetIsActive(Target $goal, CarbonImmutable $month): bool
    {
        $start = $this->savingStartMonth($goal);
        if ($start !== null && $month->lessThan($start)) {
            return false;
        }

        $end = $goal->ends_on?->startOfMonth();
        if ($end === null && $goal->repeat_times !== null && $goal->starts_on !== null) {
            $end = $this->lastOccurrence($goal)->startOfMonth();
        }

        return $end === null || $month->lessThanOrEqualTo($end);
    }

    /**
     * The first month the target asks for money. For refill targets the date
     * is a DUE date ("first amount needed by"), so quarter/year targets start
     * saving early enough that the pot is full in the due month. Builder
     * targets treat the date as the cycle's starting point.
     */
    private function savingStartMonth(Target $goal): ?CarbonImmutable
    {
        if ($goal->starts_on === null) {
            return null;
        }

        $start = $goal->starts_on->startOfMonth();
        $period = match ($goal->cadence ?? 'month') {
            'quarter' => 3,
            'year' => 12,
            default => null,
        };

        if ($period !== null && $goal->type === 'refill_monthly') {
            return $start->subMonthsNoOverflow($period - 1);
        }

        return $start;
    }

    /** The date of the final occurrence for a repeat_times-bounded target. */
    private function lastOccurrence(Target $goal): CarbonImmutable
    {
        $start = CarbonImmutable::parse($goal->starts_on);
        $n = max(0, $goal->repeat_times - 1);

        return match ($goal->cadence ?? 'month') {
            'week' => $start->addDays(7 * $n),
            'fortnight' => $start->addDays(14 * $n),
            'month' => $start->addMonthsNoOverflow($n),
            'quarter' => $start->addMonthsNoOverflow(3 * $n),
            'year' => $start->addMonthsNoOverflow(12 * $n),
        };
    }

    private function targetIsSnoozed(Target $goal, CarbonImmutable $month): bool
    {
        if (in_array($month->format('Y-m'), $goal->snoozed_months ?? [], true)) {
            return true;
        }

        return $goal->snoozed_until !== null
            && $month->startOfMonth()->lessThanOrEqualTo($goal->snoozed_until->startOfMonth());
    }

    /**
     * Translate the cadence into this month's ask: week/fortnight targets sum
     * their occurrences that fall inside the month (anchored on starts_on);
     * quarter/year targets drip evenly across their period, with rounding
     * remainders landing on the period's final month.
     */
    private function cadenceAmountForMonth(Target $goal, CarbonImmutable $month): int
    {
        return match ($goal->cadence ?? 'month') {
            'month' => $goal->amount,
            'week' => $goal->amount * $this->occurrencesInMonth($goal, $month, 7),
            'fortnight' => $goal->amount * $this->occurrencesInMonth($goal, $month, 14),
            'quarter' => $this->spreadOverPeriod($goal, $month, 3),
            'year' => $this->spreadOverPeriod($goal, $month, 12),
        };
    }

    /** Occurrences of an every-N-days cycle within the given month. */
    private function occurrencesInMonth(Target $goal, CarbonImmutable $month, int $step): int
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $anchor = $goal->starts_on !== null ? CarbonImmutable::parse($goal->starts_on) : $start;

        if ($anchor->greaterThan($end)) {
            return 0;
        }

        // First occurrence on/after the start of the month.
        $daysBehind = (int) $anchor->diffInDays($start, false);
        $skips = $daysBehind > 0 ? intdiv($daysBehind + $step - 1, $step) : 0;
        $occurrence = $anchor->addDays($skips * $step);

        $count = 0;
        while ($occurrence->lessThanOrEqualTo($end)) {
            $count++;
            $occurrence = $occurrence->addDays($step);
        }

        return $count;
    }

    /**
     * Even monthly share of a builder quarter/year target within its saving
     * window, remainder on the window's last month. (Refill quarter/year needs
     * are computed in refillNeed instead — they track the pot, not the month.)
     */
    private function spreadOverPeriod(Target $goal, CarbonImmutable $month, int $periodMonths): int
    {
        $anchor = ($goal->starts_on !== null ? $this->savingStartMonth($goal) : $month->startOfMonth());
        $index = ($month->year - $anchor->year) * 12 + ($month->month - $anchor->month);
        if ($index < 0) {
            return 0;
        }

        $position = $index % $periodMonths;
        $base = intdiv($goal->amount, $periodMonths);

        return $position === $periodMonths - 1
            ? $goal->amount - $base * ($periodMonths - 1)
            : $base;
    }

    /** @return array{0: int, 1: int, 2: int} [underfunded, progress %, needed this month] */
    private function balanceByDate(Target $goal, int $assigned, int $available, CarbonImmutable $month, callable $pct): array
    {
        $end = $goal->target_date?->startOfMonth() ?? $month;
        $monthsRemaining = max(
            1,
            ($end->year - $month->year) * 12 + ($end->month - $month->month) + 1,
        );

        // What the balance would be with nothing assigned this month.
        $baseline = $available - $assigned;
        $stillNeeded = max(0, $goal->amount - $baseline);
        $neededThisMonth = (int) ceil($stillNeeded / $monthsRemaining);

        return [max(0, $neededThisMonth - $assigned), $pct($available, $goal->amount), $neededThisMonth];
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
     * Activity per category per month across on-budget accounts, plus the
     * per-credit-card portion needed for payment-envelope attribution.
     * Aggregated in PHP to stay database-agnostic; personal-budget row counts
     * make this cheap.
     *
     * @return array{0: array<int, array<string, int>>, 1: array<int, array<string, array<int, int>>>}
     */
    private function activityByCategoryAndMonth(Budget $budget): array
    {
        $totals = [];
        $card = [];

        $add = function (int $categoryId, string $key, int $amount, ?int $cardAccountId) use (&$totals, &$card): void {
            $totals[$categoryId][$key] = ($totals[$categoryId][$key] ?? 0) + $amount;
            if ($cardAccountId !== null) {
                $card[$categoryId][$key][$cardAccountId] = ($card[$categoryId][$key][$cardAccountId] ?? 0) + $amount;
            }
        };

        $parents = Transaction::query()
            ->where('transactions.budget_id', $budget->id)
            ->whereNotNull('category_id')
            ->whereDoesntHave('subTransactions')
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->with('account:id,type')
            ->get(['id', 'account_id', 'category_id', 'date', 'amount']);

        foreach ($parents as $row) {
            $add(
                $row->category_id,
                $row->date->format('Y-m'),
                $row->amount,
                $row->account->type === 'credit' ? $row->account_id : null,
            );
        }

        $splits = SubTransaction::query()
            ->whereNotNull('category_id')
            ->whereHas('transaction', fn ($q) => $q
                ->where('budget_id', $budget->id)
                ->whereHas('account', fn ($a) => $a->where('on_budget', true)))
            ->with('transaction.account:id,type')
            ->get(['id', 'transaction_id', 'category_id', 'amount']);

        foreach ($splits as $row) {
            $add(
                $row->category_id,
                $row->transaction->date->format('Y-m'),
                $row->amount,
                $row->transaction->account->type === 'credit' ? $row->transaction->account_id : null,
            );
        }

        return [$totals, $card];
    }

    /**
     * Net transfer amounts INTO each credit card from other on-budget accounts
     * per month (a card payment is +X on the card side). These draw down the
     * payment envelope.
     *
     * @return array<int, array<string, int>> card account_id => ['YYYY-MM' => net in]
     */
    private function cardTransfersInByMonth(Budget $budget): array
    {
        $rows = Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereNotNull('transfer_transaction_id')
            ->whereHas('account', fn ($q) => $q->where('type', 'credit'))
            ->whereHas('transferTransaction.account', fn ($q) => $q->where('on_budget', true))
            ->get(['id', 'account_id', 'date', 'amount']);

        $result = [];
        foreach ($rows as $row) {
            $key = $row->date->format('Y-m');
            $result[$row->account_id][$key] = ($result[$row->account_id][$key] ?? 0) + $row->amount;
        }

        return $result;
    }

    private function firstMonth(array ...$sets): ?CarbonImmutable
    {
        $keys = [];
        foreach ($sets as $set) {
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
