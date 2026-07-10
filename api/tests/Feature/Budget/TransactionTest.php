<?php

function setupAccount(object $test, $budget, string $name = 'Checking', string $type = 'checking', int $balance = 0): array
{
    return $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => $name, 'type' => $type, 'balance' => $balance,
    ])->json('data');
}

test('a transaction can be created with a new payee and category', function () {
    $budget = budgetFor(login());
    $account = setupAccount($this, $budget);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $response = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'],
        'date' => '2026-07-05',
        'amount' => -4550,
        'payee_name' => 'Woolworths',
        'category_id' => $groceries->uuid,
        'memo' => 'weekly shop',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.amount', -4550)
        ->assertJsonPath('data.payee.name', 'Woolworths')
        ->assertJsonPath('data.category.name', 'Groceries');

    expect($budget->payees()->where('name', 'Woolworths')->exists())->toBeTrue();
});

test('split amounts must add up to the transaction amount', function () {
    $budget = budgetFor(login());
    $account = setupAccount($this, $budget);
    [$groceries, $fun] = [
        $budget->categories()->where('name', 'Groceries')->first(),
        $budget->categories()->where('name', 'Fun Money')->first(),
    ];

    $payload = [
        'account_id' => $account['uuid'],
        'date' => '2026-07-05',
        'amount' => -10000,
        'payee_name' => 'Kmart',
        'splits' => [
            ['amount' => -6000, 'category_id' => $groceries->uuid],
            ['amount' => -3000, 'category_id' => $fun->uuid],
        ],
    ];

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", $payload)->assertUnprocessable();

    $payload['splits'][1]['amount'] = -4000;
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", $payload)
        ->assertCreated()
        ->assertJsonPath('data.category', null)
        ->assertJsonCount(2, 'data.splits');
});

test('a transfer between on-budget accounts creates a mirrored pair with no category', function () {
    $budget = budgetFor(login());
    $checking = setupAccount($this, $budget, 'Checking', 'checking', 50000);
    $savings = setupAccount($this, $budget, 'Savings', 'savings');
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    $response = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'],
        'date' => '2026-07-06',
        'amount' => -20000,
        'transfer_account_id' => $savings['uuid'],
        'category_id' => $groceries->uuid, // must be ignored: both sides on budget
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.category', null)
        ->assertJsonPath('data.transfer_account_uuid', $savings['uuid']);

    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$checking['uuid']}")
        ->assertJsonPath('data.balance', 30000);
    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$savings['uuid']}")
        ->assertJsonPath('data.balance', 20000);
});

test('editing a transfer keeps both sides mirrored', function () {
    $budget = budgetFor(login());
    $checking = setupAccount($this, $budget, 'Checking', 'checking', 50000);
    $savings = setupAccount($this, $budget, 'Savings', 'savings');

    $transfer = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'],
        'date' => '2026-07-06',
        'amount' => -20000,
        'transfer_account_id' => $savings['uuid'],
    ])->json('data');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/transactions/{$transfer['uuid']}", [
        'amount' => -25000,
        'date' => '2026-07-07',
    ])->assertOk();

    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$savings['uuid']}")
        ->assertJsonPath('data.balance', 25000);
});

test('deleting a transfer removes both sides', function () {
    $budget = budgetFor(login());
    $checking = setupAccount($this, $budget, 'Checking', 'checking', 50000);
    $savings = setupAccount($this, $budget, 'Savings', 'savings');

    $transfer = $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $checking['uuid'],
        'date' => '2026-07-06',
        'amount' => -20000,
        'transfer_account_id' => $savings['uuid'],
    ])->json('data');

    $this->deleteJson("/api/v1/budgets/{$budget->uuid}/transactions/{$transfer['uuid']}")->assertNoContent();

    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$checking['uuid']}")
        ->assertJsonPath('data.balance', 50000);
    $this->getJson("/api/v1/budgets/{$budget->uuid}/accounts/{$savings['uuid']}")
        ->assertJsonPath('data.balance', 0);
});

test('transactions can be filtered by account', function () {
    $budget = budgetFor(login());
    $checking = setupAccount($this, $budget, 'Checking', 'checking', 1000);
    $savings = setupAccount($this, $budget, 'Savings', 'savings', 2000);

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?account_id={$checking['uuid']}")
        ->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['account_uuid'])->toBe($checking['uuid']);
});
