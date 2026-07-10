<?php

function reportSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000, 'balance_date' => '2026-01-01',
    ])->json('data');

    return [$budget, $account];
}

test('spending report groups by category with a monthly trend', function () {
    [$budget, $account] = reportSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $fun = $budget->categories()->where('name', 'Fun Money')->first();

    foreach ([
        ['2026-01-10', -5000, $groceries], ['2026-01-20', -3000, $fun],
        ['2026-02-05', -7000, $groceries],
    ] as [$date, $amount, $category]) {
        $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
            'account_id' => $account['uuid'], 'date' => $date, 'amount' => $amount,
            'payee_name' => 'Shop', 'category_id' => $category->uuid,
        ]);
    }

    $report = $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/spending?from=2026-01&to=2026-02")
        ->assertOk()->json();

    expect($report['total'])->toBe(15000)
        ->and($report['groups'][0])->toMatchArray(['name' => 'Groceries', 'amount' => 12000])
        ->and($report['groups'][1])->toMatchArray(['name' => 'Fun Money', 'amount' => 3000])
        ->and($report['monthly'])->toBe([
            ['month' => '2026-01', 'amount' => 8000],
            ['month' => '2026-02', 'amount' => 7000],
        ]);
});

test('spending report can group by payee and ignores income', function () {
    [$budget, $account] = reportSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $rta = $budget->readyToAssignCategory();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-01-05', 'amount' => 250000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-01-10', 'amount' => -4000,
        'payee_name' => 'Cafe', 'category_id' => $groceries->uuid,
    ]);

    $report = $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/spending?from=2026-01&to=2026-01&group_by=payee")->json();

    expect($report['groups'])->toHaveCount(1)
        ->and($report['groups'][0])->toMatchArray(['name' => 'Cafe', 'amount' => 4000]);
});

test('net worth tracks assets, debts and tracking accounts over months', function () {
    [$budget, $account] = reportSetup($this);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Visa', 'type' => 'credit', 'balance' => -20000, 'balance_date' => '2026-01-15',
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Shares', 'type' => 'tracking', 'balance' => 300000, 'balance_date' => '2026-02-10',
    ]);

    $months = $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/net-worth")->json('months');

    $january = collect($months)->firstWhere('month', '2026-01');
    $february = collect($months)->firstWhere('month', '2026-02');

    expect($january)->toMatchArray(['assets' => 100000, 'debts' => -20000, 'net' => 80000])
        ->and($february)->toMatchArray(['assets' => 400000, 'debts' => -20000, 'net' => 380000]);
});

test('income vs expense report splits rta inflows from categorized spending', function () {
    [$budget, $account] = reportSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $rta = $budget->readyToAssignCategory();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-03-01', 'amount' => 200000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-03-10', 'amount' => -45000,
        'payee_name' => 'Shop', 'category_id' => $groceries->uuid,
    ]);

    $months = $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/income-expense?from=2026-03&to=2026-03")->json('months');

    expect($months[0])->toMatchArray([
        'month' => '2026-03', 'income' => 200000, 'expense' => 45000, 'net' => 155000,
    ]);
});

test('age of money averages fifo ages of recent outflows', function () {
    $budget = budgetFor(login());
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ])->json('data');
    $rta = $budget->readyToAssignCategory();
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    // Income 1000.00 on Jan 1; spend 200.00 on Jan 11 (age 10) and
    // 300.00 on Jan 21 (age 20) -> AoM = 15.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-01-01', 'amount' => 100000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);
    foreach ([['2026-01-11', -20000], ['2026-01-21', -30000]] as [$date, $amount]) {
        $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
            'account_id' => $account['uuid'], 'date' => $date, 'amount' => $amount,
            'payee_name' => 'Shop', 'category_id' => $groceries->uuid,
        ]);
    }

    $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/age-of-money")
        ->assertOk()
        ->assertJsonPath('age_of_money', 15);
});

test('age of money ignores on-budget transfers', function () {
    $budget = budgetFor(login());
    $checking = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ])->json('data');
    $savings = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Savings', 'type' => 'savings',
    ])->json('data');
    $rta = $budget->readyToAssignCategory();
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'], 'date' => '2026-01-01', 'amount' => 50000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);
    // Shuffling money to savings must not count as spending or income.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'], 'date' => '2026-01-05', 'amount' => -40000,
        'transfer_account_id' => $savings['uuid'],
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $savings['uuid'], 'date' => '2026-01-31', 'amount' => -10000,
        'payee_name' => 'Shop', 'category_id' => $groceries->uuid,
    ]);

    $this->getJson("/api/v1/budgets/{$budget->uuid}/reports/age-of-money")
        ->assertJsonPath('age_of_money', 30);
});
