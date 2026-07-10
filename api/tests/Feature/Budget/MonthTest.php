<?php

use App\Models\Budget;

/** Find a category row in the month payload by name. */
function categoryRow(array $month, string $name): array
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

function seedChecking(object $test, Budget $budget, int $balance, string $date): array
{
    return $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => $balance, 'balance_date' => $date,
    ])->json('data');
}

test('assigning money moves it from rta into the envelope', function () {
    $budget = budgetFor(login());
    seedChecking($this, $budget, 100000, '2026-07-01');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $month = $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign",
        ['amount' => 30000],
    )->assertOk()->json();

    expect($month['ready_to_assign'])->toBe(70000)
        ->and(categoryRow($month, 'Groceries'))->toMatchArray([
            'assigned' => 30000, 'activity' => 0, 'available' => 30000,
        ]);
});

test('spending draws down the envelope, not rta', function () {
    $budget = budgetFor(login());
    $account = seedChecking($this, $budget, 100000, '2026-07-01');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 30000]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -12000,
        'payee_name' => 'Woolworths', 'category_id' => $groceries->uuid,
    ]);

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect($month['ready_to_assign'])->toBe(70000)
        ->and(categoryRow($month, 'Groceries'))->toMatchArray([
            'assigned' => 30000, 'activity' => -12000, 'available' => 18000,
        ]);
});

test('positive envelope balances carry into future months', function () {
    $budget = budgetFor(login());
    seedChecking($this, $budget, 100000, '2026-07-01');
    $holiday = $budget->categories()->where('name', 'Holiday')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$holiday->uuid}/assign", ['amount' => 20000]);

    $september = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-09")->json();

    expect(categoryRow($september, 'Holiday'))->toMatchArray([
        'assigned' => 0, 'activity' => 0, 'available' => 20000,
    ])->and($september['ready_to_assign'])->toBe(80000);
});

test('cash overspending resets next month and comes out of rta', function () {
    $budget = budgetFor(login());
    $account = seedChecking($this, $budget, 100000, '2026-07-01');
    $eatingOut = $budget->categories()->where('name', 'Eating Out')->first();

    // Spend 5000 with nothing assigned: July shows -5000 available.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-15', 'amount' => -5000,
        'payee_name' => 'Cafe', 'category_id' => $eatingOut->uuid,
    ]);

    $july = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    expect(categoryRow($july, 'Eating Out')['available'])->toBe(-5000)
        ->and($july['ready_to_assign'])->toBe(100000);

    // August: envelope resets to 0, RTA absorbs the overspend.
    $august = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json();
    expect(categoryRow($august, 'Eating Out')['available'])->toBe(0)
        ->and($august['ready_to_assign'])->toBe(95000);
});

test('move money rebalances between envelopes', function () {
    $budget = budgetFor(login());
    seedChecking($this, $budget, 100000, '2026-07-01');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $fun = $budget->categories()->where('name', 'Fun Money')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$groceries->uuid}/assign", ['amount' => 30000]);

    $month = $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/move-money", [
        'from_category_id' => $groceries->uuid,
        'to_category_id' => $fun->uuid,
        'amount' => 10000,
    ])->assertOk()->json();

    expect(categoryRow($month, 'Groceries')['available'])->toBe(20000)
        ->and(categoryRow($month, 'Fun Money')['available'])->toBe(10000)
        ->and($month['ready_to_assign'])->toBe(70000);
});

test('moving from rta assigns and moving to rta unassigns', function () {
    $budget = budgetFor(login());
    seedChecking($this, $budget, 50000, '2026-07-01');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $month = $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/move-money", [
        'to_category_id' => $groceries->uuid,
        'amount' => 20000,
    ])->json();
    expect($month['ready_to_assign'])->toBe(30000)
        ->and(categoryRow($month, 'Groceries')['available'])->toBe(20000);

    $month = $this->postJson("/api/v1/budgets/{$budget->uuid}/months/2026-07/move-money", [
        'from_category_id' => $groceries->uuid,
        'amount' => 5000,
    ])->json();
    expect($month['ready_to_assign'])->toBe(35000)
        ->and(categoryRow($month, 'Groceries')['available'])->toBe(15000);
});

test('income lands in rta in the month it is received', function () {
    $budget = budgetFor(login());
    $account = seedChecking($this, $budget, 0, '2026-07-01');
    $rta = $budget->readyToAssignCategory();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-08-01', 'amount' => 250000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);

    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json('ready_to_assign'))->toBe(0)
        ->and($this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json('ready_to_assign'))->toBe(250000)
        ->and($this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-08")->json('income'))->toBe(250000);
});

test('splits attribute activity to their own categories', function () {
    $budget = budgetFor(login());
    $account = seedChecking($this, $budget, 100000, '2026-07-01');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $fun = $budget->categories()->where('name', 'Fun Money')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-10', 'amount' => -10000,
        'payee_name' => 'Kmart',
        'splits' => [
            ['amount' => -6000, 'category_id' => $groceries->uuid],
            ['amount' => -4000, 'category_id' => $fun->uuid],
        ],
    ])->assertCreated();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();

    expect(categoryRow($month, 'Groceries')['activity'])->toBe(-6000)
        ->and(categoryRow($month, 'Fun Money')['activity'])->toBe(-4000);
});

test('assigning to the internal rta category is rejected', function () {
    $budget = budgetFor(login());
    $rta = $budget->readyToAssignCategory();

    $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/months/2026-07/categories/{$rta->uuid}/assign",
        ['amount' => 1000],
    )->assertUnprocessable();
});
