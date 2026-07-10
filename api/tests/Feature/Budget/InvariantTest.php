<?php

use App\Models\Transaction;
use App\Services\AssignMoney;
use App\Services\CreateAccount;
use App\Services\MonthService;
use App\Services\MoveMoney;
use App\Services\RecordTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The load-bearing property of envelope budgeting (PLAN.md §4): at the end of
 * any month, Ready to Assign plus every envelope's available balance must equal
 * the total cash in on-budget accounts. Random operation sequences (income,
 * spending, assigning, moving, transfers) must never break it.
 */
test('rta plus envelope balances always equals on-budget cash', function (int $seed) {
    $budget = budgetFor(login());

    $createAccount = app(CreateAccount::class);
    $checking = $createAccount($budget, ['name' => 'Checking', 'type' => 'checking'], 0);
    $savings = $createAccount($budget, ['name' => 'Savings', 'type' => 'savings'], 0);
    $tracking = $createAccount($budget, ['name' => 'Shares', 'type' => 'tracking'], 0);

    $categories = $budget->categories()->whereNull('internal_type')->get();
    $rta = $budget->readyToAssignCategory();

    $recorder = app(RecordTransaction::class);
    $assign = app(AssignMoney::class);
    $move = app(MoveMoney::class);

    $months = ['2026-01', '2026-02', '2026-03', '2026-04'];
    mt_srand($seed);

    for ($i = 0; $i < 60; $i++) {
        $month = $months[mt_rand(0, count($months) - 1)];
        $date = sprintf('%s-%02d', $month, mt_rand(1, 28));
        $category = $categories[mt_rand(0, $categories->count() - 1)];
        $cashAccount = mt_rand(0, 1) ? $checking : $savings;

        try {
            match (mt_rand(0, 5)) {
                // Income into Ready to Assign
                0 => $recorder->create($budget, [
                    'account_id' => $checking->id, 'date' => $date,
                    'amount' => mt_rand(1, 5000) * 100, 'category_id' => $rta->id,
                ]),
                // Categorized spending
                1 => $recorder->create($budget, [
                    'account_id' => $cashAccount->id, 'date' => $date,
                    'amount' => -mt_rand(1, 3000) * 100, 'category_id' => $category->id,
                ]),
                // Assign (absolute set, may overwrite an earlier assign)
                2 => $assign($budget, CarbonImmutable::parse("$month-01"), $category, mt_rand(0, 4000) * 100),
                // Move between envelopes / RTA
                3 => $move(
                    $budget,
                    CarbonImmutable::parse("$month-01"),
                    mt_rand(0, 3) === 0 ? null : $categories[mt_rand(0, $categories->count() - 1)],
                    mt_rand(0, 3) === 0 ? null : $categories[mt_rand(0, $categories->count() - 1)],
                    mt_rand(1, 2000) * 100,
                ),
                // On-budget transfer (no category; cash total unchanged)
                4 => $recorder->create($budget, [
                    'account_id' => $checking->id, 'date' => $date,
                    'amount' => -mt_rand(1, 1000) * 100, 'transfer_account_id' => $savings->id,
                ]),
                // Categorized transfer out to a tracking account (money leaves the budget)
                5 => $recorder->create($budget, [
                    'account_id' => $cashAccount->id, 'date' => $date,
                    'amount' => -mt_rand(1, 1000) * 100,
                    'transfer_account_id' => $tracking->id, 'category_id' => $category->id,
                ]),
            };
        } catch (ValidationException) {
            // e.g. move with identical from/to — skip and continue the sequence
        }
    }

    $monthService = app(MonthService::class);

    foreach ([...$months, '2026-05'] as $monthKey) {
        $payload = $monthService->compute($budget, CarbonImmutable::parse("$monthKey-01"));

        $cash = (int) Transaction::query()
            ->where('budget_id', $budget->id)
            ->whereHas('account', fn ($q) => $q->where('on_budget', true))
            ->where('date', '<=', CarbonImmutable::parse("$monthKey-01")->endOfMonth())
            ->sum('amount');

        $this->assertSame(
            $cash,
            $payload['ready_to_assign'] + $payload['available_total'],
            "Invariant broken for $monthKey (seed $seed)",
        );
    }
})->with([1, 2, 3, 4, 5, 6, 7, 8]);
