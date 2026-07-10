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
