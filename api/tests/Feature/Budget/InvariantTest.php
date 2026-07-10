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
 * The load-bearing property of envelope budgeting (PLAN.md §4), extended for
 * credit cards: at the end of any month,
 *
 *   RTA + sum(envelope available) + credit overspending (this month)
 *     == total cash in non-credit on-budget accounts.
 *
 * Random operation sequences — income, cash & card spending, assigning (to
 * spending AND payment envelopes), moving, transfers, card payments — must
 * never break it. (Card refunds are exercised in CreditCardTest; a net refund
 * exceeding all card spending creates a positive card balance, which YNAB and
 * Budgie both treat as a to-be-fixed anomaly rather than budget money.)
 */
test('rta plus envelopes plus credit overspend equals on-budget cash', function (int $seed) {
    $budget = budgetFor(login());

    $createAccount = app(CreateAccount::class);
    $checking = $createAccount($budget, ['name' => 'Checking', 'type' => 'checking'], 0);
    $savings = $createAccount($budget, ['name' => 'Savings', 'type' => 'savings'], 0);
    $card = $createAccount($budget, ['name' => 'Visa', 'type' => 'credit'], 0);
    $tracking = $createAccount($budget, ['name' => 'Shares', 'type' => 'tracking'], 0);

    $categories = $budget->categories()->whereNull('internal_type')->get();
    $paymentCategory = $budget->categories()->where('internal_type', 'credit_card_payment')->firstOrFail();
    $rta = $budget->readyToAssignCategory();

    $recorder = app(RecordTransaction::class);
    $assign = app(AssignMoney::class);
    $move = app(MoveMoney::class);

    $months = ['2026-01', '2026-02', '2026-03', '2026-04'];
    mt_srand($seed);

    for ($i = 0; $i < 70; $i++) {
        $month = $months[mt_rand(0, count($months) - 1)];
        $date = sprintf('%s-%02d', $month, mt_rand(1, 28));
        $category = $categories[mt_rand(0, $categories->count() - 1)];
        $cashAccount = mt_rand(0, 1) ? $checking : $savings;

        try {
            match (mt_rand(0, 7)) {
                // Income into Ready to Assign
                0 => $recorder->create($budget, [
                    'account_id' => $checking->id, 'date' => $date,
                    'amount' => mt_rand(1, 5000) * 100, 'category_id' => $rta->id,
                ]),
                // Categorized cash spending
                1 => $recorder->create($budget, [
                    'account_id' => $cashAccount->id, 'date' => $date,
                    'amount' => -mt_rand(1, 3000) * 100, 'category_id' => $category->id,
                ]),
                // Categorized CARD spending
                2 => $recorder->create($budget, [
                    'account_id' => $card->id, 'date' => $date,
                    'amount' => -mt_rand(1, 2000) * 100, 'category_id' => $category->id,
                ]),
                // Assign to a spending envelope (absolute set)
                3 => $assign($budget, CarbonImmutable::parse("$month-01"), $category, mt_rand(0, 4000) * 100),
                // Assign to the card payment envelope
                4 => $assign($budget, CarbonImmutable::parse("$month-01"), $paymentCategory, mt_rand(0, 2000) * 100),
                // Move between envelopes / RTA
                5 => $move(
                    $budget,
                    CarbonImmutable::parse("$month-01"),
                    mt_rand(0, 3) === 0 ? null : $categories[mt_rand(0, $categories->count() - 1)],
                    mt_rand(0, 3) === 0 ? null : $categories[mt_rand(0, $categories->count() - 1)],
                    mt_rand(1, 2000) * 100,
                ),
                // Card payment (checking -> card) or plain on-budget transfer
                6 => $recorder->create($budget, [
                    'account_id' => $checking->id, 'date' => $date,
                    'amount' => -mt_rand(1, 1500) * 100,
                    'transfer_account_id' => mt_rand(0, 1) ? $card->id : $savings->id,
                ]),
                // Categorized transfer out to a tracking account
                7 => $recorder->create($budget, [
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
            ->whereHas('account', fn ($q) => $q->where('on_budget', true)->where('type', '!=', 'credit'))
            ->where('date', '<=', CarbonImmutable::parse("$monthKey-01")->endOfMonth())
            ->sum('amount');

        $this->assertSame(
            $cash,
            $payload['ready_to_assign'] + $payload['available_total'] + $payload['credit_overspend'],
            "Invariant broken for $monthKey (seed $seed)",
        );
    }
})->with([1, 2, 3, 4, 5, 6, 7, 8]);
