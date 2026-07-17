<?php

function targetSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 500000, 'balance_date' => '2026-07-01',
    ])->json('data');

    return [$budget, $account];
}

function monthCategory(array $month, string $name): array
{
    foreach ($month['groups'] as $group) {
        foreach ($group['categories'] as $category) {
            if ($category['name'] === $name) {
                return $category;
            }
        }
    }
    throw new RuntimeException("Category $name not in payload");
}

test('a refill target reports underfunded until available reaches the amount', function () {
    [$budget] = targetSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'refill_monthly', 'amount' => 50000,
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target'])->toMatchArray([
        'type' => 'refill_monthly', 'underfunded' => 50000, 'progress' => 0,
    ])->and($month['underfunded_total'])->toBe(50000);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 20000]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target'])->toMatchArray([
        'underfunded' => 30000, 'progress' => 40,
    ]);
});

test('a monthly builder target tracks assigned, not available', function () {
    [$budget, $account] = targetSetup($this);
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000,
    ]);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$holiday->uuid}/assign", ['amount' => 10000]);
    // Spend it all — the builder goal is still met (it measures assigning).
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-15', 'amount' => -10000,
        'payee_name' => 'Flights', 'category_id' => $holiday->uuid,
    ]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Holiday')['target'])->toMatchArray([
        'underfunded' => 0, 'progress' => 100,
    ]);
});

test('a balance-by-date target spreads the need over remaining months', function () {
    [$budget] = targetSetup($this);
    $emergency = $budget->categories()->where('name', 'Emergency Fund')->first();

    // 1200.00 by December, viewing July: 6 months -> 200.00/month.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$emergency->uuid}/target", [
        'type' => 'balance_by_date', 'amount' => 120000, 'target_date' => '2026-12-31',
    ]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Emergency Fund')['target']['underfunded'])->toBe(20000);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$emergency->uuid}/assign", ['amount' => 5000]);
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Emergency Fund')['target']['underfunded'])->toBe(15000);
});

test('assign underfunded tops up every target in one call', function () {
    [$budget] = targetSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'refill_monthly', 'amount' => 50000,
    ]);
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000,
    ]);

    $month = $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/assign-underfunded")
        ->assertOk()->json();

    expect($month['underfunded_total'])->toBe(0)
        ->and(monthCategory($month, 'Groceries')['available'])->toBe(50000)
        ->and(monthCategory($month, 'Holiday')['assigned'])->toBe(10000)
        ->and($month['ready_to_assign'])->toBe(500000 - 60000);
});

test('a weekly cadence sums its occurrences inside the month', function () {
    [$budget] = targetSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    // $100 every week anchored Friday 2026-07-03: July has Fridays 3, 10, 17,
    // 24, 31 -> five occurrences -> $500 needed in July.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000, 'cadence' => 'week', 'starts_on' => '2026-07-03',
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target'])->toMatchArray([
        'cadence' => 'week', 'needed_this_month' => 50000, 'underfunded' => 50000,
    ]);

    // August 2026 has Fridays 7, 14, 21, 28 -> four occurrences.
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect(monthCategory($month, 'Groceries')['target']['needed_this_month'])->toBe(40000);
});

test('a quarterly cadence drips evenly with the remainder on the final month', function () {
    [$budget] = targetSetup($this);
    $rego = $budget->categories()->where('name', 'Emergency Fund')->first();

    // $1000/quarter from July: 333.33, 333.33, 333.34.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$rego->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 100000, 'cadence' => 'quarter', 'starts_on' => '2026-07-01',
    ]);

    foreach (['2026-07' => 33333, '2026-08' => 33333, '2026-09' => 33334, '2026-10' => 33333] as $key => $expected) {
        $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/{$key}")->json();
        expect(monthCategory($month, 'Emergency Fund')['target']['needed_this_month'])->toBe($expected);
    }
});

test('a refill target treats its date as first-needed-by and saves up to it', function () {
    [$budget] = targetSetup($this);
    $rego = $budget->categories()->where('name', 'Emergency Fund')->first();

    // $1000 needed by 15 Sep, then every quarter after: what is STILL needed
    // spreads evenly over the months left in the window, so an empty pot asks
    // 1000/3 in July, 1000/2 in August (July skipped), 1000 in September.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$rego->uuid}/target", [
        'type' => 'refill_monthly', 'amount' => 100000, 'cadence' => 'quarter', 'starts_on' => '2026-09-15',
    ])->assertCreated();

    // Before the first saving window, the target is quiet.
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-06")->json();
    expect(monthCategory($month, 'Emergency Fund')['target'])->toBeNull();

    foreach (['2026-07' => 33334, '2026-08' => 50000, '2026-09' => 100000, '2026-10' => 33334] as $key => $expected) {
        $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/{$key}")->json();
        expect(monthCategory($month, 'Emergency Fund')['target']['needed_this_month'])->toBe($expected, "month $key");
    }

    // Assigning half in July leaves an even $250/month for Aug + Sep, and the
    // due month still reports the true shortfall — never "funded".
    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$rego->uuid}/assign", ['amount' => 50000]);
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect(monthCategory($month, 'Emergency Fund')['target']['needed_this_month'])->toBe(25000);
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-09")->json();
    expect(monthCategory($month, 'Emergency Fund')['target']['underfunded'])->toBe(50000)
        ->and(monthCategory($month, 'Emergency Fund')['target']['progress'])->toBe(50);

    // A builder with the same settings starts its window ON the date instead.
    $holiday = $budget->categories()->where('name', 'Holiday')->first();
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 100000, 'cadence' => 'quarter', 'starts_on' => '2026-09-15',
    ]);
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect(monthCategory($month, 'Holiday')['target'])->toBeNull();
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-09")->json();
    expect(monthCategory($month, 'Holiday')['target']['needed_this_month'])->toBe(33333);
});

test('repeat_times and end dates bound when a target is active', function () {
    [$budget] = targetSetup($this);
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    // Monthly, twice, starting July -> active July + August, gone September.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000, 'starts_on' => '2026-07-01', 'repeat_times' => 2,
    ])->assertCreated();

    foreach (['2026-06' => false, '2026-07' => true, '2026-08' => true, '2026-09' => false] as $key => $active) {
        $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/{$key}")->json();
        expect(monthCategory($month, 'Holiday')['target'] !== null)->toBe($active, "month $key");
    }

    // An explicit end date works the same way, and cannot combine with repeats.
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000, 'starts_on' => '2026-07-01',
        'ends_on' => '2026-08-31', 'repeat_times' => 3,
    ])->assertUnprocessable();
});

test('snoozed months silence underfunded and are skipped by assign underfunded', function () {
    [$budget] = targetSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'refill_monthly', 'amount' => 50000,
    ]);
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$holiday->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 10000,
    ]);

    // Snooze groceries for July (explicit month list).
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target-snooze", [
        'months' => ['2026-07'], 'until' => null,
    ])->assertOk();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target'])->toMatchArray(['snoozed' => true, 'underfunded' => 0])
        ->and($month['underfunded_total'])->toBe(10000);

    // Assign-underfunded only funds the holiday target.
    $month = $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/assign-underfunded")->json();
    expect(monthCategory($month, 'Groceries')['assigned'])->toBe(0)
        ->and(monthCategory($month, 'Holiday')['assigned'])->toBe(10000);

    // August is unaffected; snooze-until covers a whole range instead.
    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect(monthCategory($month, 'Groceries')['target']['snoozed'])->toBeFalse();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target-snooze", [
        'months' => [], 'until' => '2026-09-30',
    ]);
    foreach (['2026-08' => true, '2026-09' => true, '2026-10' => false] as $key => $snoozed) {
        $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/{$key}")->json();
        expect(monthCategory($month, 'Groceries')['target']['snoozed'])->toBe($snoozed, "month $key");
    }
});

test('a target can be replaced and deleted', function () {
    [$budget] = targetSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'refill_monthly', 'amount' => 50000,
    ])->assertCreated();
    $this->putJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target", [
        'type' => 'monthly_builder', 'amount' => 20000,
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target']['type'])->toBe('monthly_builder');

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/categories/{$groceries->uuid}/target")->assertNoContent();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(monthCategory($month, 'Groceries')['target'])->toBeNull();
});
