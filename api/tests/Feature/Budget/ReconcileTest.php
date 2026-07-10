<?php

test('reconciling with a matching statement locks cleared transactions without an adjustment', function () {
    $budget = budgetFor(login());
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 50000,
    ])->json('data');

    $response = $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/accounts/{$account['uuid']}/reconcile",
        ['statement_balance' => 50000],
    );

    $response->assertOk()->assertJsonPath('adjustment', null);

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?account_id={$account['uuid']}")->json('data');
    expect($rows[0]['cleared'])->toBe('reconciled');
});

test('a statement mismatch creates a cleared adjustment through ready to assign', function () {
    $budget = budgetFor(login());
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 50000,
    ])->json('data');

    $response = $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/accounts/{$account['uuid']}/reconcile",
        ['statement_balance' => 47500],
    );

    $response->assertOk()
        ->assertJsonPath('adjustment.amount', -2500)
        ->assertJsonPath('account.cleared_balance', 47500);

    $month = now()->format('Y-m');
    expect($this->getJson("/api/v1/budgets/{$budget->uuid}/months/$month")->json('ready_to_assign'))->toBe(47500);
});

test('uncleared transactions are ignored by reconciliation', function () {
    $budget = budgetFor(login());
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 50000,
    ])->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => now()->toDateString(),
        'amount' => -9999, 'payee_name' => 'Pending', 'cleared' => 'uncleared',
    ]);

    $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/accounts/{$account['uuid']}/reconcile",
        ['statement_balance' => 50000],
    )->assertOk()->assertJsonPath('adjustment', null);

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?account_id={$account['uuid']}")->json('data');
    $pending = collect($rows)->firstWhere('amount', -9999);
    expect($pending['cleared'])->toBe('uncleared');
});

test('reconciled transactions are locked unless forced', function () {
    $budget = budgetFor(login());
    $account = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 50000,
    ])->json('data');

    $this->postJson(
        "/api/v1/budgets/{$budget->uuid}/accounts/{$account['uuid']}/reconcile",
        ['statement_balance' => 50000],
    );

    $txn = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?account_id={$account['uuid']}")->json('data.0');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}", ['amount' => -1])
        ->assertUnprocessable();
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}")
        ->assertUnprocessable();

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}", ['memo' => 'edited', 'force' => true])
        ->assertOk()->assertJsonPath('data.memo', 'edited');
    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/transactions/{$txn['uuid']}?force=1")
        ->assertNoContent();
});
