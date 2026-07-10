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

test('an account from another budget is not reachable', function () {
    $user = login();
    $budgetA = budgetFor($user, 'A');
    $budgetB = budgetFor($user, 'B');

    $account = $this->postJson("/api/v1/budgets/{$budgetA->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking',
    ])->json('data');

    $this->getJson("/api/v1/budgets/{$budgetB->uuid}/accounts/{$account['uuid']}")->assertNotFound();
});
