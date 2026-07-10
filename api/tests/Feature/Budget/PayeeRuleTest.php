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

test('payees can carry a unicode icon that flows into transactions', function () {
    [$budget, $account] = payeeSetup($this);

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => -4000,
        'payee_name' => 'Aldi',
    ]);

    $payee = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'))
        ->firstWhere('name', 'Aldi');

    $this->patchJson("/api/v1/budgets/{$budget->uuid}/payees/{$payee['uuid']}", ['icon' => '🛒'])
        ->assertOk()->assertJsonPath('data.icon', '🛒');

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions")->json('data');
    $aldiRow = collect($rows)->first(fn ($r) => ($r['payee']['name'] ?? '') === 'Aldi');
    expect($aldiRow['payee']['icon'])->toBe('🛒');
});

test('payees remember their last category and flow direction', function () {
    [$budget, $account] = payeeSetup($this);
    $groceries = $budget->categories()->where('name', 'Groceries')->first();
    $fun = $budget->categories()->where('name', 'Fun Money')->first();
    $rta = $budget->readyToAssignCategory();

    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => -4000,
        'payee_name' => 'Aldi', 'category_id' => $groceries->uuid,
    ]);
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-02', 'amount' => 250000,
        'payee_name' => 'Employer', 'category_id' => $rta->uuid,
    ]);

    $payees = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'));

    expect($payees->firstWhere('name', 'Aldi'))->toMatchArray([
        'last_category_uuid' => $groceries->uuid, 'last_flow' => 'outflow',
    ])->and($payees->firstWhere('name', 'Employer'))->toMatchArray([
        'last_category_uuid' => $rta->uuid, 'last_flow' => 'inflow',
    ]);

    // Memory follows the most recent transaction.
    $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
        'account_id' => $account['uuid'], 'date' => '2026-07-05', 'amount' => -1500,
        'payee_name' => 'Aldi', 'category_id' => $fun->uuid,
    ]);

    $payees = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'));
    expect($payees->firstWhere('name', 'Aldi')['last_category_uuid'])->toBe($fun->uuid);
});

test('transactions can be filtered by payee', function () {
    [$budget, $account] = payeeSetup($this);

    foreach ([['Aldi', -100], ['Aldi', -200], ['Coles', -300]] as [$name, $amount]) {
        $this->postJson("/api/v1/budgets/{$budget->uuid}/transactions", [
            'account_id' => $account['uuid'], 'date' => '2026-07-01', 'amount' => $amount,
            'payee_name' => $name,
        ]);
    }

    $aldi = collect($this->getJson("/api/v1/budgets/{$budget->uuid}/payees")->json('data'))
        ->firstWhere('name', 'Aldi');

    $rows = $this->getJson("/api/v1/budgets/{$budget->uuid}/transactions?payee_id={$aldi['uuid']}")->json('data');
    expect($rows)->toHaveCount(2);
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
