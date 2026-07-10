<?php

function payeeSetup(object $test): array
{
    $budget = budgetFor(login());
    $account = $test->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Checking', 'type' => 'checking', 'balance' => 100000,
    ])->json('data');

    return [$budget, $account];
}

test('a payee default category auto-categorises new transactions', function () {
    [$budget, $account] = payeeSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();

    // First transaction creates the payee (uncategorised).
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => -1000,
        'payee_name' => 'Woolworths',
    ]);

    $payee = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'))
        ->firstWhere('name', 'Woolworths');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/payees/{$payee['uuid']}", [
        'default_category_id' => $groceries->uuid,
    ])->assertOk()->assertJsonPath('data.default_category.name', 'Groceries');

    // No category key at all -> default applies.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-02', 'amount' => -2000,
        'payee_name' => 'Woolworths',
    ])->assertCreated()->assertJsonPath('data.category.name', 'Groceries');

    // Explicit null means the user chose "no category" — respected.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-03', 'amount' => -3000,
        'payee_name' => 'Woolworths', 'category_id' => null,
    ])->assertCreated()->assertJsonPath('data.category', null);
});

test('merging payees moves transactions and removes the source', function () {
    [$budget, $account] = payeeSetup($this);

    foreach (['Woolies', 'Woolworths'] as $name) {
        $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
            'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => -1000,
            'payee_name' => $name,
        ]);
    }

    $payees = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'));
    $source = $payees->firstWhere('name', 'Woolies');
    $into = $payees->firstWhere('name', 'Woolworths');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/payees/{$source['uuid']}/merge", [
        'into_payee_id' => $into['uuid'],
    ])->assertOk();

    $names = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'))->pluck('name');
    expect($names)->not->toContain('Woolies');

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions")->json('data');
    $woolworthsRows = collect($rows)->filter(fn ($r) => ($r['payee']['name'] ?? '') === 'Woolworths');
    expect($woolworthsRows)->toHaveCount(2);
});

test('a payee cannot merge into itself or a transfer payee', function () {
    [$budget, $account] = payeeSetup($this);
    $savings = $this->postJson("/api/v1/budgets/{$budget->uuid}/accounts", [
        'name' => 'Savings', 'type' => 'savings',
    ])->json('data');

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => -1000,
        'payee_name' => 'Cafe',
    ]);
    // Creates the transfer payee.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-02', 'amount' => -5000,
        'transfer_account_id' => $savings['uuid'],
    ]);

    $payees = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'));
    $cafe = $payees->firstWhere('name', 'Cafe');
    $transferPayee = $payees->first(fn ($p) => $p['transfer_account_uuid'] !== null);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/payees/{$cafe['uuid']}/merge", [
        'into_payee_id' => $cafe['uuid'],
    ])->assertUnprocessable();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/payees/{$transferPayee['uuid']}/merge", [
        'into_payee_id' => $cafe['uuid'],
    ])->assertNotFound();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/payees/{$cafe['uuid']}/merge", [
        'into_payee_id' => $transferPayee['uuid'],
    ])->assertUnprocessable();
});
