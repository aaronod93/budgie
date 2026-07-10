<?php

test('creating an on-budget account with a starting balance funds ready to assign', function () {
    $budget = budgetFor(login());

    $response = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking',
        'type' => 'checking',
        'balance' => 150000,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.balance', 150000)
        ->assertJsonPath('data.on_budget', true);

    $month = now()->format('Y-m');
    $this->getJson("/api/v1/budgets/{$budget->uuid}/months/$month")
        ->assertOk()
        ->assertJsonPath('ready_to_assign', 150000);
});

test('tracking accounts are off budget and do not touch ready to assign', function () {
    $budget = budgetFor(login());

    $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Shares',
        'type' => 'tracking',
        'balance' => 999999,
    ])->assertCreated()->assertJsonPath('data.on_budget', false);

    $month = now()->format('Y-m');
    $this->getJson("/api/v1/budgets/{$budget->uuid}/months/$month")
        ->assertOk()
        ->assertJsonPath('ready_to_assign', 0);
});

test('cleared balance only counts cleared transactions', function () {
    $budget = budgetFor(login());

    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 10000,
    ])->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'],
        'date' => now()->toDateString(),
        'amount' => -2500,
        'payee_name' => 'Cafe',
        'cleared' => 'uncleared',
    ])->assertCreated();

    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$account['uuid']}")
        ->assertOk()
        ->assertJsonPath('data.balance', 7500)
        ->assertJsonPath('data.cleared_balance', 10000);
});

test('deleting an account removes its money, schedules and transfer payee', function () {
    $budget = budgetFor(login());

    $checking = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000, 'balance_date' => '2026-07-01',
    ])->json('data');
    $savings = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Savings', 'type' => 'savings', 'balance' => 50000, 'balance_date' => '2026-07-01',
    ])->json('data');

    // A transfer between them and a schedule on savings.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'], 'date' => '2026-07-05', 'amount' => -20000,
        'transfer_account_id' => $savings['uuid'],
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions", [
        'account_id' => $savings['uuid'], 'frequency' => 'monthly',
        'next_date' => '2026-08-01', 'amount' => -1000, 'payee_name' => 'Fee',
    ]);

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/accounts/{$savings['uuid']}")->assertNoContent();

    // Account gone; RTA loses its starting balance; checking's transfer row survives.
    $names = array_column($this->getJson("/api/v1/budgets/{$budget->uuid}/accounts")->json('data'), 'name');
    expect($names)->toBe(['Checking']);
    $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")
        ->assertJsonPath('ready_to_assign', 100000);
    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$checking['uuid']}")
        ->assertJsonPath('data.balance', 80000);

    // Schedules and the transfer payee are gone with it.
    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/scheduled-transactions")->json('data'))->toBeEmpty();
    $payeeNames = array_column($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'), 'name');
    expect($payeeNames)->not->toContain('Transfer : Savings');
});

test('deleting a credit account removes its payment envelope', function () {
    $budget = budgetFor(login());
    $card = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Visa', 'type' => 'credit',
    ])->json('data');

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/accounts/{$card['uuid']}")->assertNoContent();

    $month = $this->getJson("/api/v1/budgets/{$budget->uuid}/months/2026-07")->json();
    $allNames = collect($month['groups'])->flatMap(fn ($g) => array_column($g['categories'], 'name'));
    expect($allNames)->not->toContain('Visa');
});

test('an account from another budget is not reachable', function () {
    $user = login();
    $budgetA = budgetFor($user, 'A');
    $budgetB = budgetFor($user, 'B');

    $account = $this->postJson("/api/v1/budgets/{$budgetA->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ])->json('data');

    $this->getJson("/api/v1/budgets/{$budgetB->uuid}/accounts/{$account['uuid']}")->assertNotFound();
});
